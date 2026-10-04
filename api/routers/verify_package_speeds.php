<?php
ob_start();ini_set('display_errors',0);
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../classes/MikrotikAPI.php';
require_once __DIR__.'/../../includes/package_profile.php';
header('Content-Type: application/json');
$api=null;
try {
    if(!isLoggedIn())throw new RuntimeException('Sign in to verify package speeds.');
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');$st->execute([$_SESSION['user_id']]);$tenant=(int)$st->fetchColumn();
    $id=(int)($_POST['router_id']??0);
    $st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?');$st->execute([$id,$tenant]);$router=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tenant || !$router)throw new RuntimeException('Router not found.');
    $api=new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));$api->connect();
    $bypass=false;foreach(routerCheckedCommand($api,'/ip/firewall/filter/print',['?action=fasttrack-connection']) as $rule)if(isset($rule['.id']) && ($rule['disabled']??'false')!=='true')$bypass=true;
    foreach(routerCheckedCommand($api,'/ip/firewall/connection/print',['?fasttrack=true','=.proplist=.id,fasttrack']) as $flow)if(($flow['fasttrack']??'false')==='true')$bypass=true;
    $st=$pdo->prepare("SELECT DISTINCT c.id,c.mikrotik_username,c.connection_type,p.* FROM clients c JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id JOIN router_services rs ON rs.client_id=c.id AND rs.tenant_id=c.tenant_id WHERE c.tenant_id=? AND rs.router_id=? AND c.mikrotik_username IS NOT NULL");$st->execute([$tenant,$id]);$customers=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$customers[]=auditCustomerPackageSpeed($api,$row['connection_type'],$row['mikrotik_username'],$row);
    $result=['success'=>true,'router'=>$router['name'],'fasttrack_disabled'=>!$bypass,'customers'=>$customers,'all_active_sessions_verified'=>!$bypass && count($customers)>0 && !array_filter($customers,fn($customer)=>!$customer['live_verified'])];
} catch(Throwable $e){$result=['success'=>false,'error'=>$e->getMessage()];}
finally {if($api)$api->disconnect();}
ob_clean();echo json_encode($result);
