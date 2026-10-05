<?php
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../classes/MikrotikAPI.php';
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) {http_response_code(401); echo json_encode(['error'=>'Sign in to continue.']); exit;}
try {
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?'); $st->execute([$_SESSION['user_id']]);
    $tenantId=(int)$st->fetchColumn();
    $st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?');
    $st->execute([(int)($_GET['router_id'] ?? 0),$tenantId]); $router=$st->fetch(PDO::FETCH_ASSOC);
    if (!$tenantId || !$router) {http_response_code(404); echo json_encode(['error'=>'Router not found.']); exit;}
    $api=new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
    $api->connect(); $bridges=[];
    foreach ($api->comm('/interface/bridge/print') as $row) {
        if (isset($row['!re']) && !empty($row['name']) && !in_array($row['disabled'] ?? 'false',['true','yes'],true)) $bridges[]=$row['name'];
    }
    $api->disconnect(); echo json_encode(['bridges'=>$bridges]);
} catch (Throwable $e) {http_response_code(503); echo json_encode(['error'=>'Cannot read bridges. Check the management connection and retry.']);}
