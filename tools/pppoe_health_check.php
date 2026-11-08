<?php
// CLI only. SELECTs and an explicit allowlist of RouterOS print commands; no repairs.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
if(in_array('--help',$argv,true)){
    echo "Read-only PPPoE diagnostic\nphp tools/pppoe_health_check.php --inventory\nphp tools/pppoe_health_check.php --tenant=EXACT_SUBDOMAIN [--router=ID]\n";exit;
}
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/pppoe_health.php';
require_once __DIR__.'/../includes/cron_heartbeat.php';
require_once __DIR__.'/../classes/MikrotikAPI.php';
$tenantKey='';$routerId=0;
foreach($argv as $arg){if(str_starts_with($arg,'--tenant='))$tenantKey=substr($arg,9);if(str_starts_with($arg,'--router='))$routerId=(int)substr($arg,9);}
$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
try {
    if($tenantKey===''){
        $rows=$pdo->query("SELECT t.id,t.company_name,t.subdomain,
            (SELECT COUNT(*) FROM mikrotik_routers r WHERE r.tenant_id=t.id) AS routers,
            (SELECT COUNT(*) FROM clients c WHERE c.tenant_id=t.id AND c.connection_type='pppoe') AS pppoe_customers
            FROM tenants t ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['mode'=>'read_only_inventory','tenants'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit;
    }
    $st=$pdo->prepare('SELECT id,company_name,subdomain FROM tenants WHERE subdomain=?');$st->execute([$tenantKey]);$tenant=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tenant)throw new RuntimeException('Tenant not found. Run --inventory to obtain the exact subdomain.');
    $sql='SELECT * FROM mikrotik_routers WHERE tenant_id=?';$params=[$tenant['id']];
    if($routerId>0){$sql.=' AND id=?';$params[]=$routerId;}
    $st=$pdo->prepare($sql.' ORDER BY id');$st->execute($params);$routers=$st->fetchAll(PDO::FETCH_ASSOC);
    $report=['mode'=>'read_only','tenant'=>$tenant,'checked_at'=>date(DATE_ATOM),'routers'=>[],
        'retry_worker'=>cron_last_run($pdo,'retry_provisions')];
    foreach($routers as $router){
        $item=['id'=>(int)$router['id'],'name'=>$router['name'],'last_recorded_status'=>$router['status'],'last_seen'=>$router['last_seen']??null];
        $api=new MikrotikAPI(($router['vpn_ip']??'')?:$router['ip_address'],$router['username'],$router['password'],(int)(($router['api_port']??0)?:8728));
        try{
            if(!$api->isReachable(3) || !$api->connect())throw new RuntimeException('Router unreachable or API login failed.');
            $item['reachable']=true;$item['inspection']=pppoeHealthReport($api);
        }catch(Throwable $e){$item['inspection_complete']=false;$item['status']='unknown';$item['note']='Could not finish a read-only router inspection. Check VPN reachability, API login and read permissions; no sessions were changed.';}
        finally{$api->disconnect();}
        $report['routers'][]=$item;
    }
    if(!$routers)$report['note']='No matching routers found for this tenant.';
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,"Diagnostic could not complete. Check the selected tenant and database availability; no changes were made.\n");exit(1);}
finally{if($pdo->inTransaction())$pdo->rollBack();}
