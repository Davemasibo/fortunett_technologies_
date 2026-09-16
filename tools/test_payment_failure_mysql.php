<?php
/** Isolated MySQL regression: no gateway, SMS or router requests. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/stk_reconciliation.php';
$schema = 'fortunett_failure_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
function failureCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
try {
    $pdo->exec('CREATE TABLE payments (id INT PRIMARY KEY,tenant_id INT,client_id INT,transaction_id VARCHAR(100),checkout_request_id VARCHAR(100),status VARCHAR(20),amount DECIMAL(10,2),payment_date DATETIME)');
    $pdo->exec('CREATE TABLE mpesa_transactions (id INT PRIMARY KEY,tenant_id INT,client_id INT,checkout_request_id VARCHAR(100),status VARCHAR(20))');
    $pdo->exec("INSERT INTO payments VALUES (1,1,1,'checkout-a','checkout-a','pending',100,NOW()),(2,1,1,'receipt-b','checkout-b','pending',200,NOW()),(3,1,1,'receipt-c','checkout-c','completed',300,NOW()),(4,2,1,'checkout-a','checkout-a','pending',400,NOW()),(5,1,1,'checkout-d','checkout-d','pending',500,NOW())");
    $pdo->exec("INSERT INTO mpesa_transactions VALUES (1,1,1,'checkout-a','failed'),(2,1,1,'checkout-b','failed'),(3,1,1,'checkout-c','failed'),(5,1,1,'checkout-d','completed')");
    foreach ([1,2,3,5] as $id) stkFailPendingPayment($pdo, ['id'=>$id,'tenant_id'=>1,'client_id'=>1]);
    $states = $pdo->query('SELECT id,status FROM payments ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    failureCheck($states === [1=>'failed',2=>'failed',3=>'completed',4=>'pending',5=>'pending'], 'Failure sync matches checkout aliases, preserves completed money and isolates tenants');
    // Execute the actual dashboard total queries against mixed payment outcomes.
    $source = file_get_contents(__DIR__ . '/../dashboard.php');
    preg_match_all('/prepare\("(SELECT COALESCE\(SUM\(amount\),0\) FROM payments[^"\r\n]+)"\)/', $source, $queries);
    failureCheck(count($queries[1]) === 3, 'Located daily, monthly and yearly dashboard total queries');
    foreach ($queries[1] as $sql) {
        $stmt = $pdo->prepare($sql); $stmt->execute([1]);
        failureCheck((float)$stmt->fetchColumn() === 300.0, 'Dashboard excludes pending and failed amounts');
    }
    $pdo->exec("UPDATE payments SET status='failed' WHERE id=3");
    foreach ($queries[1] as $sql) {
        $stmt = $pdo->prepare($sql); $stmt->execute([1]);
        failureCheck((float)$stmt->fetchColumn() === 0.0, 'No completed payments means zero dashboard revenue');
    }
    $pdo->exec('ALTER TABLE mpesa_transactions ADD created_at DATETIME, ADD updated_at DATETIME, ADD result_desc TEXT, ADD mpesa_receipt_number VARCHAR(100)');
    $pdo->exec('UPDATE mpesa_transactions SET created_at=NOW()-INTERVAL 2 DAY,updated_at=NOW()-INTERVAL 2 DAY');
    $cron = file_get_contents(__DIR__ . '/../cron/stk_poll.php');
    preg_match('/query\("(SELECT mt\.\* FROM mpesa_transactions mt.*?)"\)/s', $cron, $selection);
    failureCheck(isset($selection[1]), 'Located actual STK worker selection');
    $selected = $pdo->query($selection[1])->fetchAll(PDO::FETCH_COLUMN);
    failureCheck(array_map('intval', $selected) === [5], 'Confirmed but unfinished activation remains recoverable after one day');
    $pdo->exec("UPDATE payments SET status='completed' WHERE id=5");
    failureCheck($pdo->query($selection[1])->fetchAll() === [], 'Old completed ledger entries do not occupy the recovery batch');
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
