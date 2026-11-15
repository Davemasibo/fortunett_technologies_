<?php
/**
 * Per-tenant live router status. WAN check-in, VPS WireGuard handshake, and
 * RouterOS API login are reported independently so one cannot impersonate the
 * health of another.
 */
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/router_health.php';
require_once '../../classes/MikrotikAPI.php';
require_once '../../classes/WireGuardManager.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$user_id = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$user_id]);
$tenant_id = (int)$st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success' => false, 'message' => 'No tenant']); exit; }

session_write_close();
$healthStorageReady = ensureRouterHealthColumns($pdo);

$rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE status IN ('active','online','inactive','offline','pending') AND tenant_id = ?");
$rSt->execute([$tenant_id]);
$routerRows = $rSt->fetchAll(PDO::FETCH_ASSOC);

$wgPeers = [];
$wgMonitorAvailable = false;
try {
    WireGuardManager::getVpsPublicKey();
    $wgMonitorAvailable = true;
    $wgPeers = WireGuardManager::peerStatus();
} catch (Throwable $e) {
    error_log('router_status WireGuard snapshot unavailable: ' . $e->getMessage());
}

$routerStatus    = [];
$totalLiveUsers  = 0;
$totalPPPoE      = 0;
$totalHotspot    = 0;
$routersOnline   = 0;

foreach ($routerRows as $router) {
    $id = (int)$router['id'];
    $port = (int)($router['api_port'] ?: 8728);
    $wanAge = routerHealthAge($router['last_wan_heartbeat_at'] ?? null);
    $apiAge = routerHealthAge($router['last_api_success_at'] ?? null);

    $wgState = 'not_configured';
    $wgAge = routerHealthAge($router['last_wg_handshake_at'] ?? null);
    $wgAt = $router['last_wg_handshake_at'] ?? null;
    $peer = null;
    $publicKey = trim((string)($router['wg_public_key'] ?? ''));
    if ($publicKey !== '' && !empty($router['vpn_ip'])) {
        if (!$wgMonitorAvailable) {
            $wgState = 'unknown';
        } else {
            $peer = $wgPeers[$publicKey] ?? null;
            if (!$peer) {
                $wgState = 'peer_not_loaded';
            } elseif ((int)$peer['handshake'] < 1) {
                // After a VPS restart the live peer can report no handshake even
                // though we previously observed one. Preserve that history as stale.
                $wgState = $wgAt ? 'stale' : 'never';
            } else {
                $wgAge = max(0, time() - (int)$peer['handshake']);
                $wgAt = date('Y-m-d H:i:s', (int)$peer['handshake']);
                $wgState = $wgAge <= 300 ? 'recent' : 'stale';
                if ($healthStorageReady) {
                    $oldHandshake = strtotime((string)($router['last_wg_handshake_at'] ?? '')) ?: 0;
                    if ((int)$peer['handshake'] > $oldHandshake) {
                        $pdo->prepare("UPDATE mikrotik_routers SET last_wg_handshake_at=FROM_UNIXTIME(?) WHERE id=? AND tenant_id=?")
                            ->execute([(int)$peer['handshake'], $id, $tenant_id]);
                    }
                }
            }
        }
    }

    $rs = [
        'id' => $id,
        'name' => $router['name'],
        'ip' => $router['ip_address'],
        'online' => false,
        'api_online' => false,
        'api_error' => null,
        'active_clients' => 0,
        'pppoe_clients' => 0,
        'hotspot_clients' => 0,
        'uptime' => null,
        'cpu_load' => null,
        'signals' => [
            'wan' => ['state' => $wanAge === null ? 'never' : ($wanAge <= 420 ? 'recent' : ($wanAge <= 1800 ? 'delayed' : 'stale')),
                'last_at' => $router['last_wan_heartbeat_at'] ?? null, 'age_seconds' => $wanAge],
            'wireguard' => ['state' => $wgState, 'last_at' => $wgAt, 'age_seconds' => $wgAge,
                'observed' => $peer !== null, 'endpoint' => $peer['endpoint'] ?? null],
            'api' => ['state' => 'unreachable', 'last_at' => $router['last_api_success_at'] ?? null,
                'age_seconds' => $apiAge, 'tcp_reachable' => false],
        ],
    ];

    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    if ($mk->isReachable(2)) {
        $rs['signals']['api']['tcp_reachable'] = true;
        try {
            $mk->connect();
            $rs['online'] = true;
            $rs['api_online'] = true;
            $rs['signals']['api']['state'] = 'online';
            $rs['signals']['api']['last_at'] = date('Y-m-d H:i:s');
            $rs['signals']['api']['age_seconds'] = 0;
            $rs['signals']['api']['last_success_at'] = $rs['signals']['api']['last_at'];
            $rs['signals']['api']['last_success_age_seconds'] = 0;

            if ($healthStorageReady) {
                $pdo->prepare("UPDATE mikrotik_routers SET status='active',last_seen=NOW(),last_api_success_at=NOW() WHERE id=? AND tenant_id=?")
                    ->execute([$id, $tenant_id]);
            } else {
                $pdo->prepare("UPDATE mikrotik_routers SET status='active',last_seen=NOW() WHERE id=? AND tenant_id=?")
                    ->execute([$id, $tenant_id]);
            }

            try {
                $resources = $mk->getResources();
                $rs['uptime'] = $resources['uptime'] ?? null;
                $rs['cpu_load'] = isset($resources['cpu-load']) ? (int)$resources['cpu-load'] : null;
            } catch (Throwable $e) {
                error_log("router_status resources error router $id: " . $e->getMessage());
            }

            try { $rs['pppoe_clients'] = count($mk->getActiveSessions()); }
            catch (Throwable $e) { error_log("router_status PPPoE error router $id: " . $e->getMessage()); }
            try { $rs['hotspot_clients'] = count($mk->getActiveHotspotSessionsMap()); }
            catch (Throwable $e) { error_log("router_status hotspot error router $id: " . $e->getMessage()); }
            $rs['active_clients'] = $rs['pppoe_clients'] + $rs['hotspot_clients'];
            $totalPPPoE += $rs['pppoe_clients'];
            $totalHotspot += $rs['hotspot_clients'];
            $totalLiveUsers += $rs['active_clients'];
            $routersOnline++;
            $mk->disconnect();
        } catch (Throwable $e) {
            $mk->disconnect();
            $rs['api_error'] = $e->getMessage();
            $rs['signals']['api']['state'] = 'login_failed';
            error_log("router_status API error router $id: " . $e->getMessage());
        }
    } else {
        $rs['api_error'] = "TCP {$connectIp}:{$port} is not reachable from the application server.";
    }

    if (!$rs['api_online']) {
        // Keep status consistent with the current authenticated API check, while
        // protecting a newer successful check that may have raced this request.
        if ($healthStorageReady) {
            $pdo->prepare("UPDATE mikrotik_routers SET status='inactive' WHERE id=? AND tenant_id=? AND last_api_success_at <=> ?")
                ->execute([$id, $tenant_id, $router['last_api_success_at'] ?? null]);
        }
        $vpnUsed = !empty($router['vpn_ip']);
        if (!$rs['signals']['api']['tcp_reachable']) {
            $rs['api_error'] = $vpnUsed
                ? 'RouterOS API TCP port is unreachable over the VPN.'
                : 'RouterOS API TCP port is unreachable at the configured address.';
        } else {
            $rs['api_error'] = 'RouterOS API TCP port answers, but API login failed.';
        }
    }

    if (!$rs['api_online']) {
        $rs['signals']['api']['last_success_at'] = $router['last_api_success_at'] ?? null;
        $rs['signals']['api']['last_success_age_seconds'] = $apiAge;
    }
    $routerStatus[] = $rs;
}

echo json_encode([
    'success' => true,
    'health_storage_ready' => $healthStorageReady,
    'router_status' => $routerStatus,
    'routers_online' => $routersOnline,
    'routers_total' => count($routerRows),
    'active_users' => $totalLiveUsers,
    'pppoe_online' => $totalPPPoE,
    'hotspot_online' => $totalHotspot,
    'router_online' => $routersOnline > 0,
]);
