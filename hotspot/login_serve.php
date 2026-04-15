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

    $stmt = $pdo->prepare("SELECT brand_color, company_name FROM tenants WHERE id = ?");
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

    $templatePath = __DIR__ . '/login.html';
    if (!file_exists($templatePath)) {
        http_response_code(500);
        exit('Template not found');
    }

    $html = file_get_contents($templatePath);
    $html = str_replace(
        ['{{BRAND_COLOR}}', '{{BRAND_DARK}}', '{{COMPANY_NAME}}'],
        [$brandColor, $brandDark, htmlspecialchars($companyName, ENT_QUOTES)],
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
