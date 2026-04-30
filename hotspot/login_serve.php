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
            SELECT name, price, download_speed, validity_value, validity_unit
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
            foreach ($pkgs as $p) {
                $dur   = ($p['validity_value'] ?? 1) . ' ' . ucfirst($p['validity_unit'] ?? 'days');
                $speed = !empty($p['download_speed']) ? $p['download_speed'] . ' Mbps · ' : '';
                $rows .= '<div class="pkg-row">'
                    . '<span class="pkg-name">' . htmlspecialchars($p['name']) . '</span>'
                    . '<span class="pkg-meta">' . $speed . $dur . '</span>'
                    . '<span class="pkg-price">KES ' . number_format((float)$p['price'], 0) . '</span>'
                    . '</div>';
            }
            $packagesHtml = '<div class="pkg-section"><div class="pkg-title">Available Plans</div>'
                . $rows . '</div>';
        }
    } catch (Throwable $_e) {
        // Packages are optional — never let this break the login page
    }

    // Build the external captive portal URL for this tenant.
    // RouterOS downloads this page to flash/hotspot/login.html.
    // When a hotspot client connects, the router serves this HTML which
    // JS-redirects the browser to the full portal (with MikroTik's URL params).
    $platformDomain = 'fortunetttech.site';
    try {
        $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
        $pd = $pdSt ? $pdSt->fetchColumn() : null;
        if ($pd) $platformDomain = $pd;
    } catch (Throwable $_e) {}

    $subdomain  = $tenant['subdomain'] ?? '';
    $portalBase = 'https://' . ($subdomain ? $subdomain . '.' : '') . $platformDomain;
    $portalUrl  = $portalBase . '/customer/login.php';

    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES);

    $jsPortalUrl = json_encode($portalUrl);

    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html>'
       . '<html lang="en"><head>'
       . '<meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $safeCompany . '</title>'
       . '<style>'
       . '*{box-sizing:border-box;margin:0;padding:0}'
       . 'body{background:#0e0e0d;color:#e8e8e6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh}'
       . '.wrap{text-align:center;padding:32px 24px}'
       . '.spinner{width:40px;height:40px;border:3px solid rgba(255,255,255,.12);border-top-color:' . $brandColor . ';'
       . 'border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px}'
       . '@keyframes spin{to{transform:rotate(360deg)}}'
       . 'h2{font-size:20px;font-weight:700;margin-bottom:6px}'
       . 'p{color:#6b7280;font-size:14px;margin-bottom:18px}'
       . 'a{color:' . $brandColor . ';text-decoration:none;font-size:14px;font-weight:600}'
       . '</style>'
       . '<script>'
       . '(function(){'
       . 'var portal=' . $jsPortalUrl . ';'
       . 'var qs=window.location.search||"";'
       . 'window.location.replace(portal+qs);'
       . '})();'
       . '</script>'
       . '</head><body>'
       . '<div class="wrap">'
       . '<div class="spinner"></div>'
       . '<h2>' . $safeCompany . '</h2>'
       . '<p>Connecting to network...</p>'
       . '<a href="' . htmlspecialchars($portalUrl, ENT_QUOTES) . '">Tap here if not redirected</a>'
       . '</div></body></html>';

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    exit('Error: ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}
