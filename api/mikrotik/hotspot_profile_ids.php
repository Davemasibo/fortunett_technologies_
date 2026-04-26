<?php
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../../includes/db_master.php';
require_once '../../classes/RouterOSAPI.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['error' => 'Not logged in']); exit;
}

try {
    $t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $t->execute([$_SESSION['user_id']]);
    $tenant_id = (int)$t->fetchColumn();

    // If router_id passed use it, otherwise list all routers so user can pick
    $router_id = (int)($_GET['router_id'] ?? 0);

    if (!$router_id) {
        $rq = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, status FROM mikrotik_routers WHERE tenant_id = ?");
        $rq->execute([$tenant_id]);
        $routers = $rq->fetchAll(PDO::FETCH_ASSOC);
        ob_clean();
        echo json_encode([
            'message' => 'Pass ?router_id=X to check a specific router',
            'routers' => $routers,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
    $rq->execute([$router_id, $tenant_id]);
    $router = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$router) { ob_clean(); echo json_encode(['error' => 'Router not found']); exit; }

    $vpn_ip    = $router['vpn_ip']    ?? '';
    $public_ip = $router['ip_address'];
    $using_ip  = !empty($vpn_ip) ? $vpn_ip : $public_ip;
    $port      = (int)($router['api_port'] ?? 8728);

    $mk = new MikrotikAPI($using_ip, $router['username'], $router['password'], $port);
    $mk->connect();
    $raw = $mk->comm('/ip/hotspot/profile/print');
    try { $mk->disconnect(); } catch (Throwable $_e) {}

    $profiles = [];
    foreach ($raw as $p) {
        if (!isset($p['name'])) continue;
        $profiles[] = [
            'id'             => $p['.id']            ?? '?',
            'name'           => $p['name'],
            'html_directory' => $p['html-directory'] ?? '',
            'login_by'       => $p['login-by']       ?? '',
        ];
    }

    ob_clean();
    echo json_encode([
        'router'     => $router['name'],
        'vpn_ip'     => $vpn_ip ?: '(not set)',
        'ip_address' => $public_ip,
        'connected_via' => $using_ip,
        'profiles'   => $profiles,
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['error' => $e->getMessage()]);
}
