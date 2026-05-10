<?php
/**
 * API Endpoint: Sync all tenant package profiles to all active routers.
 * POST — no body required.
 *
 * Call this after adding a new router, or any time you suspect a router is
 * missing package profiles (causing customers to get unlimited/default speed).
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t_stmt->fetchColumn();
if (!$tenant_id) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
    exit;
}

// Fetch all active packages for this tenant
$pkgStmt = $pdo->prepare("
    SELECT id, name, download_speed, upload_speed, rate_limit, mikrotik_profile,
           COALESCE(NULLIF(connection_type,''), NULLIF(type,''), 'pppoe') AS pkg_type
    FROM packages
    WHERE tenant_id = ? AND status = 'active'
");
$pkgStmt->execute([$tenant_id]);
$packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active routers
$rtrStmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online')");
$rtrStmt->execute([$tenant_id]);
$routers = $rtrStmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];

foreach ($routers as $router) {
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $routerLabel = $router['name'] ?? $router['ip_address'];
    $synced = 0;
    $failed = 0;
    $errors = [];

    try {
        $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
        if (!$api->isReachable(4)) {
            $results[] = ['router' => $routerLabel, 'status' => 'unreachable'];
            continue;
        }
        $api->connect();

        foreach ($packages as $pkg) {
            $profileName = $pkg['mikrotik_profile']
                ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($pkg['name']));
            if (empty($profileName)) $profileName = 'default';
            if ($profileName === 'default') continue;

            $dl = max(1, (int)($pkg['download_speed'] ?? 10));
            $ul = max(1, (int)($pkg['upload_speed']   ?? 5));
            // RouterOS rate-limit: rx-rate/tx-rate (router perspective)
            // rx = client upload, tx = client download
            $rateLimit = !empty($pkg['rate_limit'])
                ? $pkg['rate_limit']
                : "{$ul}M/{$dl}M";

            try {
                if (strtolower($pkg['pkg_type']) === 'hotspot') {
                    $profiles = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profileName]);
                    $existId = null;
                    foreach ($profiles as $p) {
                        if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $existId = $p['.id'] ?? null; break; }
                    }
                    if ($existId !== null) {
                        $api->comm('/ip/hotspot/user/profile/set', ['=.id=' . $existId, '=rate-limit=' . $rateLimit]);
                    } else {
                        $api->comm('/ip/hotspot/user/profile/add', ['=name=' . $profileName, '=rate-limit=' . $rateLimit]);
                    }
                } else {
                    $profiles = $api->comm('/ppp/profile/print', ['?name=' . $profileName]);
                    $existId = null;
                    foreach ($profiles as $p) {
                        if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $existId = $p['.id'] ?? null; break; }
                    }
                    if ($existId !== null) {
                        $api->comm('/ppp/profile/set', ['=.id=' . $existId, '=rate-limit=' . $rateLimit]);
                    } else {
                        $api->comm('/ppp/profile/add', ['=name=' . $profileName, '=rate-limit=' . $rateLimit]);
                    }
                }
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = $profileName . ': ' . $e->getMessage();
            }
        }

        $api->disconnect();
        $results[] = ['router' => $routerLabel, 'status' => 'ok', 'synced' => $synced, 'failed' => $failed, 'errors' => $errors];
    } catch (Throwable $e) {
        $results[] = ['router' => $routerLabel, 'status' => 'error', 'message' => $e->getMessage()];
    }
}

ob_clean();
echo json_encode(['success' => true, 'results' => $results, 'packages' => count($packages), 'routers' => count($routers)]);
