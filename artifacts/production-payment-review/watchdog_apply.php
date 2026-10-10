<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);$api->connect();
try {
 installPaidExpiryWatchdog($api);
 routerCheckedCommand($api,'/system/script/run',['=number=fn-paid-expiry-watchdog']);
 echo json_encode(['installed'=>true,'native_run'=>'passed','interval_seconds'=>5]).PHP_EOL;
}catch(Throwable $e){
 $backup=json_decode(file_get_contents('/root/fortunett-watchdog-before-20261010.json'),true);
 foreach($backup['scheduler'] as $row)if(($row['name']??'')==='fn-paid-expiry-watchdog'){
  routerCheckedCommand($api,'/system/scheduler/set',['=.id='.$row['.id'],'=on-event='.$row['on-event'],'=interval='.$row['interval'],'=disabled='.($row['disabled']==='false'?'no':'yes')]);
 }
 throw $e;
}finally{$api->disconnect();}
