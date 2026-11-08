<?php
// Isolated local test database. No application connection or real payments used.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../includes/disbursements.php';
date_default_timezone_set('Africa/Nairobi');
$port = (int)(getenv('DISBURSEMENT_TEST_PORT') ?: 3306);
$host = getenv('DISBURSEMENT_TEST_HOST') ?: '127.0.0.1';
$dsn=in_array('--root-socket',$argv,true)?'mysql:host=localhost;charset=utf8mb4':"mysql:host=$host;port=$port;charset=utf8mb4";
$pdo = new PDO($dsn, 'root', getenv('DISBURSEMENT_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
    $pdo->exec("CREATE TABLE platform_invoices(id INT PRIMARY KEY, tenant_id INT, invoice_number VARCHAR(30), total_due DECIMAL(15,2), status VARCHAR(20), paid_at DATETIME NULL, payment_method VARCHAR(50), transaction_ref VARCHAR(100), notes TEXT,amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,billing_period DATE DEFAULT '2026-09-01',due_date DATE DEFAULT '2026-09-30') ENGINE=InnoDB");
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
    $pdo->exec("ALTER TABLE tenants ADD status VARCHAR(20) DEFAULT 'active', ADD suspended_at DATETIME NULL, ADD suspended_reason VARCHAR(255) NULL");

    $pdo->exec("INSERT INTO platform_invoices(id,tenant_id,invoice_number,total_due,status,paid_at,payment_method,transaction_ref,notes) VALUES(9,1,'INV-2026-09-0009',637.48,'paid','2026-09-25','manual','MANUAL-6B863B',NULL),
        (10,1,'PAID-MPESA',20,'paid','2026-09-25','mpesa','REAL-PAYMENT',NULL),
        (11,2,'OTHER-TENANT',20,'pending',NULL,NULL,NULL,NULL),
        (12,1,'TOO-LARGE',900,'pending',NULL,NULL,NULL,NULL),
        (13,1,'OPEN-INVOICE',16.52,'pending',NULL,NULL,NULL,NULL)");
    $offset = ['cutoff'=>'2026-09-30T12:00','disbursed_at'=>'2026-09-30T12:00','cash_amount'=>'0','fees_amount'=>'637.48',
        'platform_cost'=>'0','notes'=>'Invoice deduction','expected_gross'=>'854','expected_count'=>1];
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$offset,9)), 'manual paid placeholder requires explicit no-cash confirmation');
    $offset['replace_manual'] = true;
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$offset,10)), 'separately paid invoice cannot be deducted again');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$offset,11)), 'invoice belonging to another tenant rejected');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($offset,['fees_amount'=>'900']),12)), 'invoice exceeding available collections rejected');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($offset,['fees_amount'=>'637']),9)), 'invoice deduction cannot be rounded or tampered');
    $offsetId = recordDisbursement($pdo,1,9,$offset,9);
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn() === 216.52, '637.48 invoice deduction leaves tenant exactly 216.52');
    $inv = $pdo->query('SELECT * FROM platform_invoices WHERE id=9')->fetch(PDO::FETCH_ASSOC);
    checkDisbursement($inv['status'] === 'paid' && $inv['payment_method'] === 'collections_offset' && str_contains($inv['notes'],'MANUAL-6B863B'), 'invoice stays paid and preserves original manual reference');
    $ledger = $pdo->query('SELECT * FROM tenant_disbursements WHERE id=' . $offsetId)->fetch(PDO::FETCH_ASSOC);
    checkDisbursement($ledger['cash_amount'] === '0.00' && $ledger['fees_amount'] === '637.48', 'invoice deduction does not invent a cash payout');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,$offset,9)), 'repeat invoice deduction blocked');
    $dashboardQuery->execute([1]);
    checkDisbursement((float)$dashboardQuery->fetch(PDO::FETCH_ASSOC)['awaiting'] === 216.52, 'tenant dashboard reflects invoice deduction');
    $pdo->exec("UPDATE tenants SET status='suspended' WHERE id=1");
    recordDisbursement($pdo,1,9,array_merge($offset,['fees_amount'=>'16.52','expected_gross'=>'216.52']),13);
    checkDisbursement($pdo->query('SELECT status FROM platform_invoices WHERE id=13')->fetchColumn() === 'paid', 'unpaid invoice can also be settled from collections');
    checkDisbursement($pdo->query('SELECT status FROM tenants WHERE id=1')->fetchColumn() === 'suspended', 'other open invoice prevents reactivation');
    checkDisbursement(rejectDisbursement(fn()=>recordDisbursement($pdo,1,9,array_merge($offset,['reference'=>'BLOCKED','payout_amount'=>'10','fees_amount'=>'0','expected_gross'=>'200']))),'manual payout blocked while an invoice remains unpaid');
    $pdo->exec("UPDATE platform_invoices SET status='cancelled' WHERE id=12");
    $manual=array_merge($offset,['reference'=>'NET-PAYOUT','payout_amount'=>'100','fees_amount'=>'5','cash_amount'=>'99999','notes'=>'','expected_gross'=>'200']);
    $manualId=recordDisbursement($pdo,1,9,$manual);
    $netRecord=$pdo->query('SELECT * FROM tenant_disbursements WHERE id='.$manualId)->fetch(PDO::FETCH_ASSOC);
    checkDisbursement($netRecord['gross_amount']==='105.00' && $netRecord['cash_amount']==='100.00' && $netRecord['fees_amount']==='5.00','full cash plus transaction cost is charged server-side and ignores forged cash');
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn()===95.0,'tenant balance reduces by full cash plus transaction cost');
    checkDisbursement(str_contains($netRecord['notes'],'5.00'),'transaction cost is recorded even without optional notes');
    checkDisbursement(rejectDisbursement(fn()=>manualPayoutAmounts(['payout_amount'=>'100','fees_amount'=>'100'])),'transaction cost cannot consume the whole payout');
    checkDisbursement(rejectDisbursement(fn()=>manualPayoutAmounts(['payout_amount'=>'100','fees_amount'=>'101'])),'transaction cost cannot exceed payout');
    require_once __DIR__.'/../includes/financial_corrections.php';
    ensureFinancialCorrectionSchema($pdo);
    $correction=['revision'=>financialRevision($netRecord),'cash_amount'=>'110','fees_amount'=>'5','platform_cost'=>'0','notes'=>'Full transfer received','reason'=>'Correct original cash'];
    correctFinancialRecord($pdo,'payout',$manualId,9,$correction);
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn()===85.0,'payout correction charges extra cash to tenant balance');
    checkDisbursement(rejectDisbursement(fn()=>correctFinancialRecord($pdo,'payout',$manualId,9,$correction)),'stale correction cannot be replayed');
    $new=$pdo->query('SELECT * FROM tenant_disbursements WHERE id='.$manualId)->fetch(PDO::FETCH_ASSOC);
    $correction['revision']=financialRevision($new);$correction['cash_amount']='1000';
    checkDisbursement(rejectDisbursement(fn()=>correctFinancialRecord($pdo,'payout',$manualId,9,$correction)),'correction cannot overdraw tenant funds');
    checkDisbursement((int)$pdo->query('SELECT COUNT(*) FROM financial_corrections')->fetchColumn()===1,'failed corrections leave no audit or partial updates');
    $correction['cash_amount']='90';
    correctFinancialRecord($pdo,'payout',$manualId,9,$correction);
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn()===105.0,'smaller corrected payout restores tenant funds');
    $pdo->exec("ALTER TABLE platform_invoices ADD pppoe_user_count INT DEFAULT 0, ADD pppoe_fee_per_user DECIMAL(12,2) DEFAULT 0, ADD hotspot_collections DECIMAL(12,2) DEFAULT 0, ADD hotspot_commission_rate DECIMAL(5,4) DEFAULT 0, ADD router_count INT, ADD router_fee_per_router DECIMAL(12,2), ADD base_fee DECIMAL(12,2) DEFAULT 0, ADD sms_amount DECIMAL(12,2) DEFAULT 0");
    $pdo->exec('UPDATE platform_invoices SET base_fee=total_due');
    $pdo->exec('ALTER TABLE platform_invoices MODIFY total_due DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count*pppoe_fee_per_user+hotspot_collections*hotspot_commission_rate+base_fee+sms_amount) STORED');
    $invoice=$pdo->query('SELECT * FROM platform_invoices WHERE id=9')->fetch(PDO::FETCH_ASSOC);
    $edit=['revision'=>financialRevision($invoice),'pppoe_user_count'=>'0','pppoe_fee_per_user'=>'0','hotspot_collections'=>'0','hotspot_commission_rate'=>'0','base_fee'=>'640','sms_amount'=>'0','due_date'=>'2026-09-30','notes'=>'Corrected bill','reason'=>'Correct monthly fee'];
    correctFinancialRecord($pdo,'invoice',9,9,$edit);
    checkDisbursement((float)$pdo->query('SELECT total_due FROM platform_invoices WHERE id=9')->fetchColumn()===640.0,'settled invoice amount can be corrected');
    checkDisbursement((float)$pdo->query('SELECT fees_amount FROM tenant_disbursements WHERE id='.$offsetId)->fetchColumn()===640.0,'invoice correction updates linked collections deduction');
    $balanceQuery->execute([1]);
    checkDisbursement((float)$balanceQuery->fetchColumn()===102.48,'invoice correction updates tenant balance by difference');
    $edit['revision']=financialRevision($pdo->query('SELECT * FROM platform_invoices WHERE id=10')->fetch(PDO::FETCH_ASSOC));
    checkDisbursement(rejectDisbursement(fn()=>correctFinancialRecord($pdo,'invoice',10,9,$edit)),'separate paid invoice amount cannot be overwritten');
    require_once __DIR__.'/../includes/invoice_collections.php';
    $pdo->exec("INSERT INTO platform_invoices(id,tenant_id,invoice_number,base_fee,total_due,status,amount_paid) VALUES(20,1,'PART-PAID',40,DEFAULT,'pending',10),(21,1,'UNPAID',20,DEFAULT,'pending',0)");
    $open=collectionInvoiceBalances($pdo,1);
    checkDisbursement(collectionInvoiceOutstanding($open)===5000,'invoice-first review deducts only unpaid balances');
    $eligible=disbursementPayments($pdo,1,'2026-09-30 23:59:59');
    $bulk=['cutoff'=>'2026-09-30 23:59:59','expected_gross'=>'102.48','expected_count'=>count($eligible),'invoice_revision'=>collectionInvoiceRevision($open)];
    checkDisbursement(rejectDisbursement(fn()=>settleCollectionInvoices($pdo,1,9,array_merge($bulk,['invoice_revision'=>'stale']))),'changed invoice preview rejected before deduction');
    checkDisbursement(settleCollectionInvoices($pdo,1,9,$bulk)===2,'all unpaid invoices settled in one transaction');
    checkDisbursement(collectionInvoiceBalances($pdo,1)===[],'settling invoices unlocks payouts');
    checkDisbursement((float)$pdo->query("SELECT fees_amount FROM tenant_disbursements WHERE reference='COLLECTIONS-INVOICE-20'")->fetchColumn()===30.0,'part-payment is not deducted a second time');
    checkDisbursement((float)$pdo->query('SELECT amount_paid FROM platform_invoices WHERE id=20')->fetchColumn()===40.0,'invoice records full paid balance after offset');
    $balanceQuery->execute([1]);checkDisbursement((float)$balanceQuery->fetchColumn()===52.48,'only post-invoice balance remains available for payout');
    checkDisbursement(rejectDisbursement(fn()=>settleCollectionInvoices($pdo,1,9,$bulk)),'invoice batch cannot be deducted twice');
    $pdo->exec("INSERT INTO platform_invoices(id,tenant_id,invoice_number,base_fee,total_due,status) VALUES(22,1,'SMALL-FIRST',10,DEFAULT,'pending'),(23,1,'TOO-BIG',100,DEFAULT,'pending')");
    $open=collectionInvoiceBalances($pdo,1);$bulk['invoice_revision']=collectionInvoiceRevision($open);$bulk['expected_gross']='52.48';$bulk['expected_count']=count(disbursementPayments($pdo,1,'2026-09-30 23:59:59'));
    checkDisbursement(rejectDisbursement(fn()=>settleCollectionInvoices($pdo,1,9,$bulk)),'insufficient collections block the full invoice batch');
    checkDisbursement(count(collectionInvoiceBalances($pdo,1))===2,'insufficient batch leaves both invoices untouched');
    $balanceQuery->execute([1]);checkDisbursement((float)$balanceQuery->fetchColumn()===52.48,'rejected batch preserves available collections');
    $pdo->exec("UPDATE platform_invoices SET status='cancelled' WHERE id IN (22,23)");
    $payoutRows=disbursementPayments($pdo,1,'2026-09-30 23:59:59');
    recordDisbursement($pdo,1,9,['reference'=>'CLEAR-REMAINDER','cutoff'=>'2026-09-30 23:59:59','disbursed_at'=>'2026-09-30 23:59:59','payout_amount'=>'52.48','fees_amount'=>'0','expected_gross'=>'52.48','expected_count'=>count($payoutRows)]);
    $pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES(200,1,10,'platform','completed','2026-09-01','2026-09-01'),(201,1,10,'platform','completed','2026-09-02','2026-09-02')");
    $pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status) VALUES(200,1,200,'pending'),(201,1,201,'processing')");
    $pdo->exec("INSERT INTO platform_invoices(id,tenant_id,invoice_number,base_fee,total_due,status) VALUES(24,1,'BATCH-FIRST',10,DEFAULT,'pending'),(25,1,'BATCH-SECOND',10,DEFAULT,'pending')");
    $open=collectionInvoiceBalances($pdo,1);$bulk['invoice_revision']=collectionInvoiceRevision($open);$bulk['expected_gross']='20';$bulk['expected_count']=2;
    checkDisbursement(rejectDisbursement(fn()=>settleCollectionInvoices($pdo,1,9,$bulk)),'in-flight second deduction rejects the invoice batch');
    checkDisbursement(count(collectionInvoiceBalances($pdo,1))===2 && (int)$pdo->query("SELECT COUNT(*) FROM tenant_disbursements WHERE reference IN ('COLLECTIONS-INVOICE-24','COLLECTIONS-INVOICE-25')")->fetchColumn()===0,'failed second deduction rolls back the first invoice and ledger');
    $balanceQuery->execute([1]);checkDisbursement((float)$balanceQuery->fetchColumn()===20.0,'failed second deduction restores all collection balances');
    echo "$checks checks passed.\n";
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
