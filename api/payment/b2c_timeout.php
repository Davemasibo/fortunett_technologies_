<?php
/**
 * Safaricom B2C queue-timeout callback.
 *
 * Fired when Safaricom could not process the request in time. This is NOT proof
 * the money stayed put, so the batch is marked 'unknown' rather than failed and
 * the payouts stay 'processing'. cron/disburse_payouts.php refuses to send that
 * tenant anything further until a human clears it — a stuck payout is recoverable,
 * a duplicate B2C is not.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/payouts.php';

$raw = file_get_contents('php://input');

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents(
    $logDir . '/b2c_results.log',
    date('Y-m-d H:i:s') . ' TIMEOUT ' . ($raw ?: '(empty body)') . "\n",
    FILE_APPEND | LOCK_EX
);

try {
    ensurePayoutTables($pdo);

    $body   = json_decode($raw, true) ?: [];
    $result = $body['Result'] ?? $body;
    $originatorId = (string)($result['OriginatorConversationID'] ?? '');
    $conversation = (string)($result['ConversationID'] ?? '');

    if ($originatorId !== '' || $conversation !== '') {
        $pdo->prepare("
            UPDATE isp_payout_batches
            SET status = 'unknown',
                result_desc = 'Safaricom queue timeout — outcome unknown, check the M-Pesa statement',
                raw_result = ?
            WHERE (originator_conversation_id = ? OR (conversation_id = ? AND conversation_id <> ''))
              AND status IN ('sending','accepted')
        ")->execute([$raw, $originatorId, $conversation]);

        error_log("b2c_timeout: batch $originatorId/$conversation marked unknown");
    }
} catch (Throwable $e) {
    error_log('b2c_timeout fatal: ' . $e->getMessage());
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'logged']);
