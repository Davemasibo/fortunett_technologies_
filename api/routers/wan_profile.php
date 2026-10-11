<?php
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/router_wan_profile.php';
require_once __DIR__.'/../../includes/onboarding_checks.php';
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    if(empty($_SESSION['user_id'])){http_response_code(401);throw new RuntimeException('Sign in to continue.');}
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');$st->execute([$_SESSION['user_id']]);$tenantId=(int)$st->fetchColumn();
    $routerId=(int)(($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET)['router_id'] ?? 0);
    $st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?');$st->execute([$routerId,$tenantId]);$router=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tenantId || !$router){http_response_code(404);throw new RuntimeException('Device not found.');}
    ensureWanProfiles($pdo);$record=wanProfileRecord($pdo,$tenantId,$routerId);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        echo json_encode(['status'=>'success','router_name'=>$router['name'],'profile'=>$record ? wanProfilePublic($record) : null]);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('Method not allowed.');}
    if(empty($_SESSION['wan_csrf']) || !hash_equals($_SESSION['wan_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('Reload the page before saving.');}
    $action=(string)($_POST['action'] ?? 'save');
    if(!in_array($action,['save','connect','status'],true))throw new InvalidArgumentException('Invalid connection action.');
    $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');$st->execute([$tenantId]);$sub=(string)$st->fetchColumn();
    $domain=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1")->fetchColumn() ?: 'fortunetttech.site';
    $host=($sub?$sub.'.':'').$domain;
    if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host))throw new RuntimeException('Administrator must check the billing domain.');
    if($action==='save') {
        $ownTransaction=!$pdo->inTransaction();if($ownTransaction)$pdo->beginTransaction();
        try {
        $locked=$pdo->prepare('SELECT * FROM router_wan_profiles WHERE tenant_id=? AND router_id=? FOR UPDATE');$locked->execute([$tenantId,$routerId]);$record=$locked->fetch(PDO::FETCH_ASSOC) ?: null;
        if($record && in_array($record['state'],['applying','verifying'],true) && strtotime($record['updated_at'])>time()-120)throw new RuntimeException('A connection change is in progress. Wait up to two minutes before changing the ISP details again.');
        $input=$_POST;$input['lan_bridge']='';$password=(string)($_POST['wan_password'] ?? '');
        $old=$record ? json_decode($record['config_json'],true) : [];
        if(($input['wan_mode'] ?? '')==='pppoe' && $password==='' && !empty($record['password_ciphertext']) && ($input['wan_username'] ?? '')===($old['username'] ?? ''))$password=decryptWanPassword($record['password_ciphertext']);
        $input['wan_password']=$password;$cfg=routerWanInput($input,false);
        $cipher=$cfg['mode']==='pppoe' ? encryptWanPassword($password) : null;
        $pdo->prepare("INSERT INTO router_wan_profiles (tenant_id,router_id,config_json,password_ciphertext,state,message) VALUES (?,?,?,?,'pending',?) ON DUPLICATE KEY UPDATE config_json=VALUES(config_json),password_ciphertext=VALUES(password_ciphertext),state='pending',message=VALUES(message),revision=revision+1,checked_at=NULL,updated_at=NOW()")
            ->execute([$tenantId,$routerId,json_encode($cfg,JSON_THROW_ON_ERROR),$cipher,'ISP details saved. Waiting for a reachable API/VPN management connection.']);
        $record=wanProfileRecord($pdo,$tenantId,$routerId);
        saveOnboardingCheck($pdo,$tenantId,$routerId,(string)($router['service_types'] ?? ''),false,'New ISP settings saved. Awaiting WAN and service verification.');
        if($ownTransaction)$pdo->commit();
        } catch(Throwable $e){if($ownTransaction && $pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if(!$record)throw new RuntimeException('Save your ISP connection details first.');
    if($action==='connect' && in_array($record['state'],['needs_attention','connected'],true)) {
        // Explicit retry verifies the dispatched change; it does not reapply it.
        $pdo->prepare("UPDATE router_wan_profiles SET state='verifying' WHERE id=? AND revision=?")->execute([$record['id'],$record['revision']]);
    }
    if($action!=='status')$record=processWanProfile($pdo,$router,'https://'.$host.'/api/routers/wan_health.php');
    echo json_encode(['status'=>'success','router_name'=>$router['name'],'profile'=>wanProfilePublic($record)]);
} catch(Throwable $e) {
    if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException ? 400 : 503);
    echo json_encode(['status'=>'error','message'=>$e instanceof PDOException ? 'Connection profiles are temporarily unavailable. Ask your administrator to finish the server update.' : $e->getMessage()]);
}
