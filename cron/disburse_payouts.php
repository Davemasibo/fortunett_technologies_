<?php
/** Dry run by default. --live uses the same reservation path as tenant withdrawals. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/tenant_payouts.php';
require_once __DIR__.'/../includes/cron_heartbeat.php';
$live=in_array('--live',$argv,true);$tenantId=0;
foreach($argv as $arg)if(str_starts_with($arg,'--tenant='))$tenantId=(int)substr($arg,9);
cron_heartbeat($pdo,'disburse_payouts');ensurePayoutTables($pdo);
$pre=disbursementPreflight($pdo);
echo ($live?'LIVE':'DRY RUN — no money sent')."\n";
if(!$pre['ok']) {
    echo implode("\n",$pre['reasons'])."\n";
    if($live)exit(1);
}
$sql="SELECT DISTINCT tenant_id FROM isp_payout_queue WHERE status='pending'".($tenantId?' AND tenant_id=?':'');
$st=$pdo->prepare($sql);$st->execute($tenantId?[$tenantId]:[]);
foreach($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    try {
        $gate=tenantPayoutGate($pdo,(int)$tid);
        if(!$gate['ok']){echo "Tenant $tid: ".$gate['reason']."\n";continue;}
        $state=tenantWithdrawalState($pdo,(int)$tid);
        if(!$state['ready']){echo "Tenant $tid: ".$state['reason']."\n";continue;}
        if(!$live){echo "Tenant $tid: would send KES ".number_format($state['send_amount'],2)."\n";continue;}
        $batch=submitTenantWithdrawal($pdo,(int)$tid,bin2hex(random_bytes(16)),true);
        echo "Tenant $tid: batch ".$batch['id'].' '.$batch['status']."\n";
    }catch(Throwable $e){echo "Tenant $tid: ".$e->getMessage()."\n";error_log('Automatic tenant payout: '.$e->getMessage());}
}
