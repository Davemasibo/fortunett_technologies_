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
    } catch (Exception $e) { /* router unreachable / auth failed */ }
}

$_SESSION[$cacheKey] = ['ts' => $now, 'data' => $onlineUsernames, 'details' => $routerDetails];

echo json_encode([
    'success' => true,
    'online'  => $onlineUsernames,
    'details' => $routerDetails,
]);
