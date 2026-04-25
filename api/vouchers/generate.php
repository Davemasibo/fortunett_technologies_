<?php
/**
 * API Endpoint: Generate a batch of vouchers.
 * Also creates matching hotspot users on the router so the code works
 * as username+password at the captive portal immediately.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

// Ensure tenant_id column exists on vouchers table
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN tenant_id INT NULL AFTER id"); } catch (Throwable $_e) {}
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN connection_type VARCHAR(20) DEFAULT 'hotspot' AFTER tenant_id"); } catch (Throwable $_e) {}
try { $pdo->exec("ALTER TABLE vouchers ADD INDEX idx_tenant (tenant_id)"); } catch (Throwable $_e) {}

$count          = max(1, min(500, (int)($_POST['count']          ?? 1)));
$package_id     = (int)($_POST['package_id']     ?? 0);
$duration_value = max(1, (int)($_POST['duration_value'] ?? 1));
$duration_unit  = $_POST['duration_unit'] ?? 'days';   // hours|days|months
$price          = (float)($_POST['price'] ?? 0);
$expires_at     = !empty($_POST['expires_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
$conn_type      = ($_POST['connection_type'] ?? 'hotspot') === 'pppoe' ? 'pppoe' : 'hotspot';
$prefix         = preg_replace('/[^A-Z0-9]/', '', strtoupper($_POST['prefix'] ?? ''));

// Duration → seconds (for RouterOS limit-uptime)
$durationSecs = match($duration_unit) {
    'hours'  => $duration_value * 3600,
    'months' => $duration_value * 30 * 86400,
    default  => $duration_value * 86400, // days
};
$limitUptime = $duration_value . match($duration_unit) {
    'hours'  => 'h',
    'months' => 'd', // RouterOS doesn't have months — convert to days
    default  => 'd',
};
if ($duration_unit === 'months') $limitUptime = ($duration_value * 30) . 'd';
$duration_days = (int)ceil($durationSecs / 86400);

// Validate package
if ($package_id) {
    $pSt = $pdo->prepare("SELECT name, mikrotik_profile, type, upload_speed, download_speed FROM packages WHERE id = ? AND tenant_id = ?");
    $pSt->execute([$package_id, $tenant_id]);
    $package = $pSt->fetch(PDO::FETCH_ASSOC);
    if (!$package) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Package not found']); exit; }
} else {
    $package = null;
}

// Generate unique codes
function genVoucherCode(string $prefix, PDO $pdo): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no confusables
    $tries = 0;
    do {
        $block1 = '';
        $block2 = '';
        for ($i = 0; $i < 4; $i++) $block1 .= $chars[random_int(0, strlen($chars)-1)];
        for ($i = 0; $i < 4; $i++) $block2 .= $chars[random_int(0, strlen($chars)-1)];
        $code = ($prefix ? $prefix . '-' : '') . $block1 . '-' . $block2;
        $exists = $pdo->prepare("SELECT 1 FROM vouchers WHERE voucher_code = ? LIMIT 1");
        $exists->execute([$code]);
    } while ($exists->fetchColumn() && ++$tries < 20);
    return $code;
}

$generated = [];
$routerSynced = false;
$routerError  = null;

try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("
        INSERT INTO vouchers (tenant_id, voucher_code, package_id, duration_days, price,
            status, expires_at, created_by_user_id, connection_type)
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?)
    ");
    for ($i = 0; $i < $count; $i++) {
        $code = genVoucherCode($prefix, $pdo);
        $ins->execute([
            $tenant_id, $code, $package_id ?: null, $duration_days, $price,
            $expires_at, $_SESSION['user_id'], $conn_type
        ]);
        $generated[] = $code;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
}

// Sync to router (hotspot only)
if ($conn_type === 'hotspot' && !empty($generated)) {
    try {
        $rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') LIMIT 1");
        $rSt->execute([$tenant_id]);
        $router = $rSt->fetch(PDO::FETCH_ASSOC);

        if ($router) {
            $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
            $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
            $api->connect();

            $profile = '';
            if ($package) {
                $profile = $package['mikrotik_profile']
                    ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($package['name'] ?? ''));
                if (empty($profile)) $profile = 'default';

                // Ensure profile exists
                if ($profile !== 'default') {
                    $existing = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profile]);
                    $pExists  = false;
                    foreach ($existing as $p) {
                        if (isset($p['!re']) && ($p['name'] ?? '') === $profile) { $pExists = true; break; }
                    }
                    if (!$pExists) {
                        $up   = (int)($package['upload_speed']   ?? 0);
                        $down = (int)($package['download_speed'] ?? 0);
                        $rl   = ($up > 0 || $down > 0) ? "{$up}M/{$down}M" : null;
                        try { $api->comm('/ip/hotspot/user/profile/add', array_filter([
                            '=name='         . $profile,
                            $rl ? '=rate-limit=' . $rl : null,
                        ])); } catch (Throwable $_e) { $profile = 'default'; }
                    }
                }
            }
            if (empty($profile)) $profile = 'default';

            foreach ($generated as $code) {
                try {
                    $api->comm('/ip/hotspot/user/add', array_filter([
                        '=name='          . $code,
                        '=password='      . $code,
                        '=profile='       . $profile,
                        '=limit-uptime='  . $limitUptime,
                        $expires_at ? '=limit-bytes-total=0' : null,
                        '=server=all',
                        '=comment=voucher',
                    ]));
                } catch (Throwable $_e) { /* non-fatal per code */ }
            }
            $api->disconnect();
            $routerSynced = true;
        }
    } catch (Throwable $re) {
        $routerError = $re->getMessage();
    }
}

ob_clean();
echo json_encode([
    'success'       => true,
    'count'         => count($generated),
    'codes'         => $generated,
    'router_synced' => $routerSynced,
    'router_error'  => $routerError,
]);
