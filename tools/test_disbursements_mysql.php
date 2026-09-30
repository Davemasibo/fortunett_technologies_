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
    // Reproduce the earliest deployed schema: no platform-cost column and only
    // one disbursement item allowed per payment.
    $pdo->exec("CREATE TABLE tenant_disbursements(id INT AUTO_INCREMENT PRIMARY KEY,tenant_id INT NOT NULL,
        reference VARCHAR(100),gross_amount DECIMAL(12,2),cash_amount DECIMAL(12,2),fees_amount DECIMAL(12,2),
        disbursed_at DATETIME,recorded_by INT,notes VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY tenant_reference(tenant_id,reference)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE tenant_disbursement_items(disbursement_id INT,payment_id INT PRIMARY KEY,amount DECIMAL(12,2),KEY disbursement_id(disbursement_id)) ENGINE=InnoDB');
    ensureDisbursementSchema($pdo);
    checkDisbursement(count($pdo->query("SHOW INDEX FROM tenant_disbursement_items WHERE Key_name='PRIMARY'")->fetchAll()) === 2, 'legacy item keys upgraded for partial payouts');
    checkDisbursement((bool)$pdo->query("SHOW COLUMNS FROM tenant_disbursements LIKE 'platform_cost'")->fetch(), 'missing platform-cost column repaired on existing deployments');
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
    checkDisbursement(disbursementMoney('12,306.00') === 1230600, 'formatted amounts accepted without changing their value');
    checkDisbursement(disbursementDate('2026-02-28') === '2026-02-28 23:59:59', 'calendar date includes the selected day');
    checkDisbursement(disbursementDate('2026-02-28T15:04') === '2026-02-28 15:04:00', 'browser date-time without seconds accepted');
    checkDisbursement(substr(disbursementDate(date('Y-m-d')),0,10) === date('Y-m-d'), 'today is valid and not treated as a future date');
    checkDisbursement(rejectDisbursement(fn()=>disbursementDate('2026-02-30')), 'impossible dates rejected');
    checkDisbursement(rejectDisbursement(fn()=>disbursementDate('2099-01-01')), 'future dates rejected');
    $partial = array_merge($input,['reference'=>'PARTIAL-1','cash_amount'=>'123.06','fees_amount'=>'0','platform_cost'=>'50',
        'cutoff'=>'2026-03-31','disbursed_at'=>'2026-03-31','expected_gross'=>'800','expected_count'=>1]);
    $pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status) VALUES(6,1,6,'pending')");
    recordDisbursement($pdo,1,9,$partial);
    $remaining = disbursementPayments($pdo,1,'2026-03-31 23:59:59');
    checkDisbursement(count($remaining) === 1 && $remaining[0]['amount'] === '676.94', 'partial payout reduces outstanding balance by cash only');
    checkDisbursement($pdo->query('SELECT released_at FROM payments WHERE id=6')->fetchColumn() === null, 'partially settled payment remains outstanding');
    checkDisbursement($pdo->query('SELECT status FROM isp_payout_queue WHERE id=6')->fetchColumn() === 'cancelled', 'automatic queue cannot resend original amount after a partial payout');
    $billing = file_get_contents(__DIR__ . '/../billing.php');
    preg_match('/prepare\("(SELECT COALESCE\(SUM\(amount - disbursed_amount\),0\).*?released_at IS NULL)"\)/s', $billing, $balanceSql);
    $balanceQuery = $pdo->prepare($balanceSql[1]); $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn() === 676.94, 'actual tenant billing query deducts partial disbursements');
    $final = array_merge($partial,['reference'=>'PARTIAL-2','cash_amount'=>'666.94','fees_amount'=>'10','expected_gross'=>'676.94']);
    recordDisbursement($pdo,1,9,$final);
    checkDisbursement(disbursementPayments($pdo,1,'2026-03-31 23:59:59') === [], 'remaining balance can be settled by a later transfer');
    checkDisbursement($pdo->query('SELECT SUM(amount) FROM tenant_disbursement_items WHERE payment_id=6')->fetchColumn() === '800.00', 'multiple disbursements preserve the full payment audit');
    checkDisbursement($pdo->query('SELECT disbursed_amount FROM payments WHERE id=6')->fetchColumn() === '800.00', 'partial allocations add up exactly without double counting');
    // The tenant's report splits a partially settled payment into actual portions.
    $source = file_get_contents(__DIR__ . '/../api/reports/summary.php');
    preg_match('/\$out\[\x27by_route\x27\] = \$q\("(.*?)"\);/s', $source, $reportSql);
    $report = $pdo->prepare($reportSql[1]); $report->execute([1,'2026-01-01','2026-04-01']);
    $routes = array_column($report->fetchAll(PDO::FETCH_ASSOC), 'total', 'name');
    checkDisbursement((float)$routes['disbursed'] === 1100.0 && (float)$routes['paid_to_you'] === 500.0, 'tenant reporting preserves released and direct totals');
    $migration = file_get_contents(__DIR__ . '/../sql/migrations/2026-09-30-partial-disbursements.sql');
    $ledgerBefore = $pdo->query('SELECT COUNT(*) FROM tenant_disbursement_items')->fetchColumn();
    for ($run = 0; $run < 2; $run++) {
        foreach (explode(';', $migration) as $sql) {
            if (trim($sql) !== '') $pdo->query($sql)->closeCursor();
        }
    }
    checkDisbursement($pdo->query('SELECT COUNT(*) FROM tenant_disbursement_items')->fetchColumn() === $ledgerBefore, 'SQL migration is repeatable and preserves existing payout history');
    // Exact payout-time boundary: no later same-day collection may be released.
    $pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES
        (10,1,100,'platform','completed','2026-09-30 11:16:59','2026-09-30 11:16:59'),
        (11,1,200,'platform','completed','2026-09-30 11:17:00','2026-09-30 11:17:00'),
        (12,1,854,'platform','completed','2026-09-30 11:17:01','2026-09-30 11:17:01')");
    $timed = array_merge($input, ['reference'=>'TIMED-PAYOUT','cutoff'=>'2026-09-30T11:17',
        'disbursed_at'=>'2026-09-30T11:17','cash_amount'=>'300','fees_amount'=>'0','expected_gross'=>'300','expected_count'=>2]);
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($timed,['cutoff'=>'2026-09-30T11:18']))), 'cutoff later than transfer on the same day rejected');
    recordDisbursement($pdo,1,9,$timed);
    checkDisbursement($pdo->query("SELECT COUNT(*) FROM payments WHERE id IN (10,11) AND released_at='2026-09-30 11:17:00'")->fetchColumn() == 2, 'collections through 11:17 are marked disbursed at the exact transfer time');
    checkDisbursement($pdo->query('SELECT released_at FROM payments WHERE id=12')->fetchColumn() === null, 'collection one second after cutoff awaits next payout');
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn() === 854.0, 'tenant billing retains later same-day collections');
    $dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
    preg_match('/prepare\("(SELECT\s+COALESCE\(SUM\(CASE WHEN released_at IS NULL.*?collection_type = \'platform\')"\)/s', $dashboard, $dashboardSql);
    $dashboardQuery = $pdo->prepare($dashboardSql[1]); $dashboardQuery->execute([1]);
    $balances = $dashboardQuery->fetch(PDO::FETCH_ASSOC);
    checkDisbursement((float)$balances['awaiting'] === 854.0 && (float)$balances['disbursed'] === 1400.0, 'tenant dashboard agrees with payout and billing balances');
    echo "$checks checks passed.\n";
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
