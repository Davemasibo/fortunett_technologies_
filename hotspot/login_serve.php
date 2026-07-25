<?php
/**
 * Serves a tenant-branded hotspot login page.
 * Called by RouterOS /tool/fetch so the router can pull the branded page
 * directly to its flash. No session auth — token is the credential.
 *
 * URL: /hotspot/login_serve.php?token={provisioning_token}
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/db_master.php';

$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(400);
    exit('Token required');
}

try {
    // SELECT * so missing optional columns (brand_color, company_name) never cause a 500
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE provisioning_token = ?");
    $stmt->execute([$token]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        http_response_code(403);
        exit('Invalid token');
    }

    $tenantId    = (int)$tenant['id'];
    $brandColor  = $tenant['brand_color']  ?: '#0f3460';
    $companyName = $tenant['company_name'] ?: 'FortuNett Technologies';

    // Darken brand colour for gradient stop
    $hex = ltrim($brandColor, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $brandDark = sprintf('#%02x%02x%02x',
        max(0, hexdec(substr($hex, 0, 2)) - 40),
        max(0, hexdec(substr($hex, 2, 2)) - 40),
        max(0, hexdec(substr($hex, 4, 2)) - 40)
    );

    // Fetch active hotspot packages — wrapped so a missing column never breaks the page
    $packagesHtml = '';
    try {
        // Ensure connection_type column exists before querying it
        try { $pdo->exec("ALTER TABLE packages ADD COLUMN connection_type VARCHAR(20) DEFAULT NULL"); } catch (Exception $_e) {}

        $pkgStmt = $pdo->prepare("
            SELECT id, name, price, download_speed, validity_value, validity_unit
            FROM packages
            WHERE tenant_id = ? AND status = 'active'
              AND COALESCE(NULLIF(connection_type,''), 'hotspot') = 'hotspot'
            ORDER BY price ASC
            LIMIT 8
        ");
        $pkgStmt->execute([$tenantId]);
        $pkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pkgs) {
            $rows = '';
            foreach ($pkgs as $idx => $p) {
                // "1 day" / "7 days" — the stored unit may already be plural
                $val   = (int)($p['validity_value'] ?? 1);
                $unit  = rtrim(strtolower($p['validity_unit'] ?? 'days'), 's');
                $dur   = $val . ' ' . $unit . ($val === 1 ? '' : 's');
                $speed = !empty($p['download_speed']) ? htmlspecialchars($p['download_speed']) . ' Mbps' : '';
                $meta  = $speed ? $speed . ' &middot; ' . $dur : $dur;
                $pkgId = (int)$p['id'];
                $price = (float)$p['price'];

                // <label> makes the whole row a native radio target — no inline JS,
                // and keyboard/screen-reader selection works for free.
                $rows .= '<label class="pkg-row' . ($idx === 0 ? ' selected' : '') . '"'
                    . ' data-price="' . number_format($price, 0, '.', '') . '">'
                    . '<input type="radio" name="buy_package" value="' . $pkgId . '"' . ($idx === 0 ? ' checked' : '') . '>'
                    . '<span class="pkg-check"></span>'
                    . '<span class="pkg-body">'
                    .   '<span class="pkg-name">' . htmlspecialchars($p['name']) . '</span>'
                    .   '<span class="pkg-meta">' . $meta . '</span>'
                    . '</span>'
                    . '<span class="pkg-price">KES ' . number_format($price, 0) . '</span>'
                    . '</label>';
            }
            $packagesHtml = '<div class="pkg-section"><div class="pkg-section-title">Choose a plan</div>'
                . $rows . '</div>';
        } else {
            $packagesHtml = '<div class="pkg-empty">'
                . '<div class="pkg-empty-title">No plans available yet</div>'
                . '<div class="pkg-empty-sub">Please use the manual M-Pesa steps below, or contact support.</div>'
                . '</div>';
        }
    } catch (Throwable $_e) {
        // Packages are optional — never let this break the login page
    }

    // Fetch M-Pesa paybill for this tenant
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

    // Build the external captive portal base URL for this tenant.
    // RouterOS downloads this page to flash/hotspot/login.html via /tool/fetch.
    // When a hotspot client connects, RouterOS serves this HTML — substituting
    // $(link-login-only), $(mac-esc), $(error) etc. before sending to the browser.
    // The form then posts credentials directly to RouterOS which grants internet access,
    // and redirects the device to hotspot_landing.php to create a web portal session.
    $platformDomain = 'fortunetttech.site';
    try {
        $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
        $pd = $pdSt ? $pdSt->fetchColumn() : null;
        if ($pd) $platformDomain = $pd;
    } catch (Throwable $_e) {}

    $subdomain  = $tenant['subdomain'] ?? '';
    $portalBase = 'https://' . ($subdomain ? $subdomain . '.' : '') . $platformDomain;
    $signupUrl  = $portalBase . '/customer/register.php';

    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES);

    // Load the branded hotspot login template and fill in PHP-side placeholders.
    // MikroTik template vars like $(link-login-only) and $(mac-esc) are left
    // intact — RouterOS substitutes them at serve time.
    $templatePath = __DIR__ . '/login.html';
    $template = file_get_contents($templatePath);
    if (!$template) {
        throw new \RuntimeException('Hotspot login template not found: ' . $templatePath);
    }

    $html = str_replace(
        ['{{COMPANY_NAME}}', '{{BRAND_COLOR}}', '{{BRAND_DARK}}', '{{PORTAL_URL}}', '{{SIGNUP_URL}}', '{{PACKAGES_SECTION}}', '{{PAYBILL}}', '{{TENANT_ID}}'],
        [$safeCompany, $brandColor, $brandDark, $portalBase, $signupUrl, $packagesHtml, htmlspecialchars($paybill), (string)$tenantId],
        $template
    );

    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    exit('Error: ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}
