<?php
/**
 * Verify Provisioning
 *
 * After the admin pastes the provisioning one-liner into the RouterOS terminal,
 * this connects to the router over the API and confirms each object the script
 * creates actually exists — giving the web UI real "it worked" feedback instead
 * of asking the user to trust the terminal output.
 *
 * POST: router_id, services (csv: 'pppoe', 'hotspot', or 'pppoe,hotspot')
 * Returns: { success, api_ok, all_ok, checks:[{label, ok, detail}], error? }
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenantId = (int)$t->fetchColumn();

$routerId = (int)($_POST['router_id'] ?? 0);
$servicesRaw = trim($_POST['services'] ?? '');
$services = array_values(array_filter(array_map('trim', explode(',', $servicesRaw)),
    fn($s) => in_array($s, ['pppoe', 'hotspot'], true)));

$rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$rq->execute([$routerId, $tenantId]);
$router = $rq->fetch(PDO::FETCH_ASSOC);
if (!$router) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Router not found']); exit; }

// Prefer VPN IP (WireGuard) over public IP
$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?: 8728);

$result = ['success' => true, 'api_ok' => false, 'all_ok' => false, 'checks' => [], 'error' => null];

// Helper: does any '!re' row match a predicate?
$anyRow = function (array $rows, callable $pred): bool {
    foreach ($rows as $r) { if (isset($r['!re']) && $pred($r)) return true; }
    return false;
};

// TCP reachability first (3s)
$sock = @fsockopen($connectIp, $port, $errno, $errstr, 3);
if (!$sock) {
    $result['error'] = "Router API unreachable at $connectIp:$port — " . ($errstr ?: 'no route')
        . (str_starts_with($connectIp, '10.200.200.') ? ' (WireGuard tunnel may be down).' : '.');
    ob_clean(); echo json_encode($result); exit;
}
fclose($sock);

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $api->connect();
    $result['api_ok'] = true;
    $checks = [];

    // ── Bridge ────────────────────────────────────────────────────────────────
    $bridges   = $api->comm('/interface/bridge/print');
    $bridgeName = null;
    foreach ($bridges as $b) {
        if (isset($b['!re']) && ($b['disabled'] ?? 'false') === 'false' && !empty($b['name'])) { $bridgeName = $b['name']; break; }
    }
    $checks[] = ['label' => 'Bridge interface', 'ok' => (bool)$bridgeName, 'detail' => $bridgeName ?: 'No enabled bridge found'];

    // ── PPPoE ─────────────────────────────────────────────────────────────────
    if (in_array('pppoe', $services, true)) {
        $pp = $api->comm('/interface/pppoe-server/server/print');
        $ppOk = $anyRow($pp, fn($r) => ($r['service-name'] ?? '') === 'pppoe-service' && ($r['disabled'] ?? 'true') !== 'true');
        $checks[] = ['label' => 'PPPoE server (pppoe-service)', 'ok' => $ppOk, 'detail' => $ppOk ? 'Running' : 'Not found or disabled'];

        $prof = $api->comm('/ppp/profile/print');
        $profOk = $anyRow($prof, fn($r) => ($r['name'] ?? '') === 'pppoe-profile');
        $checks[] = ['label' => 'PPP profile (pppoe-profile)', 'ok' => $profOk, 'detail' => $profOk ? 'Present' : 'Missing'];
    }

    // ── Hotspot ─────────────────────────────────────────────────────────────────
    if (in_array('hotspot', $services, true)) {
        $hs = $api->comm('/ip/hotspot/print');
        $hsRow = null;
        foreach ($hs as $r) { if (isset($r['!re']) && ($r['name'] ?? '') === 'hotspot1') { $hsRow = $r; break; } }
        $hsOk = $hsRow && ($hsRow['disabled'] ?? 'true') !== 'true';
        $onBridge = $hsOk && $bridgeName && ($hsRow['interface'] ?? '') === $bridgeName;
        $checks[] = ['label' => 'Hotspot server (hotspot1)', 'ok' => $hsOk,
            'detail' => $hsOk ? ('Running on ' . ($hsRow['interface'] ?? '?') . ($onBridge ? '' : ' — NOT on bridge')) : 'Not found or disabled'];

        $hsp = $api->comm('/ip/hotspot/profile/print');
        $hspOk = $anyRow($hsp, fn($r) => ($r['name'] ?? '') === 'hsprof1');
        $checks[] = ['label' => 'Hotspot profile (hsprof1)', 'ok' => $hspOk, 'detail' => $hspOk ? 'Present' : 'Missing'];

        $dhcp = $api->comm('/ip/dhcp-server/print');
        $dhcpOk = $anyRow($dhcp, fn($r) => ($r['name'] ?? '') === 'hs-dhcp' && ($r['disabled'] ?? 'true') !== 'true');
        $checks[] = ['label' => 'DHCP server (hs-dhcp)', 'ok' => $dhcpOk, 'detail' => $dhcpOk ? 'Serving 10.5.50.0/24' : 'Missing — clients get no IP, portal will not load'];

        $files = $api->comm('/file/print');
        $loginOk = $anyRow($files, fn($r) => substr($r['name'] ?? '', -strlen('hotspot/login.html')) === 'hotspot/login.html');
        $checks[] = ['label' => 'Login page (flash/hotspot/login.html)', 'ok' => $loginOk, 'detail' => $loginOk ? 'Deployed' : 'Not on router — use "Deploy Login"'];

        $wg = $api->comm('/ip/hotspot/walled-garden/print');
        $wgOk = $anyRow($wg, fn($r) => ($r['comment'] ?? '') === 'FortuNett-Portal');
        $checks[] = ['label' => 'Captive portal (walled garden)', 'ok' => $wgOk, 'detail' => $wgOk ? 'Portal domain whitelisted' : 'No walled-garden entry'];
    }

    $api->disconnect();

    $result['checks']  = $checks;
    $result['all_ok']  = !empty($checks) && !array_filter($checks, fn($c) => !$c['ok']);

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($result);
