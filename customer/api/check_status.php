<?php
/**
 * Customer Portal — Poll STK Push Payment Status
 *
 * GET ?checkout_id=<CheckoutRequestID>
 *
 * Returns: { status: 'pending'|'paid'|'failed', message: string }
 *
 * Activation logic:
 *   - Package payment (mt.client_id IS NOT NULL): the M-Pesa callback.php already
 *     activates the client. This endpoint is a fallback in case callback is delayed.
 *   - Topup (mt.client_id IS NULL / result_desc starts with 'TOPUP:'): credits
 *     account_balance here (idempotent) since callback intentionally skips it.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/auto_provision.php';

if (!isCustomerLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customer = getCurrentCustomer();
if (!$customer) {
    echo json_encode(['status' => 'error', 'message' => 'Session invalid']);
    exit;
}

$checkoutId = trim($_GET['checkout_id'] ?? '');
if (!$checkoutId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing checkout_id']);
    exit;
}

$clientId = (int)$customer['id'];
$tenantId = (int)$customer['tenant_id'];

// Find transaction scoped to this tenant (client_id may be NULL for topups)
$txStmt = $pdo->prepare(
    "SELECT mt.id, mt.client_id, mt.amount, mt.status, mt.result_code, mt.result_desc,
            mt.transaction_id AS mpesa_receipt
     FROM mpesa_transactions mt
     WHERE mt.checkout_request_id = ? AND mt.tenant_id = ?
     LIMIT 1"
);
$txStmt->execute([$checkoutId, $tenantId]);
$tx = $txStmt->fetch(PDO::FETCH_ASSOC);

if (!$tx) {
    // Not yet written by the STK push — still awaiting M-Pesa acknowledgement
    echo json_encode(['status' => 'pending', 'message' => 'Awaiting M-Pesa response…']);
    exit;
}

$dbStatus = $tx['status'] ?? 'pending';

if ($dbStatus === 'completed') {

    $isTopup = (is_null($tx['client_id']) || strncmp($tx['result_desc'] ?? '', 'TOPUP:', 6) === 0);

    if ($isTopup) {
        // Credit account balance — idempotent: only apply once
        $alreadyApplied = $pdo->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE transaction_id = ? AND status = 'completed' AND client_id = ?"
        );
        $alreadyApplied->execute([$checkoutId, $clientId]);

        if ((int)$alreadyApplied->fetchColumn() === 0) {
            // Mark payment completed
            $pdo->prepare(
                "UPDATE payments SET status = 'completed'
                 WHERE transaction_id = ? AND status = 'pending'"
            )->execute([$checkoutId]);

            // Credit balance
            $pdo->prepare(
                "UPDATE clients SET account_balance = account_balance + ?
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$tx['amount'], $clientId, $tenantId]);

            // Activity log
            try {
                $pdo->prepare(
                    "INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
                     VALUES (?, ?, 'topup', ?)"
                )->execute([
                    $clientId, $tenantId,
                    'Balance topped up: KES ' . number_format($tx['amount'], 2)
                    . ' via M-Pesa' . (!empty($tx['mpesa_receipt']) ? ' (' . $tx['mpesa_receipt'] . ')' : '')
                ]);
            } catch (Exception $e) { /* log table may not exist */ }
        }

    } else {
        // Package payment — callback.php normally handles activation.
        // As a safety fallback: if the client is still not active, activate now.
        $clStmt = $pdo->prepare("SELECT status, package_id FROM clients WHERE id = ? AND tenant_id = ?");
        $clStmt->execute([$clientId, $tenantId]);
        $cl = $clStmt->fetch(PDO::FETCH_ASSOC);

        if ($cl && $cl['status'] !== 'active' && $cl['package_id']) {
            $pkg = $pdo->prepare("SELECT validity_value, validity_unit FROM packages WHERE id = ? AND tenant_id = ?");
            $pkg->execute([$cl['package_id'], $tenantId]);
            $package = $pkg->fetch(PDO::FETCH_ASSOC);

            if ($package) {
                $validityValue = max(1, (int)($package['validity_value'] ?? 30));
                $validityUnit  = in_array($package['validity_unit'], ['days','weeks','months'])
                                 ? $package['validity_unit'] : 'days';
                $expiryDate    = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));

                $pdo->prepare(
                    "UPDATE clients SET status = 'active', expiry_date = ?
                     WHERE id = ? AND tenant_id = ?"
                )->execute([$expiryDate, $clientId, $tenantId]);

                try {
                    $pdo->prepare(
                        "INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
                         VALUES (?, ?, 'payment_success', ?)"
                    )->execute([
                        $clientId, $tenantId,
                        'Service activated via M-Pesa' . (!empty($tx['mpesa_receipt']) ? ' (' . $tx['mpesa_receipt'] . ')' : '')
                        . '. Expires ' . $expiryDate
                    ]);
                } catch (Exception $e) { /* log table may not exist */ }

                // Auto-provision (best-effort)
                autoProvisionClient($pdo, $clientId, $tenantId);
            }
        }
    }

    echo json_encode(['status' => 'paid', 'message' => 'Payment confirmed!']);

} elseif ($dbStatus === 'failed') {
    $desc = $tx['result_desc'] ?? 'Payment failed or was cancelled.';
    // Human-friendly messages for common result codes
    $friendlyMap = [
        '1032' => 'Payment cancelled. Please try again.',
        '1037' => 'Request timed out — no response from phone. Please try again.',
        '2001' => 'Wrong PIN entered. Please try again.',
    ];
    $msg = $friendlyMap[(string)($tx['result_code'] ?? '')] ?? $desc;
    echo json_encode(['status' => 'failed', 'message' => $msg]);

} else {
    // Still pending
    echo json_encode(['status' => 'pending', 'message' => 'Waiting for M-Pesa confirmation…']);
}
