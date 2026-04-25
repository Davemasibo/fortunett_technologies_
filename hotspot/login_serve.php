<?php
/**
 * Serves a tenant-branded hotspot login page.
 * Called by the RouterOS API (/tool/fetch) during client provisioning so the
 * router can download the branded page directly to its flash filesystem.
 *
 * URL: /hotspot/login_serve.php?token={provisioning_token}
 * No session auth — secured by the provisioning token.
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/tenant.php';

$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    exit('Token required');
}

try {
    $tenantManager = TenantManager::getInstance($pdo);
    $tenantId = $tenantManager->validateProvisioningToken($token);

    if (!$tenantId) {
        http_response_code(403);
        exit('Invalid token');
    }

    $stmt = $pdo->prepare("SELECT id, brand_color, company_name FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    $brandColor  = $tenant['brand_color']  ?? '#0f3460';
    $companyName = $tenant['company_name'] ?? 'FortuNett Technologies';

    // Darken brand colour for gradient
    $hex = ltrim($brandColor, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $brandDark = sprintf('#%02x%02x%02x',
        max(0, hexdec(substr($hex, 0, 2)) - 40),
        max(0, hexdec(substr($hex, 2, 2)) - 40),
        max(0, hexdec(substr($hex, 4, 2)) - 40)
    );

    // Fetch hotspot packages for this tenant
    $packagesHtml = '';
    try {
        $pkgStmt = $pdo->prepare("
            SELECT name, price, download_speed, upload_speed, validity_value, validity_unit,
                   COALESCE(NULLIF(description,''), '') AS description
            FROM packages
            WHERE tenant_id = ? AND status = 'active'
              AND COALESCE(NULLIF(connection_type,''), type, 'hotspot') = 'hotspot'
            ORDER BY price ASC
            LIMIT 8
        ");
        $pkgStmt->execute([$tenantId]);
        $pkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pkgs) {
            $rows = '';
            foreach ($pkgs as $p) {
                $dur  = ($p['validity_value'] ?? 1) . ' ' . ucfirst($p['validity_unit'] ?? 'days');
                $speed = '';
                if (!empty($p['download_speed'])) {
                    $speed = $p['download_speed'] . ' Mbps';
                }
                $rows .= '<div class="pkg-row">'
                    . '<span class="pkg-name">' . htmlspecialchars($p['name']) . '</span>'
                    . '<span class="pkg-meta">' . ($speed ? $speed . ' &bull; ' : '') . $dur . '</span>'
                    . '<span class="pkg-price">KES ' . number_format((float)$p['price'], 0) . '</span>'
                    . '</div>';
            }
            $packagesHtml = '<div class="pkg-section">'
                . '<div class="pkg-title">Available Plans</div>'
                . $rows
                . '</div>';
        }
    } catch (Throwable $_e) {}

    $templatePath = __DIR__ . '/login.html';
    if (!file_exists($templatePath)) {
        http_response_code(500);
        exit('Template not found');
    }

    $html = file_get_contents($templatePath);
    $html = str_replace(
        ['{{BRAND_COLOR}}', '{{BRAND_DARK}}', '{{COMPANY_NAME}}', '{{PACKAGES_SECTION}}'],
        [$brandColor, $brandDark, htmlspecialchars($companyName, ENT_QUOTES), $packagesHtml],
        $html
    );

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;

} catch (Throwable $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}
?>
