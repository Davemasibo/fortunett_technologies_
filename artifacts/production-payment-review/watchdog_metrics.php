<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);$api->connect();
$report=['sampled_at'=>date(DATE_ATOM),'samples'=>[]];
for($i=0;$i<6;$i++){
 $start=microtime(true);$cpu=null;
 foreach(routerCheckedCommand($api,'/system/resource/print',['=.proplist=cpu-load,free-memory,uptime']) as $row)if(isset($row['cpu-load']))$cpu=(int)$row['cpu-load'];
 $jobs=routerCheckedCommand($api,'/system/script/job/print',['=.proplist=.id,script']);
 $report['samples'][]=['cpu_percent'=>$cpu,'api_read_ms'=>round((microtime(true)-$start)*1000),'script_jobs'=>count(array_filter($jobs,fn($j)=>isset($j['.id'])))];
 if($i<5)sleep(2);
}
$report['profile']=[];
foreach(routerCheckedCommand($api,'/tool/profile',['=duration=3s']) as $row)if(isset($row['name']))$report['profile'][]=array_intersect_key($row,array_flip(['name','usage']));
$report['enabled_hotspot_users']=count(array_filter(routerCheckedCommand($api,'/ip/hotspot/user/print',['?disabled=false','=.proplist=.id,disabled']),fn($u)=>isset($u['.id'])));
$report['sessions']=[];
foreach(routerCheckedCommand($api,'/ip/hotspot/active/print',['=.proplist=.id,bytes-in,bytes-out,uptime']) as $s)if(isset($s['.id']))$report['sessions'][]=array_intersect_key($s,array_flip(['bytes-in','bytes-out','uptime']));
$api->disconnect();echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
