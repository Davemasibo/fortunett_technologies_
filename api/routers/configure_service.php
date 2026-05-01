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

// Hotspot session-sharing setting: 1 = one session per user, 0 = unlimited
$noSharing = (int)($_POST['hotspot_no_sharing'] ?? 0);
$sharedUsers = $noSharing ? '1' : 'unlimited';

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

    // Persist both service_types and the hotspot sharing setting
    $serviceTypesStr = implode(',', $services);
    $pdo->prepare("
        UPDATE mikrotik_routers
        SET service_types = ?, hotspot_shared_users = ?
        WHERE id = ?
    ")->execute([$serviceTypesStr, $noSharing, $router['id']]);

    // Resolve tenant portal info for walled garden + login page fetch
    $portalHost     = '';
    $loginServeUrl  = '';
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
                    . urlencode($tRow['provisioning_token']);
            }
        }
    } catch (Throwable $_e) {}

    // Build one RouterOS command string per requested service.
    // Every block uses :do { remove } on-error={} before adding so the script is
    // idempotent — safe to run multiple times even if config already exists.
    $commands = [];
    foreach ($services as $service) {
        if ($service === 'pppoe') {
            $commands[] = implode(' ', [
                // Remove existing (ignore errors if they don't exist)
                ':do { /interface pppoe-server server remove [find service-name=pppoe-service] } on-error={};',
                ':do { /ppp profile remove [find name=pppoe-profile] } on-error={};',
                ':do { /ip pool remove [find name=pppoe-pool] } on-error={};',
                // Create fresh
                '/ip pool add name=pppoe-pool ranges=10.10.10.2-10.10.10.254;',
                '/ppp profile add name=pppoe-profile local-address=10.10.10.1 remote-address=pppoe-pool dns-server=8.8.8.8,8.8.4.4;',
                '/interface pppoe-server server add service-name=pppoe-service interface=ether2 default-profile=pppoe-profile disabled=no;',
            ]);

        } elseif ($service === 'hotspot') {
            // shared-users: '1' = one device at a time (session sharing off), 'unlimited' = no cap
            $cmds = [
                // Remove existing (reverse dependency order)
                ':do { /ip hotspot remove [find name=hotspot1] } on-error={};',
                ':do { /ip hotspot profile remove [find name=hsprof1] } on-error={};',
                ':do { /ip pool remove [find name=hs-pool] } on-error={};',
                ':do { /ip address remove [find address="10.5.50.1/24"] } on-error={};',
                // Create fresh — html-directory and login-by are required for captive portal
                '/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254;',
                '/ip address add address=10.5.50.1/24 interface=ether2;',
                '/ip hotspot profile add name=hsprof1 dns-name=hotspot.fortunett.com hotspot-address=10.5.50.1 html-directory=flash/hotspot login-by=http-pap,cookie;',
                "/ip hotspot user profile set [find name=default] rate-limit=5M/5M shared-users={$sharedUsers};",
                '/ip hotspot add name=hotspot1 interface=ether2 address-pool=hs-pool profile=hsprof1 disabled=no;',
                // NAT masquerade — without this hotspot clients get IPs but cannot reach the internet
                ':do { /ip firewall nat remove [find comment="FortuNett-Hotspot-NAT"] } on-error={};',
                '/ip firewall nat add chain=srcnat src-address=10.5.50.0/24 action=masquerade comment="FortuNett-Hotspot-NAT";',
            ];

            // Walled garden — portal must be reachable before the client authenticates
            if ($portalHost) {
                $cmds[] = ":do { /ip hotspot walled-garden remove [find comment=\"FortuNett-Portal\"] } on-error={};";
                $cmds[] = "/ip hotspot walled-garden add dst-host=\"$portalHost\" comment=\"FortuNett-Portal\";";
            }

            // Pull the branded login.html from the server onto the router flash
            if ($loginServeUrl) {
                $esc = addslashes($loginServeUrl);
                $cmds[] = ":do { /file remove [find name=\"flash/hotspot/login.html\"] } on-error={};";
                $cmds[] = "/tool fetch mode=https url=\"$esc\" dst-path=flash/hotspot/login.html check-certificate=no;";
            }

            $commands[] = implode(' ', $cmds);
        }
    }

    echo json_encode([
        'status'              => 'success',
        'message'             => 'Configuration generated for: ' . implode(', ', $services),
        'services'            => $services,
        'commands'            => $commands,
        'hotspot_no_sharing'  => (bool)$noSharing,
        // Legacy single-command field for backwards compatibility
        'command'             => $commands[0] ?? '',
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
