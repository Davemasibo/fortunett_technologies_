<?php
// Local, isolated browser regression fixture. Never uses application credentials.
if (PHP_SAPI !== 'cli') exit;
$testHost=getenv('DISBURSEMENT_TEST_HOST') ?: '127.0.0.1;port=3308';
$pdo = new PDO('mysql:host='.$testHost.';charset=utf8mb4', 'root', getenv('DISBURSEMENT_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_disbursement_browser_test';
if (in_array('--cleanup', $argv, true)) { $pdo->exec("DROP DATABASE IF EXISTS `$schema`"); exit; }
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$pdo->exec('CREATE TABLE users(id INT PRIMARY KEY, username VARCHAR(50), email VARCHAR(100), password_hash VARCHAR(255), is_super_admin TINYINT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?,?,1)')->execute(['browser-admin','browser@example.test',password_hash('Browser-test-only-42!',PASSWORD_DEFAULT)]);
$pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY,company_name VARCHAR(100))');
$pdo->exec("INSERT INTO tenants VALUES(9,'Ghetto Link (test)')");
$pdo->exec("CREATE TABLE payments(id INT PRIMARY KEY, tenant_id INT, amount DECIMAL(12,2), collection_type VARCHAR(20), status VARCHAR(20), payment_date DATETIME, created_at DATETIME, released_at DATETIME NULL, release_note VARCHAR(255)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES(1,9,15000,'platform','completed','2026-09-29 10:00:00','2026-09-29 10:00:00')");
$pdo->exec("CREATE TABLE isp_payout_queue(id INT PRIMARY KEY,tenant_id INT,payment_id INT,status VARCHAR(20),processed_at DATETIME NULL,notes TEXT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status) VALUES(1,9,1,'pending')");
$pdo->exec("ALTER TABLE tenants ADD subdomain VARCHAR(100), ADD admin_user_id INT, ADD status VARCHAR(20) DEFAULT 'active', ADD suspended_at DATETIME NULL, ADD suspended_reason VARCHAR(255) NULL");
$install = file_get_contents(__DIR__ . '/../sql/install.sql');
preg_match('/CREATE TABLE IF NOT EXISTS `platform_invoices`.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $install, $invoiceSchema);
$pdo->exec($invoiceSchema[0]);
$pdo->exec("INSERT INTO platform_invoices(id,invoice_number,tenant_id,billing_period,base_fee,status,due_date,paid_at,payment_method,transaction_ref) VALUES(9,'INV-2026-09-0009',9,'2026-09-01',637.48,'paid','2026-09-25','2026-09-25','manual','MANUAL-6B863B')");
if(in_array('--invoice-first',$argv,true)) {
    require_once __DIR__.'/../includes/platform_billing.php';
    ensurePlatformBillingSchema($pdo);ensureSmsInvoiceSchema($pdo);
    $pdo->exec("INSERT INTO platform_invoices(id,invoice_number,tenant_id,billing_period,base_fee,status,due_date,amount_paid) VALUES
        (10,'PART-PAID-FIRST',9,'2026-10-01',75,'pending','2026-11-15',25),
        (11,'INVOICE-FIRST',9,'2026-11-01',50,'pending','2026-11-15',0)");
}
if(in_array('--carryover',$argv,true)) {
    require_once __DIR__.'/../includes/platform_billing.php';
    $pdo->exec('ALTER TABLE tenants ADD subscription_plan_id INT DEFAULT 1');
    $pdo->exec('CREATE TABLE platform_subscription_plans(id INT PRIMARY KEY,pppoe_fee_per_user DECIMAL(10,2),hotspot_commission_rate DECIMAL(5,4),base_monthly_fee DECIMAL(10,2))');
    $pdo->exec('INSERT INTO platform_subscription_plans VALUES(1,0,0.09,0)');
    $pdo->exec('CREATE TABLE clients(id INT,tenant_id INT,status VARCHAR(20),connection_type VARCHAR(20))');
    $pdo->exec("INSERT INTO clients VALUES(40,9,'active','hotspot')");
    $pdo->exec('CREATE TABLE mikrotik_routers(id INT,tenant_id INT,last_seen DATETIME,status VARCHAR(20),created_at DATETIME)');
    $pdo->exec('ALTER TABLE payments ADD client_id INT');
    ensurePlatformBillingSchema($pdo);ensureSmsInvoiceSchema($pdo);
    $prior=date('Y-m-01',strtotime('first day of last month'));
    $st=$pdo->prepare("UPDATE platform_invoices SET invoice_number='EARLY-PAID',billing_period=?,base_fee=0,hotspot_collections=1000,hotspot_commission_rate=0.03,amount_paid=30 WHERE id=9");$st->execute([$prior]);
    $st=$pdo->prepare("INSERT INTO payments(id,tenant_id,client_id,amount,collection_type,status,payment_date,created_at) VALUES(?,9,40,?,'platform','completed',?,?)");
    $st->execute([2,1000,$prior,$prior]);$late=date('Y-m-d',strtotime($prior.' +27 days'));$st->execute([3,200,$late,$late]);
}
echo "Isolated browser fixture ready.\n";
