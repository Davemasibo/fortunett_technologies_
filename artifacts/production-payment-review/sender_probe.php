<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/includes/sms_config.php';
[$config]=smsResolveConfig($pdo,9);
$base=preg_replace('~/sms/send/?$~','',smsNormalizeApiUrl($config['api_url']));
if(parse_url($base,PHP_URL_HOST)!=='bulksms.talksasa.com')throw new RuntimeException('Unexpected provider');
function safeFields($value) {
 if(!is_array($value))return $value;
 $out=[];
 foreach($value as $key=>$item) {
  if(is_int($key)||in_array($key,['status','message','error','data','sender_id','senderid','name','originator','results','records','id','sms_count','balance','created_at','type','default']))$out[$key]=safeFields($item);
 }
 return $out;
}
foreach(['/senderid','/senderids','/sender-id','/sender-ids','/sms/senderid','/sms/senderids'] as $path){
 $ch=curl_init($base.$path);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.trim($config['api_key'])],CURLOPT_TIMEOUT=>8]);
 $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
 $json=json_decode($raw,true);
 echo json_encode(['path'=>$path,'http'=>$code,'response'=>$json?safeFields($json):['error'=>$error,'not_json'=>true]],JSON_UNESCAPED_SLASHES).PHP_EOL;
 if($json && ($json['status']??'')==='success')break;
}
echo 'Stored sender configurations: '.json_encode($pdo->query('SELECT tenant_id,sender_id,is_active FROM sms_configurations')->fetchAll(PDO::FETCH_ASSOC)).PHP_EOL;
