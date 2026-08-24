<?php
/**
 * POST /api/routers/wireguard_setup.php
 *
 * Generates a WireGuard VPN keypair for a router, adds it as a peer on the
 * VPS wg0 interface, persists everything to the DB, and returns the RouterOS
 * commands the admin needs to run on the router to bring the tunnel up.
 *
 * Works for routers added via the "Direct IP" method (not the RSC wizard).
 * The RSC wizard path already calls WireGuardManager::addPeer() inside provision.php.
 *
 * Request: POST { router_id }
 * Response: { success, vpn_ip, commands, vps_public_key, error? }
 */
header('Content-Type: application/json');
ob_start();

require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/WireGuardManager.php';

ob_clean();

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id=?");
$st->execute([$_SESSION['user_id']]);
$tenantId = (int)$st->fetchColumn();
if (!$tenantId) { echo json_encode(['success'=>false,'error'=>'No tenant']); exit; }

$routerId = (int)($_POST['router_id'] ?? 0);
if (!$routerId) { echo json_encode(['success'=>false,'error'=>'router_id required']); exit; }

// Verify ownership
$rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id=? AND tenant_id=?");
$rSt->execute([$routerId, $tenantId]);
$router = $rSt->fetch(PDO::FETCH_ASSOC);
if (!$router) { echo json_encode(['success'=>false,'error'=>'Router not found']); exit; }

try {
    // ── 1. Get/cache VPS public key ───────────────────────────────────────────
    // Cache in platform_settings so we survive situations where wg0 isn't running
    // at request time but was running when the server was set up.
    $vpsPublicKey = '';
    try {
        $cached = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='wg_vps_public_key' LIMIT 1")->fetchColumn();
        if ($cached) {
            $vpsPublicKey = $cached;
        } else {
            $vpsPublicKey = WireGuardManager::getVpsPublicKey();
            $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('wg_vps_public_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$vpsPublicKey]);
        }
    } catch (\Exception $e) {
        // If wg0 is truly down this will fail; surface the error
        throw new \RuntimeException('Cannot get VPS WireGuard public key: ' . $e->getMessage() . '. Is wg-quick@wg0 running on the VPS?');
    }

    // ── 2. Generate keypair for this router ───────────────────────────────────
    $existingPriv = $router['wg_private_key'] ?? '';
    $existingPub  = $router['wg_public_key']  ?? '';
    $existingVpn  = $router['vpn_ip']         ?? '';

    if ($existingPriv && $existingPub) {
        // Re-use existing keys (idempotent — just re-adds the peer)
        $routerPrivKey = $existingPriv;
        $routerPubKey  = $existingPub;
    } else {
        $keys          = WireGuardManager::generateKeyPair();
        $routerPrivKey = $keys['private'];
        $routerPubKey  = $keys['public'];
    }

    // ── 3. Assign VPN IP ──────────────────────────────────────────────────────
    if ($existingVpn && str_starts_with($existingVpn, '10.200.200.')) {
        $vpnIp = $existingVpn;
    } else {
        $vpnIp = WireGuardManager::vpnIp($routerId);
    }

    // ── 4. Add peer to VPS wg0 + persist to wg0.conf ─────────────────────────
    WireGuardManager::addPeer($routerPubKey, $vpnIp);

    // ── 5. Save to DB ─────────────────────────────────────────────────────────
    $pdo->prepare("
        UPDATE mikrotik_routers
        SET vpn_ip=?, wg_private_key=?, wg_public_key=?, updated_at=NOW()
        WHERE id=? AND tenant_id=?
    ")->execute([$vpnIp, $routerPrivKey, $routerPubKey, $routerId, $tenantId]);

    // ── 6. Build RouterOS commands ────────────────────────────────────────────
    // Get server public IP from settings
    $serverIp = '';
    try {
        $serverIp = trim((string)($pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1")->fetchColumn() ?: ''));
    } catch (\Throwable $_e) {}
    if (!filter_var($serverIp, FILTER_VALIDATE_IP)) {
        $serverIp = $_SERVER['SERVER_ADDR'] ?? '';
    }
    // Without a real endpoint the router builds a peer that can never handshake,
    // and the failure is invisible until the API calls start timing out.
    if (!filter_var($serverIp, FILTER_VALIDATE_IP)) {
        throw new \RuntimeException(
            'No usable VPS public IP. Set platform_settings.server_external_ip to the address that accepts UDP '
            . WireGuardManager::WG_PORT . ' before generating tunnel commands.'
        );
    }

    $wgPort = WireGuardManager::WG_PORT;
    $vpsVpnIp = WireGuardManager::VPS_VPN_IP;

    // Single-line, comment-free RouterOS script — one copy, one paste. Statements
    // are joined with ';' so the whole thing runs atomically in the terminal (a
    // '#' comment in a one-liner would swallow everything after it on the line).
    // Each mutating step is preceded by an idempotent remove so re-running is safe.
    $parts = [];
    // Full cleanup FIRST — remove every existing WireGuard peer and interface plus
    // any stray VPN-subnet address. Earlier runs left orphaned interfaces (e.g. *8,
    // *9) whose peers also claimed allowed-address=10.200.200.0/24, so the router
    // had multiple interfaces fighting over the same route ("ambiguous" tunnel
    // routing) and the handshake never settled. Wiping all WG state guarantees a
    // single clean interface. These routers are platform-managed for this VPN only.
    $parts[] = ':do {/interface/wireguard/peers remove [find]} on-error={}';
    $parts[] = ':do {/interface/wireguard remove [find]} on-error={}';
    $parts[] = ':do {/ip address remove [find address~"10.200.200."]} on-error={}';
    // Recreate a single clean interface + peer + VPN IP.
    $parts[] = "/interface/wireguard add name=\"wg-fortunett\" listen-port=13231 private-key=\"{$routerPrivKey}\"";
    $parts[] = "/interface/wireguard/peers add interface=\"wg-fortunett\" public-key=\"{$vpsPublicKey}\" endpoint-address={$serverIp} endpoint-port={$wgPort} allowed-address=10.200.200.0/24 persistent-keepalive=25";
    $parts[] = "/ip address add address=\"{$vpnIp}/24\" interface=\"wg-fortunett\"";
    // RFC1918 is included alongside the VPN IP on purpose: locking the API to
    // 10.200.200.1/32 alone means a dropped tunnel makes the router unmanageable
    // from the LAN too, and recovery becomes a site visit. The router sits behind
    // NAT, so the private ranges add no public exposure.
    $parts[] = "/ip service set api disabled=no port=8728 address={$vpsVpnIp}/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16";
    $parts[] = ':do {/ip firewall filter remove [find comment="Fortunett-API-VPN"]} on-error={}';
    $parts[] = "/ip firewall filter add chain=input action=accept protocol=tcp src-address={$vpsVpnIp} dst-port=8728 comment=\"Fortunett-API-VPN\"";
    $parts[] = '/ip firewall filter move [find comment="Fortunett-API-VPN"] destination=0';

    // Watchdog — a peer caches its resolved endpoint, so a WAN IP change or an ISP
    // reconnect can silently stop the handshake with the interface still showing
    // "running". Ping the VPS over the tunnel every 2 minutes and re-arm on failure.
    $wgWatch = ':local ok [/ping ' . $vpsVpnIp . ' count=3 interval=200ms]; '
             . ':if ($ok=0) do={'
             . ':log warning \\"[Fortunett] WireGuard unreachable - re-arming tunnel\\"; '
             . ':do {/interface/wireguard set [find name=\\"wg-fortunett\\"] disabled=yes} on-error={}; '
             . ':delay 2s; '
             . ':do {/interface/wireguard set [find name=\\"wg-fortunett\\"] disabled=no} on-error={}; '
             . ':do {/interface/wireguard/peers set [find interface=\\"wg-fortunett\\"] endpoint-address=' . $serverIp . ' endpoint-port=' . $wgPort . '} on-error={}'
             . '}';
    $parts[] = ':do {/system scheduler remove [find name="fortunett_wg_watchdog"]} on-error={}';
    $parts[] = "/system scheduler add name=\"fortunett_wg_watchdog\" interval=2m start-time=startup on-event=\"{$wgWatch}\"";

    $commands = implode('; ', $parts) . ';';

    echo json_encode([
        'success'        => true,
        'vpn_ip'         => $vpnIp,
        'vps_public_key' => $vpsPublicKey,
        'router_pub_key' => $routerPubKey,
        'commands'       => $commands,
    ]);

} catch (\Throwable $e) {
    error_log('[wireguard_setup] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
