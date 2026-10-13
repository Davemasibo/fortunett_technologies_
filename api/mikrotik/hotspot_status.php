<?php
/** Read-only hotspot checks, scoped to the signed-in tenant and selected router. */
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/hotspot_diagnostic_snapshot.php';
require_once __DIR__ . '/../../includes/router_expiry.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false,'error'=>'Sign in to run diagnostics.']); exit; }
$st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');$st->execute([$_SESSION['user_id']]);$tenantId=(int)$st->fetchColumn();
session_write_close();
$st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?');$st->execute([(int)($_POST['router_id']??0),$tenantId]);$router=$st->fetch(PDO::FETCH_ASSOC);
if(!$tenantId||!$router){ob_clean();echo json_encode(['success'=>false,'error'=>'Router not found for your tenant.']);exit;}
$st=$pdo->prepare("SELECT COUNT(*) total, SUM(status='active') active, SUM(status='active' AND COALESCE(mikrotik_username,'')='') missing FROM clients WHERE tenant_id=? AND COALESCE(NULLIF(connection_type,''),'hotspot')='hotspot'");$st->execute([$tenantId]);$counts=$st->fetch();
$result=['success'=>true,'reachable'=>false,'api_ok'=>false,'router_users'=>null,'active_sessions'=>null,'db_total'=>(int)$counts['total'],'db_active'=>(int)$counts['active'],'unprovisioned'=>(int)$counts['missing'],'walled_garden'=>[],'error'=>null];
$api=null;
try{
    $api=new MikrotikAPI($router['vpn_ip']?:$router['ip_address'],$router['username'],$router['password'],$router['api_port']?:8728);
    $api->connect();$result['reachable']=true;$result['api_ok']=true;
    $snapshot=[];
    foreach(['servers'=>'/ip/hotspot/print','profiles'=>'/ip/hotspot/profile/print','addresses'=>'/ip/address/print','dhcp'=>'/ip/dhcp-server/print','networks'=>'/ip/dhcp-server/network/print','dns'=>'/ip/dns/print','files'=>'/file/print','garden'=>'/ip/hotspot/walled-garden/print','garden_ip'=>'/ip/hotspot/walled-garden/ip/print','users'=>'/ip/hotspot/user/print','sessions'=>'/ip/hotspot/active/print'] as $key=>$command){
        try{$snapshot[$key]=array_values(array_filter(routerCheckedCommand($api,$command),fn($r)=>isset($r['!re'])));}
        catch(Throwable $e){$result['read_errors'][$key]='Could not read router data; check API permissions and connection.';}
    }
    $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');$st->execute([$tenantId]);
    $domain='fortunetttech.site';try{$domain=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain'")->fetchColumn()?:$domain;}catch(Throwable $e){}
    $host=$st->fetchColumn().'.'.$domain;$ips=array_values(array_unique(gethostbynamel($host)?:[]));
    $result=array_merge($result,hotspotDiagnosticSnapshot($snapshot,$host,$ips));
    $result['checked_at']=date(DATE_ATOM);$result['portal_host']=$host;$result['portal_ips']=$ips;
    $result['router_users']=isset($snapshot['users'])?count($snapshot['users']):null;
    $result['active_sessions']=isset($snapshot['sessions'])?count($snapshot['sessions']):null;
    // Compatibility with existing router widgets using this endpoint.
    $server=$snapshot['servers'][0]??[];
    $result['hotspot_server']=!empty($server) && ($server['disabled']??'false')!=='true' && ($server['invalid']??'false')!=='true' ? ($server['name'].' on '.$server['interface']) : false;
    $result['login_deployed']=false;
    foreach($result['checks'] as $check)if($check['id']==='login')$result['login_deployed']=$check['level']==='ok';
    $result['walled_garden']=array_values(array_filter(array_column($snapshot['garden']??[],'dst-host')));
}catch(Throwable $e){$result['success']=false;$result['error']='Router connection failed: '.$e->getMessage();}
finally{if($api)$api->disconnect();}
ob_clean();echo json_encode($result);
