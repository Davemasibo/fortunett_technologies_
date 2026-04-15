<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? null;
$identity = trim($_POST['identity'] ?? '');
// Accept comma-separated list: 'pppoe', 'hotspot', or 'pppoe,hotspot'
$servicesRaw = trim($_POST['services'] ?? $_POST['service'] ?? '');

if (!$tenantId || !$identity || !$servicesRaw) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

// Parse and validate service list
$allowed  = ['pppoe', 'hotspot'];
$services = array_values(array_filter(
    array_map('trim', explode(',', $servicesRaw)),
    fn($s) => in_array($s, $allowed, true)
));

if (empty($services)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid service selected']);
    exit;
}

try {
    // Find the router by identity + tenant
    $stmt = $pdo->prepare("
        SELECT id FROM mikrotik_routers
        WHERE (identity = ? OR name = ?) AND tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$identity, $identity, $tenantId]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        echo json_encode(['status' => 'error', 'message' => 'Router not found']);
        exit;
    }

    // Save service_types to the router record
    $serviceTypesStr = implode(',', $services);
    $pdo->prepare("UPDATE mikrotik_routers SET service_types = ? WHERE id = ?")
        ->execute([$serviceTypesStr, $router['id']]);

    // Build one command string per requested service
    $commands = [];
    foreach ($services as $service) {
        if ($service === 'pppoe') {
            $commands[] =
                "/ip pool add name=pppoe-pool ranges=10.10.10.2-10.10.10.254; " .
                "/ppp profile add name=pppoe-profile local-address=10.10.10.1 remote-address=pppoe-pool dns-server=8.8.8.8,8.8.4.4; " .
                "/interface pppoe-server server add service-name=pppoe-service interface=ether2 default-profile=pppoe-profile disabled=no;";
        } elseif ($service === 'hotspot') {
            $commands[] =
                "/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254; " .
                "/ip address add address=10.5.50.1/24 interface=ether2; " .
                "/ip hotspot profile add name=hsprof1 dns-name=hotspot.fortunett.com hotspot-address=10.5.50.1; " .
                "/ip hotspot user profile set [find name=default] rate-limit=5M/5M; " .
                "/ip hotspot add name=hotspot1 interface=ether2 address-pool=hs-pool profile=hsprof1 disabled=no;";
        }
    }

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Configuration generated for: ' . implode(', ', $services),
        'services' => $services,
        'commands' => $commands,
        // Legacy single-command field (first service) for backwards compatibility
        'command'  => $commands[0] ?? '',
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
