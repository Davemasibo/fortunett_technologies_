<?php
/**
 * Remove a hand-entered payment and everything it produced.
 *
 * Only ever a hand-entered one, and never one whose disbursement has moved --
 * both refusals come from includes/payment_admin.php, which is also what the
 * edit endpoint uses, so the two can never draw the line differently.
 */
require_once __DIR__ . '/_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$paymentId = (int)($_POST['payment_id'] ?? 0);
if (!$paymentId) {
    echo json_encode(['success' => false, 'message' => 'payment_id is required']);
    exit;
}

// A dry run so the confirmation dialog can state the consequences -- what will
// be removed, and whether the customer's expiry can be rolled back -- instead
// of asking "are you sure?" about something the operator cannot see.
if (!empty($_POST['preview'])) {
    try {
        $payment = loadManualPayment($pdo, $tenant_id, $paymentId);
        assertPayoutNotInFlight($payment);

        echo json_encode([
            'success'    => true,
            'payment'    => [
                'reference' => $payment['transaction_id'],
                'amount'    => (float)$payment['amount'],
                'client'    => $payment['full_name'],
                'date'      => $payment['payment_date'],
                'status'    => $payment['status'],
            ],
            'revocation' => paymentPlanRevocation($pdo, $tenant_id, $payment),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

try {
    $revoke = !empty($_POST['revoke_access']);
    $res    = deleteManualPayment($pdo, $tenant_id, $paymentId, $revoke);

    $msg = 'Payment ' . $res['reference'] . ' (KES ' . number_format($res['amount'], 2) . ') deleted.';
    if (!empty($res['revocation']['applied'])) {
        $msg .= ' Expiry rolled back by ' . $res['revocation']['period']
              . ' to ' . date('d M Y H:i', strtotime($res['revocation']['new_expiry'])) . '.';
        if (!empty($res['revocation']['now_past'])) {
            $msg .= ' That is in the past, so the expiry job will disconnect them within 15 minutes.';
        }
    } elseif ($revoke) {
        $msg .= ' Access was left alone: ' . $res['revocation']['reason'];
    }

    echo json_encode(['success' => true, 'message' => $msg, 'result' => $res]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
