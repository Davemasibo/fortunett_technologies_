<?php
/**
 * API Endpoint: Deploy the branded hotspot login page to the tenant's router(s).
 * The router pulls the file from this server via /tool/fetch — no FTP needed.
 *
 * POST router_id=<id>   deploy to one router
 * POST router_id=all    deploy to every active router for this tenant
 *
 * Deploying also installs the self-update scheduler, so subsequent portal
 * changes reach the router without another deploy.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auto_provision.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

$raw = trim((string)($_POST['router_id'] ?? ''));

if (strcasecmp($raw, 'all') === 0) {
    $rq = $pdo->prepare(
        "SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') ORDER BY id"
    );
    $rq->execute([$tenant_id]);
    $routers = $rq->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rq = $pdo->prepare(
        "SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ? AND status IN ('active','online')"
    );
    $rq->execute([(int)$raw, $tenant_id]);
    $routers = $rq->fetchAll(PDO::FETCH_ASSOC);
}

if (!$routers) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No active router found']);
    exit;
}

$results = [];
$okCount = 0;

foreach ($routers as $router) {
    $name = $router['name'] ?: $router['ip_address'];
    try {
        _uploadHotspotLoginPage($pdo, $router, $tenant_id);
        $results[] = ['router' => $name, 'success' => true,  'message' => 'Deployed'];
        $okCount++;
    } catch (Throwable $e) {
        // One unreachable router must not abort the rest of the fleet.
        $results[] = ['router' => $name, 'success' => false, 'message' => $e->getMessage()];
    }
}

$total = count($routers);
ob_clean();

if ($total === 1) {
    echo json_encode([
        'success' => $okCount === 1,
        'message' => $okCount === 1
            ? 'Hotspot login page deployed — router is pulling the file now.'
            : $results[0]['message'],
        'results' => $results,
    ]);
    exit;
}

echo json_encode([
    'success' => $okCount > 0,
    'message' => $okCount === $total
        ? "Deployed to all $total routers — each will now self-update on portal changes."
        : "Deployed to $okCount of $total routers. Unreachable routers will pick the page up from their own scheduler.",
    'results' => $results,
]);
