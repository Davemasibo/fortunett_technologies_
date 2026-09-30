<?php
// Isolated MySQL test; never loads the application database or calls gateways.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/payment_activation.php';
require_once __DIR__ . '/../includes/payment_terms.php';
date_default_timezone_set('Africa/Nairobi');
$port = (int)(getenv('PAYMENT_TEST_PORT') ?: 3306);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_advance_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$checks = 0;
function advanceCheck($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS $label\n"; }
try {
    $pdo->exec("CREATE TABLE clients (id INT PRIMARY KEY, tenant_id INT, status VARCHAR(20), package_id INT,
        expiry_date DATETIME NULL, account_balance DECIMAL(12,2) DEFAULT 0,
        expiry_reminder_3d_sent INT DEFAULT 0, expiry_reminder_1d_sent INT DEFAULT 0) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE pending_provisions (tenant_id INT, client_id INT, package_id INT, receipt VARCHAR(191),
        fail_reason TEXT, attempts INT DEFAULT 1, next_retry_at DATETIME, PRIMARY KEY(tenant_id, client_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE packages (id INT PRIMARY KEY, tenant_id INT, price DECIMAL(12,2), validity_value INT, validity_unit VARCHAR(20))");
    $pdo->exec("INSERT INTO packages VALUES(9,2,1500,1,'months')");
    $future = date('Y-m-d H:i:s', strtotime('+7 days'));
    $insert = $pdo->prepare("INSERT INTO clients(id,tenant_id,status,package_id,expiry_date,account_balance) VALUES(?,2,?,9,?,?)");
    $insert->execute([1,'active',$future,0]);
    $insert->execute([2,'expired','2000-01-01',0]);
    $insert->execute([3,'inactive',null,0]);
    $insert->execute([4,'active',$future,500]);
    $package = ['id'=>9,'price'=>1500,'validity_value'=>1,'validity_unit'=>'months'];
    $r = activatePaidSubscription($pdo,1,2,'checkout-1','receipt-1',$package,3000);
    advanceCheck($r['periods'] === 2 && $r['expiry_date'] === packageExpiryFrom(2,'months',$future), 'double payment grants two months after existing expiry');
    $firstExpiry = $r['expiry_date'];
    $r = activatePaidSubscription($pdo,1,2,'checkout-1','receipt-1',$package,3000);
    advanceCheck($r['already_applied'] && $r['expiry_date'] === $firstExpiry, 'duplicate callback grants no extra time');
    $r = activatePaidSubscription($pdo,1,2,'receipt-1','receipt-1',$package,3000);
    advanceCheck($r['already_applied'], 'receipt-only replay also cannot double-extend');
    $r = activatePaidSubscription($pdo,1,2,'receipt-2','receipt-2',$package,1500);
    advanceCheck($r['expiry_date'] === packageExpiryFrom(1,'months',$firstExpiry), 'separate early renewal preserves all prepaid time');
    $start = time();
    $r = activatePaidSubscription($pdo,2,2,'expired','expired',$package,3000);
    advanceCheck(abs(strtotime($r['expiry_date']) - strtotime(packageExpiryFrom(2,'months',$start))) <= 1, 'expired customer receives two months from payment time');
    $r = activatePaidSubscription($pdo,3,2,'partial','partial',$package,500);
    advanceCheck($r['periods'] === 0 && (float)$r['balance'] === 500.0 && $r['expiry_date'] === null, 'underpayment stays as credit without granting free access');
    advanceCheck($pdo->query('SELECT COUNT(*) FROM pending_provisions WHERE client_id=3')->fetchColumn() == 0, 'credit-only payment does not queue activation');
    $r = activatePaidSubscription($pdo,3,2,'partial','partial',$package,500);
    advanceCheck($r['already_applied'] && (float)$pdo->query('SELECT account_balance FROM clients WHERE id=3')->fetchColumn() === 500.0, 'credit is idempotent');
    $r = activatePaidSubscription($pdo,3,2,'complete','complete',$package,1000);
    advanceCheck($r['periods'] === 1 && (float)$r['balance'] === 0.0, 'later payment combines with credit to buy a full period');
    $r = activatePaidSubscription($pdo,4,2,'with-credit','with-credit',$package,2750);
    advanceCheck($r['periods'] === 2 && (float)$r['balance'] === 250.0, 'existing balance is spent once and excess stays as credit');
    $terms = preparePaymentTerms($pdo,9,2);
    recordPaymentTerms($pdo,'snapshot',1,2,$terms);
    $pdo->exec("UPDATE packages SET price=3000,validity_value=2 WHERE id=9");
    $saved = loadPaymentTerms($pdo,'snapshot',1,2);
    advanceCheck((float)$saved['package_price'] === 1500.0 && (int)$saved['validity_value'] === 1, 'checkout preserves both price and duration after package edits');
    advanceCheck(loadPaymentTerms($pdo,'snapshot',1,3) === null, 'checkout terms remain tenant scoped');
    $decimal = subscriptionPurchase(['price'=>0.29,'validity_value'=>1,'validity_unit'=>'days'],0.58);
    advanceCheck($decimal['periods'] === 2 && (float)$decimal['balance'] === 0.0, 'decimal prices grant exact full periods');
    $days = subscriptionPurchase(['price'=>1500,'validity_value'=>30,'validity_unit'=>'days'],3000);
    advanceCheck($days['validity_value'] === 60, 'two 30-day packages grant exactly 60 days');
    advanceCheck(packageExpiryFrom(1,'months','2027-01-31 12:34:56') === '2027-02-28 12:34:56', 'calendar months clamp to the last valid day');
    advanceCheck(packageExpiryFrom(1,'months','2028-01-31 12:34:56') === '2028-02-29 12:34:56', 'calendar month extension respects leap years');
    $before = $pdo->query('SELECT * FROM clients WHERE id=4')->fetch(PDO::FETCH_ASSOC);
    $pdo->exec('DROP TABLE pending_provisions');
    try { activatePaidSubscription($pdo,4,2,'rollback','rollback',$package,3000); throw new RuntimeException('Expected failure'); }
    catch (PDOException $e) { /* deliberately missing retry table */ }
    advanceCheck($pdo->query('SELECT * FROM clients WHERE id=4')->fetch(PDO::FETCH_ASSOC) === $before, 'provision queue failure rolls back both balance and expiry');
    advanceCheck($pdo->query("SELECT COUNT(*) FROM payment_subscription_grants WHERE activation_key='rollback'")->fetchColumn() == 0, 'failed activation leaves no grant audit');
    echo "$checks advance-payment checks passed.\n";
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
