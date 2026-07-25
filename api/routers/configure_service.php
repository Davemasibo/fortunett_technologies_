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
                    . rawurlencode($tRow['provisioning_token']);
            }
        }
    } catch (Throwable $_e) {}

    // ── Build a SINGLE-LINE, comment-free RouterOS script ─────────────────────
    //
    // The whole config is emitted on ONE console line (statements separated by
    // ';') so the :local variable $bn (the target bridge) stays in scope for the
    // entire run. When a MULTI-line script is pasted into the interactive RouterOS
    // terminal, :local scope resets on every newline — so $bn went empty between
    // lines and produced "ambiguous value of bridge" + a cascade of syntax errors.
    // A one-liner pastes and runs atomically, and is the minimal onboarding step:
    // one copy, one paste.
    //
    // No '#' comments — in a one-liner a '#' would comment out the entire rest of
    // the line. Names/comments that contain spaces or hyphens are quoted.
    //
    // Bridge strategy: reuse the first enabled bridge if one exists, else create
    // bridge-local; then fold every Ethernet port except ether1 (WAN) into it.
    // Both PPPoE and hotspot bind to $bn, so they coexist on one shared bridge
    // rather than fighting over a raw physical interface.
    $parts = [];
    $parts[] = ':local bn ""';
    $parts[] = ':local bf [/interface bridge find where disabled=no]';
    $parts[] = ':if ([:len $bf]>0) do={:set bn [/interface bridge get ($bf->0) name]} else={/interface bridge add name=bridge-local auto-mac=yes comment="FortuNett-Bridge"; :set bn "bridge-local"}';
    $parts[] = ':foreach i in=[/interface ethernet find where name!="ether1"] do={:local n [/interface ethernet get $i name]; :do {/ip address remove [find interface=$n]} on-error={}; :if ([:len [/interface bridge port find where interface=$n]]=0) do={/interface bridge port add bridge=$bn interface=$n}}';
    // Also fold any WiFi interface into the bridge so wireless hotspot clients are
    // covered — otherwise only wired clients hit the hotspot. Wrapped in :do because
    // wired-only routers / CHR have no /interface wireless command.
    $parts[] = ':do {:foreach w in=[/interface wireless find] do={:local wn [/interface wireless get $w name]; :if ([:len [/interface bridge port find where interface=$wn]]=0) do={/interface bridge port add bridge=$bn interface=$wn}}} on-error={}';

    if (in_array('pppoe', $services, true)) {
        $parts[] = ':do {/interface pppoe-server server remove [find service-name=pppoe-service]} on-error={}';
        $parts[] = ':do {/ip pool remove [find name=pppoe-pool]} on-error={}';
        $parts[] = ':do {/ppp profile remove [find name=pppoe-profile]} on-error={}';
        $parts[] = '/ip pool add name=pppoe-pool ranges=10.10.10.2-10.10.10.254';
        $parts[] = '/ppp profile add name=pppoe-profile local-address=10.10.10.1 remote-address=pppoe-pool dns-server=8.8.8.8,8.8.4.4';
        $parts[] = '/interface pppoe-server server add service-name=pppoe-service interface=$bn default-profile=pppoe-profile disabled=no';
    }

    if (in_array('hotspot', $services, true)) {
        $parts[] = ':do {/ip hotspot remove [find name=hotspot1]} on-error={}';
        $parts[] = ':do {/ip hotspot profile remove [find name=hsprof1]} on-error={}';
        $parts[] = ':do {/ip pool remove [find name=hs-pool]} on-error={}';
        $parts[] = ':do {/ip address remove [find address="10.5.50.1/24"]} on-error={}';
        $parts[] = '/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254';
        $parts[] = '/ip address add address=10.5.50.1/24 interface=$bn';
        // DHCP server for the hotspot subnet. WITHOUT this, a client that connects
        // never gets a 10.5.50.x lease, so it can't reach the gateway (10.5.50.1)
        // and the captive portal never loads — the #1 reason "the portal isn't
        // pushing". Remove any DHCP already bound to the bridge first (e.g. the
        // factory 'defconf' server on 192.168.88.0/24, which would hand out a
        // foreign IP). dns-server is the gateway so the hotspot's DNS proxy can
        // intercept lookups and trigger the OS captive-portal detection redirect.
        $parts[] = ':do {/ip dhcp-server remove [find interface=$bn]} on-error={}';
        $parts[] = ':do {/ip dhcp-server remove [find name=hs-dhcp]} on-error={}';
        $parts[] = ':do {/ip dhcp-server network remove [find address="10.5.50.0/24"]} on-error={}';
        $parts[] = '/ip dhcp-server add name=hs-dhcp interface=$bn address-pool=hs-pool lease-time=1h disabled=no';
        $parts[] = '/ip dhcp-server network add address=10.5.50.0/24 gateway=10.5.50.1 dns-server=10.5.50.1';
        // The hotspot DNS proxy forwards to the router's own resolver — make sure it
        // has upstreams and answers queries from hotspot clients.
        $parts[] = '/ip dns set servers=8.8.8.8,8.8.4.4 allow-remote-requests=yes';
        // html-directory MUST be "hotspot" (not "flash/hotspot"): RouterOS 7 prepends
        // flash/ internally, so "flash/hotspot" becomes flash/flash/hotspot and the
        // hotspot can't find login.html → it serves a 404 instead of the portal.
        // "hotspot" resolves to flash/hotspot, which is where the fetch below writes.
        $parts[] = '/ip hotspot profile add name=hsprof1 dns-name=hotspot.fortunett.com hotspot-address=10.5.50.1 html-directory=hotspot login-by=http-pap,cookie';
        $parts[] = '/ip hotspot user profile set [find name=default] rate-limit=5M/5M shared-users=' . $sharedUsers;
        $parts[] = '/ip hotspot add name=hotspot1 interface=$bn address-pool=hs-pool profile=hsprof1 disabled=no';
        $parts[] = ':do {/ip firewall nat remove [find comment="FortuNett-Hotspot-NAT"]} on-error={}';
        $parts[] = '/ip firewall nat add chain=srcnat src-address=10.5.50.0/24 action=masquerade comment="FortuNett-Hotspot-NAT"';

        if ($portalHost) {
            $parts[] = ':do {/ip hotspot walled-garden remove [find comment="FortuNett-Portal"]} on-error={}';
            $parts[] = '/ip hotspot walled-garden add dst-host="' . $portalHost . '" comment="FortuNett-Portal"';
        }
        if ($loginServeUrl) {
            $parts[] = ':do {/file remove [find name="flash/hotspot/login.html"]} on-error={}';
            $parts[] = '/tool fetch mode=https url="' . addslashes($loginServeUrl) . '" dst-path=flash/hotspot/login.html check-certificate=no';
        }
    }

    // One physical line — the entire configuration in a single copy/paste.
    $command = implode('; ', $parts) . ';';

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
