<?php
/** Read-only aggregate evidence; does not send STK/SMS or alter customer access. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/sms_verify.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
$checks = [
    'payments_by_state' => "SELECT status,COUNT(*) AS records,COALESCE(SUM(amount),0) AS amount FROM payments GROUP BY status",
    'completed_with_failed_provider' => "SELECT COUNT(*) FROM payments p WHERE p.status='completed' AND EXISTS (SELECT 1 FROM mpesa_transactions mt WHERE mt.tenant_id=p.tenant_id AND mt.client_id=p.client_id AND (mt.checkout_request_id=p.checkout_request_id OR mt.checkout_request_id=p.transaction_id OR mt.mpesa_receipt_number=p.transaction_id) AND mt.status='failed')",
    'pending_with_confirmed_provider' => "SELECT COUNT(*) FROM payments p WHERE p.status='pending' AND EXISTS (SELECT 1 FROM mpesa_transactions mt WHERE mt.tenant_id=p.tenant_id AND mt.client_id=p.client_id AND (mt.checkout_request_id=p.checkout_request_id OR mt.checkout_request_id=p.transaction_id OR mt.mpesa_receipt_number=p.transaction_id) AND mt.status='completed' AND mt.result_code=0)",
    'pending_with_failed_provider' => "SELECT COUNT(*) FROM payments p WHERE p.status='pending' AND EXISTS (SELECT 1 FROM mpesa_transactions mt WHERE mt.tenant_id=p.tenant_id AND mt.client_id=p.client_id AND (mt.checkout_request_id=p.checkout_request_id OR mt.checkout_request_id=p.transaction_id) AND mt.status='failed')",
    'provisioning_backlog' => 'SELECT COUNT(*) AS records,MAX(attempts) AS max_attempts FROM pending_provisions',
    'sms_states' => 'SELECT status,COUNT(*) AS records FROM sms_outbox GROUP BY status',
    'routers' => 'SELECT id,tenant_id FROM mikrotik_routers',
];
$report = ['checked_at'=>date(DATE_ATOM)];
foreach (['stk_poll','retry_provisions'] as $job) {
    $report['automation'][$job] = cron_last_run($pdo, $job);
}
foreach (['payments','mpesa_transactions'] as $table) {
    $columns = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    $report['missing_columns'][$table] = array_values(array_diff(
        $table === 'payments' ? ['checkout_request_id'] : ['tenant_id','mpesa_receipt_number'], $columns));
    // Older ledgers still link by transaction_id; audit them without migration.
    if ($table === 'payments' && !in_array('checkout_request_id', $columns, true)) {
        foreach ($checks as &$sql) $sql = str_replace('mt.checkout_request_id=p.checkout_request_id OR ', '', $sql);
        unset($sql);
    }
}
foreach ($checks as $key=>$sql) {
    try { $report[$key] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    catch (PDOException $e) { $report[$key] = ['unavailable'=>true,'sqlstate'=>$e->getCode()]; }
}
$report['sms_configuration'] = [];
foreach ($pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN) as $tenant) {
    $state = smsVerifyTenant($pdo, (int)$tenant, false);
    $report['sms_configuration'][] = array_intersect_key($state, array_flip(['tenant_id','source','sender_id','verdict']));
}
echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
