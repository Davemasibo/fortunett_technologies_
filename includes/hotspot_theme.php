<?php
/**
 * Per-tenant appearance for the hotspot captive portal.
 *
 * One place decides what a tenant's portal looks like, so the settings form,
 * the rendered page and the fingerprint the routers compare can never disagree.
 * Everything lives in `tenant_settings` under an `hs_` prefix — the same
 * key/value table the rest of the tenant's preferences use — so a saved theme
 * survives sessions, deployments and the router pull with no extra migration.
 *
 * How a change reaches the customer
 * ---------------------------------
 * Nothing is written to a router on save. `hotspot/login_version.php` hashes
 * the *rendered* page, so changing a colour changes the fingerprint, and every
 * router's own FortuNett-Portal-Sync scheduler notices within the hour and
 * pulls the new page. The Save & Push button on the settings page only makes
 * that immediate for routers the server can reach.
 *
 * Why the palette is derived rather than stored
 * ---------------------------------------------
 * A tenant picking "bright yellow" for the background must not be able to
 * produce white text on yellow. The stored theme is a handful of choices; the
 * ~25 colours the stylesheet actually needs are computed from them, including
 * whether the card is light or dark, which is decided from the background's
 * relative luminance unless the tenant overrides it.
 */

const HS_SETTING_PREFIX = 'hs_';

/** Where uploaded wallpapers and logos live, relative to the install root. */
const HS_UPLOAD_DIR = 'uploads/hotspot';

/**
 * Compressed wallpaper size cap, in bytes.
 *
 * The image is embedded in the page as a data URI rather than linked, because a
 * linked image has to survive the router's walled garden and a customer who
 * cannot load it sees a broken portal. The cost is that the page is written to
 * the router's flash, so this cap is a real constraint and not a formality:
 * base64 inflates by a third, so 260 KB here is ~350 KB on flash.
 */
const HS_WALLPAPER_MAX = 260 * 1024;
const HS_LOGO_MAX      = 60 * 1024;

/**
 * Every themeable key, with the default that ships.
 *
 * The defaults are deliberately bright: the portal used to fall back to a
 * near-black page with a near-black navy brand, which is invisible in daylight
 * on a phone — the actual viewing condition for a captive portal.
 */
function hotspotThemeDefaults(): array
{
    return [
        // ── Backdrop ──────────────────────────────────────────────────────
        'bg_style'   => 'gradient',   // solid | gradient | image
        'bg_color'   => '#ff9d2e',
        'bg_color2'  => '#ff6a00',
        'bg_image'   => '',           // path under uploads/hotspot
        'bg_dim'     => '35',         // 0-80, overlay darkness over a wallpaper
        'aurora'     => '1',          // the drifting glow orbs

        // ── Foreground ────────────────────────────────────────────────────
        'accent'     => '#f97316',
        'card_mode'  => 'auto',       // auto | light | dark
        'radius'     => '24',         // card corner radius in px

        // ── Identity ──────────────────────────────────────────────────────
        'logo'       => '',           // path under uploads/hotspot; blank = wifi glyph
        'headline'   => '',           // blank = tenant company name
        'subtitle'   => 'Fast, reliable internet — connect in seconds',
        'footer_note' => '',

        // ── What the customer is offered ──────────────────────────────────
        'show_login'   => '1',
        'show_voucher' => '1',
        'show_paid'    => '1',
        'show_manual'  => '1',

        // ── Payment details shown to the customer ─────────────────────────
        'paybill'       => '',        // blank = read from the tenant's gateway
        'pay_type'      => 'paybill', // paybill | till
        'account_label' => 'your phone number',
        'pay_note'      => '',
        'support_phone' => '',
    ];
}

/** Values a select is allowed to hold, so a hand-edited POST cannot inject CSS. */
function hotspotThemeEnums(): array
{
    return [
        'bg_style'  => ['solid', 'gradient', 'image'],
        'card_mode' => ['auto', 'light', 'dark'],
        'pay_type'  => ['paybill', 'till'],
    ];
}

/**
 * Load a tenant's theme, merged over the defaults and normalised.
 *
 * Never throws: a tenant with no tenant_settings row, or a deployment where the
 * table is missing, gets the defaults rather than a 500 on the captive portal.
 */
function hotspotThemeLoad(PDO $pdo, int $tenantId): array
{
    $theme = hotspotThemeDefaults();

    try {
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
        $st->execute([$tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if (strpos($k, HS_SETTING_PREFIX) !== 0) continue;
            $short = substr($k, strlen(HS_SETTING_PREFIX));
            if (array_key_exists($short, $theme)) {
                $theme[$short] = (string)$v;
            }
        }
    } catch (Throwable $e) { /* defaults */ }

    return hotspotThemeNormalise($theme);
}

/** Clamp everything into a range the stylesheet can safely interpolate. */
function hotspotThemeNormalise(array $t): array
{
    $d = hotspotThemeDefaults();
    $t = array_merge($d, $t);

    foreach (hotspotThemeEnums() as $key => $allowed) {
        if (!in_array($t[$key], $allowed, true)) $t[$key] = $d[$key];
    }
    foreach (['bg_color', 'bg_color2', 'accent'] as $key) {
        $t[$key] = hsNormaliseHex($t[$key], $d[$key]);
    }
    $t['bg_dim'] = (string)max(0, min(80, (int)$t['bg_dim']));
    $t['radius'] = (string)max(0, min(36, (int)$t['radius']));

    foreach (['aurora', 'show_login', 'show_voucher', 'show_paid', 'show_manual'] as $key) {
        $t[$key] = !empty($t[$key]) && $t[$key] !== '0' ? '1' : '0';
    }

    // An uploaded path is ours to generate, so anything with a slash or a dot
    // segment in it did not come from us.
    foreach (['bg_image', 'logo'] as $key) {
        $t[$key] = preg_match('/^[A-Za-z0-9._-]+$/', $t[$key]) ? $t[$key] : '';
    }

    // A wallpaper that is not selected must not silently keep applying.
    if ($t['bg_style'] === 'image' && $t['bg_image'] === '') {
        $t['bg_style'] = 'gradient';
    }

    return $t;
}

/** '#abc' / 'ABCDEF' / junk → '#aabbcc' / '#abcdef' / $fallback. */
function hsNormaliseHex(?string $hex, string $fallback = '#ff9500'): string
{
    $h = strtolower(ltrim(trim((string)$hex), '#'));
    if (strlen($h) === 3 && ctype_xdigit($h)) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    return (strlen($h) === 6 && ctype_xdigit($h)) ? '#' . $h : $fallback;
}

/** '#aabbcc' → [r, g, b]. */
function hsRgb(string $hex): array
{
    $h = ltrim(hsNormaliseHex($hex), '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

/**
 * WCAG relative luminance, 0 (black) to 1 (white).
 *
 * This is what decides light-vs-dark card, so it has to be the perceptual
 * formula and not a naive channel average: pure yellow and pure blue have
 * almost the same average and wildly different brightness.
 */
function hsLuminance(string $hex): float
{
    [$r, $g, $b] = hsRgb($hex);
    $f = static function (int $c): float {
        $s = $c / 255;
        return $s <= 0.03928 ? $s / 12.92 : pow(($s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
}

/**
 * WCAG contrast ratio between two colours, 1 (identical) to 21 (black/white).
 */
function hsContrast(string $a, string $b): float
{
    $l1 = hsLuminance($a);
    $l2 = hsLuminance($b);
    if ($l1 < $l2) { [$l1, $l2] = [$l2, $l1]; }
    return ($l1 + 0.05) / ($l2 + 0.05);
}

/**
 * White or near-black — whichever is actually more readable on $bg.
 *
 * A luminance threshold gets this wrong in the middle of the range, which is
 * exactly where brand colours live: a mid-yellow like #eab308 sits at 0.49, so
 * a "> 0.55 means dark text" rule paints white on it at a contrast ratio of
 * 2.0:1. Comparing both candidates cannot make that mistake.
 */
function hsBestInk(string $bg): string
{
    return hsContrast('#ffffff', $bg) >= hsContrast('#1a1206', $bg) ? '#ffffff' : '#1a1206';
}

/**
 * Push $color away from $bg until it is readable as TEXT on it.
 *
 * The accent doubles as a fill (buttons, badges) and as text (the price, the
 * active tab, links). Those are different jobs: a pale yellow that is perfect
 * as a button fill is invisible as a price on a near-white card. The fill keeps
 * the tenant's exact colour; text uses this adjusted one.
 */
function hsReadableOn(string $color, string $bg, float $target = 4.5): string
{
    $step = hsLuminance($bg) > 0.4 ? -12 : 12;
    $c = $color;
    for ($i = 0; $i < 26 && hsContrast($c, $bg) < $target; $i++) {
        $c = hsShift($c, $step);
    }
    return $c;
}

/** Blend two hex colours; $amount 0 = all $a, 1 = all $b. */
function hsMix(string $a, string $b, float $amount): string
{
    [$r1, $g1, $b1] = hsRgb($a);
    [$r2, $g2, $b2] = hsRgb($b);
    $amount = max(0.0, min(1.0, $amount));
    return sprintf(
        '#%02x%02x%02x',
        (int)round($r1 + ($r2 - $r1) * $amount),
        (int)round($g1 + ($g2 - $g1) * $amount),
        (int)round($b1 + ($b2 - $b1) * $amount)
    );
}

/** Lighten (positive) or darken (negative) by a flat RGB step. */
function hsShift(string $hex, int $step): string
{
    [$r, $g, $b] = hsRgb($hex);
    return sprintf(
        '#%02x%02x%02x',
        max(0, min(255, $r + $step)),
        max(0, min(255, $g + $step)),
        max(0, min(255, $b + $step))
    );
}

/** "r,g,b" for use inside rgba(). */
function hsRgbList(string $hex): string
{
    return implode(',', hsRgb($hex));
}

/**
 * Turn the tenant's handful of choices into the full set of CSS variables the
 * stylesheet consumes.
 *
 * Returns ['vars' => "--x:y;\n…", 'body_bg' => "…", 'is_light' => bool, …].
 */
function hotspotThemePalette(array $t): array
{
    $t = hotspotThemeNormalise($t);

    // What the card is actually sitting on. For a wallpaper the dim overlay is
    // what the eye reads, so a heavily dimmed photo is treated as dark however
    // bright the picture is.
    if ($t['bg_style'] === 'image') {
        $backdrop = hsMix('#ffffff', '#000000', min(1.0, ((int)$t['bg_dim']) / 100 + 0.30));
    } elseif ($t['bg_style'] === 'gradient') {
        $backdrop = hsMix($t['bg_color'], $t['bg_color2'], 0.5);
    } else {
        $backdrop = $t['bg_color'];
    }

    // 0.28, not 0.5: a saturated orange or red sits around 0.35 and reads as a
    // bright backdrop to the eye, so it wants a light card floating on it. Only
    // a genuinely dark page (deep navy, charcoal, black) falls below this.
    $isLight = $t['card_mode'] === 'light'
        || ($t['card_mode'] === 'auto' && hsLuminance($backdrop) > 0.28);

    $accent      = $t['accent'];
    $accentDark  = hsShift($accent, -45);
    $accentLight = hsShift($accent, 55);
    // Text drawn ON the accent — a pale amber button needs dark text, a deep
    // orange one needs white. Getting this wrong is the single most common way
    // a themeable UI ends up with an unreadable primary button.
    $onAccent = hsBestInk($accent);

    if ($isLight) {
        // A light card floating on a bright backdrop. Surfaces carry a trace of
        // the backdrop so the card belongs to the page instead of being a white
        // rectangle dropped on it.
        $surface   = hsMix('#ffffff', $backdrop, 0.05);
        $sunken    = hsMix('#ffffff', $backdrop, 0.13);
        $raised    = hsMix('#ffffff', $backdrop, 0.09);
        $raisedSel = hsMix('#ffffff', $accent,   0.13);
        $vars = [
            '--surface'    => $surface,
            '--sunken'     => $sunken,
            '--raised'     => $raised,
            '--raised-sel' => $raisedSel,
            '--sh-out-d'   => 'rgba(0,0,0,.16)',
            '--sh-out-l'   => 'rgba(255,255,255,.85)',
            '--sh-in-d'    => 'rgba(0,0,0,.09)',
            '--sh-in-l'    => 'rgba(255,255,255,.9)',
            '--tint'       => '0,0,0',
            '--ink'        => '#1c1917',
            '--ink-dim'    => 'rgba(0,0,0,.58)',
            '--ink-faint'  => 'rgba(0,0,0,.42)',
            '--well'       => 'rgba(0,0,0,.06)',
            '--ok'         => '#047857',
            '--warn'       => '#b45309',
            '--bad'        => '#b91c1c',
            '--ok-rgb'     => '4,120,87',
            '--warn-rgb'   => '180,83,9',
            '--bad-rgb'    => '185,28,28',
        ];
    } else {
        $surface = hsMix('#222221', $backdrop, 0.06);
        $vars = [
            '--surface'    => $surface,
            '--sunken'     => hsMix('#1b1b1a', $backdrop, 0.05),
            '--raised'     => hsMix('#1e1e1d', $backdrop, 0.05),
            '--raised-sel' => hsMix('#1e1e1d', $accent, 0.16),
            '--sh-out-d'   => 'rgba(6,6,5,.85)',
            '--sh-out-l'   => 'rgba(255,255,255,.07)',
            '--sh-in-d'    => 'rgba(0,0,0,.55)',
            '--sh-in-l'    => 'rgba(255,255,255,.06)',
            '--tint'       => '255,255,255',
            '--ink'        => '#e8e8e6',
            '--ink-dim'    => 'rgba(255,255,255,.55)',
            '--ink-faint'  => 'rgba(255,255,255,.36)',
            '--well'       => 'rgba(0,0,0,.28)',
            '--ok'         => '#34d399',
            '--warn'       => '#fbbf24',
            '--bad'        => '#f87171',
            '--ok-rgb'     => '52,211,153',
            '--warn-rgb'   => '251,191,36',
            '--bad-rgb'    => '248,113,113',
        ];
    }

    // The dock fades the plan grid out behind it with a gradient that has to
    // end on the card colour at zero alpha. `transparent` is interpolated
    // through transparent-BLACK by older engines and shows as a grey smear, so
    // the surface is also published as an r,g,b list.
    $vars['--surface-rgb']  = hsRgbList($vars['--surface']);
    $vars['--accent']       = $accent;
    // The accent as TEXT on the card. Everything readable — the price, the
    // active tab, links, the headline — uses this; fills and gradients keep the
    // tenant's exact colour. Without the split, a yellow accent produced a
    // yellow price on a white card.
    $vars['--accent-ink']   = hsReadableOn($accent, $vars['--surface']);
    $vars['--accent-dark']  = $accentDark;
    $vars['--accent-light'] = $accentLight;
    $vars['--accent-rgb']   = hsRgbList($accent);
    $vars['--on-accent']    = $onAccent;
    $vars['--radius']       = $t['radius'] . 'px';
    $vars['--aurora-op']    = $t['aurora'] === '1' ? ($isLight ? '.34' : '.5') : '0';
    // The page colour behind everything, including the area the card does not
    // cover and the browser's overscroll gutter.
    $vars['--page']         = $backdrop;

    $css = '';
    foreach ($vars as $k => $v) {
        $css .= '  ' . $k . ':' . $v . ";\n";
    }

    return [
        'vars'     => rtrim($css),
        'is_light' => $isLight,
        'backdrop' => $backdrop,
        'theme'    => $t,
    ];
}

/**
 * The `background` shorthand for <body>.
 *
 * $imageData is a data: URI (or ''), never a URL: a linked image has to be let
 * through the router's walled garden, and a customer whose request is blocked
 * sees a broken portal with no way to tell why.
 */
function hotspotThemeBodyBackground(array $t, string $imageData = ''): string
{
    $t = hotspotThemeNormalise($t);

    if ($t['bg_style'] === 'image' && $imageData !== '') {
        $dim = ((int)$t['bg_dim']) / 100;
        return 'linear-gradient(rgba(0,0,0,' . $dim . '),rgba(0,0,0,' . $dim . ')),'
             . "url('" . $imageData . "') center center / cover no-repeat fixed, "
             . $t['bg_color'];
    }
    if ($t['bg_style'] === 'gradient') {
        return 'linear-gradient(158deg,' . $t['bg_color'] . ' 0%,' . $t['bg_color2'] . ' 100%) fixed';
    }
    return $t['bg_color'];
}

/** Absolute path of an uploaded asset, or '' when there is nothing there. */
function hotspotThemeAssetPath(string $file): string
{
    if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) return '';
    $path = dirname(__DIR__) . '/' . HS_UPLOAD_DIR . '/' . $file;
    return is_file($path) ? $path : '';
}

/**
 * Read an uploaded asset back as a data: URI, or '' if it is gone or too big.
 *
 * The size ceiling is enforced again on READ, not only on upload: the page is
 * written to router flash, and a file that grew past the cap by some other
 * route must not be able to bloat every router in the fleet.
 */
function hotspotThemeAssetDataUri(string $file, int $maxBytes): string
{
    $path = hotspotThemeAssetPath($file);
    if ($path === '') return '';

    $size = @filesize($path);
    if ($size === false || $size > $maxBytes) return '';

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') return '';

    $mime = 'image/jpeg';
    $info = @getimagesize($path);
    if ($info && !empty($info['mime'])) $mime = $info['mime'];

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/**
 * Persist a theme for a tenant.
 *
 * Only keys the defaults define are written, so a crafted POST cannot create
 * arbitrary tenant_settings rows.
 */
function hotspotThemeSave(PDO $pdo, int $tenantId, array $input): void
{
    $clean = hotspotThemeNormalise(array_intersect_key($input, hotspotThemeDefaults()));

    $up = $pdo->prepare("
        INSERT INTO tenant_settings (tenant_id, setting_key, setting_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    foreach ($clean as $key => $value) {
        $up->execute([$tenantId, HS_SETTING_PREFIX . $key, (string)$value]);
    }
}

/**
 * Accept an uploaded image, shrink it, and return the stored filename.
 *
 * Throws with a message meant for the operator. Re-encoding is not cosmetic:
 * an untouched 4 MB phone photo would be base64'd into the login page and
 * written to the flash of every router the tenant owns.
 *
 * @param array $file  One entry from $_FILES
 * @param int   $maxW  Longest edge after resizing
 * @param int   $cap   Byte ceiling for the stored file
 */
function hotspotThemeStoreUpload(array $file, int $tenantId, string $kind, int $maxW, int $cap): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }

    $tmp  = $file['tmp_name'] ?? '';
    $info = $tmp ? @getimagesize($tmp) : false;
    if (!$info) {
        throw new RuntimeException('That file is not an image the server can read.');
    }

    $dir = dirname(__DIR__) . '/' . HS_UPLOAD_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create ' . HS_UPLOAD_DIR . ' — check directory permissions.');
    }

    [$w, $h, $type] = $info;
    $isPng  = ($type === IMAGETYPE_PNG);
    // A logo is usually a PNG with transparency that must survive; a wallpaper
    // is a photo, and JPEG is a great deal smaller at the same quality.
    $keepPng = $isPng && $kind === 'logo';

    // Only claim the file is a JPEG if we are actually going to re-encode it.
    // Without GD the fallback below copies the original bytes, and naming PNG
    // bytes .jpg makes the server send the wrong Content-Type for the thumbnail
    // on the settings page.
    $canReencode = function_exists('imagecreatetruecolor');
    $srcExt = [
        IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg',
    ][$type] ?? 'jpg';
    $ext  = $canReencode ? ($keepPng ? 'png' : 'jpg') : $srcExt;
    $name = 't' . $tenantId . '-' . $kind . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $name;

    $wrote = false;
    if ($canReencode) {
        $wrote = hsResizeTo($tmp, $type, $dest, $maxW, $keepPng, $cap);
        // The re-encode failed after the name was already chosen; fall back to
        // copying, under the original extension so the type is not a lie.
        if (!$wrote && $ext !== $srcExt) {
            @unlink($dest);
            $name = 't' . $tenantId . '-' . $kind . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $srcExt;
            $dest = $dir . '/' . $name;
        }
    }

    if (!$wrote) {
        // No GD, or the re-encode failed. Copying the original is only safe if
        // it already fits — otherwise every router pays for it.
        if ((int)($file['size'] ?? 0) > $cap) {
            throw new RuntimeException(
                'That image is ' . round(((int)$file['size']) / 1024) . ' KB and the server could not '
                . 'compress it (GD is not available). Please upload one under ' . round($cap / 1024) . ' KB.'
            );
        }
        if (!@move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
    }

    if (@filesize($dest) > $cap) {
        @unlink($dest);
        throw new RuntimeException(
            'That image is still over ' . round($cap / 1024) . ' KB after compression. The portal page is '
            . 'stored on each router\'s flash, so it has to stay small — try a smaller or simpler image.'
        );
    }

    return $name;
}

/** Re-encode an image down to $maxW, stepping quality down until it fits $cap. */
function hsResizeTo(string $src, int $type, string $dest, int $maxW, bool $png, int $cap): bool
{
    try {
        switch ($type) {
            case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $im = @imagecreatefrompng($src);  break;
            case IMAGETYPE_GIF:  $im = @imagecreatefromgif($src);  break;
            case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
            default: return false;
        }
        if (!$im) return false;

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > $maxW) {
            $nh  = (int)max(1, round($h * ($maxW / $w)));
            $out = imagecreatetruecolor($maxW, $nh);
            if ($png) {
                imagealphablending($out, false);
                imagesavealpha($out, true);
            }
            imagecopyresampled($out, $im, 0, 0, 0, 0, $maxW, $nh, $w, $h);
            imagedestroy($im);
            $im = $out;
        } elseif ($png) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }

        if ($png) {
            $ok = imagepng($im, $dest, 8);
        } else {
            // Step the quality down rather than emit one guess: a photo that
            // lands at 900 KB at q82 usually fits comfortably at q60, and a
            // fixed low quality would make every small image look bad.
            $ok = false;
            foreach ([82, 72, 62, 52, 42] as $q) {
                $ok = imagejpeg($im, $dest, $q);
                if (!$ok || @filesize($dest) <= $cap) break;
            }
        }
        imagedestroy($im);
        return (bool)$ok;
    } catch (Throwable $e) {
        error_log('[hotspot_theme] resize failed: ' . $e->getMessage());
        return false;
    }
}

/** Delete a stored asset. Silent when it is already gone. */
function hotspotThemeDeleteAsset(string $file): void
{
    $path = hotspotThemeAssetPath($file);
    if ($path !== '') @unlink($path);
}
