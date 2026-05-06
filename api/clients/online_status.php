<?php
/**
 * GET /api/clients/online_status.php
 * Returns list of usernames currently active on any tenant router (MikroTik live sessions)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

// Cache per session for 45 seconds to avoid hammering routers
$cacheKey = 'online_status_' . $tenant_id;
$now = time();
if (isset($_SESSION[$cacheKey]) && ($now - $_SESSION[$cacheKey]['ts']) < 45) {
    echo json_encode(['success'=>true,'online'=>$_SESSION[$cacheKey]['data'],'details'=>$_SESSION[$cacheKey]['details']??[],'cached'=>true]);
    exit;
}

require_once '../../classes/MikrotikAPI.php';

$rSt = $pdo->prepare("SELECT id, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
$rSt->execute([$tenant_id]);
$routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

$onlineUsernames = [];
$routerDetails   = []; // username → {uptime, bytes_in, bytes_out, address, service}

foreach ($routers as $router) {
    $port      = (int)($router['api_port'] ?: 8728);
    // Use VPN IP if set (router API accessible via WireGuard/OpenVPN tunnel)
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $sock = @fsockopen($connectIp, $port, $e, $s, 2);
    if (!$sock) continue;
    fclose($sock);
    try {
        $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
        $mk->connect();

        // PPPoE sessions
        $sessions = $mk->getActiveSessions();
        foreach ($sessions as $sess) {
            $uname = $sess['name'] ?? $sess['user'] ?? '';
            if ($uname) {
                $onlineUsernames[] = $uname;
                $routerDetails[$uname] = [
                    'uptime'   => $sess['uptime']    ?? '',
                    'bytes_in' => $sess['bytes-in']  ?? $sess['bytes_in']  ?? '',
                    'bytes_out'=> $sess['bytes-out'] ?? $sess['bytes_out'] ?? '',
                    'address'  => $sess['address']   ?? '',
                    'service'  => $sess['service']   ?? 'pppoe',
                ];
            }
        }

        $mk->disconnect();

        // Hotspot sessions — fresh connection because RouterOS 7.x closes TCP
        // after each !done, so the socket above is dead after getActiveSessions().
        try {
            $sock2 = @fsockopen($connectIp, $port, $e2, $s2, 2);
            if ($sock2) {
                fclose($sock2);
                $mk2 = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                $mk2->connect();
                $hotspotMap = $mk2->getActiveHotspotSessionsMap();
                $mk2->disconnect();
                foreach ($hotspotMap as $uname => $sessData) {
                    $onlineUsernames[] = $uname;
                    $routerDetails[$uname] = [
                        'uptime'   => $sessData['uptime']   ?? '',
                        'bytes_in' => $sessData['rx_byte']  ?? '0',
                        'bytes_out'=> $sessData['tx_byte']  ?? '0',
                        'address'  => $sessData['address']  ?? '',
                        'service'  => 'hotspot',
                    ];
                }
            }
        } catch (Exception $hsErr) { /* hotspot not configured on this router */ }
    } catch (Exception $e) { /* router unreachable / auth failed */ }
}

$_SESSION[$cacheKey] = ['ts' => $now, 'data' => $onlineUsernames, 'details' => $routerDetails];

// Update last_seen for currently online users
if (!empty($onlineUsernames)) {
    try {
        // Ensure column exists (silent if already there)
        try { $pdo->exec("ALTER TABLE clients ADD COLUMN last_seen DATETIME NULL DEFAULT NULL"); } catch (Throwable $_ce) {}
        $uniqNames = array_unique(array_map('strtolower', $onlineUsernames));
        $lsPh = implode(',', array_fill(0, count($uniqNames), 'LOWER(?)'));
        $lsParams = array_merge($uniqNames, [$tenant_id]);
        $pdo->prepare("UPDATE clients SET last_seen = NOW() WHERE LOWER(mikrotik_username) IN ($lsPh) AND tenant_id = ?")
            ->execute($lsParams);
    } catch (Throwable $_lse) {}
}

echo json_encode([
    'success' => true,
    'online'  => $onlineUsernames,
    'details' => $routerDetails,
]);
