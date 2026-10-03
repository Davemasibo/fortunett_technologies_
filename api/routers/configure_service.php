<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/router_service_config.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? null;
$identity = trim($_POST['identity'] ?? '');
$routerId = (int)($_POST['router_id'] ?? 0);
$bridgeName = trim((string)($_POST['bridge_name'] ?? ''));
if (strlen($bridgeName) > 64 || preg_match('/[\x00-\x1f]/', $bridgeName)) {
    http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid bridge name']); exit;
}

// Accept comma-separated list: 'pppoe', 'hotspot', or 'pppoe,hotspot'
$servicesRaw = trim($_POST['services'] ?? $_POST['service'] ?? '');

// Hotspot session-sharing setting: 1 = one session per user, 0 = unlimited
$noSharing = (int)($_POST['hotspot_no_sharing'] ?? 0);
$sharedUsers = $noSharing ? '1' : 'unlimited';

if (!$tenantId || (!$routerId && !$identity) || !$servicesRaw) {
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
    // Resolve ownership from the authenticated user; device names can repeat.
    $owner = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
    $owner->execute([$_SESSION['user_id']]);
    $tenantId = (int)$owner->fetchColumn();
    if ($routerId) {
        $stmt = $pdo->prepare('SELECT id,service_types FROM mikrotik_routers WHERE id=? AND tenant_id=?');
        $stmt->execute([$routerId,$tenantId]);
    } else {
        $stmt = $pdo->prepare('SELECT id,service_types FROM mikrotik_routers WHERE (identity=? OR name=?) AND tenant_id=? ORDER BY id LIMIT 1');
        $stmt->execute([$identity,$identity,$tenantId]);
    }
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        echo json_encode(['status' => 'error', 'message' => 'Router not found']);
        exit;
    }

    // Persist both service_types and the hotspot sharing setting
    $serviceTypesStr = mergeRouterServiceTypes((string)($router['service_types'] ?? ''), $services);
    $pdo->prepare("
        UPDATE mikrotik_routers
        SET service_types = ?, hotspot_shared_users = CASE WHEN ? THEN ? ELSE hotspot_shared_users END
        WHERE id = ? AND tenant_id = ?
    ")->execute([$serviceTypesStr, in_array('hotspot', $services, true), $noSharing, $router['id'], $tenantId]);

    // Resolve tenant portal info for walled garden + login page fetch
    $portalHost     = '';
    $loginServeUrl  = '';
    $portalIp       = '';
    try {
        $tSt = $pdo->prepare("SELECT subdomain, provisioning_token FROM tenants WHERE id = ? LIMIT 1");
        $tSt->execute([$tenantId]);
        $tRow = $tSt->fetch(PDO::FETCH_ASSOC);

        $platformDomain = 'fortunetttech.site';
        try {
            $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
            $pd   = $pdSt ? $pdSt->fetchColumn() : null;
            if ($pd) $platformDomain = $pd;
        } catch (Throwable $_e) {}

        if ($tRow) {
            $sub        = $tRow['subdomain'] ?: '';
            $portalHost = $sub ? "$sub.$platformDomain" : $platformDomain;
            if (!empty($tRow['provisioning_token'])) {
                $loginServeUrl = 'https://' . $portalHost . '/hotspot/login_serve.php?token='
                    . rawurlencode($tRow['provisioning_token']);
            }
        }

        // The portal's IP, for the walled-garden IP entry. A dst-host entry only
        // matches the plaintext HTTP Host header, so HTTPS to the portal — and
        // therefore the M-Pesa STK push fired from the login page — stays blocked
        // until an IP entry exists. This was the missing piece that made the
        // captive portal load but "Pay" do nothing.
        try {
            $ipSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $portalIp = $ipSt ? trim((string)($ipSt->fetchColumn() ?: '')) : '';
        } catch (Throwable $_e) {}
        if (!filter_var($portalIp, FILTER_VALIDATE_IP) && $portalHost) {
            $resolved = @gethostbyname($portalHost);
            $portalIp = ($resolved && $resolved !== $portalHost && filter_var($resolved, FILTER_VALIDATE_IP)) ? $resolved : '';
        }
    } catch (Throwable $_e) {}

    $command = buildRouterServiceCommand($services, (bool)$noSharing, $portalHost, $loginServeUrl, $portalIp, $bridgeName);

    echo json_encode([
        'status'             => 'success',
        'message'            => 'Configuration generated for: ' . implode(', ', $services),
        'services'           => $services,
        'command'            => $command,
        'hotspot_no_sharing' => (bool)$noSharing,
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
