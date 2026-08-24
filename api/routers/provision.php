<?php
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/tenant.php';
require_once '../../classes/WireGuardManager.php';
ob_clean();
header('Content-Type: application/json');

$token    = $_GET['token']    ?? '';
$identity = $_GET['identity'] ?? 'MikroTik';
$format   = $_GET['format']   ?? 'json';

if (!$token) {
    if ($format === 'rsc') { echo ":log error \"Provisioning token required\";"; exit; }
    echo json_encode(['status' => 'error', 'message' => 'Provisioning token required']);
    exit;
}

try {
    $tenantManager = TenantManager::getInstance($pdo);
    $tenantId      = $tenantManager->validateProvisioningToken($token);

    if (!$tenantId) {
        if ($format === 'rsc') { echo ":log error \"Invalid provisioning token\";"; exit; }
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }

    if ($format === 'rsc') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="provision.rsc"');

        // ── URL / server IP ───────────────────────────────────────────────────
        $protocol  = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443)
                     ? 'https://' : 'http://';
        $basePath  = str_replace('/provision.php', '', $_SERVER['SCRIPT_NAME']);
        $serverUrl = $protocol . $_SERVER['HTTP_HOST'] . $basePath . '/auto_register.php';
        $mode      = str_starts_with($serverUrl, 'https://') ? 'mode=https' : 'mode=http';

        // ── VPS public IP — this is the WireGuard endpoint the router dials ───
        //
        // Order matters and used to be wrong. SERVER_ADDR is the *private* address
        // on any VPS behind a load balancer or NAT, so it fails the public-IP filter
        // and we fell through to gethostbyname(HTTP_HOST) — which on a proxied
        // domain resolves to the CDN/WAF edge, not the VPS. The router then pointed
        // its WireGuard peer at an address that does not answer UDP 51820 and the
        // handshake never completed. Every router provisioned that way came up with
        // a dead tunnel.
        //
        // platform_settings.server_external_ip is the operator-declared truth and
        // must win. Only fall back to auto-detection when it is unset.
        $serverIp = '';
        try {
            $ipSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $serverIp = $ipSt ? trim((string)($ipSt->fetchColumn() ?: '')) : '';
        } catch (\Throwable $_e) {}

        if (!filter_var($serverIp, FILTER_VALIDATE_IP)) {
            $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
            $serverIp   = (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
                          ? $serverAddr
                          : (@gethostbyname($_SERVER['HTTP_HOST']) ?: '');
            $serverIpAutodetected = true;
        } else {
            $serverIpAutodetected = false;
        }

        // ── Managed admin credentials ─────────────────────────────────────────
        $adminPassword = bin2hex(random_bytes(8));

        // ── WireGuard setup ───────────────────────────────────────────────────
        // Pre-create a router record so we have an ID for the VPN IP assignment.
        // This row stays "pending" until the router calls auto_register.php.
        $provisionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        $routerWgPriv  = '';
        $routerWgPub   = '';
        $vpnIp         = '';
        $vpsWgPub      = '';
        $wgNote        = '';
        $wgSkipReason  = '';

        // Resolve the VPS public key cache-first. isAvailable() shells out to
        // `sudo wg show` on every call; if PHP-FPM cannot sudo — or wg0 is briefly
        // down during a restart — it returned false and provisioning silently
        // emitted a "# WireGuard skipped" comment. The router came up with no
        // tunnel and nobody noticed until the API calls started failing. The
        // cached key in platform_settings survives all of that.
        $wgAvailable = false;
        try {
            $cached = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='wg_vps_public_key' LIMIT 1")->fetchColumn();
            if ($cached) {
                $vpsWgPub    = (string)$cached;
                $wgAvailable = true;
            } else {
                $vpsWgPub = WireGuardManager::getVpsPublicKey();
                $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('wg_vps_public_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                    ->execute([$vpsWgPub]);
                $wgAvailable = true;
            }
        } catch (\Throwable $wgKeyEx) {
            $wgSkipReason = 'VPS public key unavailable: ' . $wgKeyEx->getMessage();
        }

        // A tunnel endpoint we cannot name is a tunnel that will never connect.
        if ($wgAvailable && !filter_var($serverIp, FILTER_VALIDATE_IP)) {
            $wgAvailable  = false;
            $wgSkipReason = 'No usable VPS public IP. Set platform_settings.server_external_ip to the VPS address that '
                          . 'accepts UDP ' . WireGuardManager::WG_PORT . '.';
        }

        if ($wgAvailable) {
            try {
                $keys         = WireGuardManager::generateKeyPair();
                $routerWgPriv = $keys['private'];
                $routerWgPub  = $keys['public'];

                // Insert pending row to get a router ID → derive VPN IP
                // Use only base columns first, then try to set WG columns (may not exist yet)
                // username/password must be supplied explicitly — `password` is
                // NOT NULL with no default, so a partial INSERT fails under
                // MySQL strict mode (production) while passing on local XAMPP.
                // These are the same credentials the .rsc creates on the router.
                $pdo->prepare("
                    INSERT INTO mikrotik_routers
                        (tenant_id, name, ip_address, username, password, status)
                    VALUES (?, ?, '', 'fortunett_admin', ?, 'pending')
                ")->execute([
                    $tenantId,
                    $identity ?: 'Pending-' . substr($provisionId, 0, 8),
                    $adminPassword,
                ]);

                $routerId = (int)$pdo->lastInsertId();

                // Set WG + provision columns (silently skip if columns missing)
                foreach ([
                    "UPDATE mikrotik_routers SET provision_id=? WHERE id=?"  => [$provisionId, $routerId],
                    "UPDATE mikrotik_routers SET wg_private_key=? WHERE id=?" => [$routerWgPriv, $routerId],
                    "UPDATE mikrotik_routers SET wg_public_key=? WHERE id=?"  => [$routerWgPub, $routerId],
                ] as $sql => $params) {
                    try { $pdo->prepare($sql)->execute($params); } catch (\Exception $e) {}
                }

                $vpnIp    = WireGuardManager::vpnIp($routerId);

                // Update the row with the assigned VPN IP
                $pdo->prepare("UPDATE mikrotik_routers SET vpn_ip=? WHERE id=?")
                    ->execute([$vpnIp, $routerId]);

                // Add this router as a peer on the live VPS WireGuard interface
                WireGuardManager::addPeer($routerWgPub, $vpnIp);

            } catch (\Exception $wgEx) {
                $wgAvailable  = false;
                $wgSkipReason = $wgEx->getMessage();
                error_log('[provision.php] WireGuard error: ' . $wgEx->getMessage());
            }
        }

        if (!$wgAvailable) {
            // Make this impossible to miss. A '#' comment scrolls past unread in
            // the RouterOS terminal, which is how a whole fleet ended up with no
            // tunnel; :log error + :put land in the router log and on screen.
            $reason  = $wgSkipReason ?: 'WireGuard is not running on the VPS. Run setup_wireguard_server.sh first.';
            $wgNote  = "# ── WireGuard tunnel NOT configured ──────────────────────────────────\n";
            $wgNote .= '# ' . str_replace(["\n", "\r"], ' ', $reason) . "\n";
            $wgNote .= ':log error "[Fortunett] WireGuard tunnel NOT configured — ' . addslashes(substr(str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $reason), 0, 160)) . '";' . "\n";
            $wgNote .= ':put "[Fortunett] WARNING: no WireGuard tunnel. This router will NOT be manageable from the portal.";' . "\n";
            error_log('[provision.php] WireGuard skipped for tenant ' . $tenantId . ': ' . $reason);
        }

        $t = $token;

        // ── RSC Output ────────────────────────────────────────────────────────
        echo "# Fortunett Technologies Provisioning Script\n";
        echo "# Generated:    " . date('Y-m-d H:i:s') . "\n";
        echo "# Tenant ID:    $tenantId\n";
        echo "# Provision ID: $provisionId\n";
        if ($vpnIp) echo "# VPN IP:       $vpnIp\n";
        echo "# Server IP:    " . ($serverIp ?: 'unknown')
             . ($serverIpAutodetected ? '  (AUTO-DETECTED — if this is a CDN/proxy address the tunnel will not connect;'
                                        . ' set platform_settings.server_external_ip)' : '  (from platform_settings)') . "\n";
        echo "# Safe to re-run — cleans up previous config first.\n\n";

        echo ":log info \"[Fortunett] Starting provisioning — $identity\";\n\n";

        // 1. Identity
        echo "/system identity set name=\"$identity\";\n\n";

        // 2. Managed admin user
        echo ":do { /user remove [find name=\"fortunett_admin\"] } on-error={};\n";
        echo "/user add name=\"fortunett_admin\" group=full password=\"$adminPassword\" comment=\"Managed by Fortunett\";\n\n";

        // 3. WireGuard VPN tunnel (bypasses NAT — required for VPS→router API calls)
        if ($wgAvailable && $vpnIp && $routerWgPriv && $vpsWgPub) {
            $vpsEndpoint = $serverIp;
            $wgPort      = WireGuardManager::WG_PORT;
            $vpsVpnIp    = WireGuardManager::VPS_VPN_IP;

            echo "# ── WireGuard VPN tunnel ──────────────────────────────────────────────\n";

            // Wipe ALL WireGuard state first, not just the interface named
            // wg-fortunett. Re-running provisioning used to leave orphaned
            // interfaces (*8, *9, …) behind, and every one of their peers also
            // claimed allowed-address=10.200.200.0/24. RouterOS then had several
            // interfaces competing for the same route, picked one at random, and
            // the handshake never settled — the single biggest cause of tunnels
            // that come up and then fall behind. These routers are platform-managed
            // for this VPN only, so wiping is safe and makes the result deterministic.
            echo ":do { /interface/wireguard/peers remove [find] } on-error={};\n";
            echo ":do { /interface/wireguard remove [find] } on-error={};\n";
            echo ":do { /ip address remove [find address~\"10.200.200.\"] } on-error={};\n";

            echo "/interface/wireguard add name=\"wg-fortunett\" listen-port=13231 private-key=\"$routerWgPriv\";\n";
            echo "/interface/wireguard/peers add interface=\"wg-fortunett\" public-key=\"$vpsWgPub\"";
            echo " endpoint-address=$vpsEndpoint endpoint-port=$wgPort";
            echo " allowed-address=10.200.200.0/24 persistent-keepalive=25;\n";
            echo "/ip address add address=\"$vpnIp/24\" interface=\"wg-fortunett\";\n\n";

            // API service. The address list deliberately includes RFC1918 as well
            // as the VPS VPN IP: restricting the API to 10.200.200.1/32 alone means
            // that the moment the tunnel drops, the router is unmanageable from the
            // portal AND from its own LAN, and the only recovery is a site visit.
            // The router is behind NAT, so the private ranges add no public exposure.
            echo "/ip service set api disabled=no port=8728 address=$vpsVpnIp/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16;\n\n";

            // Firewall: allow API from the VPN IP, placed at the top of input.
            echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API-VPN\"] } on-error={};\n";
            echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API\"] } on-error={};\n";
            echo "/ip firewall filter add chain=input action=accept protocol=tcp";
            echo " src-address=$vpsVpnIp dst-port=8728 comment=\"Fortunett-API-VPN\";\n";
            echo "/ip firewall filter move [find comment=\"Fortunett-API-VPN\"] destination=0;\n\n";

            // ── Tunnel watchdog ──────────────────────────────────────────────
            // A WireGuard peer caches its resolved endpoint. After a WAN IP change,
            // an ISP reconnect or a router reboot the handshake can stop without
            // the interface ever reporting a fault — this is exactly what "falling
            // behind" looks like from the portal side. The watchdog pings the VPS
            // over the tunnel every 2 minutes and, after two consecutive failures,
            // re-arms the interface and re-pins the endpoint, which forces a new
            // handshake. Costs nothing when the tunnel is healthy.
            $wgWatch  = ':local ok [/ping ' . $vpsVpnIp . ' count=3 interval=200ms];';
            $wgWatch .= ':if ($ok=0) do={';
            $wgWatch .=   ':log warning \\"[Fortunett] WireGuard unreachable - re-arming tunnel\\";';
            $wgWatch .=   ':do {/interface/wireguard set [find name=\\"wg-fortunett\\"] disabled=yes} on-error={};';
            $wgWatch .=   ':delay 2s;';
            $wgWatch .=   ':do {/interface/wireguard set [find name=\\"wg-fortunett\\"] disabled=no} on-error={};';
            $wgWatch .=   ':do {/interface/wireguard/peers set [find interface=\\"wg-fortunett\\"] endpoint-address=' . $vpsEndpoint . ' endpoint-port=' . $wgPort . '} on-error={};';
            $wgWatch .= '}';
            echo ":do { /system scheduler remove [find name=\"fortunett_wg_watchdog\"] } on-error={};\n";
            echo "/system scheduler add name=\"fortunett_wg_watchdog\" interval=2m start-time=startup on-event=\"$wgWatch\";\n\n";
        } else {
            echo $wgNote;
            // WireGuard unavailable — enable API but DO NOT set an address restriction.
            // If the router already has a WG tunnel with address=10.200.200.1/32 from a
            // previous provisioning, we must NOT overwrite it with the public server IP.
            // The existing restriction is preserved; access will only work once WG is up.
            echo "/ip service set api disabled=no port=8728;\n";
            echo "# NOTE: API address restriction NOT changed — run setup_wireguard_server.sh on VPS first.\n\n";
        }

        // 4. Heartbeat scheduler — posts to auto_register.php every 5 min
        $wgIpParam = $vpnIp ? "&vpn_ip=$vpnIp" : '';
        echo ":local cmd \"/tool fetch $mode url=\\\"$serverUrl\\\" http-method=post";
        echo " http-data=\\\"provisioning_token=$t&provision_id=$provisionId$wgIpParam";
        echo "&router_ip=\\\$[/ip address get [find interface=ether1] address]";
        echo "&router_mac=\\\$[/interface ethernet get ether1 mac-address]";
        echo "&router_identity=\\\$[/system identity get name]";
        echo "&router_username=fortunett_admin&router_password=$adminPassword\\\"";
        echo " keep-result=no\";\n";
        echo ":do { /system scheduler remove [find name=\"fortunett_heartbeat\"] } on-error={};\n";
        echo "/system scheduler add name=\"fortunett_heartbeat\" interval=5m on-event=\$cmd start-time=startup;\n\n";

        // 5. Register immediately
        echo ":delay 3s;\n";
        echo "/tool fetch $mode url=\"$serverUrl\" http-method=post";
        echo " http-data=\"provisioning_token=$t&provision_id=$provisionId$wgIpParam";
        echo "&router_ip=\$[/ip address get [find interface=ether1] address]";
        echo "&router_mac=\$[/interface ethernet get ether1 mac-address]";
        echo "&router_identity=\$[/system identity get name]";
        echo "&router_username=fortunett_admin&router_password=$adminPassword\"";
        echo " keep-result=no;\n\n";

        // 6. Download branded hotspot login page to every html-directory path.
        // RouterOS 7 sometimes stores html-directory=flash/hotspot but serves from
        // flash/flash/hotspot, and hotspots built outside the setup wizard use a
        // bare hotspot/ — writing all three ensures it always finds login.html.
        $htmlDirs       = ['flash/hotspot', 'flash/flash/hotspot', 'hotspot'];
        $loginServeBase = $protocol . $_SERVER['HTTP_HOST'];
        $loginServeUrl  = $loginServeBase . '/hotspot/login_serve.php?token=' . rawurlencode($t);
        echo "# ── Hotspot login page (all paths for RouterOS 7 compatibility) ──────────\n";
        foreach ($htmlDirs as $dir) {
            echo ":do { /file remove [find name=\"$dir/login.html\"] } on-error={};\n";
            echo "/tool fetch $mode url=\"$loginServeUrl\" dst-path=$dir/login.html check-certificate=no;\n";
        }

        // login.html alone is not enough. redirect.html is what the servlet serves
        // to an intercepted client to bounce it to /login — without it the redirect
        // fires but returns an empty body, which is indistinguishable from having
        // no captive portal at all. Escape " and $ so RouterOS stores them literally
        // instead of expanding them as script variables.
        $supportPages = [
            'redirect.html' => '<meta http-equiv="refresh" content="0;url=/login">',
            'alogin.html'   => '<meta http-equiv="refresh" content="0;url=$(link-orig)">',
            'logout.html'   => '<meta http-equiv="refresh" content="0;url=/login">',
            'error.html'    => '<html><body><h3>$(error)</h3><a href="$(link-login)">Back to login</a></body></html>',
        ];
        echo "\n# ── Servlet support pages (redirect.html drives the captive bounce) ──────\n";
        foreach ($htmlDirs as $dir) {
            foreach ($supportPages as $fname => $body) {
                $esc = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $body);
                echo ":do { /file remove [find name=\"$dir/$fname\"] } on-error={};\n";
                echo ":do { /file add name=\"$dir/$fname\" contents=\"$esc\" } on-error={};\n";
            }
        }
        echo "\n";

        echo ":do { /system scheduler remove [find name=\"fortunett_login_refresh\"] } on-error={};\n";
        $schedDsts = implode(';', array_map(fn($d) => "$d/login.html", $htmlDirs));
        $loginCmd = "foreach dst in {" . $schedDsts . "} do={/tool fetch $mode url=\\\"$loginServeUrl\\\" dst-path=\\\$dst check-certificate=no}";
        echo "/system scheduler add name=\"fortunett_login_refresh\" interval=24h start-time=startup on-event=\"$loginCmd\";\n\n";

        echo ":log info \"[Fortunett] Provisioning complete — VPN IP: $vpnIp\";\n";
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'Token valid. Use ?format=rsc for script.']);

} catch (Exception $e) {
    if ($format === 'rsc') {
        echo ":log error \"Provisioning failed: " . addslashes($e->getMessage()) . "\";";
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
