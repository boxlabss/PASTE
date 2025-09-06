<?php
declare(strict_types=1);

/**
 * Build captcha config, store in session, and return src + code.
 * Usage in your form controller:
 *   $cap = captcha('#0a58ca','Normal','on','ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
 *   echo '<img src="'.htmlspecialchars($cap['image_src'],ENT_QUOTES).'" ...>';
 */
function captcha(string $color, string $mode, string $mul, string $allowed, array $config = []): array
{
    $bg_path   = __DIR__ . '/captchabg/';
    $font_path = __DIR__ . '/fonts/';

    // choose font by difficulty
    if ($mode === 'Easy') {
        $font_name = 'SigmarOne.ttf';
    } elseif ($mode === 'Normal') {
        $font_name = 'times_new_yorker.ttf';
    } elseif ($mode === 'Tough') {
        $font_name = 'captcha_code.otf';
    } else {
        $font_name = 'times_new_yorker.ttf';
    }

    // base config
    $captcha_config = [
        'code'            => '',
        'min_length'      => 5,
        'max_length'      => 5,
        'backgrounds'     => ($mul === 'on')
            ? [$bg_path.'text3.png', $bg_path.'text2.png', $bg_path.'text1.png']
            : [$bg_path.'text2.png'],
        'fonts'           => ($mul === 'on')
            ? [$font_path.$font_name]
            : [$font_path.'times_new_yorker.ttf'],
        'characters'      => $allowed,
        'min_font_size'   => 28,
        'max_font_size'   => 28,
        'color'           => $color,
        'angle_min'       => 0,
        'angle_max'       => 10,
        'shadow'          => true,
        'shadow_color'    => '#fff',
        'shadow_offset_x' => -1,
        'shadow_offset_y' => 1,
    ];

    // allow overrides via $config param
    foreach ($config as $k => $v) {
        $captcha_config[$k] = $v;
    }

    // clamp/normalize
    if (($captcha_config['min_length'] ?? 1) < 1)                                       $captcha_config['min_length'] = 1;
    if (($captcha_config['angle_min']  ?? 0) < 0)                                       $captcha_config['angle_min']  = 0;
    if (($captcha_config['angle_max']  ?? 10) > 10)                                     $captcha_config['angle_max']  = 10;
    if ($captcha_config['angle_max'] < $captcha_config['angle_min'])                    $captcha_config['angle_max']  = $captcha_config['angle_min'];
    if (($captcha_config['min_font_size'] ?? 10) < 10)                                  $captcha_config['min_font_size'] = 10;
    if (($captcha_config['max_font_size'] ?? 10) < $captcha_config['min_font_size'])    $captcha_config['max_font_size'] = $captcha_config['min_font_size'];

    // generate code if empty
    if ($captcha_config['code'] === '') {
        $letters = (string)$captcha_config['characters'];
        $lenMin  = (int)$captcha_config['min_length'];
        $lenMax  = (int)$captcha_config['max_length'];
        $length  = ($lenMin === $lenMax) ? $lenMin : random_int($lenMin, $lenMax);

        $cap = '';
        $maxIdx = max(strlen($letters) - 1, 0);
        for ($i = 0; $i < $length; $i++) {
            $idx = ($maxIdx > 0) ? random_int(0, $maxIdx) : 0;
            $cap .= $letters[$idx] ?? '';
        }
        $captcha_config['code'] = $cap;
    }

    // build image src (ensure _CAPTCHA param exists)
    $image_src = substr(__FILE__, strlen(realpath($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $image_src = '/'.ltrim(str_replace('\\','/',$image_src), '/');
    $sep = (strpos($image_src, '?') === false) ? '?' : '&';
    $image_src .= $sep.'_CAPTCHA=1&t='.rawurlencode((string)microtime(true));

    // ensure session before writing
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['_CAPTCHA']['config'] = serialize($captcha_config);
    $_SESSION['captcha']['image_src'] = $image_src;
    $_SESSION['captcha']['code']      = $captcha_config['code'];

    return [
        'code'      => $captcha_config['code'],
        'image_src' => $image_src,
    ];
}

if (!function_exists('hex2rgb')) {
    function hex2rgb(string $hex_str, bool $return_string = false, string $separator = ',')
    {
        $hex_str = preg_replace("/[^0-9A-Fa-f]/", '', $hex_str);
        $rgb = [];
        if (strlen($hex_str) === 6) {
            $color_val = hexdec($hex_str);
            $rgb['r'] = (int)(0xFF & ($color_val >> 16));
            $rgb['g'] = (int)(0xFF & ($color_val >> 8));
            $rgb['b'] = (int)(0xFF & $color_val);
        } elseif (strlen($hex_str) === 3) {
            $rgb['r'] = (int)hexdec(str_repeat($hex_str[0], 2));
            $rgb['g'] = (int)hexdec(str_repeat($hex_str[1], 2));
            $rgb['b'] = (int)hexdec(str_repeat($hex_str[2], 2));
        } else {
            return false;
        }
        return $return_string ? implode($separator, $rgb) : $rgb;
    }
}

/* -------- image drawing --------
 * GET params:
 *   _CAPTCHA=1   -> required to render
 *   regen=1      -> regenerate a fresh code server-side
 */
if (isset($_GET['_CAPTCHA'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $captcha_config = isset($_SESSION['_CAPTCHA']['config'])
        ? @unserialize($_SESSION['_CAPTCHA']['config'], ['allowed_classes' => false])
        : null;

    if (!$captcha_config || !is_array($captcha_config)) {
        // sensible fallback if hit directly
        $captcha_config = [
            'code' => 'ABCD1',
            'characters' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'min_length' => 5, 'max_length' => 5,
            'backgrounds' => [__DIR__.'/captchabg/text2.png'],
            'fonts' => [__DIR__.'/fonts/times_new_yorker.ttf'],
            'color' => '#000000',
            'angle_min' => 0, 'angle_max' => 10,
            'min_font_size' => 28, 'max_font_size' => 28,
            'shadow' => true, 'shadow_color' => '#fff',
            'shadow_offset_x' => -1, 'shadow_offset_y' => 1,
        ];
    }

    // refresh code on demand
    if (isset($_GET['regen']) && $_GET['regen'] === '1') {
        $letters = (string)($captcha_config['characters'] ?? 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $lenMin  = (int)($captcha_config['min_length'] ?? 5);
        $lenMax  = (int)($captcha_config['max_length'] ?? 5);
        $length  = ($lenMin === $lenMax) ? $lenMin : random_int($lenMin, $lenMax);

        $cap = '';
        $maxIdx = max(strlen($letters) - 1, 0);
        for ($i = 0; $i < $length; $i++) {
            $idx = ($maxIdx > 0) ? random_int(0, $maxIdx) : 0;
            $cap .= $letters[$idx] ?? '';
        }
        $captcha_config['code'] = $cap;
        $_SESSION['_CAPTCHA']['config'] = serialize($captcha_config);
        $_SESSION['captcha']['code']    = $cap; // keep compat
    }

    // choose background
    $bgList = $captcha_config['backgrounds'] ?? [];
    if (empty($bgList)) exit;
    $bgIdx = (count($bgList) === 1) ? 0 : random_int(0, count($bgList) - 1);
    $background = $bgList[$bgIdx];

    [$bg_width, $bg_height] = getimagesize($background);
    $captcha = imagecreatefrompng($background);

    // text color
    $rgb = hex2rgb((string)$captcha_config['color']);
    if (!is_array($rgb)) { $rgb = ['r'=>0,'g'=>0,'b'=>0]; }
    $color = imagecolorallocate($captcha, (int)$rgb['r'], (int)$rgb['g'], (int)$rgb['b']);

    // angle
    $amin = (int)$captcha_config['angle_min'];
    $amax = (int)$captcha_config['angle_max'];
    $angle = ($amax !== $amin) ? random_int($amin, $amax) : $amin;
    $angle *= (random_int(0, 1) === 1) ? -1 : 1;

    // font
    $fonts = $captcha_config['fonts'] ?? [];
    if (empty($fonts)) exit;
    $font = (count($fonts) === 1) ? $fonts[0] : $fonts[random_int(0, count($fonts)-1)];
    if (!is_file($font)) {
        throw new RuntimeException('Font file not found: '.$font);
    }

    // font size
    $fsMin = (int)$captcha_config['min_font_size'];
    $fsMax = (int)$captcha_config['max_font_size'];
    $font_size = ($fsMin === $fsMax) ? $fsMin : random_int($fsMin, $fsMax);

    // bbox -> width/height
    $bbox = imagettfbbox($font_size, $angle, $font, (string)$captcha_config['code']);
    $xs = [$bbox[0], $bbox[2], $bbox[4], $bbox[6]];
    $ys = [$bbox[1], $bbox[3], $bbox[5], $bbox[7]];
    $box_width  = (int)ceil(max($xs) - min($xs));
    $box_height = (int)ceil(max($ys) - min($ys));

    // positions
    $text_pos_x_max = max(0, (int)$bg_width - $box_width);
    $text_pos_x     = ($text_pos_x_max > 0) ? random_int(0, $text_pos_x_max) : 0;

    $text_pos_y_min = $box_height;
    $text_pos_y_max = max($text_pos_y_min, (int)$bg_height - (int)floor($box_height / 2));
    $text_pos_y     = ($text_pos_y_max > $text_pos_y_min)
        ? random_int($text_pos_y_min, $text_pos_y_max)
        : $text_pos_y_min;

    // shadow
    if (!empty($captcha_config['shadow'])) {
        $srgb = hex2rgb((string)$captcha_config['shadow_color']);
        if (!is_array($srgb)) { $srgb = ['r'=>255,'g'=>255,'b'=>255]; }
        $shadow_color = imagecolorallocate($captcha, (int)$srgb['r'], (int)$srgb['g'], (int)$srgb['b']);
        $sx = (int)$text_pos_x + (int)($captcha_config['shadow_offset_x'] ?? 0);
        $sy = (int)$text_pos_y + (int)($captcha_config['shadow_offset_y'] ?? 0);
        imagettftext($captcha, (float)$font_size, (float)$angle, $sx, $sy, $shadow_color, $font, (string)$captcha_config['code']);
    }

    // text
    imagettftext($captcha, (float)$font_size, (float)$angle, (int)$text_pos_x, (int)$text_pos_y, $color, $font, (string)$captcha_config['code']);

    // headers (no-cache)
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Captcha-Rev: 2025-09-06');

    imagepng($captcha);
    imagedestroy($captcha);
    exit;
}