<?php
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/router_wan.php';
require_once __DIR__.'/../../classes/MikrotikAPI.php';
require_once __DIR__.'/../../includes/onboarding_checks.php';
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if (empty($_SESSION['user_id'])) {http_response_code(401); throw new RuntimeException('Sign in to continue.');}
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?'); $st->execute([$_SESSION['user_id']]); $tenantId=(int)$st->fetchColumn();
    if (!$tenantId) throw new RuntimeException('No tenant assigned.');
    $routerId=(int)($_REQUEST['router_id'] ?? 0); $router=null;
    if ($routerId) {
        $st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?'); $st->execute([$routerId,$tenantId]); $router=$st->fetch(PDO::FETCH_ASSOC);
        if (!$router) {http_response_code(404); throw new RuntimeException('Router not found.');}
    }
    $identity=$router ? (string)$router['name'] : trim((string)($_POST['identity'] ?? ''));
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $record=$router ? loadRouterWan($pdo,$tenantId,$routerId) : null;
        echo json_encode(['status'=>'success','config'=>$record ? json_decode($record['config_json'],true) : null]); exit;
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405); throw new RuntimeException('Method not allowed.');}
    if (empty($_SESSION['wan_csrf']) || !hash_equals($_SESSION['wan_csrf'],(string)($_POST['csrf'] ?? ''))) {http_response_code(403); throw new RuntimeException('Reload this page before configuring WAN.');}
    if (($_POST['action'] ?? '')==='verify') {
        if (!$router) throw new RuntimeException('Register the router before dashboard verification.');
        $record=loadRouterWan($pdo,$tenantId,$routerId);
        if (!$record) throw new RuntimeException('Prepare WAN settings first.');
        $pdo->prepare('UPDATE router_wan_config SET verified_at=NULL WHERE id=? AND tenant_id=?')->execute([$record['id'],$tenantId]);
        $api=new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
        try {$api->connect(); $checks=verifyRouterWan($api,$record);} finally {$api->disconnect();}
        $ok=!array_filter($checks,fn($c)=>!$c['ok']);
        if (!$ok) saveOnboardingCheck($pdo,$tenantId,$routerId,(string)($router['service_types'] ?? ''),false,'WAN verification failed. Fix connectivity and verify services again.');
        $pdo->prepare('UPDATE router_wan_config SET verified_at=IF(?,NOW(),NULL) WHERE id=? AND tenant_id=?')->execute([$ok,$record['id'],$tenantId]);
        echo json_encode(['status'=>'success','all_ok'=>$ok,'checks'=>$checks]); exit;
    }
    if ($identity==='' || strlen($identity)>64 || preg_match('/[\x00-\x1f]/',$identity)) throw new InvalidArgumentException('Enter a router name of up to 64 characters.');
    $cfg=routerWanInput($_POST);
    // Use tenant/platform configuration, never a browser supplied probe URL.
    $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?'); $st->execute([$tenantId]); $sub=(string)$st->fetchColumn();
    $domain=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1")->fetchColumn() ?: 'fortunetttech.site';
    $host=($sub ? $sub.'.' : '').$domain;
    if (!preg_match('/^[A-Za-z0-9.-]+$/D',$host)) throw new RuntimeException('Configure a valid platform domain.');
    $billingUrl='https://'.$host.'/api/routers/wan_health.php';
    $script=routerWanSetupScript($cfg,(string)($_POST['wan_password'] ?? ''),$billingUrl);
    if (!$routerId) {
        $st=$pdo->prepare('SELECT id FROM mikrotik_routers WHERE tenant_id=? AND name=? ORDER BY id DESC LIMIT 1'); $st->execute([$tenantId,$identity]); $routerId=(int)$st->fetchColumn();
    }
    $pdo->prepare('INSERT INTO router_wan_config (tenant_id,identity,router_id,config_json,lan_bridge,billing_url) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE router_id=VALUES(router_id),config_json=VALUES(config_json),lan_bridge=VALUES(lan_bridge),billing_url=VALUES(billing_url),verified_at=NULL,updated_at=NOW()')
        ->execute([$tenantId,$identity,$routerId ?: null,json_encode($cfg,JSON_THROW_ON_ERROR),$cfg['lan'],$billingUrl]);
    if ($routerId) saveOnboardingCheck($pdo,$tenantId,$routerId,(string)($router['service_types'] ?? ''),false,'WAN settings changed. Apply and verify WAN and services.');
    echo json_encode(['status'=>'success','script'=>$script,'config'=>$cfg,'identity'=>$identity]);
} catch (Throwable $e) {
    if (http_response_code()<400) http_response_code($e instanceof InvalidArgumentException ? 400 : 503);
    $message=$e instanceof PDOException ? 'WAN storage unavailable. Apply the router WAN database migration.' : $e->getMessage();
    echo json_encode(['status'=>'error','message'=>$message]);
}
