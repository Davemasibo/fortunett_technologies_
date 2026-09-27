<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/auth.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$userId = (int)$_SESSION['user_id'];
session_write_close();
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/router_expiry.php';
require_once __DIR__ . '/../../includes/hotspot_locations.php';
$api = null;
try {
    $st = $pdo->prepare('SELECT r.* FROM mikrotik_routers r JOIN users u ON u.tenant_id=r.tenant_id WHERE u.id=? AND r.id=?');
    $st->execute([$userId, (int)($_GET['router_id'] ?? 0)]);
    $router = $st->fetch(PDO::FETCH_ASSOC);
    if (!$router) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Router not found.']); exit; }
    $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'], $router['username'], $router['password'], (int)($router['api_port'] ?: 8728));
    $api->connect();
    $interfaces = routerCheckedCommand($api, '/interface/print', ['=stats=']);
    $hosts = routerCheckedCommand($api, '/interface/bridge/host/print');
    $sessions = routerCheckedCommand($api, '/ip/hotspot/active/print');
    echo json_encode(['success'=>true,'sampled_at'=>microtime(true)] + hotspotLocationSnapshot($interfaces,$hosts,$sessions));
} catch (Throwable $e) {
    error_log('Hotspot locations: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Could not read router traffic. Check the router connection and API permissions.']);
} finally {
    if ($api) $api->disconnect();
}
