<?php
if(PHP_SAPI!=='cli')exit;
$app='/var/www/html/fortunett_technologies_'; require "$app/includes/db_master.php"; require "$app/classes/MikrotikAPI.php";
$t=$pdo->query("SELECT id,subdomain FROM tenants WHERE subdomain='ecolandattic'")->fetch(); if(!$t)throw new Exception('Tenant missing');
$st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=?');$st->execute([$t['id']]);$routers=$st->fetchAll();
$out=['tenant'=>$t,'routers'=>[]];
foreach($routers as $r){$entry=array_intersect_key($r,array_flip(['id','name','status','vpn_ip','ip_address','service_type']));try{$a=new MikrotikAPI($r['vpn_ip']?:$r['ip_address'],$r['username'],$r['password'],$r['api_port']?:8728);$a->connect();foreach(['/system/resource/print','/system/device-mode/print','/interface/bridge/print','/interface/bridge/port/print','/ip/hotspot/print','/ip/hotspot/profile/print','/ip/pool/print','/ip/address/print','/ip/dhcp-server/print','/ip/dhcp-server/network/print','/ip/firewall/nat/print','/ip/hotspot/walled-garden/print','/ip/hotspot/walled-garden/ip/print','/interface/pppoe-server/server/print'] as $cmd){$entry[$cmd]=$a->comm($cmd);} $a->disconnect();}catch(Throwable $e){$entry['error']=$e->getMessage();}$out['routers'][]=$entry;}
$st=$pdo->prepare('SELECT id FROM users WHERE tenant_id=? LIMIT 1');$st->execute([$t['id']]);$out['user_id']=$st->fetchColumn();echo json_encode($out,JSON_PRETTY_PRINT);
