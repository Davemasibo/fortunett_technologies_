<?php
/** Uses connection-local temporary tables. No real payout API is called. */
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/db_master.php';
require __DIR__.'/../includes/tenant_payouts.php';
function withdrawalAssert(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
ensurePayoutTables($pdo);ensureDisbursementBalance($pdo);
$paymentTemplate=$pdo->query('SELECT * FROM payments LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$queueTemplate=$pdo->query('SELECT * FROM isp_payout_queue LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$config=$pdo->query('SELECT * FROM platform_mpesa_config LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if(!$paymentTemplate || !$queueTemplate || !$config)throw new RuntimeException('Existing table templates are required.');
foreach(['payments','isp_payout_queue','isp_payout_batches','tenant_payout_settings','platform_settings','platform_mpesa_config','payment_reset_receipts'] as $table){
    if($table==='payment_reset_receipts')$pdo->exec('CREATE TEMPORARY TABLE payment_reset_receipts (tenant_id INT,reference VARCHAR(255))');
    else {
        $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
        $ddl=preg_replace('/,?\n\s*CONSTRAINT[^\n]+/','',$ddl);
        $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
        $pdo->exec($ddl);
    }
}
function insertWithdrawalFixture(PDO $pdo,string $table,array $row): void {
    $columns=array_keys($row);$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
}
$config=array_merge($config,['consumer_key'=>'test','consumer_secret'=>'test','initiator_name'=>'test','security_credential'=>'test','environment'=>'production','shortcode'=>'600000']);
insertWithdrawalFixture($pdo,'platform_mpesa_config',$config);
$pdo->exec("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('payouts_enabled','1'),('public_base_url','https://example.test')");
$tid=987654;
$pdo->prepare("INSERT INTO tenant_payout_settings (tenant_id,payout_phone,payout_name,auto_payout,min_payout,max_daily,verified_at) VALUES (?,'254712345678','Test',0,1,50000,NOW())")->execute([$tid]);
$reset=function()use($pdo,$paymentTemplate,$queueTemplate,$tid){
    $pdo->exec('DELETE FROM isp_payout_queue');$pdo->exec('DELETE FROM isp_payout_batches');$pdo->exec('DELETE FROM payments');
    $p=array_merge($paymentTemplate,['id'=>901,'tenant_id'=>$tid,'amount'=>'102.50','status'=>'completed','collection_type'=>'platform','released_at'=>null,'disbursed_amount'=>'0.00','transaction_id'=>'test-withdrawal']);
    insertWithdrawalFixture($pdo,'payments',$p);
    $q=array_merge($queueTemplate,['id'=>902,'tenant_id'=>$tid,'payment_id'=>901,'gross_amount'=>'102.50','commission_amount'=>'1.53','net_amount'=>'100.97','status'=>'pending','batch_id'=>null,'attempts'=>0]);
    insertWithdrawalFixture($pdo,'isp_payout_queue',$q);
};
$reset();$calls=0;
$accepted=function($phone,$amount,$id,$cfg)use(&$calls){$calls++;withdrawalAssert($phone==='254712345678' && $amount===100.0,'Sender uses verified destination and whole-shilling available balance');return ['success'=>true,'accepted'=>true,'conversation_id'=>'TEST-CONVERSATION'];};
$key=str_repeat('a',32);$batch=submitTenantWithdrawal($pdo,$tid,$key,false,$accepted);
withdrawalAssert($batch['status']==='accepted' && $calls===1,'Tenant can initiate a payout without enabling automatic payouts');
$again=submitTenantWithdrawal($pdo,$tid,$key,false,$accepted);
withdrawalAssert($again['id']===$batch['id'] && $calls===1,'Repeated submission does not send a second payment');
$blocked=false;try{submitTenantWithdrawal($pdo,$tid,str_repeat('b',32),false,$accepted);}catch(RuntimeException $e){$blocked=true;}
withdrawalAssert($blocked && $calls===1,'An in-flight payout blocks another request');
$st=$pdo->prepare('SELECT * FROM isp_payout_batches WHERE id=?');$st->execute([$batch['id']]);$full=$st->fetch(PDO::FETCH_ASSOC);
$pdo->beginTransaction();settlePayoutBatch($pdo,$full,'Test callback');$pdo->prepare("UPDATE isp_payout_batches SET status='paid',completed_at=NOW() WHERE id=?")->execute([$batch['id']]);$pdo->commit();
$remaining=$pdo->query('SELECT amount-disbursed_amount remaining,released_at FROM payments WHERE id=901')->fetch(PDO::FETCH_ASSOC);
withdrawalAssert(abs((float)$remaining['remaining']-.97)<.001 && $remaining['released_at']===null,'Fractional balance stays held instead of being silently settled');
$q=$pdo->query('SELECT * FROM isp_payout_queue WHERE id=902')->fetch(PDO::FETCH_ASSOC);
withdrawalAssert($q['status']==='pending' && (float)$q['commission_amount']===0.0 && abs((float)$q['net_amount']-.97)<.001,'Remaining cents are available next time without a second commission');
$pdo->beginTransaction();$n=settlePayoutBatch($pdo,$full,'Duplicate callback');$pdo->commit();
withdrawalAssert($n===0,'A repeated settlement cannot consume the carried balance');
$reset();$calls=0;
$blocked=false;try{submitTenantWithdrawal($pdo,$tid,str_repeat('e',32),false,fn()=>throw new RuntimeException('Must not send'),['amount'=>101,'phone'=>'254712345678']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'changed');}
withdrawalAssert($blocked,'Withdrawal cannot exceed the amount the tenant confirmed');
$pdo->exec("UPDATE tenant_payout_settings SET verified_at=NULL");
withdrawalAssert(!tenantWithdrawalState($pdo,$tid)['ready'],'Unverified payout destinations cannot receive withdrawals');
$pdo->exec("UPDATE tenant_payout_settings SET verified_at=NOW(),max_daily=50");
withdrawalAssert(!tenantWithdrawalState($pdo,$tid)['ready'],'Daily withdrawal cap is enforced');
$pdo->exec("UPDATE tenant_payout_settings SET max_daily=50000");
$unknown=function()use(&$calls){$calls++;throw new RuntimeException('Simulated transport loss');};
$batch=submitTenantWithdrawal($pdo,$tid,str_repeat('c',32),false,$unknown);
withdrawalAssert($batch['status']==='unknown' && $pdo->query('SELECT status FROM isp_payout_queue')->fetchColumn()==='processing','Uncertain transfer remains reserved and cannot be automatically resent');
$reset();
$batch=submitTenantWithdrawal($pdo,$tid,str_repeat('d',32),false,fn()=>['success'=>false,'accepted'=>false,'error'=>'Test rejection']);
withdrawalAssert($batch['status']==='failed' && $pdo->query('SELECT status FROM isp_payout_queue')->fetchColumn()==='pending','Explicit provider rejection restores the available queue');
$reset();$pdo->exec("UPDATE payments SET collection_type='direct'");
withdrawalAssert(tenantWithdrawableRows($pdo,$tid)===[],'Tenant-owned direct collections cannot be withdrawn from the platform');
$pdo->exec("INSERT INTO payment_reset_receipts VALUES (987654,'old-receipt')");
require_once __DIR__.'/../includes/payment_resets.php';
withdrawalAssert(paymentWasReset($pdo,$tid,'old-receipt') && !paymentWasReset($pdo,5,'old-receipt'),'Reset receipt protection is tenant scoped');
require_once __DIR__.'/../includes/payment_pipeline.php';
$ignored=process_payment_success($pdo,123,$tid,20,'old-receipt');
withdrawalAssert(!empty($ignored['steps']['reset_receipt_ignored']) && $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()==1,'An old reset callback cannot recreate a payment or reactivate a customer');
echo "All withdrawal checks passed. Temporary fixtures disappear on connection close.\n";
