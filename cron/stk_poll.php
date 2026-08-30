<?php
/**
 * STK Push Poll — FortuNett Technologies
 *
 * Runs every 2 minutes. Finds mpesa_transactions that are still 'pending'
 * after 45 seconds (enough time for a real callback to arrive) and queries
 * Daraja's stkpushquery endpoint to learn their final status.
 *
 * This eliminates "I paid but nothing happened" — typically caused by
 * Safaricom failing to deliver the callback after the customer approved payment.
 *
 * Cron schedule:
 *   *\/2 * * * * php /var/www/html/cron/stk_poll.php >> /var/log/fortunett_stk_poll.log 2>&1
 *
 * ResultCode reference:
 *   0    — success (process pipeline)
 *   1019 — request expired at Safaricom (mark failed)
 *   1025 — still processing (skip — will retry next run)
 *   1032 — cancelled by customer (mark failed)
 *   1037 — DS timeout (mark failed)
 *   other non-zero — failed (mark failed)
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auto_provision.php';
require_once __DIR__ . '/../includes/payment_pipeline.php';
require_once __DIR__ . '/../classes/MpesaAPI.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';

cron_heartbeat($pdo, 'stk_poll');

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== STK Poll Run ===");

// Fetch pending transactions older than 45 seconds (callback window), younger than 15 minutes
// Transactions older than 15 min are definitively expired — mark failed without querying Daraja
$pending = $pdo->query("
    SELECT mt.*, c.tenant_id AS c_tenant_id, c.package_id, c.id AS client_id_val
    FROM mpesa_transactions mt
    LEFT JOIN clients c ON c.id = mt.client_id
    WHERE mt.status = 'pending'
      AND mt.checkout_request_id IS NOT NULL
      AND mt.created_at < NOW() - INTERVAL 45 SECOND
      AND mt.created_at > NOW() - INTERVAL 15 MINUTE
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$log("Found " . count($pending) . " pending transaction(s) to check.");

foreach ($pending as $tx) {
    $checkoutId = $tx['checkout_request_id'];
    $tenantId   = $tx['tenant_id'] ?? $tx['c_tenant_id'] ?? null;

    $log("Checking {$checkoutId} (tenant={$tenantId}, client={$tx['client_id']})");

    try {
        $mpesa  = new MpesaAPI($pdo, $tenantId);
        $result = $mpesa->stkQuery($checkoutId);

        $code = $result['result_code'];
        $desc = $result['result_desc'];

        @file_put_contents(
            $logDir . '/stk_poll_detail.log',
            date('Y-m-d H:i:s') . " checkout={$checkoutId} code={$code} desc={$desc} raw=" . json_encode($result['raw']) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if ($result['error']) {
            $log("ERROR querying {$checkoutId}: {$result['error']}");
            continue;
        }

        if ($code === 1025) {
            // Still processing — Safaricom hasn't settled yet; try again next run
            $log("PENDING (1025) {$checkoutId} — will retry");
            continue;
        }

        if ($code === 0) {
            // SUCCESS — extract metadata from the query response
            $raw    = $result['raw'];
            $amount  = 0;
            $receipt = '';
            $phone   = '';

            // Metadata is nested in CallbackMetadata for STK query just like in callbacks
            $items = $raw['CallbackMetadata']['Item'] ?? [];
            foreach ($items as $item) {
                $name = $item['Name'] ?? '';
                $val  = $item['Value'] ?? '';
                if ($name === 'Amount')             $amount  = (float)$val;
                if ($name === 'MpesaReceiptNumber') $receipt = (string)$val;
                if ($name === 'PhoneNumber')        $phone   = (string)$val;
            }

            if (empty($receipt)) {
                $log("WARN: success but no receipt for {$checkoutId} — skipping pipeline. Raw: " . json_encode($raw));
                continue;
            }

            // Idempotency: skip if already processed (e.g., real callback arrived first)
            if ($tx['status'] !== 'pending') {
                $log("SKIP {$checkoutId} — already {$tx['status']}");
                continue;
            }

            // Mark transaction completed
            $pdo->prepare("
                UPDATE mpesa_transactions
                SET status = 'completed', result_code = ?, result_desc = ?,
                    mpesa_receipt_number = ?, updated_at = NOW()
                WHERE checkout_request_id = ?
            ")->execute([0, $desc, $receipt, $checkoutId]);

            if ($tx['client_id']) {
                $clientId         = (int)$tx['client_id'];
                $resolvedTenantId = (int)($tenantId ?? $tx['c_tenant_id']);

                // Upsert payment row
                $rows = $pdo->prepare("
                    UPDATE payments
                    SET status = 'completed', transaction_id = ?
                    WHERE transaction_id = ? AND (tenant_id = ? OR ? IS NULL)
                ");
                $rows->execute([$receipt, $checkoutId, $resolvedTenantId, $resolvedTenantId]);

                // See api/payment/callback.php: no untagged INSERT fallback.
                // The pipeline creates the row with the right collection_type
                // when the rename above matched no pending row.

                // See api/payment/callback.php: the gateway_type = 'mpesa' lookup
                // that used to live here matched no row on any deployment, so this
                // backstop tagged everything it recovered as platform-collected.
                // NULL defers to the tag written at STK-push time.
                $platformCollected = null;

                process_payment_success(
                    $pdo,
                    $clientId,
                    $resolvedTenantId,
                    $amount,
                    $receipt,
                    'mpesa',
                    $tx['package_id'] ? (int)$tx['package_id'] : null,
                    $platformCollected
                );

                $log("SUCCESS {$checkoutId} → receipt={$receipt} amount={$amount} client={$clientId}");
            } else {
                $log("SUCCESS {$checkoutId} → receipt={$receipt} but no client_id — transaction recorded only");
            }

        } else {
            // Failed / cancelled / expired
            $pdo->prepare("
                UPDATE mpesa_transactions
                SET status = 'failed', result_code = ?, result_desc = ?, updated_at = NOW()
                WHERE checkout_request_id = ?
            ")->execute([$code, $desc, $checkoutId]);

            $pdo->prepare("
                UPDATE payments SET status = 'failed'
                WHERE transaction_id = ? AND status = 'pending'
            ")->execute([$checkoutId]);

            $log("FAILED {$checkoutId} code={$code} desc={$desc}");
        }

    } catch (Throwable $e) {
        $log("EXCEPTION for {$checkoutId}: " . $e->getMessage());
        error_log("stk_poll.php {$checkoutId}: " . $e->getMessage());
    }
}

// Also sweep truly expired transactions (> 15 min old, still pending — Daraja won't respond)
$expired = $pdo->prepare("
    UPDATE mpesa_transactions
    SET status = 'failed', result_code = 1019,
        result_desc = 'Expired — not confirmed within 15 minutes', updated_at = NOW()
    WHERE status = 'pending'
      AND created_at < NOW() - INTERVAL 15 MINUTE
      AND checkout_request_id IS NOT NULL
");
$expired->execute();
$expiredCount = $expired->rowCount();
if ($expiredCount > 0) {
    $log("Expired {$expiredCount} stale pending transaction(s) (>15 min old).");

    // Mark their payment rows failed too
    $pdo->query("
        UPDATE payments p
        JOIN mpesa_transactions mt ON mt.checkout_request_id = p.transaction_id
        SET p.status = 'failed'
        WHERE p.status = 'pending'
          AND mt.status = 'failed'
          AND mt.result_desc LIKE '%15 minutes%'
    ");
}

$log("=== Done ===");
