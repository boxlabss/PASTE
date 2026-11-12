<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Spam detection engine.
 *
 * Adds up points from these signals and turns the total into allow, hold for
 * moderation, or block:
 *
 *   1. Admin keyword / regex / domain rules      (spam_rules)
 *   2. Content heuristics                        (links, link ratio, length, caps)
 *   3. Link domains on URI blocklists            (Spamhaus DBL, SURBL)
 *   4. The learned filter                        (spam_tokens, spam_learned)
 *   5. Form traps                                (honeypot field, submit time)
 *   6. DNSBL lookups on the poster's IP
 *   7. StopForumSpam IP reputation               (api.stopforumspam.org)
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License (version 3 or later). See LICENCE.
 */

declare(strict_types=1);

require_once __DIR__ . '/paste_crypt.php';

/* ============================ Settings & rules ============================ */

/** Built-in defaults, also used as a safe fallback when the table is missing. */
function spam_default_settings(): array
{
    return [
        'enabled'           => 0,
        'block_threshold'   => 100,
        'review_threshold'  => 50,
        // content heuristics
        'max_links'         => 4,     // links beyond this start scoring
        'link_weight'       => 12,    // per link over the limit
        'link_ratio_pct'    => 35,    // links-per-line % that looks spammy
        'link_ratio_weight' => 30,
        'min_length'        => 24,    // very short + links == suspicious
        'short_weight'      => 15,
        'caps_weight'       => 10,    // mostly-uppercase body
        'guest_weight'      => 10,    // flat add for guests (0 to disable)
        // ip reputation
        'dnsbl_enabled'     => 0,
        'dnsbl_zones'       => "zen.spamhaus.org\nbl.spamcop.net\ndnsbl.dronebl.org",
        'dnsbl_weight'      => 40,    // per zone that lists the IP
        'sfs_enabled'       => 0,
        'sfs_weight'        => 50,
        'sfs_confidence'    => 30,    // StopForumSpam confidence % to act on
        'ip_cache_ttl'      => 3600,  // seconds to cache an IP verdict
        // link domains
        'uribl_enabled'     => 0,
        'uribl_zones'       => "dbl.spamhaus.org\nmulti.surbl.org",
        'uribl_weight'      => 50,    // per listed domain
        // learned filter
        'bayes_enabled'     => 1,
        'bayes_min_docs'    => 5,     // spam and normal examples needed before it scores
        'bayes_spam_pct'    => 90,
        'bayes_spam_weight' => 60,
        'bayes_ham_pct'     => 10,
        'bayes_ham_weight'  => 30,    // taken off clearly normal pastes
        // form traps
        'trap_enabled'      => 1,
        'trap_weight'       => 150,   // honeypot filled in
        'trap_min_seconds'  => 3,
        'trap_fast_weight'  => 30,    // sent sooner than trap_min_seconds
        // logging
        'log_allowed'       => 0,     // also log pastes that passed
    ];
}

/**
 * Load settings (single row id=1). Returns defaults with enabled=0 if the table
 * is absent, so callers never need to special-case a fresh install.
 */
function spam_get_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = spam_default_settings();
    try {
        $row = $pdo->query("SELECT * FROM spam_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache = $defaults; // table missing -> disabled
    }
    if (!$row) return $cache = $defaults;

    $out = $defaults;
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $row) || $row[$k] === null) continue;
        $out[$k] = is_int($v) ? (int)$row[$k] : (string)$row[$k];
    }
    return $cache = $out;
}

/** Load enabled rules. Empty array if the table is missing. */
function spam_get_rules(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT rule_type, pattern, weight, target FROM spam_rules WHERE enabled = 1")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    return $rows ?: [];
}

/* ============================ Exemptions ============================ */

/** True if users.spam_exempt exists. */
function spam_exempt_column_exists(PDO $pdo): bool
{
    try {
        return $pdo->query("SHOW COLUMNS FROM users LIKE 'spam_exempt'")->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/** Add users.spam_exempt if missing. Returns true when the column is available. */
function spam_ensure_exempt_column(PDO $pdo): bool
{
    if (spam_exempt_column_exists($pdo)) return true;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN spam_exempt TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        error_log('spam_ensure_exempt_column: ' . $e->getMessage());
    }
    return spam_exempt_column_exists($pdo);
}

/** True if a registered member is exempt from spam checks. */
function spam_member_is_exempt(PDO $pdo, string $member): bool
{
    static $cache = [];
    if ($member === '' || $member === 'Guest') return false;
    if (array_key_exists($member, $cache)) return $cache[$member];

    try {
        $stmt = $pdo->prepare("SELECT spam_exempt FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$member]);
        return $cache[$member] = ((int)$stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        return $cache[$member] = false;
    }
}

/* ============================ Content scoring ============================ */

/** Pull bare http(s) URLs out of a blob. */
function spam_extract_urls(string $text): array
{
    if ($text === '') return [];
    if (!preg_match_all('~https?://[^\s"\'<>()]+~i', $text, $m)) return [];
    return $m[0];
}

/** Host portion of a URL, lowercased, leading "www." stripped. */
function spam_url_host(string $url): string
{
    $host = (string)parse_url($url, PHP_URL_HOST);
    $host = strtolower($host);
    return preg_replace('~^www\.~', '', $host) ?? $host;
}

/**
 * Score the text signals. $content here must be the PLAINTEXT body (run before
 * encryption); pass '' for client-encrypted pastes, which can only be judged on
 * title + IP reputation.
 *
 * @return array{0:int,1:string[]} [score, reasons]
 */
function spam_score_content(array $settings, array $rules, string $title, string $content, bool $isGuest): array
{
    $score = 0; $reasons = [];
    $haystackBoth = trim($title . "\n" . $content);

    // -- admin rules ----------------------------------------------------------
    foreach ($rules as $r) {
        $type   = (string)($r['rule_type'] ?? '');
        $pat    = (string)($r['pattern'] ?? '');
        $weight = (int)($r['weight'] ?? 0);
        $target = (string)($r['target'] ?? 'both');
        if ($pat === '' || $weight === 0) continue;

        $subject = $target === 'title' ? $title : ($target === 'content' ? $content : $haystackBoth);

        $hit = false;
        if ($type === 'keyword') {
            $hit = stripos($subject, $pat) !== false;
        } elseif ($type === 'regex') {
            // Treat pattern as the inner expression; we own the delimiters so a
            // bad rule can never break out. Suppress warnings on invalid regex.
            $rx = '~' . str_replace('~', '\~', $pat) . '~iu';
            $hit = @preg_match($rx, $subject) === 1;
        } elseif ($type === 'domain') {
            $needle = spam_url_host('http://' . $pat);
            foreach (spam_extract_urls($subject) as $u) {
                if (spam_url_host($u) === $needle || str_ends_with(spam_url_host($u), '.' . $needle)) { $hit = true; break; }
            }
        }
        if ($hit) {
            $score += $weight;
            $reasons[] = "rule:{$type}:{$pat} (+{$weight})";
        }
    }

    // -- heuristics ------------------------------------------------------------
    $urls  = spam_extract_urls($content);
    $links = count($urls);
    $maxL  = (int)$settings['max_links'];
    if ($links > $maxL) {
        $add = (int)$settings['link_weight'] * ($links - $maxL);
        $score += $add;
        $reasons[] = "links:{$links} (+{$add})";
    }

    $lines = max(1, substr_count($content, "\n") + 1);
    $ratio = (int)round(($links * 100) / $lines);
    if ($links >= 2 && $ratio >= (int)$settings['link_ratio_pct']) {
        $add = (int)$settings['link_ratio_weight'];
        $score += $add;
        $reasons[] = "link_ratio:{$ratio}% (+{$add})";
    }

    $len = mb_strlen(trim($content));
    if ($links >= 1 && $len > 0 && $len < (int)$settings['min_length']) {
        $add = (int)$settings['short_weight'];
        $score += $add;
        $reasons[] = "short_with_links:{$len} (+{$add})";
    }

    $letters = preg_replace('/[^A-Za-z]/', '', $content) ?? '';
    if (mb_strlen($letters) >= 20) {
        $upper = preg_replace('/[^A-Z]/', '', $letters) ?? '';
        if (mb_strlen($upper) / mb_strlen($letters) >= 0.7) {
            $add = (int)$settings['caps_weight'];
            $score += $add;
            $reasons[] = "all_caps (+{$add})";
        }
    }

    if ($isGuest && (int)$settings['guest_weight'] > 0) {
        $add = (int)$settings['guest_weight'];
        $score += $add;
        $reasons[] = "guest (+{$add})";
    }

    return [$score, $reasons];
}

/* ============================ IP reputation ============================ */

/** True only for a public, routable IP worth looking up. */
function spam_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** True when a blocklist answer is a listing: 127.0.0.2 and up, not 127.0.0.1 or the 127.255.255.x error codes. */
function spam_dns_answer_listed(array $ips): bool
{
    foreach ($ips as $ip) {
        $ip = (string)$ip;
        if (str_starts_with($ip, '127.') && $ip !== '127.0.0.1' && !str_starts_with($ip, '127.255.255.')) {
            return true;
        }
    }
    return false;
}

/** True when a DNS blocklist lists $name. */
function spam_dns_listed(string $name): bool
{
    $ips = @gethostbynamel(rtrim($name, '.') . '.');
    return is_array($ips) && spam_dns_answer_listed($ips);
}

/** Build the reversed lookup label for a DNSBL query (IPv4 and IPv6). */
function spam_dnsbl_label(string $ip, string $zone): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return implode('.', array_reverse(explode('.', $ip))) . '.' . $zone;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = inet_pton($ip);
        if ($packed === false) return null;
        $hex = bin2hex($packed);                       // 32 nibbles
        $nib = implode('.', array_reverse(str_split($hex)));
        return $nib . '.' . $zone;
    }
    return null;
}

/**
 * Live IP reputation (no cache). Returns [score, reasons, listed_sources].
 * Fails open: every lookup is wrapped so a network error contributes nothing.
 */
function spam_lookup_ip(array $settings, string $ip): array
{
    $score = 0; $reasons = []; $sources = [];
    if (!spam_ip_is_public($ip)) return [0, [], []];

    // -- DNSBLs ----------------------------------------------------------------
    if ((int)$settings['dnsbl_enabled'] === 1) {
        $zones = preg_split('/[\s,]+/', (string)$settings['dnsbl_zones'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($zones as $zone) {
            $label = spam_dnsbl_label($ip, trim($zone));
            if ($label === null) continue;
            try {
                if (spam_dns_listed($label)) {
                    $add = (int)$settings['dnsbl_weight'];
                    $score   += $add;
                    $sources[] = $zone;
                    $reasons[] = "dnsbl:{$zone} (+{$add})";
                }
            } catch (Throwable $e) { /* fail open */ }
        }
    }

    // -- StopForumSpam ---------------------------------------------------------
    if ((int)$settings['sfs_enabled'] === 1 && function_exists('curl_init')) {
        try {
            $url = 'https://api.stopforumspam.org/api?json&ip=' . urlencode($ip);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT      => 'PASTE-spamfilter/1.0',
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '') {
                $data = json_decode($body, true);
                $ipd  = $data['ip'] ?? null;
                if (is_array($ipd) && (int)($ipd['appears'] ?? 0) === 1) {
                    $conf = (float)($ipd['confidence'] ?? 0);
                    if ($conf >= (float)$settings['sfs_confidence']) {
                        $add = (int)$settings['sfs_weight'];
                        $score   += $add;
                        $sources[] = 'stopforumspam';
                        $reasons[] = "sfs:conf={$conf} (+{$add})";
                    }
                }
            }
        } catch (Throwable $e) { /* fail open */ }
    }

    return [$score, $reasons, $sources];
}

/**
 * Cached IP reputation. Uses spam_ip_cache (created by the installer); if that
 * table is missing it simply runs live each time.
 */
function spam_score_ip(PDO $pdo, array $settings, string $ip): array
{
    if ((int)$settings['dnsbl_enabled'] !== 1 && (int)$settings['sfs_enabled'] !== 1) {
        return [0, []];
    }
    if (!spam_ip_is_public($ip)) return [0, []];

    // Try cache
    try {
        $stmt = $pdo->prepare("SELECT score, sources, expires_at FROM spam_ip_cache WHERE ip = ? LIMIT 1");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && strtotime((string)$row['expires_at']) > time()) {
            $reasons = [];
            if ((int)$row['score'] > 0) $reasons[] = 'ip_rep:' . ((string)$row['sources'] ?: 'listed') . ' (+' . (int)$row['score'] . ')';
            return [(int)$row['score'], $reasons];
        }
    } catch (Throwable $e) {
        // no cache table -> live, uncached
        [$s, $r] = spam_lookup_ip($settings, $ip);
        return [$s, $r];
    }

    // Live lookup + write-through cache
    [$score, $reasons, $sources] = spam_lookup_ip($settings, $ip);
    try {
        $ttl = max(60, (int)$settings['ip_cache_ttl']);
        $exp = date('Y-m-d H:i:s', time() + $ttl);
        $pdo->prepare(
            "INSERT INTO spam_ip_cache (ip, score, sources, checked_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), sources = VALUES(sources),
                                     checked_at = NOW(), expires_at = VALUES(expires_at)"
        )->execute([$ip, $score, implode(',', $sources), $exp]);
    } catch (Throwable $e) { /* cache write best-effort */ }

    return [$score, $reasons];
}

/* ============================ Link domains ============================ */

/** Domains to look up for a link host: its last two and last three labels. */
function spam_uri_candidates(string $host): array
{
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return [];
    $labels = explode('.', $host);
    $n = count($labels);
    if ($n < 2) return [];
    $out = [implode('.', array_slice($labels, -2))];
    if ($n >= 3) $out[] = implode('.', array_slice($labels, -3));
    return $out;
}

/** Score links whose domains are on a URI blocklist (up to five hosts, once each). */
function spam_score_links(array $settings, string $content): array
{
    if ((int)$settings['uribl_enabled'] !== 1 || $content === '') return [0, []];
    $zones = preg_split('/[\s,]+/', (string)$settings['uribl_zones'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hosts = array_slice(array_values(array_unique(array_filter(array_map('spam_url_host', spam_extract_urls($content))))), 0, 5);
    $score = 0; $reasons = [];
    foreach ($hosts as $host) {
        foreach (spam_uri_candidates($host) as $domain) {
            foreach ($zones as $zone) {
                if (spam_dns_listed($domain . '.' . $zone)) {
                    $add = (int)$settings['uribl_weight'];
                    $score += $add;
                    $reasons[] = "link_listed:{$domain}@{$zone} (+{$add})";
                    continue 3;
                }
            }
        }
    }
    return [$score, $reasons];
}

/* ============================ Learned filter ============================ */

/** Common words the learned filter ignores. */
function spam_stopwords(): array
{
    static $words = null;
    return $words ??= array_flip(['a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can', 'could',
        'do', 'does', 'for', 'from', 'had', 'has', 'have', 'if', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or',
        'so', 'such', 'than', 'that', 'the', 'then', 'there', 'these', 'this', 'those', 'to', 'was', 'were', 'will',
        'with', 'would', 'also', 'any', 'all', 'each', 'not', 'no']);
}

/** Tokens the learned filter judges a paste by: link hosts, its shape, and up to 400 distinct words. */
function spam_learn_tokens(string $title, string $content): array
{
    $urlRx  = '~https?://[^\s"\'<>()]+~i';
    $tokens = [];
    foreach (spam_extract_urls($title . "\n" . $content) as $u) {
        $host = spam_url_host($u);
        if ($host !== '') $tokens[mb_substr('url:' . $host, 0, 64)] = true;
    }

    $body = trim(preg_replace($urlRx, ' ', mb_substr($content, 0, 20000)) ?? '');
    if ($body !== '' || $content !== '') {
        $links = count(spam_extract_urls($content));
        $tokens['meta:links:' . ($links >= 2 ? '2+' : $links)] = true;
    }
    $chars = $body === '' ? 0 : (int)preg_match_all('/\S/u', $body);
    if ($chars > 0) {
        $ratio = (int)preg_match_all('~[{}\[\]();=<>$#*/\\\\|&%^`]~', $body) / $chars;
        $tokens['meta:' . ($ratio < 0.01 ? 'prose' : ($ratio > 0.04 ? 'code' : 'mixed'))] = true;
        $lines = array_filter(array_map('trim', explode("\n", $body)), static fn($l) => $l !== '');
        $avg   = array_sum(array_map('mb_strlen', $lines)) / max(1, count($lines));
        $tokens['meta:lines:' . ($avg > 120 ? 'long' : 'short')] = true;
    }

    $text = mb_strtolower(preg_replace($urlRx, ' ', $title . "\n" . mb_substr($content, 0, 20000)) ?? '');
    if (preg_match_all('/\p{L}[\p{L}\p{M}]{1,29}/u', $text, $m)) {
        $stop  = spam_stopwords();
        $words = 0;
        foreach ($m[0] as $w) {
            if (isset($stop[$w]) || isset($tokens[$w])) continue;
            $tokens[$w] = true;
            if (++$words >= 400) break;
        }
    }
    return array_map('strval', array_keys($tokens));
}

/** Learned examples per label. */
function spam_learned_counts(PDO $pdo): array
{
    $out = ['spam' => 0, 'ham' => 0];
    try {
        foreach ($pdo->query("SELECT label, COUNT(*) AS n FROM spam_learned GROUP BY label")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['label']] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* tables missing */ }
    return $out;
}

/** True when the learned filter's tables exist. */
function spam_learn_ready(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM spam_learned LIMIT 1");
        $pdo->query("SELECT 1 FROM spam_tokens LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Add one to (or take one from) each token's count for $label. */
function spam_learn_count(PDO $pdo, array $tokens, string $label, int $delta): void
{
    $col = $label === 'spam' ? 'spam' : 'ham';
    foreach (array_chunk($tokens, 200) as $chunk) {
        if ($delta > 0) {
            $vals = implode(',', array_fill(0, count($chunk), $col === 'spam' ? '(?,1,0)' : '(?,0,1)'));
            $pdo->prepare("INSERT INTO spam_tokens (token, spam, ham) VALUES $vals ON DUPLICATE KEY UPDATE $col = $col + 1")
                ->execute($chunk);
        } else {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("UPDATE spam_tokens SET $col = GREATEST($col, 1) - 1 WHERE token IN ($in)")->execute($chunk);
            $pdo->prepare("DELETE FROM spam_tokens WHERE spam = 0 AND ham = 0 AND token IN ($in)")->execute($chunk);
        }
    }
}

/**
 * Teach the learned filter one paste as 'spam' or 'ham'.
 * Returns 'learned', 'relearned' (moved from the other label), 'known' or 'empty'.
 */
function spam_learn(PDO $pdo, string $title, string $content, string $label): string
{
    if ($label !== 'spam' && $label !== 'ham') {
        throw new InvalidArgumentException('label must be spam or ham');
    }
    $tokens = spam_learn_tokens($title, $content);
    if (count($tokens) < 3) return 'empty';
    sort($tokens, SORT_STRING);
    $hash = hash('sha256', implode("\n", $tokens));

    $st = $pdo->prepare("SELECT label FROM spam_learned WHERE hash = ?");
    $st->execute([$hash]);
    $was = $st->fetchColumn();
    if ($was === $label) return 'known';

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($was !== false) {
            spam_learn_count($pdo, $tokens, (string)$was, -1);
            $pdo->prepare("UPDATE spam_learned SET label = ?, created = NOW() WHERE hash = ?")->execute([$label, $hash]);
        } else {
            $pdo->prepare("INSERT INTO spam_learned (hash, label, created) VALUES (?, ?, NOW())")->execute([$hash, $label]);
        }
        spam_learn_count($pdo, $tokens, $label, 1);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $was !== false ? 'relearned' : 'learned';
}

/** Learn a stored paste row (pastes table or queue payload); quietly does nothing without the tables. */
function spam_learn_row(PDO $pdo, array $row, string $label): string
{
    [$title, $body] = spam_row_text($row);
    try {
        return spam_learn($pdo, $title, $body, $label);
    } catch (PDOException $e) {
        error_log('spam_learn_row: ' . $e->getMessage());
        return 'empty';
    }
}

/** Plain title and body of a stored paste row. Client-encrypted bodies come back empty. */
function spam_row_text(array $row): array
{
    $title = html_entity_decode((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $body  = (string)($row['content'] ?? '');
    $enc   = (string)($row['encrypt'] ?? '0');
    if ($enc === '2') {
        $body = '';
    } elseif ($enc === '1') {
        $body = defined('SECRET') ? (decrypt($body, (string)hex2bin((string)SECRET)) ?? '') : '';
    }
    return [$title, html_entity_decode($body, ENT_QUOTES, 'UTF-8')];
}

/** Chi-squared survival function for even degrees of freedom. */
function spam_chi2q(float $x2, int $v): float
{
    $m = $x2 / 2;
    $sum = $term = exp(-$m);
    for ($i = 1, $n = intdiv($v, 2); $i < $n; $i++) {
        $term *= $m / $i;
        $sum  += $term;
    }
    return min($sum, 1.0);
}

/** Spam probability from 0 to 1, or null until the filter has learned enough of both kinds. */
function spam_learned_probability(PDO $pdo, array $settings, string $title, string $content): ?float
{
    $counts = spam_learned_counts($pdo);
    $min = max(1, (int)$settings['bayes_min_docs']);
    if ($counts['spam'] < $min || $counts['ham'] < $min) return null;

    $tokens = spam_learn_tokens($title, $content);
    if (!$tokens) return null;
    try {
        $st = $pdo->prepare("SELECT token, spam, ham FROM spam_tokens WHERE token IN (" . implode(',', array_fill(0, count($tokens), '?')) . ")");
        $st->execute($tokens);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    $clues = [];
    foreach ($rows as $r) {
        $s  = (int)$r['spam'];
        $hm = (int)$r['ham'];
        $sr = min(1.0, $s / $counts['spam']);
        $hr = min(1.0, $hm / $counts['ham']);
        if ($sr + $hr <= 0) continue;
        $n = $s + $hm;
        $f = (0.45 * 0.5 + $n * ($sr / ($sr + $hr))) / (0.45 + $n);
        if (abs($f - 0.5) >= 0.1) $clues[] = $f;
    }
    if (!$clues) return 0.5;
    usort($clues, static fn($a, $b) => abs($b - 0.5) <=> abs($a - 0.5));
    $clues = array_slice($clues, 0, 150);

    $lnSpam = 0.0; $lnHam = 0.0;
    foreach ($clues as $f) {
        $f = min(max($f, 1e-6), 1 - 1e-6);
        $lnSpam += log(1 - $f);
        $lnHam  += log($f);
    }
    $v = 2 * count($clues);
    $S = 1 - spam_chi2q(-2 * $lnSpam, $v);
    $H = 1 - spam_chi2q(-2 * $lnHam, $v);
    return ($S - $H + 1) / 2;
}

/** Points from the learned filter: added for spam-like pastes, taken off clearly normal ones. */
function spam_score_learned(PDO $pdo, array $settings, string $title, string $content): array
{
    if ((int)$settings['bayes_enabled'] !== 1) return [0, []];
    $p = spam_learned_probability($pdo, $settings, $title, $content);
    if ($p === null) return [0, []];
    $pct = (int)round($p * 100);
    if ($pct >= (int)$settings['bayes_spam_pct'] && (int)$settings['bayes_spam_weight'] > 0) {
        $add = (int)$settings['bayes_spam_weight'];
        return [$add, ["learned:{$pct}% (+{$add})"]];
    }
    if ($pct <= (int)$settings['bayes_ham_pct'] && (int)$settings['bayes_ham_weight'] > 0) {
        $sub = (int)$settings['bayes_ham_weight'];
        return [-$sub, ["learned:{$pct}% (-{$sub})"]];
    }
    return [0, ["learned:{$pct}%"]];
}

/* ============================ Form traps ============================ */

/** Signature for a form render time, or '' without a site secret. */
function spam_form_sig(string $ts): string
{
    if (!defined('SECRET') || (string)SECRET === '') return '';
    return substr(hash_hmac('sha256', 'paste-form|' . $ts, (string)SECRET), 0, 32);
}

/** Hidden fields for paste forms: a honeypot and the signed render time. */
function spam_form_fields(): string
{
    $ts   = (string)time();
    $sig  = spam_form_sig($ts);
    $html = '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
          . '<label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>';
    if ($sig !== '') {
        $html .= '<input type="hidden" name="form_ts" value="' . $ts . '.' . $sig . '">';
    }
    return $html;
}

/** Points for a paste form that filled in the honeypot, or was sent sooner than a person could. */
function spam_score_form(array $settings, array $form): array
{
    if ((int)$settings['trap_enabled'] !== 1) return [0, []];
    $score = 0; $reasons = [];
    if (trim((string)($form['website'] ?? '')) !== '' && (int)$settings['trap_weight'] > 0) {
        $add = (int)$settings['trap_weight'];
        $score += $add;
        $reasons[] = "honeypot (+{$add})";
    }
    $raw = (string)($form['form_ts'] ?? '');
    if (preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $raw, $m) && hash_equals(spam_form_sig($m[1]), $m[2])) {
        $elapsed = time() - (int)$m[1];
        if ($elapsed < (int)$settings['trap_min_seconds'] && (int)$settings['trap_fast_weight'] > 0) {
            $add = (int)$settings['trap_fast_weight'];
            $score += $add;
            $reasons[] = "fast_submit:{$elapsed}s (+{$add})";
        }
    }
    return [$score, $reasons];
}

/* ============================ Verdict ============================ */

/**
 * Full evaluation. Returns:
 *   ['action' => 'allow'|'hold'|'block', 'score' => int, 'reasons' => string[]]
 *
 * Writes a spam_log row for blocked/held pastes (and allowed ones if log_allowed).
 * Exempt members are always allowed. $context labels non-paste checks in the log.
 * $form is the submitted web form, for the form traps. For a $private paste
 * (private or password-protected) the log keeps no excerpt of the content.
 */
function spam_evaluate(PDO $pdo, string $ip, string $member, string $title, string $contentPlain, bool $isGuest, string $context = 'paste', ?array $form = null, bool $private = false): array
{
    $logExcerpt = $private ? '' : $contentPlain;
    $settings = spam_get_settings($pdo);
    if ((int)$settings['enabled'] !== 1) {
        return ['action' => 'allow', 'score' => 0, 'reasons' => []];
    }

    $ctxReason = $context !== 'paste' ? ["context:{$context}"] : [];

    if (!$isGuest && spam_member_is_exempt($pdo, $member)) {
        $reasons = array_merge(['exempt'], $ctxReason);
        if ((int)$settings['log_allowed'] === 1) {
            spam_log($pdo, $ip, $member, 0, 'allow', $reasons, $title, $logExcerpt);
        }
        return ['action' => 'allow', 'score' => 0, 'reasons' => $reasons];
    }

    $rules = spam_get_rules($pdo);
    [$cScore, $cReasons] = spam_score_content($settings, $rules, $title, $contentPlain, $isGuest);
    [$lScore, $lReasons] = spam_score_links($settings, $contentPlain);
    [$bScore, $bReasons] = $context === 'comment' ? [0, []] : spam_score_learned($pdo, $settings, $title, $contentPlain);
    [$fScore, $fReasons] = $form !== null ? spam_score_form($settings, $form) : [0, []];
    [$iScore, $iReasons] = spam_score_ip($pdo, $settings, $ip);

    $score   = $cScore + $lScore + $bScore + $fScore + $iScore;
    $reasons = array_merge($cReasons, $lReasons, $bReasons, $fReasons, $iReasons, $ctxReason);

    $block  = (int)$settings['block_threshold'];
    $review = (int)$settings['review_threshold'];

    $action = 'allow';
    if ($block > 0 && $score >= $block)       $action = 'block';
    elseif ($review > 0 && $score >= $review) $action = 'hold';

    if ($action !== 'allow' || (int)$settings['log_allowed'] === 1) {
        spam_log($pdo, $ip, $member, $score, $action, $reasons, $title, $logExcerpt);
    }

    return ['action' => $action, 'score' => $score, 'reasons' => $reasons];
}

/* ============================ Persistence ============================ */

/** Append a row to spam_log (best-effort). */
function spam_log(PDO $pdo, string $ip, string $member, int $score, string $action, array $reasons, string $title, string $content): void
{
    try {
        $pdo->prepare(
            "INSERT INTO spam_log (created, ip, member, score, action, reasons, title, excerpt)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $ip,
            $member !== '' ? $member : 'Guest',
            $score,
            $action,
            implode('; ', $reasons),
            mb_substr($title, 0, 255),
            mb_substr($content, 0, 500),
        ]);
    } catch (Throwable $e) { /* logging must never break posting */ }
}

/**
 * Stash a held paste for moderation. $row is the exact column => value map that
 * would have been INSERTed into `pastes` (content stays encrypted there, and is
 * re-inserted verbatim on approval). $titlePlain/$excerptPlain are stored
 * separately purely so a moderator can read what was posted.
 *
 * @return bool true if queued, false if the queue table is unavailable
 */
function spam_enqueue(PDO $pdo, array $row, string $ip, string $member, int $score, array $reasons, string $titlePlain = '', string $excerptPlain = ''): bool
{
    try {
        $pdo->prepare(
            "INSERT INTO spam_queue (created, ip, member, score, reasons, title, excerpt, payload, status)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $ip,
            $member !== '' ? $member : 'Guest',
            $score,
            implode('; ', $reasons),
            mb_substr($titlePlain !== '' ? $titlePlain : (string)($row['title'] ?? ''), 0, 255),
            mb_substr($excerptPlain, 0, 500),
            json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('spam_enqueue failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Insert a pastes row from an associative column => value map (used by the
 * moderation queue when an admin approves a held paste). Returns the new id.
 */
function spam_insert_paste_row(PDO $pdo, array $row): int
{
    // Only allow known pastes columns through.
    $allowed = ['slug','parent_id','title','content','visible','code','expiry',
                'password','encrypt','member','date','ip','now_time','s_date'];
    $cols = []; $vals = [];
    foreach ($allowed as $c) {
        if (array_key_exists($c, $row)) { $cols[] = $c; $vals[] = $row[$c]; }
    }
    if (!$cols) throw new RuntimeException('empty paste row');
    $place = implode(',', array_fill(0, count($cols), '?'));
    $sql   = "INSERT INTO pastes (" . implode(',', $cols) . ") VALUES ($place)";
    $pdo->prepare($sql)->execute($vals);
    return (int)$pdo->lastInsertId();
}
