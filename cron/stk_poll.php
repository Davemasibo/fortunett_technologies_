<?php
/** Reconcile pending and interrupted customer STK activations every minute.
 * Safaricom confirmation is authoritative; age alone never proves payment failure.
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

require_once __DIR__ . '/../includes/stk_reconciliation.php';
// Never infer payment failure from elapsed time. A missed callback is recoverable.
$transactions = $pdo->query("SELECT mt.* FROM mpesa_transactions mt
    WHERE mt.client_id IS NOT NULL AND mt.checkout_request_id IS NOT NULL
      AND mt.created_at < NOW() - INTERVAL 15 SECOND
      AND (mt.status='pending' OR (mt.status='completed' AND mt.created_at > NOW()-INTERVAL 1 DAY)
           OR (mt.result_desc LIKE '%15 minutes%' AND mt.created_at > NOW()-INTERVAL 7 DAY))
    ORDER BY mt.updated_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
foreach ($transactions as $tx) {
    try {
        $state = reconcileCustomerStk($pdo, $tx);
        $log('Checkout ' . $tx['checkout_request_id'] . ': ' . $state);
    } catch (Throwable $e) {
        $log('Recovery will retry: ' . $e->getMessage());
    }
}
$log('=== Done ===');
