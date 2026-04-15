<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';

// disable error reporting to screen to prevent HTML in JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

$id = $_POST['id'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!$id) {
    echo json_encode(['status' => 'error', 'message' => 'Router ID Required']);
    exit;
}

try {
    // Get Tenant ID
    $tStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $tStmt->execute([$user_id]);
    $tenant_id = $tStmt->fetchColumn();

    if (!$tenant_id) {
        throw new Exception("Tenant not found");
    }

    // Get Router with Tenant Validation
    $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        throw new Exception("Router not found or access denied");
    }

    $ip = $router['ip_address'];
    $user = $router['username'];
    $pass = $router['password']; 
    $port = $router['api_port'] ?? 8728;

    $mk = new MikrotikAPI($ip, $user, $pass, $port);

    // Check TCP reachability separately so we can give a clear error reason
    if (!$mk->isReachable(4)) {
        $pdo->prepare("UPDATE mikrotik_routers SET status = 'inactive' WHERE id = ?")->execute([$id]);
        echo json_encode([
            'status'  => 'error',
            'reason'  => 'unreachable',
            'message' => "TCP port $port unreachable on $ip. Re-run the provisioning script to add the firewall rule, then test again.",
        ]);
        exit;
    }

    if ($mk->connect()) {

        // Identity
        $idResp   = $mk->comm('/system/identity/print');
        $identity = '';
        foreach ($idResp as $r) { if (isset($r['!re'])) { $identity = $r['name'] ?? ''; break; } }

        // Resources (uptime, CPU)
        $resources = $mk->getResources();
        $uptime    = $resources['uptime']   ?? '—';
        $cpuLoad   = $resources['cpu-load'] ?? '0';

        // Live sessions: PPPoE + hotspot
        $pppoeSessions  = $mk->getActiveSessions();
        $pppoeCount     = count($pppoeSessions);
        $hotspotMap     = $mk->getActiveHotspotSessionsMap();
        $hotspotCount   = count($hotspotMap);
        $totalActive    = $pppoeCount + $hotspotCount;

        $mk->disconnect();

        // Mark active — valid ENUM value
        $pdo->prepare("UPDATE mikrotik_routers SET status = 'active', last_seen = NOW() WHERE id = ?")->execute([$id]);

        echo json_encode([
            'status'        => 'success',
            'message'       => "Connected to '" . ($identity ?: $ip) . "'",
            'stats' => [
                'active_total'  => $totalActive,
                'pppoe_active'  => $pppoeCount,
                'hotspot_active'=> $hotspotCount,
                'uptime'        => $uptime,
                'cpu_load'      => (int)$cpuLoad,
            ],
        ]);
    } else {
        $pdo->prepare("UPDATE mikrotik_routers SET status = 'inactive' WHERE id = ?")->execute([$id]);

        echo json_encode([
            'status'  => 'error',
            'message' => "Connection failed to $ip. Is the router reachable from this server?",
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
