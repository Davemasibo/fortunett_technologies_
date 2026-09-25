<?php
// Local regression test using a disposable database, without application data.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/platform_billing.php';
$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_billing_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
function checkBilling($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
try {
    $pdo->exec("USE `$schema`");
    $pdo->exec('CREATE TABLE tenants (id INT PRIMARY KEY, subscription_plan_id INT)');
    $pdo->exec('CREATE TABLE platform_subscription_plans (id INT PRIMARY KEY, pppoe_fee_per_user DECIMAL(10,2), hotspot_commission_rate DECIMAL(5,4), base_monthly_fee DECIMAL(10,2))');
    $migration = file_get_contents(__DIR__ . '/../run_migration.php');
    preg_match('/CREATE TABLE IF NOT EXISTS platform_invoices \(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $migration, $match);
    $pdo->exec($match[0]);
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY, tenant_id INT, status VARCHAR(20), connection_type VARCHAR(20))');
    $pdo->exec('CREATE TABLE payments (client_id INT, tenant_id INT, amount DECIMAL(10,2), status VARCHAR(20), payment_date DATETIME)');
    $pdo->exec('INSERT INTO platform_subscription_plans VALUES (1,25,0.03,500)');
    $pdo->exec('INSERT INTO tenants VALUES (1,1),(2,NULL),(3,1)');
    $pdo->exec("INSERT INTO clients VALUES (1,1,'active','pppoe'),(2,1,'active','pppoe'),(3,1,'active','hotspot'),(4,1,'inactive','pppoe')");
    $pdo->exec("INSERT INTO payments VALUES (3,1,1000,'completed',NOW()),(3,1,9000,'failed',NOW())");
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling($inv && (float)$inv['base_fee'] === 500.0 && (float)$inv['total_due'] === 580.0, 'new invoice includes base + active PPPoE + completed hotspot commission');
    $pdo->exec('UPDATE platform_invoices SET base_fee = 0 WHERE tenant_id = 1');
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 580.0, 'existing unpaid invoice repairs missing base fee');
    $pdo->exec('UPDATE platform_subscription_plans SET base_monthly_fee = 750');
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 830.0, 'current unpaid invoice picks up plan changes');
    checkBilling((int)$pdo->query('SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = 1')->fetchColumn() === 1, 'repeated refresh does not duplicate invoices');
    $pdo->exec('UPDATE platform_invoices SET amount_paid = 100 WHERE tenant_id = 1');
    $pdo->exec('UPDATE platform_subscription_plans SET base_monthly_fee = 900');
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 830.0 && (float)$inv['amount_paid'] === 100.0, 'part-paid invoice retains its agreed charges');
    foreach (['paid', 'waived', 'cancelled'] as $status) {
        $pdo->exec("UPDATE platform_invoices SET status = '$status', amount_paid = 0 WHERE tenant_id = 1");
        $inv = ensureCurrentPlatformInvoice($pdo, 1);
        checkBilling((float)$inv['total_due'] === 830.0, "$status invoice is preserved");
    }
    $inv = ensureCurrentPlatformInvoice($pdo, 2);
    checkBilling((float)$inv['base_fee'] === 0.0 && (float)$inv['total_due'] === 0.0, 'unassigned plan defaults to zero base fee');
    $inv = ensureCurrentPlatformInvoice($pdo, 3);
    checkBilling((float)$inv['total_due'] === 900.0, 'base fee is charged even without usage');
} finally {
    $pdo->exec("DROP DATABASE `$schema`");
}
