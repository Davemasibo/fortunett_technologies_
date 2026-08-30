<?php
/**
 * Safaricom B2C result callback — the only place a payout becomes 'paid'.
 *
 * cron/disburse_payouts.php gets a request ACCEPTED; that is not the money
 * moving. This endpoint receives the actual outcome, asynchronously, and is
 * what marks isp_payout_queue rows paid and stamps payments.released_at.
 *
 * Register this URL as the ResultURL on the B2C request (the cron builds it
 * from platform_settings.public_base_url).
 *
 * Like the C2B handlers, this always answers 200 with a success body: an error
 * response makes Safaricom retry, and a retry storm against a handler that is
 * already half-applied is worse than a logged failure. Idempotent — a repeated
 * callback for a batch already settled changes nothing.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/payouts.php';

$raw = file_get_contents('php://input');

// Log every callback verbatim before touching the database. A payout dispute is
// settled from this file, so it is written first and unconditionally.
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents(
    $logDir . '/b2c_results.log',
    date('Y-m-d H:i:s') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' -- ' . ($raw ?: '(empty body)') . "\n",
    FILE_APPEND | LOCK_EX
);

$ok = function (string $note = 'ok') {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => $note]);
    exit;
};

try {
    ensurePayoutTables($pdo);

    $body   = json_decode($raw, true) ?: [];
    $result = $body['Result'] ?? [];

    $originatorId = (string)($result['OriginatorConversationID'] ?? '');
    $conversation = (string)($result['ConversationID'] ?? '');
    $resultCode   = $result['ResultCode'] ?? null;
    $resultDesc   = (string)($result['ResultDesc'] ?? '');

    if ($originatorId === '' && $conversation === '') {
        error_log('b2c_result: callback with no conversation identifiers');
        $ok('ignored — no conversation id');
    }

    $bSt = $pdo->prepare("
        SELECT * FROM isp_payout_batches
        WHERE originator_conversation_id = ? OR (conversation_id = ? AND conversation_id <> '')
        ORDER BY id DESC LIMIT 1
    ");
    $bSt->execute([$originatorId, $conversation]);
    $batch = $bSt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        // Never invent a batch from a callback. An unmatched result means money
        // may have moved for something we have no record of — that is a human
        // problem, and the raw log above is the evidence.
        error_log("b2c_result: no batch for originator=$originatorId conversation=$conversation");
        $ok('ignored — unknown batch');
    }

    if (in_array($batch['status'], ['paid', 'failed'], true)) {
        $ok('already final');
    }

    // Safaricom returns the receipt inside a loosely-typed parameter bag.
    $receipt = null;
    foreach (($result['ResultParameters']['ResultParameter'] ?? []) as $p) {
        if (($p['Key'] ?? '') === 'TransactionReceipt') {
            $receipt = (string)($p['Value'] ?? '');
            break;
        }
    }

    $success = ((int)$resultCode === 0);

    $pdo->prepare("
        UPDATE isp_payout_batches
        SET status = ?, conversation_id = COALESCE(NULLIF(?,''), conversation_id),
            transaction_id = ?, result_code = ?, result_desc = ?, raw_result = ?,
            completed_at = NOW()
        WHERE id = ?
    ")->execute([
        $success ? 'paid' : 'failed',
        $conversation, $receipt, (string)$resultCode,
        substr($resultDesc, 0, 255), $raw, (int)$batch['id'],
    ]);

    $queueIds = array_filter(array_map('intval', explode(',', (string)$batch['queue_ids'])));

    if ($success) {
        $note = 'Disbursed via M-Pesa B2C' . ($receipt ? ' — ' . $receipt : '');
        $n = settlePayoutBatch($pdo, $batch, $note);
        error_log("b2c_result: batch {$batch['id']} PAID, {$n} payment(s) released");
        $ok('settled');
    }

    // Failed: put the money back so the next run can try again. Only rows still
    // 'processing' are touched — anything a human has since resolved is left be.
    if ($queueIds) {
        $in = implode(',', array_fill(0, count($queueIds), '?'));
        $pdo->prepare("
            UPDATE isp_payout_queue
            SET status = 'pending', batch_id = NULL, last_error = ?
            WHERE id IN ($in) AND status = 'processing'
        ")->execute(array_merge([substr($resultDesc, 0, 255)], $queueIds));
    }

    error_log("b2c_result: batch {$batch['id']} FAILED ($resultCode) $resultDesc — returned to queue");
    $ok('failed, requeued');

} catch (Throwable $e) {
    error_log('b2c_result fatal: ' . $e->getMessage());
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'logged']);
}
