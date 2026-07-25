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

        // Verify the profile the server actually references, not a hardcoded name.
        // A router can carry several profiles (hsprof1, hsprof2, …) and pass a
        // name-only check while its server points at a different, broken one.
        $hspName = $hsRow['profile'] ?? '';
        $hsp     = $api->comm('/ip/hotspot/profile/print');
        $hspRow  = null;
        foreach ($hsp as $r) { if (isset($r['!re']) && ($r['name'] ?? '') === $hspName) { $hspRow = $r; break; } }
        $hspOk = $hspName !== '' && $hspRow !== null;
        $checks[] = ['label' => 'Hotspot profile (in use)', 'ok' => $hspOk,
            'detail' => $hspOk
                ? ($hspName . ' — html-directory=' . ($hspRow['html-directory'] ?? '?'))
                : ($hspName ? "Server references '$hspName' but no such profile exists" : 'Server has no profile assigned')];

        // The bundled md5.js is a stub that returns the password unhashed, so any
        // CHAP challenge fails. The login page authenticates by PAP only.
        if ($hspRow !== null) {
            $chapOk = strpos($hspRow['login-by'] ?? '', 'http-chap') === false;
            $checks[] = ['label' => 'Login method (PAP, no CHAP)', 'ok' => $chapOk,
                'detail' => $chapOk
                    ? ($hspRow['login-by'] ?? 'unknown')
                    : 'http-chap enabled — logins will fail; set login-by=cookie,http-pap'];
        }

        $dhcp = $api->comm('/ip/dhcp-server/print');
        $dhcpOk = $anyRow($dhcp, fn($r) => ($r['name'] ?? '') === 'hs-dhcp' && ($r['disabled'] ?? 'true') !== 'true');
        $checks[] = ['label' => 'DHCP server (hs-dhcp)', 'ok' => $dhcpOk, 'detail' => $dhcpOk ? 'Serving 10.5.50.0/24' : 'Missing — clients get no IP, portal will not load'];

        $files = $api->comm('/file/print');
        $loginOk = $anyRow($files, fn($r) => substr($r['name'] ?? '', -strlen('hotspot/login.html')) === 'hotspot/login.html');
        $checks[] = ['label' => 'Login page (flash/hotspot/login.html)', 'ok' => $loginOk, 'detail' => $loginOk ? 'Deployed' : 'Not on router — use "Deploy Login"'];

        // redirect.html is what the servlet serves to an intercepted client to bounce
        // it to /login. With login.html present but this missing, the hotspot redirects
        // and returns an empty body — indistinguishable from having no portal at all.
        $redirOk = $anyRow($files, fn($r) => substr($r['name'] ?? '', -strlen('hotspot/redirect.html')) === 'hotspot/redirect.html');
        $checks[] = ['label' => 'Redirect page (hotspot/redirect.html)', 'ok' => $redirOk,
                     'detail' => $redirOk ? 'Deployed' : 'Missing — clients are intercepted but get a blank page instead of the portal'];

        $wg = $api->comm('/ip/hotspot/walled-garden/print');
        $wgOk = $anyRow($wg, fn($r) => ($r['comment'] ?? '') === 'FortuNett-Portal');
        $checks[] = ['label' => 'Captive portal (walled garden)', 'ok' => $wgOk, 'detail' => $wgOk ? 'Portal domain whitelisted' : 'No walled-garden entry'];

        // dst-host entries only match HTTP (the proxy reads the Host header), so the
        // check above passing does not mean the portal is reachable over HTTPS.
        // M-Pesa STK push from the login page needs an explicit IP entry.
        $wgIp   = $api->comm('/ip/hotspot/walled-garden/ip/print');
        $wgIpOk = $anyRow($wgIp, fn($r) => !empty($r['dst-address']));
        $checks[] = ['label' => 'Portal reachable over HTTPS (walled-garden IP)', 'ok' => $wgIpOk,
                     'detail' => $wgIpOk ? 'Portal IP whitelisted' : 'No IP entry — HTTPS to the portal is blocked, M-Pesa STK push will fail'];
    }

    $api->disconnect();

    $result['checks']  = $checks;
    $result['all_ok']  = !empty($checks) && !array_filter($checks, fn($c) => !$c['ok']);

} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($result);
