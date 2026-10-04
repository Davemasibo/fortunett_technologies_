<?php
// Local regression test using a disposable database, without application data.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/platform_billing.php';
$pdo = new PDO(in_array('--root-socket',$argv,true)?'mysql:host=localhost;charset=utf8mb4':'mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_billing_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
function checkBilling($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
try {
    $pdo->exec("USE `$schema`");
    $pdo->exec("CREATE TABLE tenants (id INT PRIMARY KEY, subscription_plan_id INT, status VARCHAR(20) DEFAULT 'active')");
    $pdo->exec('CREATE TABLE platform_subscription_plans (id INT PRIMARY KEY, pppoe_fee_per_user DECIMAL(10,2), hotspot_commission_rate DECIMAL(5,4), base_monthly_fee DECIMAL(10,2))');
    $migration = file_get_contents(__DIR__ . '/../run_migration.php');
    preg_match('/CREATE TABLE IF NOT EXISTS platform_invoices \(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $migration, $match);
    $pdo->exec($match[0]);
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY, tenant_id INT, status VARCHAR(20), connection_type VARCHAR(20))');
    $pdo->exec('CREATE TABLE payments (client_id INT, tenant_id INT, amount DECIMAL(10,2), status VARCHAR(20), payment_date DATETIME)');
    $pdo->exec('INSERT INTO platform_subscription_plans VALUES (1,25,0.03,500)');
    $pdo->exec("INSERT INTO tenants VALUES (1,1,'active'),(2,NULL,'active'),(3,1,'active'),(4,1,'trial'),(5,1,'active'),(6,1,'active')");
    $pdo->exec('CREATE TABLE mikrotik_routers(id INT PRIMARY KEY,tenant_id INT,status VARCHAR(20),last_seen DATETIME,created_at DATETIME)');
    $pdo->exec("INSERT INTO mikrotik_routers VALUES(1,1,'active',NOW(),NOW()),(2,1,'offline',NOW(),NOW()),(3,1,'pending',NULL,NOW()),(4,1,'active',NULL,NOW()),(5,4,'active',NOW(),NOW()),(6,4,'offline',NOW(),NOW()),(7,5,'pending',NULL,NOW()),(8,6,'inactive',NOW(),NOW()),(9,1,'active',NOW(),DATE_ADD(NOW(),INTERVAL 1 MONTH))");
    $pdo->exec("INSERT INTO clients VALUES (1,1,'active','pppoe'),(2,1,'active','pppoe'),(3,1,'active','hotspot'),(4,1,'inactive','pppoe')");
    $pdo->exec("INSERT INTO payments VALUES (3,1,1000,'completed',NOW()),(3,1,9000,'failed',NOW())");
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling($inv && (float)$inv['base_fee'] === 1000.0 && (float)$inv['total_due'] === 1080.0, 'two configured routers multiply monthly rate; usage fees stay separate');
    $pdo->exec('UPDATE platform_invoices SET base_fee = 0 WHERE tenant_id = 1');
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 1080.0, 'existing unpaid invoice repairs missing per-router fee');
    $pdo->exec('UPDATE platform_subscription_plans SET base_monthly_fee = 750');
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 1580.0, 'current unpaid invoice picks up plan changes');
    checkBilling((int)$pdo->query('SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = 1')->fetchColumn() === 1, 'repeated refresh does not duplicate invoices');
    $pdo->exec('UPDATE platform_invoices SET amount_paid = 100 WHERE tenant_id = 1');
    $pdo->exec('UPDATE platform_subscription_plans SET base_monthly_fee = 900');
    $pdo->exec("INSERT INTO mikrotik_routers VALUES(10,1,'active',NOW(),NOW())");
    $inv = ensureCurrentPlatformInvoice($pdo, 1);
    checkBilling((float)$inv['total_due'] === 1580.0 && (float)$inv['amount_paid'] === 100.0, 'part-paid invoice retains its agreed charges');
    foreach (['paid', 'waived', 'cancelled'] as $status) {
        $pdo->exec("UPDATE platform_invoices SET status = '$status', amount_paid = 0 WHERE tenant_id = 1");
        $inv = ensureCurrentPlatformInvoice($pdo, 1);
        checkBilling((float)$inv['total_due'] === 1580.0, "$status invoice is preserved");
    }
    checkBilling((int)$inv['router_count']===2 && (float)$inv['router_fee_per_router']===750.0,'paid and partly paid invoices retain router count and unit-rate snapshots');
    checkBilling(platformConfiguredRouterCount($pdo,1)===3,'pending, never-connected and next-month devices are excluded; offline devices count');
    checkBilling(ensureCurrentPlatformInvoice($pdo,2)===null,'unassigned plan with no billable usage creates no invoice');
    checkBilling(ensureCurrentPlatformInvoice($pdo,3)===null,'no configured routers means no base-only invoice');
    checkBilling(ensureCurrentPlatformInvoice($pdo,5)===null,'pending router placeholders incur no base fee');
    $inv=ensureCurrentPlatformInvoice($pdo,6);
    checkBilling((float)$inv['base_fee']===900.0 && (int)$inv['router_count']===1,'previously configured offline router retains its monthly fee without usage');
    checkBilling(ensureCurrentPlatformInvoice($pdo,4)===null,'trial with two configured routers and no collections has no invoice');
    $pdo->exec("INSERT INTO clients VALUES(5,4,'active','hotspot'),(6,4,'active','pppoe'),(7,4,'active','pppoe')");
    $pdo->exec("INSERT INTO payments VALUES(5,4,1000,'completed',NOW()),(6,4,25,'completed',NOW())");
    $inv=ensureCurrentPlatformInvoice($pdo,4);
    checkBilling((float)$inv['base_fee']===0.0 && (int)$inv['router_count']===0 && (float)$inv['total_due']===55.0,'trial collections bill usage only, never a router base fee');
    $pdo->exec("INSERT INTO platform_invoices(invoice_number,tenant_id,billing_period,base_fee,due_date) VALUES('LEGACY-3',3,CURRENT_DATE-INTERVAL(DAY(CURRENT_DATE)-1) DAY,900,CURRENT_DATE)");
    $inv=ensureCurrentPlatformInvoice($pdo,3);
    checkBilling((float)$inv['base_fee']===0.0 && (float)$inv['total_due']===0.0 && $inv['status']==='paid','unpaid base-only charge corrected for an account with no configured router');
} finally {
    $pdo->exec("DROP DATABASE `$schema`");
}
