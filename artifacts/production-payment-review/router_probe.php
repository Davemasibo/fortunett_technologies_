<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$router=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=new MikrotikAPI($router['vpn_ip']?:$router['ip_address'],$router['username'],$router['password'],(int)$router['api_port']);
if(!$api->connect())throw new RuntimeException('Router unavailable');
$report=['router'=>19,'reachable'=>true,'clients'=>[]];
$clients=$pdo->query("SELECT c.id,c.mikrotik_username,c.status,c.expiry_date,c.last_seen,c.bound_mac_address,dc.mac_address,dc.updated_at FROM clients c LEFT JOIN hotspot_device_context dc ON dc.client_id=c.id AND dc.tenant_id=c.tenant_id WHERE c.tenant_id=9 AND c.status='active' AND c.expiry_date>NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach($clients as $c){
 $sessions=routerCheckedCommand($api,'/ip/hotspot/active/print',['?user='.$c['mikrotik_username']]);
 $user=routerCheckedCommand($api,'/ip/hotspot/user/print',['?name='.$c['mikrotik_username']]);
 $entry=['id'=>$c['id'],'expiry'=>$c['expiry_date'],'last_seen'=>$c['last_seen'],'device_context_at'=>$c['updated_at'],'router_user'=>null,'sessions'=>[]];
 foreach($user as $u)if(isset($u['.id']))$entry['router_user']=array_intersect_key($u,array_flip(['disabled','profile','uptime','limit-uptime']));
 foreach($sessions as $session)if(isset($session['.id']))$entry['sessions'][]=array_intersect_key($session,array_flip(['uptime','session-time-left','bytes-in','bytes-out','login-by']));
 $mac=$c['bound_mac_address']?:$c['mac_address'];
 $hosts=$mac?routerCheckedCommand($api,'/ip/hotspot/host/print',['?mac-address='.$mac]):[];
 $entry['device_visible']=count(array_filter($hosts,fn($h)=>isset($h['.id'])))>0;
 $report['clients'][]=$entry;
}
$api->disconnect();echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
