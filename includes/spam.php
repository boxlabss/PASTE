<?php
/*
 * Paste $v3.3 https://github.com/boxlabss/PASTE
 *
 * Spam detection engine.
 *
 * Scores a paste from four independent signals and turns the total into one of
 * three verdicts (allow / hold-for-moderation / block):
 *
 *   1. Admin keyword / regex / domain rules      (spam_rules)
 *   2. Local content heuristics                  (links, link ratio, length, caps)
 *   3. DNSBL lookups on the poster's IP          (email-style DNS blocklists)
 *   4. StopForumSpam IP reputation               (api.stopforumspam.org)
 *
 * Everything degrades safely: if the spam_* tables do not exist yet (installer
 * not re-run) the engine reports "disabled" and every paste is allowed, so the
 * file can ship without changing behaviour until the admin opts in. All network
 * lookups are time-bounded, cached per IP, and fail OPEN — a DNS/HTTP failure
 * never blocks a legitimate paste.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License (version 3 or later). See LICENCE.
 */

declare(strict_types=1);

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
    if ((int)$settings['dnsbl_enabled'] === 1 && function_exists('checkdnsrr')) {
        $zones = preg_split('/[\s,]+/', (string)$settings['dnsbl_zones'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($zones as $zone) {
            $label = spam_dnsbl_label($ip, trim($zone));
            if ($label === null) continue;
            try {
                if (@checkdnsrr($label, 'A')) {
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

/* ============================ Verdict ============================ */

/**
 * Full evaluation. Returns:
 *   ['action' => 'allow'|'hold'|'block', 'score' => int, 'reasons' => string[]]
 *
 * Writes a spam_log row for blocked/held pastes (and allowed ones if log_allowed).
 * Exempt members are always allowed. $context labels non-paste checks in the log.
 */
function spam_evaluate(PDO $pdo, string $ip, string $member, string $title, string $contentPlain, bool $isGuest, string $context = 'paste'): array
{
    $settings = spam_get_settings($pdo);
    if ((int)$settings['enabled'] !== 1) {
        return ['action' => 'allow', 'score' => 0, 'reasons' => []];
    }

    $ctxReason = $context !== 'paste' ? ["context:{$context}"] : [];

    if (!$isGuest && spam_member_is_exempt($pdo, $member)) {
        $reasons = array_merge(['exempt'], $ctxReason);
        if ((int)$settings['log_allowed'] === 1) {
            spam_log($pdo, $ip, $member, 0, 'allow', $reasons, $title, $contentPlain);
        }
        return ['action' => 'allow', 'score' => 0, 'reasons' => $reasons];
    }

    $rules = spam_get_rules($pdo);
    [$cScore, $cReasons] = spam_score_content($settings, $rules, $title, $contentPlain, $isGuest);
    [$iScore, $iReasons] = spam_score_ip($pdo, $settings, $ip);

    $score   = $cScore + $iScore;
    $reasons = array_merge($cReasons, $iReasons, $ctxReason);

    $block  = (int)$settings['block_threshold'];
    $review = (int)$settings['review_threshold'];

    $action = 'allow';
    if ($block > 0 && $score >= $block)       $action = 'block';
    elseif ($review > 0 && $score >= $review) $action = 'hold';

    if ($action !== 'allow' || (int)$settings['log_allowed'] === 1) {
        spam_log($pdo, $ip, $member, $score, $action, $reasons, $title, $contentPlain);
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
