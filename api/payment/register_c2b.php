<?php
/**
 * Register tenant-specific C2B URLs with Safaricom Daraja.
 *
 * This is now only the *manual* re-run of something that happens automatically
 * when M-Pesa credentials are saved (api/payment_gateways/save.php). It stays
 * because re-registering is idempotent and an admin who changed shortcode,
 * domain or store number needs a way to force it.
 *
 * All of the actual work — preflight checks, the Buy Goods store-number rule,
 * the URLs, the cached flags — lives in includes/c2b_registration.php so the
 * automatic and manual paths can never drift apart.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/c2b_registration.php';

// Auth guard
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) {
    echo json_encode(['success' => false, 'error' => 'Tenant session missing']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$gatewayId = (int)($input['gateway_id'] ?? 0);

if (!$gatewayId) {
    echo json_encode(['success' => false, 'error' => 'gateway_id required']);
    exit;
}

$host  = $_SERVER['HTTP_HOST'] ?? '';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

echo json_encode(registerTenantC2B($pdo, $tenantId, $gatewayId, $host, $https));
