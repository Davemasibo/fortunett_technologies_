<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=null;
for($i=0;$i<3;$i++){
 try{$api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);$api->connect();break;}
 catch(Throwable $e){$api->disconnect();$api=null;if($i===2)throw $e;}
}
$report=['recurring_schedulers'=>[],'running_jobs'=>[],'wireguard'=>[]];
foreach(routerCheckedCommand($api,'/system/scheduler/print',['=.proplist=name,interval,disabled,run-count']) as $row)if(isset($row['name'])&&($row['disabled']??'')==='false'&&($row['interval']??'0s')!=='0s')$report['recurring_schedulers'][]=$row;
foreach(routerCheckedCommand($api,'/system/script/job/print') as $row)if(isset($row['.id']))$report['running_jobs'][]=['id'=>$row['.id'],'script_name'=>isset($row['script'])&&preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$row['script'])?$row['script']:'inline/unnamed','started'=>$row['started']??null];
foreach(routerCheckedCommand($api,'/interface/wireguard/peers/print',['=.proplist=allowed-address,endpoint-address,endpoint-port,current-endpoint-address,last-handshake,persistent-keepalive,rx,tx,disabled']) as $row)if(isset($row['allowed-address']))$report['wireguard'][]=$row;
$api->disconnect();echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
