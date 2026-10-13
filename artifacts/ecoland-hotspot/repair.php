<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=12 AND tenant_id=5')->fetch();$a=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],$r['api_port']);$a->connect();
$rows=fn($cmd)=>array_values(array_filter(routerCheckedCommand($a,$cmd),fn($v)=>isset($v['.id'])));
$before=['addresses'=>$rows('/ip/address/print'),'dhcp'=>$rows('/ip/dhcp-server/print'),'networks'=>$rows('/ip/dhcp-server/network/print'),'dns'=>$rows('/ip/dns/print'),'files'=>$rows('/file/print'),'pppoe_sessions'=>count($rows('/ppp/active/print'))];
file_put_contents('/root/ecoland-router-repair-before.json',json_encode($before,JSON_PRETTY_PRINT));chmod('/root/ecoland-router-repair-before.json',0600);
if(!array_filter($before['addresses'],fn($v)=>($v['address']??'')==='10.5.50.1/24'))routerCheckedCommand($a,'/ip/address/add',['=address=10.5.50.1/24','=interface=bridge','=comment=FortuNett-Hotspot-Gateway']);
$dhcp=array_values(array_filter($before['dhcp'],fn($v)=>($v['interface']??'')==='bridge'));if(count($dhcp)!==1 || $dhcp[0]['name']!=='defconf')throw new Exception('DHCP changed; inspect before continuing');
routerCheckedCommand($a,'/ip/dhcp-server/set',['=.id='.$dhcp[0]['.id'],'=address-pool=hs-pool','=disabled=no']);
if(!array_filter($before['networks'],fn($v)=>($v['address']??'')==='10.5.50.0/24'))routerCheckedCommand($a,'/ip/dhcp-server/network/add',['=address=10.5.50.0/24','=gateway=10.5.50.1','=dns-server=10.5.50.1','=comment=FortuNett-Hotspot-Network']);
routerCheckedCommand($a,'/ip/dns/set',['=allow-remote-requests=yes']);
$t=$pdo->query('SELECT provisioning_token FROM tenants WHERE id=5')->fetchColumn();
routerCheckedCommand($a,'/tool/fetch',['=url=https://ecolandattic.fortunetttech.site/hotspot/login_serve.php?token='.rawurlencode($t),'=dst-path=flash/hotspot/login.html','=check-certificate=yes']);
$out=['hotspot'=>$rows('/ip/hotspot/print'),'dhcp'=>$rows('/ip/dhcp-server/print'),'pppoe_sessions'=>count($rows('/ppp/active/print')),'login_files'=>array_values(array_filter($rows('/file/print'),fn($v)=>str_ends_with($v['name']??'','/login.html')))];$a->disconnect();echo json_encode($out,JSON_PRETTY_PRINT);
