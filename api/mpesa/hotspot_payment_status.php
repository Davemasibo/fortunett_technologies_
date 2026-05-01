<?php
/**
 * Hotspot Payment Status Polling Endpoint
 * Called every few seconds by the captive portal page after STK push.
 * Returns payment status + MikroTik credentials when payment is confirmed.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../includes/db_master.php';

$checkoutId = trim($_GET['checkout_request_id'] ?? '');
$clientId   = (int)($_GET['client_id'] ?? 0);

if (!$checkoutId || !$clientId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

try {
    // Check transaction status
    $txSt = $pdo->prepare("SELECT status, result_desc FROM mpesa_transactions WHERE checkout_request_id = ? AND client_id = ? LIMIT 1");
    $txSt->execute([$checkoutId, $clientId]);
    $tx = $txSt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        echo json_encode(['status' => 'pending']);
        exit;
    }

    if ($tx['status'] === 'completed') {
        // Fetch client credentials
        $clSt = $pdo->prepare("SELECT mikrotik_username, mikrotik_password, status, account_number FROM clients WHERE id = ? LIMIT 1");
        $clSt->execute([$clientId]);
        $client = $clSt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            echo json_encode(['status' => 'pending']);
            exit;
        }

        // If client is still pending/inactive (e.g. callback hasn't fired yet),
        // activate it now so the customer is not stuck waiting.
        if ($client['status'] === 'pending' || $client['status'] === 'inactive') {
            try {
                $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$clientId]);
                $client['status'] = 'active';
            } catch (Throwable $_e) {}
        }

        // Create auto-login token for customer portal
        $portalToken = null;
        try {
            $portalToken = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')")
                ->execute([$clientId, $portalToken]);
        } catch (Exception $_e) { $portalToken = null; }

        echo json_encode([
            'status'       => 'completed',
            'username'     => $client['mikrotik_username'],
            'password'     => $client['mikrotik_password'],
            'portal_token' => $portalToken,
        ]);
        exit;
    }

    if ($tx['status'] === 'failed') {
        $desc = $tx['result_desc'] ?? '';
        $msg  = str_contains((string)$desc, '1032') ? 'Payment cancelled by user.'
              : (str_contains((string)$desc, '1037') ? 'Request timed out. Please try again.'
              : 'Payment failed. Please try again.');
        echo json_encode(['status' => 'failed', 'message' => $msg]);
        exit;
    }

    // Still pending
    echo json_encode(['status' => 'pending']);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error.']);
}
