<?php
/**
 * Lightweight router status endpoint — only MikroTik live data, no chart stats.
 * Called independently by the dashboard so heavy MikroTik queries never block the stats response.
 */
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$user_id = $_SESSION['user_id'];
$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$user_id]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success' => false, 'message' => 'No tenant']); exit; }

$rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
$rSt->execute([$tenant_id]);
$routerRows = $rSt->fetchAll(PDO::FETCH_ASSOC);

$routerStatus    = [];
$totalLiveUsers  = 0;
$routersOnline   = 0;
$anyRouterOnline = false;

foreach ($routerRows as $router) {
    $port = (int)($router['api_port'] ?: 8728);
    $rs   = [
        'id'              => $router['id'],
        'name'            => $router['name'],
        'ip'              => $router['ip_address'],
        'online'          => false,
        'active_clients'  => 0,
        'pppoe_clients'   => 0,
        'hotspot_clients' => 0,
    ];

    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $sock = @fsockopen($connectIp, $port, $errno, $errstr, 4);
    if ($sock) {
        fclose($sock);
        try {
            $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
            $mk->connect();

            $pppoeCount   = count($mk->getActiveSessions());
            $hotspotCount = count($mk->getActiveHotspotSessionsMap());

            $rs['online']          = true;
            $rs['pppoe_clients']   = $pppoeCount;
            $rs['hotspot_clients'] = $hotspotCount;
            $rs['active_clients']  = $pppoeCount + $hotspotCount;

            $totalLiveUsers += $rs['active_clients'];
            $anyRouterOnline = true;
            $routersOnline++;

            $mk->disconnect();
        } catch (Exception $e) {
            $rs['online'] = true;
            $anyRouterOnline = true;
            $routersOnline++;
        }
    }
    $routerStatus[] = $rs;
}

echo json_encode([
    'success'        => true,
    'router_status'  => $routerStatus,
    'routers_online' => $routersOnline,
    'routers_total'  => count($routerRows),
    'active_users'   => $totalLiveUsers,
    'router_online'  => $anyRouterOnline,
]);
