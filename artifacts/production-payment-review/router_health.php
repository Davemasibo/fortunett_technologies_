<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);
$api->connect();$report=[];
foreach(routerCheckedCommand($api,'/system/resource/print') as $row)if(isset($row['uptime']))$report['resource']=array_intersect_key($row,array_flip(['uptime','version','cpu-load','free-memory','total-memory','architecture-name','board-name']));
foreach(routerCheckedCommand($api,'/ip/service/print',['?name=api']) as $row)if(isset($row['name']))$report['api_service']=array_intersect_key($row,array_flip(['disabled','port','address','max-sessions']));
$report['hotspot_sessions']=count(array_filter(routerCheckedCommand($api,'/ip/hotspot/active/print'),fn($r)=>isset($r['.id'])));
$report['tcp_api_connections']=count(array_filter(routerCheckedCommand($api,'/ip/firewall/connection/print',['?dst-address=10.200.200.19:8728']),fn($r)=>isset($r['.id'])));
$report['relevant_firewall_rules']=[];
foreach(routerCheckedCommand($api,'/ip/firewall/filter/print') as $row){
 if(($row['chain']??'')!=='input')continue;
 $report['relevant_firewall_rules'][]=array_intersect_key($row,array_flip(['chain','action','protocol','dst-port','src-address','src-address-list','connection-limit','limit','disabled','comment','bytes','packets']));
}
$report['profile']=[];
foreach(routerCheckedCommand($api,'/tool/profile',['=duration=3s']) as $row)if(isset($row['name']))$report['profile'][]=array_intersect_key($row,array_flip(['name','usage','cpu']));
$report['paid_scheduler_count']=count(array_filter(routerCheckedCommand($api,'/system/scheduler/print'),fn($row)=>str_starts_with($row['name']??'','fn-exp-')));
$report['recurring_schedulers']=[];
foreach(routerCheckedCommand($api,'/system/scheduler/print',['=.proplist=name,interval,disabled,run-count']) as $row)if(isset($row['name'])&&($row['disabled']??'')==='false'&&($row['interval']??'0s')!=='0s')$report['recurring_schedulers'][]=$row;
$report['running_jobs']=[];
foreach(routerCheckedCommand($api,'/system/script/job/print') as $row)if(isset($row['.id']))$report['running_jobs'][]=['id'=>$row['.id'],'script_name'=>isset($row['script'])&&preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$row['script'])?$row['script']:'inline/unnamed','started'=>$row['started']??null];
$api->disconnect();echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
