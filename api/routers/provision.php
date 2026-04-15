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

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
        $serverIp   = (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
                      ? $serverAddr
                      : (@gethostbyname($_SERVER['HTTP_HOST']) ?: '');

        // ── Managed admin credentials ─────────────────────────────────────────
        $adminPassword = bin2hex(random_bytes(8));

        // ── WireGuard setup ───────────────────────────────────────────────────
        // Pre-create a router record so we have an ID for the VPN IP assignment.
        // This row stays "pending" until the router calls auto_register.php.
        $provisionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        $wgAvailable   = WireGuardManager::isAvailable();
        $routerWgPriv  = '';
        $routerWgPub   = '';
        $vpnIp         = '';
        $vpsWgPub      = '';
        $wgNote        = '';

        if ($wgAvailable) {
            try {
                $keys         = WireGuardManager::generateKeyPair();
                $routerWgPriv = $keys['private'];
                $routerWgPub  = $keys['public'];
                $vpsWgPub     = WireGuardManager::getVpsPublicKey();

                // Insert pending row to get a router ID → derive VPN IP
                // Use only base columns first, then try to set WG columns (may not exist yet)
                $pdo->prepare("
                    INSERT INTO mikrotik_routers
                        (tenant_id, name, ip_address, status)
                    VALUES (?, ?, '', 'pending')
                ")->execute([$tenantId, $identity ?: 'Pending-' . substr($provisionId, 0, 8)]);

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
                $wgAvailable = false;
                $wgNote      = '# WireGuard setup skipped: ' . $wgEx->getMessage() . "\n";
                error_log('[provision.php] WireGuard error: ' . $wgEx->getMessage());
            }
        } else {
            $wgNote = "# WireGuard not running on VPS — tunnel skipped. Run setup_wireguard_server.sh first.\n";
        }

        $t = $token;

        // ── RSC Output ────────────────────────────────────────────────────────
        echo "# Fortunett Technologies Provisioning Script\n";
        echo "# Generated:    " . date('Y-m-d H:i:s') . "\n";
        echo "# Tenant ID:    $tenantId\n";
        echo "# Provision ID: $provisionId\n";
        if ($vpnIp) echo "# VPN IP:       $vpnIp\n";
        echo "# Server IP:    " . ($serverIp ?: 'unknown') . "\n";
        echo "# Safe to re-run — cleans up previous config first.\n\n";

        echo ":log info \"[Fortunett] Starting provisioning — $identity\";\n\n";

        // 1. Identity
        echo "/system identity set name=\"$identity\";\n\n";

        // 2. Managed admin user
        echo ":do { /user remove [find name=\"fortunett_admin\"] } on-error={};\n";
        echo "/user add name=\"fortunett_admin\" group=full password=\"$adminPassword\" comment=\"Managed by Fortunett\";\n\n";

        // 3. WireGuard VPN tunnel (bypasses NAT — required for VPS→router API calls)
        if ($wgAvailable && $vpnIp && $routerWgPriv && $vpsWgPub) {
            $vpsEndpoint = $serverIp ?: $_SERVER['HTTP_HOST'];
            echo "# ── WireGuard VPN tunnel ──────────────────────────────────────────────\n";
            echo ":do { /interface/wireguard remove [find name=\"wg-fortunett\"] } on-error={};\n";
            echo "/interface/wireguard add name=\"wg-fortunett\" listen-port=13231 private-key=\"$routerWgPriv\";\n";
            echo ":do { /interface/wireguard/peers remove [find interface=\"wg-fortunett\"] } on-error={};\n";
            echo "/interface/wireguard/peers add interface=\"wg-fortunett\" public-key=\"$vpsWgPub\"";
            echo " endpoint-address=$vpsEndpoint endpoint-port=" . WireGuardManager::WG_PORT;
            echo " allowed-address=10.200.200.0/24 persistent-keepalive=25;\n";
            echo ":do { /ip address remove [find interface=\"wg-fortunett\"] } on-error={};\n";
            echo "/ip address add address=\"$vpnIp/24\" interface=\"wg-fortunett\";\n\n";

            // API service: restrict to VPN only
            echo "/ip service set api disabled=no port=8728 address=" . WireGuardManager::VPS_VPN_IP . "/32;\n\n";

            // Firewall: allow API from VPN IP only
            echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API-VPN\"] } on-error={};\n";
            echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API\"] } on-error={};\n";
            echo "/ip firewall filter add chain=input action=accept protocol=tcp";
            echo " src-address=" . WireGuardManager::VPS_VPN_IP . " dst-port=8728 comment=\"Fortunett-API-VPN\";\n";
            echo "/ip firewall filter move [find comment=\"Fortunett-API-VPN\"] destination=0;\n\n";
        } else {
            echo $wgNote;
            // Fallback: open API service without VPN restriction
            if ($serverIp) {
                echo "/ip service set api disabled=no port=8728 address=$serverIp/32;\n";
                echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API\"] } on-error={};\n";
                echo "/ip firewall filter add chain=input action=accept protocol=tcp src-address=$serverIp/32 dst-port=8728 comment=\"Fortunett-API\";\n";
                echo "/ip firewall filter move [find comment=\"Fortunett-API\"] destination=0;\n\n";
            } else {
                echo "/ip service set api disabled=no port=8728;\n\n";
            }
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
