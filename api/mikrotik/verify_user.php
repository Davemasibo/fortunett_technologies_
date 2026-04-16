<?php
/**
 * API Endpoint: Verify a customer's credentials exist on the router.
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    }
});

header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $t_stmt->execute([$_SESSION['user_id']]);
    $tenant_id = (int)$t_stmt->fetchColumn();

    $client_id = (int)($_REQUEST['client_id'] ?? 0);
    if (!$client_id) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'client_id required']);
        exit;
    }

    // Fetch client + package profile in one query
    $stmt = $pdo->prepare("
        SELECT c.id, c.status, c.mikrotik_username, c.connection_type,
               COALESCE(p.mikrotik_profile, '') AS stored_profile,
               COALESCE(NULLIF(p.mikrotik_profile,''),
                        REGEXP_REPLACE(LOWER(COALESCE(p.name,'')), '[^a-z0-9-]', ''),
                        'default') AS expected_profile
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

    // Fallback: compute expected_profile in PHP if the SQL function isn't available
    $expectedProfile = $client['expected_profile'];
    if (empty($expectedProfile)) {
        $expectedProfile = $client['stored_profile']
            ?: preg_replace('/[^a-z0-9-]/', '', strtolower($client['stored_profile'] ?? ''));
        if (empty($expectedProfile)) $expectedProfile = 'default';
    }

    $result = [
        'success'          => true,
        'db_ok'            => true,
        'db_status'        => $client['status'],
        'db_username'      => $client['mikrotik_username'],
        'db_connection'    => $client['connection_type'] ?? 'pppoe',
        'expected_profile' => $expectedProfile,
        'router_ok'        => false,
        'router_profile'   => null,
        'profile_match'    => false,
        'is_online'        => false,
        'session'          => null,
        'router_error'     => null,
        'router_ip'        => null,
    ];

    $username        = $client['mikrotik_username'];
    $connection_type = $client['connection_type'] ?? 'pppoe';

    if (empty($username)) {
        $result['router_error'] = 'No mikrotik_username set on this client record';
        ob_clean();
        echo json_encode($result);
        exit;
    }

    // Fetch router
    $router_stmt = $pdo->prepare(
        "SELECT id, ip_address, vpn_ip, username, password, api_port
         FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ? LIMIT 1"
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

    // Quick reachability check before attempting full API connection
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $port      = (int)($router['api_port'] ?? 8728);
    $fp = @fsockopen($connectIp, $port, $errno, $errstr, 3);
    if (!$fp) {
        $result['router_error'] = "Router unreachable ($connectIp:$port) — $errstr";
        ob_clean();
        echo json_encode($result);
        exit;
    }
    fclose($fp);

    try {
        $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
        $api->connect();

        // Use targeted ?name= query instead of fetching all users
        if ($connection_type === 'hotspot') {
            $found = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
            foreach ($found as $u) {
                if (isset($u['!re']) && ($u['name'] ?? '') === $username) {
                    $result['router_ok']      = true;
                    $result['router_profile'] = $u['profile'] ?? null;
                    break;
                }
            }
            // Check active hotspot session
            $sessMap = $api->getActiveHotspotSessionsMap();
            if (isset($sessMap[strtolower($username)])) {
                $s = $sessMap[strtolower($username)];
                $result['is_online'] = true;
                $result['session']   = [
                    'uptime'  => $s['uptime'],
                    'address' => $s['address'],
                    'rx_mb'   => round((int)$s['rx_byte'] / 1048576, 2),
                    'tx_mb'   => round((int)$s['tx_byte'] / 1048576, 2),
                ];
            }
        } else {
            // PPPoE — targeted lookup
            $found = $api->comm('/ppp/secret/print', ['?name=' . $username]);
            foreach ($found as $u) {
                if (isset($u['!re']) && ($u['name'] ?? '') === $username) {
                    $result['router_ok']      = true;
                    $result['router_profile'] = $u['profile'] ?? null;
                    break;
                }
            }
            // Check active PPPoE session
            $sessMap = $api->getActiveSessionsMap();
            if (isset($sessMap[strtolower($username)])) {
                $s = $sessMap[strtolower($username)];
                $result['is_online'] = true;
                $result['session']   = [
                    'uptime'  => $s['uptime'],
                    'address' => $s['address'],
                    'rx_mb'   => round((int)$s['rx_byte'] / 1048576, 2),
                    'tx_mb'   => round((int)$s['tx_byte'] / 1048576, 2),
                ];
            }
        }

        $api->disconnect();

        if ($result['router_ok'] && $result['router_profile'] !== null) {
            $result['profile_match'] = ($result['router_profile'] === $expectedProfile);
        }

    } catch (Exception $e) {
        $result['router_error'] = $e->getMessage();
    }

    ob_clean();
    echo json_encode($result);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
