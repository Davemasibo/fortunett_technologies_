<?php
ob_start();
ini_set('display_errors', 0);
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../classes/MikrotikAPI.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
$result = ['connected'=>false];
try {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $identity = trim((string)($_GET['identity'] ?? ''));
    if (!$userId || !$identity) throw new RuntimeException('Sign in and enter a router name.');
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?'); $st->execute([$userId]);
    $tenantId=(int)$st->fetchColumn();
    if (!$tenantId) throw new RuntimeException('No tenant assigned.');
    $st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? AND (name=? OR identity=?) ORDER BY id DESC LIMIT 1');
    $st->execute([$tenantId,$identity,$identity]); $router=$st->fetch(PDO::FETCH_ASSOC);
    if (!$router) {
        $result['message']='Waiting for the router. Open WinBox, choose New Terminal and paste the connection command.';
    } else {
        $result['router']=['id'=>(int)$router['id'],'name'=>$router['name']];
        $api=new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
        if (!$api->isReachable(2)) {
            $result['message']='Router setup has started, but the management connection is not ready. Wait a few seconds. If it stays here, check that the import completed without an error.';
        } else {
            $api->connect();
            $api->disconnect();
            $pdo->prepare("UPDATE mikrotik_routers SET status='active',last_seen=NOW() WHERE id=? AND tenant_id=?")->execute([$router['id'],$tenantId]);
            $result['connected']=true;
            $result['message']='Router API connection verified.';
        }
    }
} catch (Throwable $e) {
    $result['message']='Connection not verified: '.$e->getMessage();
}
ob_clean(); echo json_encode($result);
