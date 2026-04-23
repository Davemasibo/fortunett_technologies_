<?php
/**
 * TEMPORARY DEBUG — delete after diagnosis.
 */
ob_start(); ini_set('display_errors', 0);
require_once '../includes/db_master.php';
require_once '../includes/auth.php';
require_once '../classes/MikrotikAPI.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['error' => 'Login first']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();

$rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port, status FROM mikrotik_routers WHERE tenant_id = ?");
$rSt->execute([$tenant_id]);
$routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

$results = [];

foreach ($routers as $router) {
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $port = (int)($router['api_port'] ?: 8728);

    $r = [
        'router_id'   => $router['id'],
        'router_name' => $router['name'],
        'connect_ip'  => $connectIp,
        'db_status'   => $router['status'],
        'port'        => $port,
    ];

    $sock = @fsockopen($connectIp, $port, $errno, $errstr, 3);
    if (!$sock) {
        $r['reachable'] = false;
        $r['error'] = "TCP unreachable: $errstr";
        $results[] = $r;
        continue;
    }
    fclose($sock);
    $r['reachable'] = true;

    try {
        $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
        $mk->connect();
        $r['connected'] = true;

        // 1. Hotspot servers configured on this router
        $rawServers = $mk->comm('/ip/hotspot/print');
        $servers = [];
        foreach ($rawServers as $item) {
            if (isset($item['!re'])) { unset($item['!re']); $servers[] = $item; }
        }
        $r['hotspot_servers'] = $servers;

        // 2. Active hotspot sessions
        $rawActive = $mk->comm('/ip/hotspot/active/print');
        $active = [];
        foreach ($rawActive as $item) {
            if (isset($item['!re'])) { unset($item['!re']); $active[] = $item; }
            if (isset($item['!trap'])) { $r['hotspot_active_trap'] = $item['message'] ?? 'trap'; }
        }
        $r['hotspot_active_count'] = count($active);
        $r['hotspot_active']       = $active;

        // 3. Hotspot host table
        $rawHosts = $mk->comm('/ip/hotspot/host/print');
        $hosts = [];
        foreach ($rawHosts as $item) {
            if (isset($item['!re'])) { unset($item['!re']); $hosts[] = $item; }
        }
        $r['host_count'] = count($hosts);
        $r['hosts']      = $hosts;

        // 4. PPPoE active (baseline)
        $pppoe = $mk->getActiveSessions();
        $r['pppoe_active_count'] = count($pppoe);

        // 5. Wireless layer-2 clients
        $rawWireless = $mk->comm('/interface/wireless/registration-table/print');
        $wireless = [];
        foreach ($rawWireless as $item) {
            if (isset($item['!re'])) { unset($item['!re']); $wireless[] = $item; }
        }
        $r['wireless_clients_count'] = count($wireless);
        $r['wireless_clients'] = array_map(fn($w) => [
            'mac'       => $w['mac-address']      ?? '',
            'interface' => $w['interface']         ?? '',
            'signal'    => $w['signal-strength']   ?? '',
            'uptime'    => $w['uptime']            ?? '',
        ], $wireless);

        // ── Why is the hotspot server invalid? ──────────────────────────────────
        // Check the resources the hotspot server references:
        //  a) IP addresses on all interfaces
        //  b) IP pools
        //  c) Hotspot user profiles
        // This tells you exactly what is missing so you can fix it on the router.

        // a) Interface IP addresses
        $rawAddrs = $mk->comm('/ip/address/print');
        $addrs = [];
        foreach ($rawAddrs as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $addrs[] = [
                    'address'   => $item['address']   ?? '',
                    'interface' => $item['interface'] ?? '',
                    'disabled'  => $item['disabled']  ?? 'false',
                    'invalid'   => $item['invalid']   ?? 'false',
                ];
            }
        }
        $r['ip_addresses'] = $addrs;

        // b) IP address pools
        $rawPools = $mk->comm('/ip/pool/print');
        $pools = [];
        foreach ($rawPools as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $pools[] = [
                    'name'   => $item['name']   ?? '',
                    'ranges' => $item['ranges']  ?? '',
                ];
            }
        }
        $r['ip_pools'] = $pools;

        // c) Hotspot user profiles
        $rawProfiles = $mk->comm('/ip/hotspot/user/profile/print');
        $profiles = [];
        foreach ($rawProfiles as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $profiles[] = [
                    'name'         => $item['name']         ?? '',
                    'rate-limit'   => $item['rate-limit']   ?? '',
                    'shared-users' => $item['shared-users'] ?? '',
                ];
            }
        }
        $r['hotspot_user_profiles'] = $profiles;

        // d) Hotspot server profiles (referenced by the hotspot server)
        $rawSrvProfiles = $mk->comm('/ip/hotspot/profile/print');
        $srvProfiles = [];
        foreach ($rawSrvProfiles as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $srvProfiles[] = [
                    'name'          => $item['name']          ?? '',
                    'dns-name'      => $item['dns-name']      ?? '',
                    'hotspot-address' => $item['hotspot-address'] ?? '',
                    'invalid'       => $item['invalid']       ?? 'false',
                ];
            }
        }
        $r['hotspot_server_profiles'] = $srvProfiles;

        // e) DHCP servers (helps see if the hotspot interface has DHCP)
        $rawDhcp = $mk->comm('/ip/dhcp-server/print');
        $dhcp = [];
        foreach ($rawDhcp as $item) {
            if (isset($item['!re'])) {
                unset($item['!re']);
                $dhcp[] = [
                    'name'      => $item['name']      ?? '',
                    'interface' => $item['interface'] ?? '',
                    'disabled'  => $item['disabled']  ?? 'false',
                    'invalid'   => $item['invalid']   ?? 'false',
                ];
            }
        }
        $r['dhcp_servers'] = $dhcp;

        $mk->disconnect();
    } catch (Exception $e) {
        $r['connected'] = false;
        $r['error']     = $e->getMessage();
    }

    $results[] = $r;
}

echo json_encode(['routers' => $results], JSON_PRETTY_PRINT);
