<?php
if(PHP_SAPI!=='cli')exit;
session_start();
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/router_wan_profile.php';
putenv('WAN_CREDENTIAL_KEY='.base64_encode(random_bytes(32)));
$user=$pdo->query('SELECT id,tenant_id FROM users WHERE tenant_id>0 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if(!$user)throw new RuntimeException('A local tenant user is required.');
ensureWanProfiles($pdo);$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO mikrotik_routers (tenant_id,name,ip_address,username,password,status) VALUES (?,?,'127.0.0.1','test','test','pending')")->execute([$user['tenant_id'],'wan-endpoint-test-'.bin2hex(random_bytes(5))]);
    $id=(int)$pdo->lastInsertId();$_SESSION=['user_id'=>$user['id'],'wan_csrf'=>bin2hex(random_bytes(16))];$_SERVER['REQUEST_METHOD']='POST';
    $_POST=['router_id'=>$id,'csrf'=>$_SESSION['wan_csrf'],'action'=>'save','wan_mode'=>'pppoe','wan_username'=>'test-isp-user','wan_password'=>'test-isp-private'];
    ob_start();require __DIR__.'/../api/routers/wan_profile.php';$response=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    if(($response['status'] ?? '')!=='success' || $response['profile']['config']['lan']!=='' || $response['profile']['state']!=='pending')throw new RuntimeException('Offline save should succeed without a LAN bridge.');
    if(str_contains(json_encode($response),'test-isp-private') || array_key_exists('password_ciphertext',$response['profile']))throw new RuntimeException('Response exposed ISP secret');
    $record=wanProfileRecord($pdo,(int)$user['tenant_id'],$id);
    if(decryptWanPassword($record['password_ciphertext'])!=='test-isp-private')throw new RuntimeException('Saved encrypted ISP secret could not be recovered');
    $_POST['wan_password']='';
    ob_start();require __DIR__.'/../api/routers/wan_profile.php';$response=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    if(($response['status'] ?? '')!=='success')throw new RuntimeException('Blank password should retain saved secret for same ISP username');
    if(wanProfileRecord($pdo,(int)$user['tenant_id']+10000,$id))throw new RuntimeException('Cross-tenant profile exposed');
    echo "PASS: authenticated endpoint saves ISP credentials offline without LAN bridge, retains encrypted password on edit, and returns no secret.\n";
}finally{$pdo->rollBack();}
