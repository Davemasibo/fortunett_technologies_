<?php
require_once __DIR__.'/payouts.php';
require_once __DIR__.'/disbursements.php';

/** Only confirmed, unreleased platform receipts belong in a withdrawal. */
function tenantWithdrawableRows(PDO $pdo,int $tenantId,bool $lock=false): array {
    $st=$pdo->prepare("SELECT q.* FROM isp_payout_queue q JOIN payments p ON p.id=q.payment_id AND p.tenant_id=q.tenant_id
        WHERE q.tenant_id=? AND q.status='pending' AND p.status='completed' AND p.collection_type='platform'
        AND p.released_at IS NULL AND q.gross_amount>0 AND q.net_amount>0
        AND q.commission_amount>=0 AND q.net_amount<=q.gross_amount
        AND ABS(q.gross_amount-q.net_amount-q.commission_amount)<0.005
        AND ABS((p.amount-p.disbursed_amount)-q.gross_amount)<0.005 ORDER BY q.id".($lock?' FOR UPDATE':''));
    $st->execute([$tenantId]);return $st->fetchAll(PDO::FETCH_ASSOC);
}

function tenantWithdrawalState(PDO $pdo,int $tenantId): array {
    ensurePayoutTables($pdo);ensureDisbursementBalance($pdo);
    $st=$pdo->prepare('SELECT * FROM tenant_payout_settings WHERE tenant_id=?');$st->execute([$tenantId]);$settings=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    $rows=tenantWithdrawableRows($pdo,$tenantId);
    $net=round(array_sum(array_column($rows,'net_amount')),2);$send=floor($net);
    $pre=disbursementPreflight($pdo);
    $st=$pdo->prepare("SELECT id,status,sent_amount,created_at FROM isp_payout_batches WHERE tenant_id=? AND status IN ('sending','accepted','unknown') ORDER BY id DESC LIMIT 1");$st->execute([$tenantId]);$inFlight=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    $reason='';
    if(!$pre['ok'])$reason='M-Pesa withdrawals are not available yet. Platform payout setup is required.';
    elseif(empty($settings['payout_phone']) || empty($settings['verified_at']))$reason='Add and verify your payout number with the platform administrator.';
    elseif($inFlight)$reason='Your previous payout is still being confirmed.';
    elseif($send<max(1,(float)($settings['min_payout'] ?? 100)))$reason='Minimum withdrawal: KES '.number_format(max(1,(float)($settings['min_payout'] ?? 100)),2).'.';
    elseif(payoutSentToday($pdo,$tenantId)+$send>(float)($settings['max_daily'] ?? 50000))$reason='This payout exceeds your remaining daily withdrawal limit.';
    return ['ready'=>$reason==='','reason'=>$reason,'net'=>$net,'send_amount'=>$send,'phone'=>$settings['payout_phone'] ?? '',
        'name'=>$settings['payout_name'] ?? '', 'in_flight'=>$inFlight,'settings'=>$settings];
}

/** Shared by tenant withdrawals and the automatic sender; never retries an uncertain transfer. */
function submitTenantWithdrawal(PDO $pdo,int $tenantId,string $requestId,bool $automatic=false,?callable $sender=null,?array $expected=null): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$requestId))throw new InvalidArgumentException('Reload billing before requesting a payout.');
    ensurePayoutTables($pdo);ensureDisbursementBalance($pdo);
    $originator='FTN-W-'.$tenantId.'-'.$requestId;
    $lockName='tenant-withdrawal-'.$tenantId;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('A payout is already being prepared. Check your payout history.');
    try {
        $st=$pdo->prepare('SELECT id,status,sent_amount FROM isp_payout_batches WHERE tenant_id=? AND originator_conversation_id=?');$st->execute([$tenantId,$originator]);
        if($previous=$st->fetch(PDO::FETCH_ASSOC))return $previous;
        $state=tenantWithdrawalState($pdo,$tenantId);
        if(!$state['ready'])throw new RuntimeException($state['reason']);
        if($expected && ((float)$expected['amount']!==$state['send_amount'] || $expected['phone']!==$state['phone']))throw new RuntimeException('Your balance or payout destination changed. Reload billing and confirm the new details.');
        if($automatic && empty($state['settings']['auto_payout']))throw new RuntimeException('Automatic payouts are disabled for this tenant.');
        $pre=disbursementPreflight($pdo);
        if(!$pre['ok'])throw new RuntimeException('Platform payout setup is incomplete.');
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM tenant_payout_settings WHERE tenant_id=? FOR UPDATE');$st->execute([$tenantId]);
        $lockedSettings=$st->fetch(PDO::FETCH_ASSOC);
        if(!$lockedSettings || empty($lockedSettings['verified_at']) || $lockedSettings['payout_phone']!==$state['phone'])throw new RuntimeException('Your payout destination changed. Reload billing.');
        if(($automatic && empty($lockedSettings['auto_payout'])) || $state['send_amount']<(float)$lockedSettings['min_payout'] || payoutSentToday($pdo,$tenantId)+$state['send_amount']>(float)$lockedSettings['max_daily'])throw new RuntimeException('Payout limits changed. Reload billing.');
        $rows=tenantWithdrawableRows($pdo,$tenantId,true);
        $gross=round(array_sum(array_column($rows,'gross_amount')),2);$commission=round(array_sum(array_column($rows,'commission_amount')),2);$net=round(array_sum(array_column($rows,'net_amount')),2);$send=floor($net);
        if(!$rows || $send!==$state['send_amount'])throw new RuntimeException('Your available balance changed. Reload billing.');
        $ids=array_column($rows,'id');$in=implode(',',array_fill(0,count($ids),'?'));
        $pdo->prepare("INSERT INTO isp_payout_batches (tenant_id,originator_conversation_id,payout_phone,gross_amount,commission_amount,net_amount,sent_amount,queue_ids,status) VALUES (?,?,?,?,?,?,?,?,'sending')")
            ->execute([$tenantId,$originator,$state['phone'],$gross,$commission,$net,$send,implode(',',$ids)]);
        $batchId=(int)$pdo->lastInsertId();
        $claim=$pdo->prepare("UPDATE isp_payout_queue SET status='processing',batch_id=?,attempts=attempts+1 WHERE tenant_id=? AND id IN ($in) AND status='pending'");$claim->execute(array_merge([$batchId,$tenantId],$ids));
        if($claim->rowCount()!==count($ids))throw new RuntimeException('Another payout claimed these collections. Reload billing.');
        $pdo->commit();
        // From this point a transport failure must remain unknown, never retryable.
        try {
            if($sender)$res=$sender($state['phone'],$send,$originator,$pre['config']);
            else {
                require_once __DIR__.'/../classes/MpesaAPI.php';$cfg=$pre['config'];
                $mpesa=new MpesaAPI($pdo,null);$mpesa->loadFromArray($cfg);
                $mpesa->setInitiator((string)$cfg['initiator_name'],(string)$cfg['security_credential'],(string)(($cfg['b2c_shortcode'] ?? '') ?: ($cfg['shortcode'] ?? '')));
                $base=rtrim(payoutSetting($pdo,'public_base_url',''),'/');
                $res=$mpesa->b2cPayment($state['phone'],$send,'Tenant withdrawal',$originator,$base.'/api/payment/b2c_result.php',$base.'/api/payment/b2c_timeout.php');
            }
        }catch(Throwable $e){$res=['success'=>false,'accepted'=>null,'error'=>'Transfer outcome is unknown. Await confirmation before trying again.'];error_log('Tenant payout '.$batchId.': '.$e->getMessage());}
        if(!empty($res['success'])) {
            $pdo->prepare("UPDATE isp_payout_batches SET status='accepted',conversation_id=? WHERE id=? AND status='sending'")->execute([$res['conversation_id'] ?? null,$batchId]);
        }elseif(($res['accepted'] ?? null)===null) {
            $pdo->prepare("UPDATE isp_payout_batches SET status='unknown',result_desc=? WHERE id=? AND status='sending'")->execute([substr($res['error'] ?? 'Outcome unknown',0,255),$batchId]);
        }else {
            $pdo->beginTransaction();
            $st=$pdo->prepare("UPDATE isp_payout_batches SET status='failed',result_desc=?,completed_at=NOW() WHERE id=? AND status='sending'");$st->execute([substr($res['error'] ?? 'Payout rejected',0,255),$batchId]);
            if($st->rowCount())$pdo->prepare("UPDATE isp_payout_queue SET status='pending',batch_id=NULL,last_error=? WHERE tenant_id=? AND batch_id=? AND status='processing'")->execute([substr($res['error'] ?? 'Payout rejected',0,255),$tenantId,$batchId]);
            $pdo->commit();
        }
        $st=$pdo->prepare('SELECT id,status,sent_amount FROM isp_payout_batches WHERE id=? AND tenant_id=?');$st->execute([$batchId,$tenantId]);return $st->fetch(PDO::FETCH_ASSOC);
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}
