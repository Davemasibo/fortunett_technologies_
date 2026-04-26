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

    $rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') LIMIT 1");
    $rq->execute([$tenant_id]);
    $router = $rq->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        ob_clean(); echo json_encode(['error' => 'No active router found']); exit;
    }

    $ip   = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $port = (int)($router['api_port'] ?? 8728);

    $mk = new MikrotikAPI($ip, $router['username'], $router['password'], $port);
    $mk->connect();
    $raw = $mk->comm('/ip/hotspot/profile/print');
    try { $mk->disconnect(); } catch (Throwable $_e) {}

    $out = [];
    foreach ($raw as $p) {
        if (!isset($p['name'])) continue;
        $out[] = [
            'id'             => $p['.id']           ?? '?',
            'name'           => $p['name'],
            'html_directory' => $p['html-directory'] ?? '',
            'login_by'       => $p['login-by']       ?? '',
        ];
    }

    ob_clean();
    echo json_encode($out, JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['error' => $e->getMessage()]);
}
