<?php
// Uses an isolated throwaway database; never calls activation, SMS or gateways.
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/db_master.php';
require __DIR__.'/migrate_payment_identity.php';
require __DIR__.'/../includes/payment_identity.php';
$rootSocket=in_array('--root-socket',$argv,true);
if($rootSocket)$pdo=new PDO('mysql:host=localhost;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='fortunett_identity_test_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$checks=0;
function verifyIdentity($ok,$message) {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";}
function rejectsIdentity(callable $fn) {try{$fn();return false;}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1062)throw $e;return true;}}
try {
 $pdo->exec("CREATE TABLE clients(id INT PRIMARY KEY) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE payments(id INT AUTO_INCREMENT PRIMARY KEY,tenant_id INT,client_id INT,amount DECIMAL(10,2),transaction_id VARCHAR(100),payment_method VARCHAR(30),collection_type VARCHAR(20),status VARCHAR(20)) ENGINE=InnoDB");
 $pdo->exec("CREATE TABLE mpesa_transactions(id INT AUTO_INCREMENT PRIMARY KEY,tenant_id INT,client_id INT,amount DECIMAL(10,2),checkout_request_id VARCHAR(100),mpesa_receipt_number VARCHAR(100),merchant_request_id VARCHAR(100),result_desc TEXT,result_code INT,status VARCHAR(20),payment_id INT) ENGINE=InnoDB");
 foreach(['client_invoices','ledger_entries','isp_payout_queue','platform_commissions','platform_payment_allocations','payment_auto_logins'] as $table)$pdo->exec("CREATE TABLE $table(payment_id INT) ENGINE=InnoDB");
 $pdo->exec("INSERT INTO mpesa_transactions(tenant_id,client_id,amount,checkout_request_id,mpesa_receipt_number,status,result_code) VALUES(9,1,20,'ws_CO_old','RECEIPT_OLD','completed',0)");
 $pdo->exec("INSERT INTO payments(tenant_id,client_id,amount,transaction_id,status) VALUES(9,1,20,'RECEIPT_OLD','completed'),(9,1,20,'ws_CO_old','completed')");
 migratePaymentIdentity($pdo,true);
 verifyIdentity($pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()==1,'proven duplicate archived');
 verifyIdentity($pdo->query('SELECT COUNT(*) FROM payment_duplicate_archive')->fetchColumn()==1,'original row retained in audit archive');
 $insert=fn($ref,$client=2)=>$pdo->prepare("INSERT INTO payments(tenant_id,client_id,amount,transaction_id,status) VALUES(9,?,20,?,'pending')")->execute([$client,$ref]);
 $insert('ws_CO_new');
 $pdo->exec("UPDATE payments SET transaction_id='RECEIPT_NEW',status='completed' WHERE transaction_id='ws_CO_new'");
 verifyIdentity(rejectsIdentity(fn()=>$insert('ws_CO_new')),'late pending writer cannot recreate checkout');
 verifyIdentity(rejectsIdentity(fn()=>$insert('RECEIPT_NEW')),'duplicate receipt rejected');
 verifyIdentity(rejectsIdentity(fn()=>$insert('RECEIPT_NEW',3)),'same receipt cannot fund a different customer');
 $pdo->exec("INSERT INTO mpesa_transactions(tenant_id,client_id,amount,checkout_request_id,mpesa_receipt_number) VALUES(9,2,20,'ws_CO_new','RECEIPT_NEW')");
 $identity=resolvePaymentIdentity($pdo,9,2,'ws_CO_new','ws_CO_new');
 verifyIdentity($identity['receipt']==='RECEIPT_NEW','stale query resolves final receipt');
 verifyIdentity(paymentIsManualTransaction(['merchant_request_id'=>'MANUAL-ABC','result_code'=>0]),'manual verified payment excluded from STK');
 verifyIdentity(paymentIsManualTransaction(['result_desc'=>'Manual:cash | counter']), 'legacy manual provenance recognised');
 $pdo->exec("INSERT INTO payments(tenant_id,client_id,amount,transaction_id,status) VALUES(10,2,20,'RECEIPT_NEW','completed')");
 verifyIdentity(true,'separate tenant reference scope preserved');
 // A competing connection waits for the registry key, then rejects on commit.
 $pdo->beginTransaction();$insert('CONCURRENT');
 $worker=tempnam(sys_get_temp_dir(),'identity-worker-');
 $code='<?php require '.var_export(realpath(__DIR__.'/../includes/db_master.php'),true).'; '.($rootSocket?'$pdo=new PDO("mysql:host=localhost;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);':'').' $pdo->exec('.var_export("USE `$schema`",true).'); try {$pdo->exec("INSERT INTO payments(tenant_id,client_id,amount,transaction_id,status) VALUES(9,2,20,\'CONCURRENT\',\'completed\')");exit(2);} catch(PDOException $e){exit(($e->errorInfo[1]??0)===1062?0:3);}';
 file_put_contents($worker,$code);chmod($worker,0600);
 $proc=proc_open([PHP_BINARY,$worker],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 usleep(300000);$pdo->commit();$result=proc_close($proc);unlink($worker);
 verifyIdentity($result===0,'concurrent writer rejected after first transaction commits');
 migratePaymentIdentity($pdo,true);
 verifyIdentity(true,'migration is repeatable');
 echo "$checks checks passed\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec("DROP DATABASE `$schema`");}
