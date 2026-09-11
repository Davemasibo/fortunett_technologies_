<?php
/**
 * POST /api/hotspot/verify_payment.php
 *
 * Public endpoint (no session required) — called by the captive portal's
 * "Reconnect" tab. Verifies an M-Pesa transaction code and reconnects the
 * client only while their existing paid access is valid,
 * and returns their MikroTik credentials so the portal can auto-login.
 *
 * POST params:
 *   mpesa_code  — M-Pesa confirmation code (e.g. QH12345678)
 *   tenant_id   — numeric tenant ID (embedded in the captive portal template)
 *   mac         — MAC address of the device (optional, for logging)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/receipt_reconnect.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

function fail(string $msg, string $hint = ''): void {
    echo json_encode(['success' => false, 'message' => $msg, 'hint' => $hint]);
    exit;
}

$mpesaCode = strtoupper(trim($_POST['mpesa_code'] ?? ''));
$tenantId  = (int)($_POST['tenant_id'] ?? 0);
$mac       = trim($_POST['mac'] ?? '');

if (!$mpesaCode) fail('No M-Pesa code provided.');
if (!$tenantId)  fail('Invalid tenant.');

// ── Look up the transaction ───────────────────────────────────────────────────
// Check both mpesa_transactions (STK push) and payments (manual/C2B) tables.
$tx = null;
try {
    $st = $pdo->prepare("
        SELECT mt.id, mt.client_id, mt.tenant_id, mt.amount, mt.status,
               mt.mpesa_receipt_number AS mpesa_receipt
        FROM mpesa_transactions mt
        WHERE mt.mpesa_receipt_number = ?
          AND mt.tenant_id = ?
          AND mt.status = 'completed' AND mt.result_code = 0
        LIMIT 1
    ");
    $st->execute([$mpesaCode, $tenantId]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_e) {}

// Fallback: check payments table
if (!$tx) {
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.client_id, p.tenant_id, p.amount, p.status,
                   p.transaction_id AS mpesa_receipt
            FROM payments p
            WHERE p.transaction_id = ?
              AND p.tenant_id = ?
              AND p.status = 'completed'
            LIMIT 1
        ");
        $st->execute([$mpesaCode, $tenantId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_e) {}
}

if (!$tx) {
    fail(
        'Payment code not found.',
        'Make sure you entered the M-Pesa confirmation code exactly. If you just paid, wait 30 seconds and try again.'
    );
}

$clientId = (int)($tx['client_id'] ?? 0);
if (!$clientId) {
    fail('Payment found but no account is linked to it. Please contact support.');
}

try {
    echo json_encode(reconnectReceiptClient($pdo, $clientId, $tenantId, $mac));
} catch (Throwable $e) {
    error_log('Receipt reconnect: ' . $e->getMessage());
    fail('Payment found. Connection setup needs attention; do not pay again.', 'Contact your ISP with the payment code.');
}
