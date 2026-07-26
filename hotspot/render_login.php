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
 */

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
    $brandColor  = ($tenant['brand_color']  ?? '') ?: '#0f3460';
    $companyName = ($tenant['company_name'] ?? '') ?: 'FortuNett Technologies';

    // Derive a darker and a lighter stop from the brand colour for gradients
    $hex = ltrim($brandColor, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $brandDark  = sprintf('#%02x%02x%02x', max(0, $r - 40), max(0, $g - 40), max(0, $b - 40));
    $brandLight = sprintf('#%02x%02x%02x', min(255, $r + 55), min(255, $g + 55), min(255, $b + 55));
    $brandRgb   = "$r,$g,$b";

    [$packagesHtml, $filtersHtml] = _renderHotspotPackages($pdo, $tenantId);

    // M-Pesa paybill for the manual fallback instructions
    $paybill = 'N/A';
    try {
        require_once __DIR__ . '/../includes/credential_helper.php';
        $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
        $gwSt->execute([$tenantId]);
        $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
        if ($gwRow) {
            $gwCreds = decrypt_gateway_credentials($gwRow['credentials']);
            $paybill = $gwCreds['shortcode'] ?? $gwCreds['paybill'] ?? 'N/A';
        }
    } catch (Throwable $_e) {}

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

    // Support line — shown in the footer when the tenant has one on file
    $supportPhone = trim((string)($tenant['support_phone'] ?? $tenant['phone'] ?? ''));

    return str_replace(
        [
            '{{COMPANY_NAME}}', '{{BRAND_COLOR}}', '{{BRAND_DARK}}', '{{BRAND_LIGHT}}',
            '{{BRAND_RGB}}', '{{PORTAL_URL}}', '{{SIGNUP_URL}}', '{{PACKAGES_SECTION}}',
            '{{PACKAGE_FILTERS}}', '{{PAYBILL}}', '{{TENANT_ID}}', '{{SUPPORT_PHONE}}',
        ],
        [
            htmlspecialchars($companyName, ENT_QUOTES), $brandColor, $brandDark, $brandLight,
            $brandRgb, $portalBase, $signupUrl, $packagesHtml,
            $filtersHtml, htmlspecialchars($paybill), (string)$tenantId, htmlspecialchars($supportPhone, ENT_QUOTES),
        ],
        $template
    );
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

        // Feature chips
        $chips = '';
        if (!empty($p['download_speed'])) {
            $chips .= _hsChip('bolt', (int)$p['download_speed'] . ' Mbps');
        }
        $chips .= _hsChip('clock', $p['_dur']);
        $limit = (float)($p['data_limit'] ?? 0);
        $chips .= _hsChip('data', $limit > 0 ? _hsFormatBytes($limit) : 'Unlimited');
        $devices = (int)($p['device_limit'] ?? 0);
        if ($devices > 1) {
            $chips .= _hsChip('device', $devices . ' devices');
        }

        // At most one tag per row — free beats best-value
        $tag = '';
        if ($isFree) {
            $tag = '<em class="pkg-tag pkg-tag-free">Free trial</em>';
        } elseif ($idx === $bestIdx && count($pkgs) > 1) {
            $tag = '<em class="pkg-tag">Best value</em>';
        }

        $checked = $firstEnabled ? ' checked' : '';
        $selCls  = $firstEnabled ? ' selected' : '';
        $firstEnabled = false;

        $priceHtml = $isFree
            ? '<span class="pkg-price pkg-price-free">FREE</span>'
            : '<span class="pkg-price"><small>KES</small>' . number_format($price, 0) . '</span>';

        // <label> wrapping the radio gives native click + keyboard + a11y for free
        $rows .= '<label class="pkg-row' . $selCls . '"'
            . ' data-price="' . number_format($price, 0, '.', '') . '"'
            . ' data-group="' . $group . '"'
            . ' data-free="' . ($isFree ? '1' : '0') . '"'
            . ' data-name="' . htmlspecialchars($p['name'], ENT_QUOTES) . '"'
            . ' data-dur="'  . htmlspecialchars($p['_dur'], ENT_QUOTES) . '"'
            . ' style="--i:' . $idx . '">'
            . '<input type="radio" name="buy_package" value="' . (int)$p['id'] . '"' . $checked . '>'
            . '<span class="pkg-check"></span>'
            . '<span class="pkg-body">'
            .   '<span class="pkg-name">' . htmlspecialchars($p['name']) . $tag . '</span>'
            .   '<span class="pkg-chips">' . $chips . '</span>'
            . '</span>'
            . $priceHtml
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
