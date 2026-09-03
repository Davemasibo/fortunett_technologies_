<?php
/**
 * Builds the tenant-branded hotspot login page.
 *
 * Shared by:
 *   login_serve.php     — serves the page to RouterOS /tool/fetch
 *   login_version.php   — hashes the page so routers only re-download on change
 *
 * MikroTik template variables ($(link-login-only), $(mac-esc), $(error), …) are
 * left intact — RouterOS substitutes them when it serves the page to a client.
 *
 * Appearance comes entirely from includes/hotspot_theme.php, which reads what
 * the tenant saved on settings.php. Nothing here decides a colour: if the two
 * ever disagreed, the settings preview and the page the router serves would
 * drift apart with no way to tell which one was lying.
 */
require_once __DIR__ . '/../includes/hotspot_theme.php';

/**
 * Look up a tenant by its provisioning token. Returns null when unknown.
 */
function hotspotTenantByToken(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    // SELECT * so a missing optional column (brand_color, company_name) can't 500
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE provisioning_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    return $tenant ?: null;
}

/**
 * Render the complete login.html for a tenant.
 */
function renderHotspotLoginPage(PDO $pdo, array $tenant): string
{
    // ?? not ?: — brand_color and company_name are optional columns that some
    // deployments simply don't have, and the caller passes SELECT * straight in.
    $tenantId    = (int)$tenant['id'];
    $companyName = ($tenant['company_name'] ?? '') ?: 'FortuNett Technologies';

    $theme   = hotspotThemeLoad($pdo, $tenantId);
    $palette = hotspotThemePalette($theme);

    // Images are embedded, not linked. A linked asset has to be allowed through
    // the router's walled garden, and a customer whose request is blocked gets
    // a portal with a broken background and no clue why.
    $bgData   = $theme['bg_style'] === 'image'
        ? hotspotThemeAssetDataUri($theme['bg_image'], HS_WALLPAPER_MAX) : '';
    $logoData = hotspotThemeAssetDataUri($theme['logo'], HS_LOGO_MAX);

    $bodyBg = hotspotThemeBodyBackground($theme, $bgData);

    // A wallpaper that will not load falls back to the tenant's colours rather
    // than to a blank page.
    if ($theme['bg_style'] === 'image' && $bgData === '') {
        $bodyBg = hotspotThemeBodyBackground(
            ['bg_style' => 'gradient'] + $theme, ''
        );
    }

    [$packagesHtml, $filtersHtml] = _renderHotspotPackages($pdo, $tenantId);

    // M-Pesa paybill for the manual fallback instructions. A tenant override
    // wins: the number a customer should pay is not always the one attached to
    // the API gateway row, and a Buy Goods till is not a paybill at all.
    $paybill = trim($theme['paybill']);
    if ($paybill === '') try {
        require_once __DIR__ . '/../includes/credential_helper.php';
        $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
        $gwSt->execute([$tenantId]);
        $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
        if ($gwRow) {
            $gwCreds = decrypt_gateway_credentials($gwRow['credentials']);
            $paybill = $gwCreds['shortcode'] ?? $gwCreds['paybill'] ?? '';
        }
    } catch (Throwable $_e) {}
    if ($paybill === '') $paybill = 'N/A';

    // Portal base URL — the router's walled garden must allow this host
    $platformDomain = 'fortunetttech.site';
    try {
        $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
        $pd = $pdSt ? $pdSt->fetchColumn() : null;
        if ($pd) $platformDomain = $pd;
    } catch (Throwable $_e) {}

    $subdomain  = $tenant['subdomain'] ?? '';
    $portalBase = 'https://' . ($subdomain ? $subdomain . '.' : '') . $platformDomain;
    $signupUrl  = $portalBase . '/customer/register.php';

    $templatePath = __DIR__ . '/login.html';
    $template = @file_get_contents($templatePath);
    if ($template === false) {
        throw new \RuntimeException('Hotspot login template not found: ' . $templatePath);
    }

    // Support line — the tenant's portal setting wins over the account record
    $supportPhone = trim($theme['support_phone'])
        ?: trim((string)($tenant['support_phone'] ?? $tenant['phone'] ?? ''));

    // ── Tabs the tenant chose to show ────────────────────────────────────────
    // 'buy' is not optional: with every tab switched off there would be nothing
    // on the page at all, and a customer with no way to get online.
    $tabDefs = [
        ['buy',       'Get Online', true],
        ['login',     'Sign In',    $theme['show_login']   === '1'],
        ['voucher',   'Voucher',    $theme['show_voucher'] === '1'],
        ['reconnect', 'Paid?',      $theme['show_paid']    === '1'],
    ];
    $tabButtons = '';
    $tabNames   = [];
    foreach ($tabDefs as [$id, $label, $on]) {
        if (!$on) continue;
        $tabNames[] = $id;
        $tabButtons .= '    <button class="tab-btn' . ($tabNames === [$id] ? ' active' : '')
            . '" id="tb-' . $id . '" type="button" onclick="switchTab(\'' . $id . '\')">'
            . $label . "</button>\n";
    }

    $template = _hsApplyConditionals($template, [
        'LOGIN'   => $theme['show_login']   === '1',
        'VOUCHER' => $theme['show_voucher'] === '1',
        'PAID'    => $theme['show_paid']    === '1',
    ]);

    // ── Logo ─────────────────────────────────────────────────────────────────
    $logoBlock = $logoData !== ''
        ? '<img class="logo-img" src="' . $logoData . '" alt="'
          . htmlspecialchars($companyName, ENT_QUOTES) . '">'
        : '<div class="logo">'
          . '<svg viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/>'
          . '<path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>'
          . '<circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/></svg></div>';

    // ── Manual payment instructions ──────────────────────────────────────────
    $manual = '';
    if ($theme['show_manual'] === '1') {
        $isTill  = $theme['pay_type'] === 'till';
        $acctLbl = trim($theme['account_label']) ?: 'your phone number';
        $note    = trim($theme['pay_note']);

        $steps = [
            'Open <strong>M-Pesa</strong> &rarr; <strong>Lipa na M-Pesa</strong> &rarr; <strong>'
                . ($isTill ? 'Buy Goods and Services' : 'Pay Bill') . '</strong>',
            ($isTill ? 'Till No: <strong>' : 'Business No: <strong>')
                . htmlspecialchars($paybill) . '</strong>',
        ];
        // A Buy Goods till has no account field, so asking for one sends the
        // customer looking for a screen that does not exist.
        if (!$isTill) {
            $steps[] = 'Account No: <strong>' . htmlspecialchars($acctLbl) . '</strong>';
        }
        $steps[] = 'Enter the plan amount and confirm with your PIN';
        if ($theme['show_paid'] === '1') {
            $steps[] = 'Got the M-Pesa code? Open the <strong>Paid?</strong> tab and enter it';
        }

        $manual = '    <details class="how-to"><summary>Pay manually with '
            . ($isTill ? 'Buy Goods' : 'Paybill') . ' instead</summary><div class="how-to-inner">';
        foreach ($steps as $i => $stepHtml) {
            $manual .= '<div class="how-step"><div class="step-num">' . ($i + 1) . '</div>'
                . '<div class="step-text">' . $stepHtml . '</div></div>';
        }
        if ($note !== '') {
            $manual .= '<div class="step-text" style="margin-top:10px">'
                . nl2br(htmlspecialchars($note)) . '</div>';
        }
        $manual .= '</div></details>';
    }

    // ── Footer note + support line ───────────────────────────────────────────
    $footerBits = [];
    if (trim($theme['footer_note']) !== '') {
        $footerBits[] = nl2br(htmlspecialchars(trim($theme['footer_note'])));
    }
    if ($supportPhone !== '') {
        $footerBits[] = 'Need help? <a class="support-line" href="tel:'
            . htmlspecialchars(preg_replace('/[^0-9+]/', '', $supportPhone), ENT_QUOTES) . '">'
            . htmlspecialchars($supportPhone) . '</a>';
    }
    $footerNote = $footerBits
        ? '<div class="footer-note">' . implode('<br>', $footerBits) . '</div>'
        : '';

    $headline = trim($theme['headline']) ?: $companyName;

    return str_replace(
        [
            '{{COMPANY_NAME}}', '{{HEADLINE}}', '{{SUBTITLE}}', '{{LOGO_BLOCK}}',
            '{{THEME_VARS}}', '{{BODY_BG}}', '{{THEME_COLOR}}',
            '{{PORTAL_URL}}', '{{SIGNUP_URL}}', '{{PACKAGES_SECTION}}', '{{PACKAGE_FILTERS}}',
            '{{PAYBILL}}', '{{TENANT_ID}}', '{{SUPPORT_PHONE}}',
            '{{TAB_BUTTONS}}', '{{TAB_BAR_CLASS}}', '{{TABS_JSON}}', '{{HAS_PAID_TAB}}',
            '{{MANUAL_PAYBILL}}', '{{FOOTER_NOTE}}',
        ],
        [
            htmlspecialchars($companyName, ENT_QUOTES),
            htmlspecialchars($headline, ENT_QUOTES),
            htmlspecialchars($theme['subtitle'], ENT_QUOTES),
            $logoBlock,
            $palette['vars'], $bodyBg, $palette['backdrop'],
            $portalBase, $signupUrl, $packagesHtml, $filtersHtml,
            htmlspecialchars($paybill), (string)$tenantId, htmlspecialchars($supportPhone, ENT_QUOTES),
            rtrim($tabButtons, "\n"),
            count($tabNames) < 2 ? ' single' : '',
            json_encode(array_values($tabNames)),
            $theme['show_paid'] === '1' ? 'true' : 'false',
            $manual, $footerNote,
        ],
        $template
    );
}

/**
 * Strip the sections a tenant has switched off.
 *
 * The optional tab panels are wrapped in <!--{{IF:NAME}}--> … <!--{{ENDIF:NAME}}-->
 * markers rather than being pulled out into placeholders, so login.html stays a
 * file you can open and read as a page. A marker pair whose flag is true simply
 * has the markers removed.
 */
function _hsApplyConditionals(string $html, array $flags): string
{
    foreach ($flags as $name => $on) {
        $open  = '<!--{{IF:' . $name . '}}-->';
        $close = '<!--{{ENDIF:' . $name . '}}-->';
        if ($on) {
            $html = str_replace([$open, $close], '', $html);
            continue;
        }
        $start = strpos($html, $open);
        $end   = strpos($html, $close);
        if ($start === false || $end === false || $end < $start) {
            // Markers missing or crossed — leave the section in place. Showing a
            // tab the tenant switched off is a cosmetic fault; deleting an
            // arbitrary span of the page is not.
            continue;
        }
        $html = substr($html, 0, $start) . substr($html, $end + strlen($close));
    }
    return $html;
}

/**
 * Build the plan picker: [rows html, filter-chip html].
 * Returns an empty-state card (and no filters) when the tenant has no plans.
 */
function _renderHotspotPackages(PDO $pdo, int $tenantId): array
{
    try {
        // Older tenants predate this column; add it before filtering on it
        try { $pdo->exec("ALTER TABLE packages ADD COLUMN connection_type VARCHAR(20) DEFAULT NULL"); } catch (Throwable $_e) {}

        $pkgStmt = $pdo->prepare("
            SELECT id, name, description, price, download_speed, data_limit,
                   device_limit, validity_value, validity_unit
            FROM packages
            WHERE tenant_id = ? AND status = 'active'
              AND COALESCE(NULLIF(connection_type,''), 'hotspot') = 'hotspot'
            ORDER BY price ASC
            LIMIT 12
        ");
        $pkgStmt->execute([$tenantId]);
        $pkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_e) {
        $pkgs = [];
    }

    if (!$pkgs) {
        return [
            '<div class="pkg-empty">'
            . '<div class="pkg-empty-title">No plans available yet</div>'
            . '<div class="pkg-empty-sub">Please use the manual M-Pesa steps below, or contact support.</div>'
            . '</div>',
            '',
        ];
    }

    // ── Pass 1: normalise durations and find the best value ───────────────────
    $groupLabels = [
        'minute' => 'Minutes', 'hour' => 'Hourly', 'day' => 'Daily',
        'week'   => 'Weekly',  'month' => 'Monthly',
    ];
    $hoursPer = ['minute' => 1 / 60, 'hour' => 1, 'day' => 24, 'week' => 168, 'month' => 720];

    $bestIdx  = -1;
    $bestRate = INF;

    foreach ($pkgs as $i => $p) {
        $val   = max(1, (int)($p['validity_value'] ?? 1));
        $unit  = rtrim(strtolower(trim((string)($p['validity_unit'] ?? 'days'))), 's');
        // Anything unrecognised (mins, hrs, a typo) is treated as days — the
        // same assumption the provisioning code makes for the expiry date.
        if (!isset($groupLabels[$unit])) $unit = 'day';

        $hours = $val * $hoursPer[$unit];

        $pkgs[$i]['_group'] = $unit;
        $pkgs[$i]['_hours'] = $hours;
        $pkgs[$i]['_dur']   = $val . ' ' . $unit . ($val === 1 ? '' : 's');

        // Cheapest per hour of access wins the badge — only among paid plans
        // that last at least a day, so a 1-hour teaser can't game it.
        $price = (float)$p['price'];
        if ($price > 0 && $hours >= 24) {
            $rate = $price / $hours;
            if ($rate < $bestRate) { $bestRate = $rate; $bestIdx = $i; }
        }
    }

    // ── Pass 2: markup ────────────────────────────────────────────────────────
    $rows         = '';
    $groupsInUse  = [];
    $firstEnabled = true;

    foreach ($pkgs as $idx => $p) {
        $price   = (float)$p['price'];
        $isFree  = $price <= 0;
        $group   = $p['_group'];
        $groupsInUse[$group] = $groupLabels[$group];

        // Feature chips. The duration is promoted out of the chip row and given
        // its own line -- in a side-by-side card it is the second thing a
        // customer compares after the price, and it was competing with the
        // speed and data pills for the same eye.
        $chips = '';
        if (!empty($p['download_speed'])) {
            $chips .= _hsChip('bolt', (int)$p['download_speed'] . ' Mbps');
        }
        $limit = (float)($p['data_limit'] ?? 0);
        $chips .= _hsChip('data', $limit > 0 ? _hsFormatBytes($limit) : 'Unlimited');
        $devices = (int)($p['device_limit'] ?? 0);
        if ($devices > 1) {
            $chips .= _hsChip('device', $devices . ' devices');
        }

        // At most one tag per card — free beats best-value
        $tag = '';
        if ($isFree) {
            $tag = '<em class="pkg-tag pkg-tag-free">Free</em>';
        } elseif ($idx === $bestIdx && count($pkgs) > 1) {
            $tag = '<em class="pkg-tag">Best value</em>';
        }

        $checked = $firstEnabled ? ' checked' : '';
        $selCls  = $firstEnabled ? ' selected' : '';
        $firstEnabled = false;

        $priceHtml = $isFree
            ? '<span class="pkg-price pkg-price-free">FREE</span>'
            : '<span class="pkg-price"><small>KES</small>' . number_format($price, 0) . '</span>';

        // <label> wrapping the radio gives native click + keyboard + a11y for
        // free. The class stays `pkg-row` -- every filter, selection and pay
        // handler in login.html keys off it, so the layout change is entirely
        // in CSS and markup order, not in the page's behaviour.
        $rows .= '<label class="pkg-row' . $selCls . '"'
            . ' data-price="' . number_format($price, 0, '.', '') . '"'
            . ' data-group="' . $group . '"'
            . ' data-free="' . ($isFree ? '1' : '0') . '"'
            . ' data-name="' . htmlspecialchars($p['name'], ENT_QUOTES) . '"'
            . ' data-dur="'  . htmlspecialchars($p['_dur'], ENT_QUOTES) . '"'
            . ' style="--i:' . $idx . '">'
            . '<input type="radio" name="buy_package" value="' . (int)$p['id'] . '"' . $checked . '>'
            . '<span class="pkg-check"></span>'
            . '<span class="pkg-name">' . htmlspecialchars($p['name']) . '</span>'
            . $priceHtml
            . '<span class="pkg-dur">' . htmlspecialchars($p['_dur']) . '</span>'
            . '<span class="pkg-chips">' . $chips . '</span>'
            . $tag
            . '</label>';
    }

    // Filter chips only earn their space when there's more than one duration
    $filters = '';
    if (count($groupsInUse) > 1) {
        $filters = '<div class="pkg-filters"><button type="button" class="filter-chip active" data-filter="all">All</button>';
        foreach (['minute', 'hour', 'day', 'week', 'month'] as $g) {
            if (isset($groupsInUse[$g])) {
                $filters .= '<button type="button" class="filter-chip" data-filter="' . $g . '">' . $groupsInUse[$g] . '</button>';
            }
        }
        $filters .= '</div>';
    }

    return ['<div class="pkg-list">' . $rows . '</div>', $filters];
}

/** A small icon + label pill inside a plan row. */
function _hsChip(string $icon, string $label): string
{
    $paths = [
        'bolt'   => '<path d="M13 2 3 14h8l-1 8 10-12h-8l1-8z"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'data'   => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'device' => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M11 18h2"/>',
    ];
    return '<span class="chip"><svg viewBox="0 0 24 24">' . ($paths[$icon] ?? '') . '</svg>'
        . htmlspecialchars($label) . '</span>';
}

/** 1073741824 → "1 GB". Values are stored in bytes. */
function _hsFormatBytes(float $bytes): string
{
    if ($bytes >= 1073741824) {
        $gb = $bytes / 1073741824;
        return rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576) . ' MB';
    }
    return round($bytes / 1024) . ' KB';
}
