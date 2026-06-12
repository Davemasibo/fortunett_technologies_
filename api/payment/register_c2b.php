<?php
/**
 * Register tenant-specific C2B URLs with Safaricom Daraja.
 *
 * Tenant admins call this once after saving their M-Pesa API credentials.
 * Safaricom stores the validation/confirmation URLs per shortcode — the tenant
 * never needs to register again unless the URLs change.
 *
 * Callback URLs are derived from the current HTTP_HOST so they always point to
 * the correct tenant subdomain without manual configuration.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MpesaAPI.php';
require_once __DIR__ . '/../../includes/credential_helper.php';

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

// Load gateway — verify it belongs to this tenant
$stmt = $pdo->prepare("
    SELECT * FROM payment_gateways
    WHERE id = ? AND tenant_id = ? AND gateway_type = 'mpesa_api'
    LIMIT 1
");
$stmt->execute([$gatewayId, $tenantId]);
$gateway = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gateway) {
    echo json_encode(['success' => false, 'error' => 'M-Pesa API gateway not found']);
    exit;
}

// Build tenant-scoped callback URLs from current host (subdomain.fortunetttech.site)
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$baseUrl = $scheme . '://' . $host;

// Safaricom cannot reach localhost — block registration from local environments
if (preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Cannot register C2B from a local/private URL. Deploy to your public domain first.',
    ]);
    exit;
}

$validationUrl   = $baseUrl . '/api/payment/tenant_c2b_validation.php';
$confirmationUrl = $baseUrl . '/api/payment/tenant_c2b_confirmation.php';

$mpesa = new MpesaAPI($pdo, $tenantId);

if (!$mpesa->hasValidCredentials()) {
    echo json_encode([
        'success' => false,
        'error'   => 'Incomplete M-Pesa credentials. Ensure Consumer Key, Consumer Secret, Passkey, and Shortcode are all saved.',
    ]);
    exit;
}

$result = $mpesa->registerC2B($validationUrl, $confirmationUrl, 'Completed');

if ($result['success']) {
    // Persist registration status back into credentials (re-encrypted)
    $creds = decrypt_gateway_credentials($gateway['credentials']);
    $creds['c2b_registered']      = true;
    $creds['c2b_registered_at']   = date('Y-m-d H:i:s');
    $creds['c2b_validation_url']  = $validationUrl;
    $creds['c2b_confirmation_url'] = $confirmationUrl;

    $pdo->prepare("UPDATE payment_gateways SET credentials = ? WHERE id = ? AND tenant_id = ?")
        ->execute([encrypt_gateway_credentials($creds), $gatewayId, $tenantId]);

    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents(
        $logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " C2B REGISTERED: tenant=$tenantId gateway=$gatewayId validation=$validationUrl confirmation=$confirmationUrl\n",
        FILE_APPEND | LOCK_EX
    );

    echo json_encode([
        'success'          => true,
        'message'          => 'C2B registered. Customers paying your paybill will now be auto-connected on payment.',
        'validation_url'   => $validationUrl,
        'confirmation_url' => $confirmationUrl,
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Registration failed']);
}
