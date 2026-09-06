<?php
/**
 * Correct a hand-entered payment.
 *
 * The work — and the reasoning about what may be changed and what must follow
 * the change through the ledger — lives in includes/payment_admin.php so that
 * this endpoint and delete_manual.php cannot disagree about it.
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

try {
    $fields = [];
    foreach (['reference', 'amount', 'method', 'notes', 'status', 'payment_date'] as $f) {
        if (array_key_exists($f, $_POST)) $fields[$f] = $_POST[$f];
    }

    $res = updateManualPayment($pdo, $tenant_id, $paymentId, $fields);

    if (!$res['changed']) {
        echo json_encode(['success' => true, 'message' => 'Nothing was changed.', 'result' => $res]);
        exit;
    }

    $msg = 'Payment updated (' . implode(', ', array_diff($res['changed'], ['activation'])) . ').';
    if (!empty($res['activation']['expiry_date'])) {
        $msg = 'Payment confirmed — customer reconnected until '
             . date('d M Y H:i', strtotime($res['activation']['expiry_date'])) . '.';
    }

    echo json_encode(['success' => true, 'message' => $msg, 'result' => $res]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
