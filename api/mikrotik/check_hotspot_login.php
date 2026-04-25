<?php
/**
 * API Endpoint: Check whether hotspot/login.html exists on the router's flash.
 * Returns the file's modification time if found.
 *
 * POST router_id — the router to check
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
$tenant_id = (int)$t->fetchColumn();

$router_id = (int)($_POST['router_id'] ?? 0);

$rq = $pdo->prepare(
    "SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?"
);
$rq->execute([$router_id, $tenant_id]);
$router = $rq->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Router not found']); exit;
}

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?? 8728);

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);

    if (!$api->isReachable(4)) {
        ob_clean();
        echo json_encode(['success' => false, 'deployed' => false, 'message' => 'Router unreachable']);
        exit;
    }

    $api->connect();

    // Fetch all files from flash — filter client-side to avoid RouterOS query
    // filter format ambiguity across firmware versions.
    $files = $api->comm('/file/print');

    try { $api->disconnect(); } catch (Throwable $_e) {}

    $found   = false;
    $modTime = null;
    $size    = null;
    foreach ($files as $f) {
        $fname = $f['name'] ?? '';
        // Match both 'hotspot/login.html' and 'flash/hotspot/login.html'
        if ($fname === 'hotspot/login.html' || substr($fname, -strlen('hotspot/login.html')) === 'hotspot/login.html') {
            $found   = true;
            $modTime = $f['creation-time'] ?? ($f['last-modified'] ?? null);
            $size    = $f['size'] ?? null;
            break;
        }
    }

    ob_clean();
    echo json_encode([
        'success'  => true,
        'deployed' => $found,
        'mod_time' => $modTime,
        'size'     => $size,
    ]);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'deployed' => false, 'message' => $e->getMessage()]);
}
