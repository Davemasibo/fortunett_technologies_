<?php
/**
 * M-Pesa C2B Validation Handler — Platform Shared Paybill
 *
 * Called by Safaricom BEFORE crediting the customer's account.
 * We validate that the account number maps to a known tenant + client.
 * Return ResultCode 0 to accept, any other code to reject.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/account_resolver.php';

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$content = file_get_contents('php://input');
file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " VALIDATE -- " . $content . "\n", FILE_APPEND | LOCK_EX);

$data       = json_decode($content, true);
$accountRef = strtoupper(trim($data['BillRefNumber'] ?? ''));
$phone      = $data['MSISDN'] ?? '';

try {
    // Use the same resolver as the confirmation handler, so validation can never
    // reject a reference that confirmation would happily have accepted.
    $match = resolveAccountRef($pdo, $accountRef, $phone, null);

    if ($match) {
        file_put_contents($logDir . '/mpesa_c2b.log',
            date('Y-m-d H:i:s') . " VALIDATE OK via {$match['matched_by']}: account=$accountRef -> tenant={$match['tenant_id']} client={$match['client_id']}\n",
            FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    } else {
        // Accept anyway. Rejecting bounces the payment at the till and strands a
        // customer who mistyped one character, while confirmation logs the same
        // money as UNROUTABLE for a human to reconcile — a far better outcome
        // than refusing to take it.
        file_put_contents($logDir . '/mpesa_c2b.log',
            date('Y-m-d H:i:s') . " VALIDATE UNRESOLVED (accepted anyway): account=$accountRef phone=$phone\n",
            FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
} catch (Throwable $e) {
    file_put_contents($logDir . '/mpesa_errors.log', date('Y-m-d H:i:s') . " C2B validate error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
