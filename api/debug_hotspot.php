<?php
/**
 * TEMPORARY DEBUG — delete after diagnosis.
 */
ob_start();
ini_set('display_errors', 0);
require_once '../includes/db_master.php';
require_once '../includes/auth.php';
require_once '../classes/MikrotikAPI.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['error' => 'Login first']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();

$rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ? LIMIT 1");
$rSt->execute([$tenant_id]);
$router = $rSt->fetch(PDO::FETCH_ASSOC);
if (!$router) { echo json_encode(['error' => 'No active router']); exit; }

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port = (int)($router['api_port'] ?: 8728);
$out = ['router' => $router['name'], 'connect_ip' => $connectIp];

try {
    $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $mk->connect();

    // 1. Hotspot active (authenticated sessions)
    $rawActive = $mk->comm('/ip/hotspot/active/print');
    $active = [];
    foreach ($rawActive as $item) {
        if (isset($item['!re'])) { unset($item['!re']); $active[] = $item; }
    }
    $out['active_sessions'] = $active;
    $out['active_count'] = count($active);

    // 2. Hotspot host table (ALL connected devices: waiting, authorized, bypass)
    $rawHosts = $mk->comm('/ip/hotspot/host/print');
    $hosts = [];
    foreach ($rawHosts as $item) {
        if (isset($item['!re'])) { unset($item['!re']); $hosts[] = $item; }
    }
    $out['host_table'] = $hosts;
    $out['host_count'] = count($hosts);

    // 3. Hotspot server list (shows which interfaces hotspot is running on)
    $rawServers = $mk->comm('/ip/hotspot/print');
    $servers = [];
    foreach ($rawServers as $item) {
        if (isset($item['!re'])) { unset($item['!re']); $servers[] = $item; }
    }
    $out['hotspot_servers'] = $servers;

    // 4. IP bindings (bypass rules)
    $rawBindings = $mk->comm('/ip/hotspot/ip-binding/print');
    $bindings = [];
    foreach ($rawBindings as $item) {
        if (isset($item['!re'])) { unset($item['!re']); $bindings[] = $item; }
    }
    $out['ip_bindings'] = $bindings;
    $out['ip_binding_count'] = count($bindings);

    $mk->disconnect();
} catch (Exception $e) {
    $out['exception'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
