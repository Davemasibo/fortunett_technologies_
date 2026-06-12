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

    // ── Bridge detection preamble ─────────────────────────────────────────────
    //
    // Runs ONCE before any service block. Uses RouterOS scripting to:
    //   1. Find any existing non-disabled bridge and reuse it under $bname.
    //      If none exists, create bridge-local.
    //   2. Loop over every Ethernet interface EXCEPT ether1 (WAN) and add it
    //      to the bridge if it is not already a member — handles any port count
    //      (ether2, ether3 … etherN) without hard-coding interface names.
    //   3. Strip any raw IP from each access port before bridging it.
    //
    // Both the PPPoE server and hotspot server below reference $bname so they
    // coexist on a single physically-segregated bridge.
    $bridgePreamble = <<<'RSC'
# ═══════════════════════════════════════════════════════════════════════════════
# FortuNett: Bridge Detection + Access-Port Discovery
# ═══════════════════════════════════════════════════════════════════════════════
# Step 1 — reuse any existing bridge, or create bridge-local
:local bname "";
:local bfound [/interface bridge find !disabled];
:if ([:len $bfound] > 0) do={
    :set bname [/interface bridge get ($bfound->0) name];
    :log info ("FortuNett: reusing existing bridge: " . $bname);
} else={
    :set bname "bridge-local";
    :do { /interface bridge add name=bridge-local auto-mac=yes comment="FortuNett-Bridge" } on-error={};
    :log info "FortuNett: created bridge-local";
};
# Step 2 — dump all access ports (every Ethernet except ether1 WAN) into the bridge
:foreach iface in=[/interface ethernet find !disabled] do={
    :local ifname [/interface ethernet get $iface name];
    :if ($ifname != "ether1") do={
        :do { /ip address remove [find interface=$ifname] } on-error={};
        :if ([:len [/interface bridge port find interface=$ifname]] = 0) do={
            /interface bridge port add bridge=$bname interface=$ifname;
        };
    };
};
RSC;

    // ── Per-service command blocks (reference $bname set by the preamble) ──────
    $serviceBlocks = [];

    foreach ($services as $service) {
        if ($service === 'pppoe') {
            // $bname is a RouterOS variable (single-quoted — no PHP interpolation)
            $serviceBlocks['pppoe'] = <<<'RSC'
# ═══════════════════════════════════════════════════════════════════════════════
# FortuNett: PPPoE Server — bound to bridge $bname
# ═══════════════════════════════════════════════════════════════════════════════
:do { /interface pppoe-server server remove [find service-name=pppoe-service] } on-error={};
:do { /ppp profile remove [find name=pppoe-profile] } on-error={};
:do { /ip pool remove [find name=pppoe-pool] } on-error={};
/ip pool add name=pppoe-pool ranges=10.10.10.2-10.10.10.254;
/ppp profile add name=pppoe-profile local-address=10.10.10.1 remote-address=pppoe-pool dns-server=8.8.8.8,8.8.4.4;
/interface pppoe-server server add service-name=pppoe-service interface=$bname default-profile=pppoe-profile disabled=no;
RSC;

        } elseif ($service === 'hotspot') {
            // $sharedUsers is PHP-interpolated; $bname is a literal RouterOS var
            // (single-quoted array entries pass $bname through as-is)
            $hsLines = [
                '# ═══════════════════════════════════════════════════════════════════════════════',
                '# FortuNett: Hotspot Server — bound to bridge $bname',
                '# ═══════════════════════════════════════════════════════════════════════════════',
                ':do { /ip hotspot remove [find name=hotspot1] } on-error={};',
                ':do { /ip hotspot profile remove [find name=hsprof1] } on-error={};',
                ':do { /ip pool remove [find name=hs-pool] } on-error={};',
                ':do { /ip address remove [find address="10.5.50.1/24"] } on-error={};',
                '/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254;',
                '/ip address add address=10.5.50.1/24 interface=$bname;',
                '/ip hotspot profile add name=hsprof1 dns-name=hotspot.fortunett.com hotspot-address=10.5.50.1 html-directory=flash/hotspot login-by=http-pap,cookie;',
                "/ip hotspot user profile set [find name=default] rate-limit=5M/5M shared-users={$sharedUsers};",
                '/ip hotspot add name=hotspot1 interface=$bname address-pool=hs-pool profile=hsprof1 disabled=no;',
                ':do { /ip firewall nat remove [find comment="FortuNett-Hotspot-NAT"] } on-error={};',
                '/ip firewall nat add chain=srcnat src-address=10.5.50.0/24 action=masquerade comment="FortuNett-Hotspot-NAT";',
            ];

            if ($portalHost) {
                $hsLines[] = ":do { /ip hotspot walled-garden remove [find comment=\"FortuNett-Portal\"] } on-error={};";
                $hsLines[] = "/ip hotspot walled-garden add dst-host=\"{$portalHost}\" comment=\"FortuNett-Portal\";";
            }
            if ($loginServeUrl) {
                $esc = addslashes($loginServeUrl);
                $hsLines[] = ":do { /file remove [find name=\"flash/hotspot/login.html\"] } on-error={};";
                $hsLines[] = "/tool fetch mode=https url=\"{$esc}\" dst-path=flash/hotspot/login.html check-certificate=no;";
            }

            $serviceBlocks['hotspot'] = implode("\n", $hsLines);
        }
    }

    // ── Build output ───────────────────────────────────────────────────────────
    // 'command'  — single unified script (bridge preamble + all service blocks).
    //              Paste the whole thing once in the RouterOS terminal; $bname is
    //              set by the preamble and shared by every service block below it.
    // 'commands' — array: first element = bridge preamble, then one per service.
    //              Lets the frontend display each block separately if needed.
    $commands = array_merge([$bridgePreamble], array_values($serviceBlocks));

    echo json_encode([
        'status'             => 'success',
        'message'            => 'Configuration generated for: ' . implode(', ', $services),
        'services'           => $services,
        'commands'           => $commands,
        'hotspot_no_sharing' => (bool)$noSharing,
        'command'            => implode("\n\n", $commands),
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
