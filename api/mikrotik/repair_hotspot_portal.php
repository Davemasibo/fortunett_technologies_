<?php
/** Add only missing tenant portal HTTP/HTTPS allow rules; never change LAN topology. */
ob_start();
ini_set('display_errors', '0');
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/router_expiry.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$api = null;
$applied = [];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id'])) throw new RuntimeException('Sign in to repair this router.');
    if (empty($_SESSION['hotspot_diagnostics_csrf']) || !hash_equals($_SESSION['hotspot_diagnostics_csrf'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Reload diagnostics before repairing.');
    $st = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
    $st->execute([$_SESSION['user_id']]);
    $tenant = (int)$st->fetchColumn();
    session_write_close();
    $st = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? AND id=?');
    $st->execute([$tenant, (int)($_POST['router_id'] ?? 0)]);
    $router = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tenant || !$router) throw new RuntimeException('Router not found for your tenant.');
    $st = $pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');
    $st->execute([$tenant]);
    $domain = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain'")->fetchColumn() ?: 'fortunetttech.site';
    $host = $st->fetchColumn() . '.' . $domain;
    $ips = array_values(array_unique(gethostbynamel($host) ?: []));
    if (!$ips) throw new RuntimeException('Portal DNS did not resolve. No changes applied.');
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Portal DNS returned a non-public address.');
    $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'], $router['username'], $router['password'], $router['api_port'] ?: 8728);
    $api->connect();
    $garden = routerCheckedCommand($api, '/ip/hotspot/walled-garden/print');
    $found = false;
    foreach ($garden as $g) if (($g['dst-host'] ?? '') === $host && ($g['disabled'] ?? 'false') !== 'true' && ($g['action'] ?? 'allow') === 'allow' && empty($g['server']) && empty($g['src-address'])) $found = true;
    if (!$found) {
        routerCheckedCommand($api, '/ip/hotspot/walled-garden/add', ['=dst-host=' . $host, '=action=allow', '=comment=FortuNett-Diagnostics-Portal']);
        $applied[] = 'Allowed the tenant portal hostname.';
    }
    $garden = routerCheckedCommand($api, '/ip/hotspot/walled-garden/ip/print');
    foreach ($ips as $ip) {
        $found = false;
        foreach ($garden as $g) if (in_array($g['dst-address'] ?? '', [$ip, $ip . '/32'], true) && ($g['disabled'] ?? 'false') !== 'true' && ($g['invalid'] ?? 'false') !== 'true' && ($g['action'] ?? '') === 'accept' && empty($g['server']) && empty($g['src-address']) && empty($g['src-address-list']) && empty($g['dst-address-list']) && (empty($g['protocol']) || $g['protocol']==='tcp') && (empty($g['dst-port']) || $g['dst-port']==='443')) $found = true;
        if (!$found) {
            routerCheckedCommand($api, '/ip/hotspot/walled-garden/ip/add', ['=dst-address=' . $ip . '/32', '=protocol=tcp', '=dst-port=443', '=action=accept', '=comment=FortuNett-Diagnostics-Portal']);
            $applied[] = 'Allowed portal HTTPS at ' . $ip . '.';
        }
    }
    $response = ['success'=>true, 'applied'=>$applied, 'message'=>$applied ? 'Portal rules updated. Recheck and verify from Wi-Fi.' : 'Portal rules already present. Recheck to verify.'];
} catch (Throwable $e) {
    $response = ['success'=>false, 'applied'=>$applied, 'message'=>$e->getMessage()];
} finally {
    if ($api) $api->disconnect();
}
ob_clean();
echo json_encode($response);
