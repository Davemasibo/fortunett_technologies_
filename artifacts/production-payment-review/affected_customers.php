<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/classes/MikrotikAPI.php';
require '/var/www/html/fortunett_technologies_/includes/router_expiry.php';
$suffixes=['143121697','791205446','102695735','758262685'];
$report=['checked_at'=>date(DATE_ATOM),'customers'=>[]];$clients=[];
foreach($suffixes as $suffix){
 $st=$pdo->prepare("SELECT c.*,p.name AS package_name,p.price,p.validity_value,p.validity_unit FROM clients c LEFT JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id WHERE c.tenant_id=9 AND RIGHT(REPLACE(REPLACE(c.phone,'+',''),' ',''),9)=?");
 $st->execute([$suffix]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
 if(!$rows)$report['customers'][]=['phone_ending'=>substr($suffix,-4),'found'=>false];
 foreach($rows as $c){
  $clients[$c['id']]=$c;
  $row=array_intersect_key($c,array_flip(['id','account_number','status','connection_type','package_id','package_name','price','validity_value','validity_unit','expiry_date','last_seen']));
  $row['phone_ending']=substr($suffix,-4);$row['has_credentials']=!empty($c['mikrotik_username'])&&!empty($c['mikrotik_password']);
  $row['entitled_now']=$c['status']==='active'&&!empty($c['expiry_date'])&&strtotime($c['expiry_date'])>time();
  $queries=[
   'payments'=>"SELECT id,amount,status,payment_date,payment_method FROM payments WHERE tenant_id=9 AND client_id=? ORDER BY payment_date DESC,id DESC LIMIT 8",
   'transactions'=>"SELECT id,amount,status,result_code,result_desc,created_at,updated_at FROM mpesa_transactions WHERE tenant_id=9 AND client_id=? ORDER BY id DESC LIMIT 8",
   'grants'=>"SELECT package_id,periods,validity_value,validity_unit,expiry_date,previous_expiry,created_at FROM payment_subscription_grants WHERE tenant_id=9 AND client_id=? ORDER BY created_at DESC LIMIT 5",
   'provision'=>"SELECT attempts,fail_reason,next_retry_at FROM pending_provisions WHERE tenant_id=9 AND client_id=?",
   'device'=>"SELECT updated_at FROM hotspot_device_context WHERE tenant_id=9 AND client_id=?",
   'sms'=>"SELECT status,last_error,attempts FROM payment_notifications WHERE tenant_id=9 AND client_id=? ORDER BY id DESC LIMIT 2"
  ];
  foreach($queries as $key=>$sql){try{$st=$pdo->prepare($sql);$st->execute([$c['id']]);$row[$key]=$st->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$row[$key]=['error'=>$e->getMessage()];}}
  $report['customers'][]=$row;
 }
}
$report['near_phone_matches']=[];
foreach(['3121697','2695735'] as $last7){
 $st=$pdo->prepare("SELECT c.id,c.tenant_id,c.account_number,CONCAT(LEFT(c.phone,5),'***',RIGHT(c.phone,4)) AS masked_phone,c.status,c.expiry_date FROM clients c WHERE RIGHT(REPLACE(REPLACE(c.phone,'+',''),' ',''),7)=? OR RIGHT(c.mikrotik_username,7)=?");$st->execute([$last7,$last7]);
 $report['near_phone_matches'][$last7]=$st->fetchAll(PDO::FETCH_ASSOC);
 foreach(['provider_transactions'=>"SELECT id,tenant_id,client_id,amount,status,result_code,created_at FROM mpesa_transactions WHERE RIGHT(REGEXP_REPLACE(phone_number,'[^0-9]',''),7)=? ORDER BY created_at DESC LIMIT 8",'unmatched_payments'=>"SELECT id,tenant_id,amount,status,reason,created_at FROM unmatched_payments WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),7)=? ORDER BY created_at DESC LIMIT 8"] as $table=>$sql){
  try{$st=$pdo->prepare($sql);$st->execute([$last7]);$report[$table][$last7]=$st->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$report[$table][$last7]=['error'=>$e->getMessage()];}
 }

}
try{
 $r=$pdo->query('SELECT * FROM mikrotik_routers WHERE id=19 AND tenant_id=9')->fetch(PDO::FETCH_ASSOC);
 $api=new MikrotikAPI($r['vpn_ip'],$r['username'],$r['password'],(int)$r['api_port']);$api->connect();
 $users=routerCheckedCommand($api,'/ip/hotspot/user/print',['=.proplist=name,disabled,profile,uptime,limit-uptime,comment']);
 $sessions=routerCheckedCommand($api,'/ip/hotspot/active/print',['=.proplist=user,mac-address,uptime,session-time-left,bytes-in,bytes-out']);
 $hosts=routerCheckedCommand($api,'/ip/hotspot/host/print',['=.proplist=mac-address,address,authorized']);
 foreach($report['customers'] as &$row){
  if(empty($row['id']))continue;$c=$clients[$row['id']];
  $row['router_user']=null;$row['sessions']=[];
  foreach($users as $u)if(($u['name']??'')===$c['mikrotik_username'])$row['router_user']=array_intersect_key($u,array_flip(['disabled','profile','uptime','limit-uptime','comment']));
  foreach($sessions as $s)if(($s['user']??'')===$c['mikrotik_username'])$row['sessions'][]=array_intersect_key($s,array_flip(['uptime','session-time-left','bytes-in','bytes-out']));
  $st=$pdo->prepare('SELECT mac_address FROM hotspot_device_context WHERE tenant_id=9 AND client_id=?');$st->execute([$c['id']]);$mac=$c['bound_mac_address']?:$st->fetchColumn();
  $row['remembered_device_visible']=false;
  foreach($hosts as $h)if($mac&&strtoupper($h['mac-address']??'')===strtoupper($mac))$row['remembered_device_visible']=true;
 }unset($row);
 $report['script_jobs']=count(array_filter(routerCheckedCommand($api,'/system/script/job/print'),fn($j)=>isset($j['.id'])));
 $report['watchdog']=[];
 foreach(routerCheckedCommand($api,'/system/scheduler/print',['?name=fn-paid-expiry-watchdog']) as $s)if(isset($s['name']))$report['watchdog'][]=array_intersect_key($s,array_flip(['name','interval','run-count','disabled']));
 $api->disconnect();
}catch(Throwable $e){$report['router_error']=$e->getMessage();}
echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
