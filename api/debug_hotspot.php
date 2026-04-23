<?php
/**
 * TEMPORARY DEBUG — remove this file once hotspot issue is diagnosed.
 * Shows raw RouterOS response for /ip/hotspot/active/print
 */
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
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
$port      = (int)($router['api_port'] ?: 8728);

$out = [
    'router'     => $router['name'],
    'connect_ip' => $connectIp,
    'port'       => $port,
];

try {
    $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $mk->connect();
    $out['connected'] = true;

    // --- PPPoE active (known-working baseline) ---
    $pppoe = $mk->getActiveSessions();
    $out['pppoe_count']    = count($pppoe);
    $out['pppoe_sample']   = array_slice($pppoe, 0, 2);

    // --- Hotspot active: raw comm() output without any proplist ---
    $rawHs = $mk->comm('/ip/hotspot/active/print');
    $out['hotspot_raw_response'] = $rawHs;
    $out['hotspot_raw_count']    = count($rawHs);

    // --- Hotspot via the normal helper ---
    $hsMap = $mk->getActiveHotspotSessionsMap();
    $out['hotspot_map_count']  = count($hsMap);
    $out['hotspot_map_sample'] = array_slice($hsMap, 0, 2, true);

    // --- Hotspot user list (not sessions, just registered users) ---
    $hsUsers = $mk->getHotspotUsers();
    $out['hotspot_user_count']  = count($hsUsers);
    $out['hotspot_user_sample'] = array_slice($hsUsers, 0, 3);

    // --- Hotspot host table: shows ALL connected devices and their status ---
    // status=authorized means the device is online (via portal login, MAC bypass, or cookie)
    // status=waiting means the device is connected but not yet authenticated
    $rawHosts = $mk->comm('/ip/hotspot/host/print');
    $hosts = [];
    foreach ($rawHosts as $h) {
        if (isset($h['!re'])) {
            unset($h['!re']);
            $hosts[] = $h;
        }
    }
    $out['hotspot_host_count']  = count($hosts);
    $out['hotspot_hosts']       = $hosts; // show all so we can see statuses

    $mk->disconnect();
} catch (Exception $e) {
    $out['exception'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
