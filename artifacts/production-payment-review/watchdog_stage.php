<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/root/fortunett-router-expiry-release.php';
$router=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=new MikrotikAPI($router['vpn_ip'],$router['username'],$router['password'],(int)$router['api_port']);$api->connect();
$backup=['scheduler'=>routerCheckedCommand($api,'/system/scheduler/print',['?name=fn-paid-expiry-watchdog']),
 'script'=>routerCheckedCommand($api,'/system/script/print',['?name=fn-paid-expiry-watchdog'])];
$backupPath='/root/fortunett-watchdog-before-20261010.json';
if(!file_exists($backupPath)){file_put_contents($backupPath,json_encode($backup,JSON_PRETTY_PRINT));chmod($backupPath,0600);}
$stage='fn-paid-expiry-watchdog-v2-check';$stageId=null;
try{
 $source=str_replace('script="fn-paid-expiry-watchdog"','script="'.$stage.'"',paidExpiryWatchdogScript());
 routerCheckedCommand($api,'/system/script/add',['=name='.$stage,'=source='.$source,'=policy=read,write,test']);
 foreach(routerCheckedCommand($api,'/system/script/print',['?name='.$stage]) as $s)if(($s['name']??'')===$stage)$stageId=$s['.id'];
 if(!$stageId)throw new RuntimeException('Staged script was not recorded');
 $started=microtime(true);routerCheckedCommand($api,'/system/script/run',['=number='.$stageId]);
 echo json_encode(['native_script_run'=>'passed','duration_seconds'=>round(microtime(true)-$started,3),'router_backup'=>$backupPath]).PHP_EOL;
}finally{
 if($stageId)routerCheckedCommand($api,'/system/script/remove',['=.id='.$stageId]);
 $api->disconnect();
}
