<?php
// Run after the invoice-first browser fixture/test. Only this named test schema is used.
if(PHP_SAPI!=='cli')exit;
$settings=['DB_HOST'=>getenv('DISBURSEMENT_TEST_HOST')?:'127.0.0.1;port=3306','DB_NAME'=>'fortunett_disbursement_browser_test','DB_USER'=>'root','DB_PASS'=>getenv('DISBURSEMENT_TEST_PASSWORD')?:''];
foreach($settings as $key=>$value){putenv($key.'='.$value);$_SERVER[$key]=$value;$_ENV[$key]=$value;}
require_once __DIR__.'/../includes/tenant_payouts.php';
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='fortunett_disbursement_browser_test')throw new RuntimeException('Isolated fixture required.');
function invoiceWithdrawalCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$pdo->exec("CREATE TABLE platform_mpesa_config(id INT PRIMARY KEY,consumer_key VARCHAR(50),consumer_secret VARCHAR(50),environment VARCHAR(20),shortcode VARCHAR(20))");
$pdo->exec("INSERT INTO platform_mpesa_config VALUES(1,'test','test','production','600000')");
$pdo->exec("CREATE TABLE platform_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT)");
$pdo->exec("INSERT INTO platform_settings VALUES('payouts_enabled','1'),('public_base_url','https://example.test')");
$pdo->exec('ALTER TABLE isp_payout_queue ADD gross_amount DECIMAL(12,2) DEFAULT 0,ADD commission_amount DECIMAL(12,2) DEFAULT 0,ADD net_amount DECIMAL(12,2) DEFAULT 0');
ensurePayoutTables($pdo);
$pdo->exec("UPDATE platform_mpesa_config SET initiator_name='test',security_credential='test'");
$pdo->exec("INSERT INTO tenant_payout_settings(tenant_id,payout_phone,auto_payout,min_payout,max_daily,verified_at) VALUES(9,'254712345678',1,100,50000,NOW())");
$pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES(2,9,200,'platform','completed','2026-10-01','2026-10-01')");
$pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status,gross_amount,commission_amount,net_amount) VALUES(2,9,2,'pending',200,0,200)");
$pdo->exec("INSERT INTO platform_invoices(id,invoice_number,tenant_id,billing_period,base_fee,status,due_date) VALUES(12,'WITHDRAWAL-HOLD',9,'2026-12-01',50,'pending','2026-12-15')");
$calls=0;$sender=function()use(&$calls){$calls++;return ['success'=>true,'conversation_id'=>'TEST-ONLY'];};
$state=tenantWithdrawalState($pdo,9);
invoiceWithdrawalCheck(!$state['ready'] && str_contains($state['reason'],'Outstanding invoices'),'tenant withdrawal state explains invoice hold');
foreach([false,true] as $automatic){
    $blocked=false;try{submitTenantWithdrawal($pdo,9,bin2hex(random_bytes(16)),$automatic,$sender);}catch(InvalidArgumentException|RuntimeException $e){$blocked=str_contains($e->getMessage(),'invoices');}
    invoiceWithdrawalCheck($blocked && $calls===0,($automatic?'automatic':'tenant').' payout cannot call sender while invoices are unpaid');
}
$pdo->exec("UPDATE platform_invoices SET status='paid',amount_paid=total_due WHERE id=12");
invoiceWithdrawalCheck(tenantWithdrawalState($pdo,9)['ready'],'settled invoice removes tenant withdrawal hold');
$batch=submitTenantWithdrawal($pdo,9,bin2hex(random_bytes(16)),true,$sender);
invoiceWithdrawalCheck($batch['status']==='accepted' && $calls===1,'automatic payout proceeds through mocked sender only after invoices are settled');
