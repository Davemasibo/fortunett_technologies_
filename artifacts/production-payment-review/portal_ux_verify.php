<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/hotspot_sync.php';
require '/var/www/html/fortunett_technologies_/hotspot/render_login.php';
$r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
$t=$pdo->query('SELECT * FROM tenants WHERE id=9')->fetch(PDO::FETCH_ASSOC);
$expected=substr(sha1(renderHotspotLoginPage($pdo,$t)),0,12);
$api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);
$api->connect();
if(in_array('--sync',$argv,true)){
 foreach(routerCheckedCommand($api,'/system/script/print',['=.proplist=.id,name']) as $s){
  if(($s['name']??'')===HOTSPOT_SYNC_NAME){routerCheckedCommand($api,'/system/script/run',['=number='.$s['.id']]);break;}
 }
}
$actual=null;
foreach(routerCheckedCommand($api,'/file/print',['=.proplist=.id,name']) as $f){
 if(($f['name']??'')==='fortunett-portal.ver'){
  foreach(routerCheckedCommand($api,'/file/get',['=number='.$f['.id'],'=value-name=contents']) as $v)if(isset($v['ret']))$actual=trim($v['ret']);
 }
}
$api->disconnect();
echo json_encode(['router_status'=>$r['status'],'expected_build'=>$expected,'router_build'=>$actual,'matches'=>$expected===$actual]).PHP_EOL;
$tx=$pdo->query("SELECT mt.checkout_request_id,mt.client_id,c.tenant_id FROM mpesa_transactions mt
 JOIN clients c ON c.id=mt.client_id AND c.tenant_id=mt.tenant_id
 JOIN payment_activations a ON a.client_id=c.id AND a.tenant_id=c.tenant_id AND a.activation_key=mt.checkout_request_id AND a.expiry_date=c.expiry_date
 WHERE c.tenant_id=9 AND c.status='active' AND c.expiry_date>NOW() AND mt.status='completed' AND mt.result_code=0
 AND (c.bound_mac_address IS NULL OR c.bound_mac_address='') ORDER BY mt.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if($tx){
 $lock='payment-client-'.$tx['tenant_id'].'-'.$tx['client_id'];
 $s=$pdo->prepare('SELECT GET_LOCK(?,1)');$s->execute([$lock]);$held=(int)$s->fetchColumn()===1;
 try{
  $start=microtime(true);$ch=curl_init('https://ghettohlink.fortunetttech.site/api/payment/hotspot_payment_status.php?'.http_build_query(['checkout_request_id'=>$tx['checkout_request_id'],'client_id'=>$tx['client_id']]));
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=json_decode($raw?:'',true)?:[];
  echo json_encode(['status_http'=>$code,'response_ms'=>round((microtime(true)-$start)*1000),'customer_lock_held'=>$held,'payment_status'=>$d['status']??null,'credentials_present'=>!empty($d['username'])&&!empty($d['password']),'account_link_present'=>!empty($d['portal_token'])]).PHP_EOL;
 }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
