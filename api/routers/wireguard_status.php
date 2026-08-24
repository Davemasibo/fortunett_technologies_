<?php
/**
 * WireGuard tunnel diagnostics for one router.
 *
 * "The tunnel is falling behind" is never one failure — it is one of about six,
 * and from the portal they all look identical (API timeout). This walks the
 * whole chain in order and names the first broken link:
 *
 *   1. VPS: wg0 up, listening on UDP 51820, public key known
 *   2. VPS: an endpoint address the router can actually dial
 *   3. DB:  this router has a keypair and a VPN IP
 *   4. VPS: the peer is loaded into the running interface
 *   5. VPS: the peer is written to wg0.conf (survives reboot)
 *   6. Wire: a handshake has completed, and how long ago
 *   7. Router: TCP 8728 answers over the VPN IP
 *
 * POST router_id
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/WireGuardManager.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenantId = (int)$t->fetchColumn();

$routerId = (int)($_POST['router_id'] ?? 0);
$rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$rq->execute([$routerId, $tenantId]);
$router = $rq->fetch(PDO::FETCH_ASSOC);
if (!$router) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Router not found']); exit; }

$out = [
    'success' => true,
    'checks'  => [],
    'verdict' => ['level' => 'warn', 'title' => '', 'message' => ''],
    'vpn_ip'  => $router['vpn_ip'] ?? null,
    'handshake_age' => null,
];

$add = function (string $label, string $level, string $detail) use (&$out) {
    $out['checks'][] = ['label' => $label, 'level' => $level, 'detail' => $detail];
};

/** Human-readable "3m 12s ago". */
function wg_ago(int $secs): string {
    if ($secs < 60) return $secs . 's ago';
    if ($secs < 3600) return intdiv($secs, 60) . 'm ' . ($secs % 60) . 's ago';
    if ($secs < 86400) return intdiv($secs, 3600) . 'h ' . intdiv($secs % 3600, 60) . 'm ago';
    return intdiv($secs, 86400) . 'd ago';
}

$firstFail = null;
$fail = function (string $msg) use (&$firstFail) { if ($firstFail === null) $firstFail = $msg; };

// ── 1. VPS interface + public key ─────────────────────────────────────────────
$vpsKey = '';
$vpsKeyLive = false;
try {
    $vpsKey     = WireGuardManager::getVpsPublicKey();
    $vpsKeyLive = true;
    $add('VPS interface wg0', 'ok', 'Running — public key ' . substr($vpsKey, 0, 12) . '…');
} catch (Throwable $e) {
    try {
        $cached = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='wg_vps_public_key' LIMIT 1")->fetchColumn();
        $vpsKey = (string)($cached ?: '');
    } catch (Throwable $_e) {}

    if ($vpsKey) {
        $add('VPS interface wg0', 'fail',
            'Not readable right now (' . $e->getMessage() . '), but a cached public key exists. '
            . 'Either wg-quick@wg0 is stopped or the web user cannot run `sudo wg`. '
            . 'Check: systemctl status wg-quick@wg0, and that /etc/sudoers.d grants the web user NOPASSWD on /usr/bin/wg.');
        $fail('wg0 is not readable from PHP — routers cannot be reached even if their tunnels are up.');
    } else {
        $add('VPS interface wg0', 'fail',
            'No public key available at all: ' . $e->getMessage() . '. Run scripts/setup_wireguard_server.sh on the VPS.');
        $fail('WireGuard is not set up on the VPS.');
    }
}

// ── 2. UDP port + endpoint address ────────────────────────────────────────────
if ($vpsKeyLive) {
    $add('VPS listening on UDP ' . WireGuardManager::WG_PORT,
        WireGuardManager::isListening() ? 'ok' : 'warn',
        WireGuardManager::isListening()
            ? 'Socket open.'
            : 'No UDP socket found on that port. If the firewall or provider security group blocks it, no router can ever handshake.');
}

$serverIp = '';
try {
    $serverIp = trim((string)($pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1")->fetchColumn() ?: ''));
} catch (Throwable $_e) {}

if (filter_var($serverIp, FILTER_VALIDATE_IP)) {
    $isPublic = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    $add('Tunnel endpoint address', $isPublic ? 'ok' : 'fail',
        $isPublic
            ? $serverIp . ' (platform_settings.server_external_ip)'
            : $serverIp . ' is a private address — routers on the internet cannot dial it.');
    if (!$isPublic) $fail('server_external_ip is a private address.');
} else {
    $add('Tunnel endpoint address', 'fail',
        'platform_settings.server_external_ip is not set. Provisioning then guesses via DNS, which on a proxied domain '
        . 'resolves to the CDN edge rather than the VPS — the router points its peer at an address that never answers '
        . 'UDP ' . WireGuardManager::WG_PORT . '. This is the most common cause of a fleet of dead tunnels.');
    $fail('server_external_ip is unset, so provisioned routers were handed a guessed endpoint.');
}

// ── 3. Router keypair + VPN IP in the DB ──────────────────────────────────────
$routerPub = trim((string)($router['wg_public_key'] ?? ''));
$vpnIp     = trim((string)($router['vpn_ip'] ?? ''));

if ($routerPub && $vpnIp) {
    $expected = null;
    try { $expected = WireGuardManager::vpnIp((int)$router['id']); } catch (Throwable $_e) {}
    $add('Router keypair + VPN IP', $expected && $expected !== $vpnIp ? 'warn' : 'ok',
        $vpnIp . ' · key ' . substr($routerPub, 0, 12) . '…'
        . ($expected && $expected !== $vpnIp ? " (expected {$expected} for router id {$router['id']})" : ''));
} else {
    $add('Router keypair + VPN IP', 'fail',
        'This router has no WireGuard keypair or VPN IP recorded. It was provisioned while WireGuard was unavailable — '
        . 'use "Set up VPN tunnel" on the Routers page to generate one and re-paste the commands.');
    $fail('No tunnel has ever been generated for this router.');
}

// ── 4/5/6. Peer loaded, persisted, handshaking ────────────────────────────────
if ($routerPub && $vpsKeyLive) {
    $peers = WireGuardManager::peerStatus();
    $peer  = $peers[$routerPub] ?? null;

    if (!$peer) {
        $add('Peer loaded on wg0', 'fail',
            'This router is not a peer on the running wg0 interface. Re-run "Set up VPN tunnel" — '
            . 'it calls addPeer() again and is safe to repeat.');
        $fail('The router is not registered as a peer on the VPS.');
    } else {
        $add('Peer loaded on wg0', 'ok', 'allowed-ips ' . $peer['allowed_ips']
            . ($peer['endpoint'] ? ' · last seen from ' . $peer['endpoint'] : ' · no endpoint yet'));

        $persisted = WireGuardManager::peerPersisted($routerPub);
        $add('Peer persisted to wg0.conf', $persisted ? 'ok' : 'fail',
            $persisted
                ? 'Present — survives a VPS reboot.'
                : 'Missing from /etc/wireguard/wg0.conf. The tunnel works now but disappears on the next VPS reboot, '
                . 'which is exactly how a fleet "falls behind" all at once.');
        if (!$persisted) $fail('The peer is only in memory and will be lost on reboot.');

        $out['handshake_age'] = $peer['handshake_age'];
        if ($peer['handshake'] === 0) {
            $add('Handshake', 'fail',
                'Never completed. The router has the config but its packets are not arriving. Check, in order: '
                . 'the endpoint IP baked into the router peer, UDP ' . WireGuardManager::WG_PORT . ' open inbound on the VPS firewall, '
                . 'and that the router key on the VPS matches the one in the router.');
            $fail('The tunnel has never handshaked.');
        } elseif ($peer['handshake_age'] > 300) {
            $add('Handshake', 'fail',
                'Last handshake ' . wg_ago($peer['handshake_age']) . '. With persistent-keepalive=25 this should never exceed '
                . '~2 minutes, so the tunnel is down. The fortunett_wg_watchdog scheduler on the router re-arms it every 2 minutes '
                . '— if this router predates that scheduler, re-run provisioning to install it.');
            $fail('The tunnel handshaked once but has since gone quiet.');
        } elseif ($peer['handshake_age'] > 180) {
            $add('Handshake', 'warn', 'Last handshake ' . wg_ago($peer['handshake_age']) . ' — later than keepalive should allow.');
        } else {
            $add('Handshake', 'ok', 'Last handshake ' . wg_ago($peer['handshake_age'])
                . ' · ' . number_format($peer['rx'] / 1024, 1) . ' KB in / ' . number_format($peer['tx'] / 1024, 1) . ' KB out');
        }
    }
}

// ── 7. Router API over the tunnel ─────────────────────────────────────────────
if ($vpnIp) {
    $port = (int)($router['api_port'] ?: 8728);
    $sock = @fsockopen($vpnIp, $port, $errno, $errstr, 4);
    if ($sock) {
        fclose($sock);
        $add('Router API over the tunnel', 'ok', "TCP {$vpnIp}:{$port} answers.");
    } else {
        $add('Router API over the tunnel', 'fail',
            "TCP {$vpnIp}:{$port} refused — " . ($errstr ?: 'no route') . '. '
            . 'If the handshake above is healthy, the router is reachable but not accepting the API: check '
            . '/ip service api is enabled and its address list still contains ' . WireGuardManager::VPS_VPN_IP . '.');
        $fail('The API port does not answer over the tunnel.');
    }
}

// ── Verdict ───────────────────────────────────────────────────────────────────
$fails = array_filter($out['checks'], fn($c) => $c['level'] === 'fail');
if ($firstFail !== null) {
    $out['verdict'] = [
        'level'   => 'fail',
        'title'   => count($fails) === 1 ? 'Tunnel broken at 1 point' : 'Tunnel broken at ' . count($fails) . ' points',
        'message' => $firstFail,
    ];
} elseif (array_filter($out['checks'], fn($c) => $c['level'] === 'warn')) {
    $out['verdict'] = ['level' => 'warn', 'title' => 'Tunnel up with caveats',
                       'message' => 'The router is reachable, but something below will bite later.'];
} else {
    $out['verdict'] = ['level' => 'ok', 'title' => 'Tunnel healthy',
                       'message' => 'Handshaking, persisted, and the router API answers over ' . $vpnIp . '.'];
}

ob_clean();
echo json_encode($out);
