<?php
/**
 * Hotspot Diagnostic API
 * Returns a comprehensive status snapshot for a single router:
 *   reachable, api_ok, hotspot_server, login_deployed, user_counts, walled_garden
 *
 * POST router_id
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenantId = (int)$t->fetchColumn();

$routerId = (int)($_POST['router_id'] ?? 0);
$rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$rq->execute([$routerId, $tenantId]);
$router = $rq->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Router not found']); exit;
}

// DB counts (done before router API attempt — always available)
$dbCount = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND COALESCE(NULLIF(connection_type,''),'hotspot') = 'hotspot'");
$dbCount->execute([$tenantId]);
$dbUserCount = (int)$dbCount->fetchColumn();

$dbActive = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND status = 'active' AND COALESCE(NULLIF(connection_type,''),'hotspot') = 'hotspot'");
$dbActive->execute([$tenantId]);
$dbActiveCount = (int)$dbActive->fetchColumn();

$dbNoRouter = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND status = 'active' AND (mikrotik_username IS NULL OR mikrotik_username = '') AND COALESCE(NULLIF(connection_type,''),'hotspot') = 'hotspot'");
$dbNoRouter->execute([$tenantId]);
$unprovisioned = (int)$dbNoRouter->fetchColumn();

$result = [
    'success'        => true,
    'reachable'      => false,
    'api_ok'         => false,
    'bridge'         => null,   // name of the detected bridge interface, or null
    'hotspot_server' => null,   // name of running hotspot server, or false
    'pppoe_server'   => null,   // name of running PPPoE server, or false
    'login_deployed' => false,
    'login_size'     => null,
    'login_mod_time' => null,
    'router_users'   => null,
    'active_sessions'=> null,
    'walled_garden'  => [],
    'db_total'       => $dbUserCount,
    'db_active'      => $dbActiveCount,
    'unprovisioned'  => $unprovisioned,
    'error'          => null,
];

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?: 8728);

// TCP reachability check (3s timeout)
$sock = @fsockopen($connectIp, $port, $errno, $errstr, 3);
if (!$sock) {
    $result['error'] = "TCP unreachable ($connectIp:$port) — $errstr";
    ob_clean(); echo json_encode($result); exit;
}
fclose($sock);
$result['reachable'] = true;

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $api->connect();
    $result['api_ok'] = true;

    // ── Bridge detection ──────────────────────────────────────────────────
    try {
        $bridges = $api->comm('/interface/bridge/print');
        foreach ($bridges as $b) {
            if (isset($b['!re']) && ($b['disabled'] ?? 'false') === 'false' && !empty($b['name'])) {
                $result['bridge'] = $b['name'];
                break;
            }
        }
    } catch (Throwable $_e) {}

    // ── Hotspot server status ─────────────────────────────────────────────
    try {
        $servers = $api->comm('/ip/hotspot/print');
        $running = null;
        foreach ($servers as $s) {
            if (isset($s['!re']) && ($s['disabled'] ?? 'true') !== 'true') {
                $running = ($s['name'] ?? 'unknown') . ' on ' . ($s['interface'] ?? '?');
                break;
            }
        }
        $result['hotspot_server'] = $running;
    } catch (Throwable $_e) {
        $result['hotspot_server'] = false;
    }

    // ── PPPoE server status ───────────────────────────────────────────────
    try {
        $ppServers = $api->comm('/interface/pppoe-server/server/print');
        $ppRunning = null;
        foreach ($ppServers as $s) {
            if (isset($s['!re']) && ($s['disabled'] ?? 'true') !== 'true') {
                $ppRunning = ($s['service-name'] ?? ($s['name'] ?? 'pppoe')) . ' on ' . ($s['interface'] ?? '?');
                break;
            }
        }
        $result['pppoe_server'] = $ppRunning;
    } catch (Throwable $_e) {
        $result['pppoe_server'] = false;
    }

    // ── Login page on flash ───────────────────────────────────────────────
    try {
        $files = $api->comm('/file/print');
        foreach ($files as $f) {
            $fname = $f['name'] ?? '';
            if ($fname === 'hotspot/login.html'
                || substr($fname, -strlen('hotspot/login.html')) === 'hotspot/login.html') {
                $result['login_deployed'] = true;
                $result['login_size']     = $f['size'] ?? null;
                $result['login_mod_time'] = $f['creation-time'] ?? ($f['last-modified'] ?? null);
                break;
            }
        }
    } catch (Throwable $_e) {}

    // ── Hotspot user count on router ──────────────────────────────────────
    try {
        $users = $api->comm('/ip/hotspot/user/print');
        $count = 0;
        foreach ($users as $u) { if (isset($u['!re'])) $count++; }
        $result['router_users'] = $count;
    } catch (Throwable $_e) {
        $result['router_users'] = null;
    }

    // ── Active sessions ───────────────────────────────────────────────────
    try {
        $sessions = $api->getActiveHotspotSessionsMap();
        $result['active_sessions'] = count($sessions);
    } catch (Throwable $_e) {
        $result['active_sessions'] = null;
    }

    // ── Walled garden entries ─────────────────────────────────────────────
    try {
        $wg = $api->comm('/ip/hotspot/walled-garden/print');
        $entries = [];
        foreach ($wg as $w) {
            if (isset($w['!re'])) {
                $entries[] = trim(($w['dst-host'] ?? '') . ' ' . ($w['dst-address'] ?? ''));
            }
        }
        $result['walled_garden'] = $entries;
    } catch (Throwable $_e) {}

    $api->disconnect();

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($result);
