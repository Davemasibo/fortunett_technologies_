<?php
/**
 * API Endpoint: Verify a customer's credentials exist on the router.
 *
 * Returns:
 *   - db_ok         : client found in DB
 *   - router_ok     : user found on router (PPPoE secret or Hotspot user)
 *   - profile_match : the profile assigned on the router matches the package profile
 *   - is_online     : user currently has an active session
 *   - session        : session details if online (uptime, ip, rx/tx)
 *   - router_profile: what profile the router actually has for this user
 *   - expected_profile: what profile we expect (from package)
 *
 * GET/POST params:
 *   client_id   (required)
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t_stmt->fetchColumn();

$client_id = (int)($_REQUEST['client_id'] ?? 0);
if (!$client_id) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'client_id required']);
    exit;
}

// ── Fetch client from DB ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*, p.name AS pkg_name, p.mikrotik_profile,
           COALESCE(p.mikrotik_profile, '') AS expected_profile
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    WHERE c.id = ? AND c.tenant_id = ?
");
$stmt->execute([$client_id, $tenant_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Client not found']);
    exit;
}

$result = [
    'success'          => true,
    'db_ok'            => true,
    'db_status'        => $client['status'],
    'db_username'      => $client['mikrotik_username'],
    'db_connection'    => $client['connection_type'] ?? 'pppoe',
    'expected_profile' => $client['expected_profile']
                           ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($client['pkg_name'] ?? ''))
                           ?: 'default',
    'router_ok'        => false,
    'router_profile'   => null,
    'profile_match'    => false,
    'is_online'        => false,
    'session'          => null,
    'router_error'     => null,
    'router_ip'        => null,
];

$username       = $client['mikrotik_username'];
$connection_type = $client['connection_type'] ?? 'pppoe';

if (empty($username)) {
    $result['router_error'] = 'No mikrotik_username set on this client record';
    ob_clean();
    echo json_encode($result);
    exit;
}

// ── Fetch router ───────────────────────────────────────────────────────────
$router_stmt = $pdo->prepare(
    "SELECT id, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ? LIMIT 1"
);
$router_stmt->execute([$tenant_id]);
$router = $router_stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    $result['router_error'] = 'No active router configured for this tenant';
    ob_clean();
    echo json_encode($result);
    exit;
}

$result['router_ip'] = $router['ip_address'];

try {
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
    $api->connect();

    // ── Check if user exists on router ────────────────────────────────────────
    if ($connection_type === 'hotspot') {
        $users = $api->getHotspotUsers();
        foreach ($users as $u) {
            if (($u['name'] ?? '') === $username) {
                $result['router_ok']      = true;
                $result['router_profile'] = $u['profile'] ?? null;
                break;
            }
        }

        // Check active hotspot session
        $sessions = $api->getActiveHotspotSessionsMap();
        if (isset($sessions[strtolower($username)])) {
            $s = $sessions[strtolower($username)];
            $result['is_online'] = true;
            $result['session']   = [
                'uptime'  => $s['uptime'],
                'address' => $s['address'],
                'rx_mb'   => round($s['rx_byte'] / 1048576, 2),
                'tx_mb'   => round($s['tx_byte'] / 1048576, 2),
            ];
        }
    } else {
        // PPPoE
        $users = $api->getPPPoEUsers();
        foreach ($users as $u) {
            if (($u['name'] ?? '') === $username) {
                $result['router_ok']      = true;
                $result['router_profile'] = $u['profile'] ?? null;
                break;
            }
        }

        // Check active PPPoE session
        $sessions = $api->getActiveSessionsMap();
        if (isset($sessions[strtolower($username)])) {
            $s = $sessions[strtolower($username)];
            $result['is_online'] = true;
            $result['session']   = [
                'uptime'  => $s['uptime'],
                'address' => $s['address'],
                'rx_mb'   => round($s['rx_byte'] / 1048576, 2),
                'tx_mb'   => round($s['tx_byte'] / 1048576, 2),
            ];
        }
    }

    $api->disconnect();

    // ── Profile match check ───────────────────────────────────────────────────
    if ($result['router_ok'] && $result['router_profile'] !== null) {
        $result['profile_match'] = ($result['router_profile'] === $result['expected_profile']);
    }

} catch (Exception $e) {
    $result['router_error'] = $e->getMessage();
}

ob_clean();
echo json_encode($result);
