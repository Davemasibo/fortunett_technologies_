<?php
// Isolated local test database. No application connection or real payments used.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../includes/disbursements.php';
date_default_timezone_set('Africa/Nairobi');
$port = (int)(getenv('DISBURSEMENT_TEST_PORT') ?: 3306);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_disbursement_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$checks = 0;
function checkDisbursement($ok, $message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS $message\n"; }
function rejectDisbursement(callable $fn) { try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; } }
try {
    $pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE payments(id INT PRIMARY KEY, tenant_id INT, amount DECIMAL(12,2), collection_type VARCHAR(20), status VARCHAR(20), payment_date DATETIME, created_at DATETIME, released_at DATETIME NULL, release_note VARCHAR(255)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE isp_payout_queue(id INT PRIMARY KEY, tenant_id INT, payment_id INT, status VARCHAR(20), processed_at DATETIME NULL, notes TEXT) ENGINE=InnoDB");
    ensureDisbursementSchema($pdo);
    $pdo->exec('INSERT INTO tenants VALUES(1),(2)');
    $pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES
        (1,1,100,'platform','completed','2026-01-31 23:59:59','2026-01-31 23:59:59'),
        (2,1,200,'platform','completed','2026-02-01','2026-02-01'),
        (3,1,500,'direct','completed','2026-02-01','2026-02-01'),
        (4,1,600,'platform','pending','2026-02-01','2026-02-01'),
        (5,2,700,'platform','completed','2026-02-01','2026-02-01'),
        (6,1,800,'platform','completed','2026-03-01','2026-03-01')");
    $pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status) VALUES(1,1,1,'pending'),(2,1,2,'pending')");
    $input = ['reference'=>'TRANSFER-1','notes'=>'Transfer charge','cutoff'=>'2026-02-28 23:59:59', 'disbursed_at'=>'2026-03-01 00:00:00','cash_amount'=>'250.00','fees_amount'=>'50.00','platform_cost'=>'50.00','expected_gross'=>'300.00','expected_count'=>2];
    checkDisbursement(count(disbursementPayments($pdo,1,$input['cutoff'])) === 2, 'preview excludes other tenants, direct, pending and later payments');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($input,['cash_amount'=>'301']))), 'overpayment rejected');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($input,['expected_gross'=>'200']))), 'stale preview rejected');
    $pdo->exec("UPDATE isp_payout_queue SET status='processing' WHERE id=1");
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$input)), 'in-flight B2C payout blocked');
    checkDisbursement($pdo->query('SELECT COUNT(*) FROM tenant_disbursements')->fetchColumn() == 0, 'failed requests leave no ledger entries');
    $pdo->exec("UPDATE isp_payout_queue SET status='pending' WHERE id=1");
    recordDisbursement($pdo,1,9,$input);
    checkDisbursement($pdo->query('SELECT COUNT(*) FROM payments WHERE released_at IS NOT NULL')->fetchColumn() == 2, 'only selected collections settled across month boundary');
    checkDisbursement($pdo->query("SELECT COUNT(*) FROM isp_payout_queue WHERE status='paid'")->fetchColumn() == 2, 'automatic queue cannot resend manually settled payments');
    checkDisbursement($pdo->query('SELECT COUNT(*) FROM tenant_disbursement_items')->fetchColumn() == 2, 'payment-level audit preserved');
    $r = $pdo->query('SELECT * FROM tenant_disbursements')->fetch(PDO::FETCH_ASSOC);
    checkDisbursement($r['cash_amount'] === '250.00' && $r['fees_amount'] === '50.00' && $r['gross_amount'] === '300.00', 'cash and transfer charge recorded separately');
    checkDisbursement($r['platform_cost'] === '50.00', 'platform-funded charge does not increase the tenant deduction');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$input)), 'duplicate reference rejected');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($input,['reference'=>'TRANSFER-2']))), 'repeat submission with different reference cannot settle twice');
    checkDisbursement(array_column(disbursementPayments($pdo,1,'2026-03-31 23:59:59'),'id') == [6], 'later collections remain available for next payout');
    checkDisbursement(rejectDisbursement(fn()=>disbursementMoney('1.001')), 'fractional cents rejected');
    echo "$checks checks passed.\n";
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
