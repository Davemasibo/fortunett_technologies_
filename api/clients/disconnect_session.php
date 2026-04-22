<?php
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST only']); exit; }

$username  = trim($_POST['username'] ?? '');
$type      = strtolower(trim($_POST['type'] ?? 'pppoe'));
$router_id = (int)($_POST['router_id'] ?? 0);

if (!$username || !$router_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$user_id = $_SESSION['user_id'];
$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$user_id]);
$tenant_id = $st->fetchColumn();

$rSt = $pdo->prepare("SELECT ip_address, vpn_ip, username AS ruser, password AS rpass, api_port FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$rSt->execute([$router_id, $tenant_id]);
$router = $rSt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found or access denied']);
    exit;
}

$port      = (int)($router['api_port'] ?: 8728);
$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];

try {
    $mk = new MikrotikAPI($connectIp, $router['ruser'], $router['rpass'], $port);
    $mk->connect();

    if ($type === 'hotspot') {
        // Disable the hotspot user account so they cannot re-auth, then kick the live session.
        $mk->disableHotspotUser($username);
        $kicked = $mk->kickHotspotSession($username);
    } else {
        // Disable the PPP secret so the client cannot auto-reconnect, then remove the active session.
        $mk->disablePPPoEUser($username);
        $kicked = $mk->kickPPPoESession($username);
    }

    $mk->disconnect();

    if ($kicked) {
        echo json_encode(['success' => true, 'message' => htmlspecialchars($username) . ' disconnected and account disabled on router']);
    } else {
        // Disabling worked but session may have already dropped — still report success.
        echo json_encode(['success' => true, 'message' => htmlspecialchars($username) . ' disabled on router (session may have already dropped)']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
