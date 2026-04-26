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

    $templatePath = __DIR__ . '/login.html';
    if (!file_exists($templatePath)) {
        http_response_code(500);
        exit('Login template not found on server');
    }

    // Build the tenant's portal base URL (used for signup link + post-auth redirect)
    $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $portalUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $signupUrl = $portalUrl . '/customer/register.php';

    $html = file_get_contents($templatePath);
    $html = str_replace(
        ['{{BRAND_COLOR}}', '{{BRAND_DARK}}', '{{COMPANY_NAME}}', '{{PACKAGES_SECTION}}', '{{PORTAL_URL}}', '{{SIGNUP_URL}}'],
        [$brandColor, $brandDark, htmlspecialchars($companyName, ENT_QUOTES), $packagesHtml, $portalUrl, $signupUrl],
        $html
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
