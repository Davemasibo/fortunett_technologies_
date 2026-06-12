<?php
/**
 * POST /api/v1/vouchers/generate.php
 * Body (JSON): count, package_id, duration_value, duration_unit (hours|days|months),
 *              price, expires_at (opt), connection_type (hotspot|pppoe), prefix (opt)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../classes/MikrotikAPI.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$count         = max(1, min(500, (int)($body['count']          ?? 1)));
$packageId     = (int)($body['package_id']     ?? 0);
$durationValue = max(1, (int)($body['duration_value'] ?? 1));
$durationUnit  = in_array($body['duration_unit'] ?? '', ['hours','days','months']) ? $body['duration_unit'] : 'days';
$price         = (float)($body['price'] ?? 0);
$expiresAt     = !empty($body['expires_at']) ? date('Y-m-d H:i:s', strtotime($body['expires_at'])) : null;
$connType      = ($body['connection_type'] ?? 'hotspot') === 'pppoe' ? 'pppoe' : 'hotspot';
$prefix        = preg_replace('/[^A-Z0-9]/', '', strtoupper($body['prefix'] ?? ''));

if ($durationUnit === 'hours') {
    $durationDays = max(1, (int)ceil($durationValue / 24));
    $limitUptime  = $durationValue . 'h';
} elseif ($durationUnit === 'months') {
    $durationDays = $durationValue * 30;
    $limitUptime  = ($durationValue * 30) . 'd';
} else {
    $durationDays = $durationValue;
    $limitUptime  = $durationValue . 'd';
}

// Validate package
$package = null;
if ($packageId) {
    $pSt = $pdo->prepare("SELECT name, mikrotik_profile, upload_speed, download_speed FROM packages WHERE id = ? AND tenant_id = ?");
    $pSt->execute([$packageId, $tenantId]);
    $package = $pSt->fetch(PDO::FETCH_ASSOC);
    if (!$package) { http_response_code(404); echo json_encode(['error' => 'Package not found']); exit; }
}

// Ensure vouchers table has required columns (one-time migration guards)
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN tenant_id INT NULL AFTER id"); } catch (Throwable $_) {}
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN connection_type VARCHAR(20) DEFAULT 'hotspot'"); } catch (Throwable $_) {}

function genCode(string $prefix, PDO $pdo): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $tries = 0;
    do {
        $b1 = $b2 = '';
        for ($i = 0; $i < 4; $i++) $b1 .= $chars[random_int(0, strlen($chars)-1)];
        for ($i = 0; $i < 4; $i++) $b2 .= $chars[random_int(0, strlen($chars)-1)];
        $code = ($prefix ? $prefix . '-' : '') . $b1 . '-' . $b2;
        $ex = $pdo->prepare("SELECT 1 FROM vouchers WHERE voucher_code = ? LIMIT 1");
        $ex->execute([$code]);
    } while ($ex->fetchColumn() && ++$tries < 20);
    return $code;
}

$generated = [];
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("
        INSERT INTO vouchers (tenant_id, voucher_code, package_id, duration_days, price, status, expires_at, connection_type)
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
    ");
    for ($i = 0; $i < $count; $i++) {
        $code = genCode($prefix, $pdo);
        $ins->execute([$tenantId, $code, $packageId ?: null, $durationDays, $price, $expiresAt, $connType]);
        $generated[] = $code;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Sync to hotspot router (best-effort)
$routerSynced = false;
$routerError  = null;
if ($connType === 'hotspot') {
    try {
        $rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') LIMIT 1");
        $rSt->execute([$tenantId]);
        $router = $rSt->fetch(PDO::FETCH_ASSOC);
        if ($router) {
            $ip  = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
            $api = new MikrotikAPI($ip, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
            $api->connect();
            $profile = 'default';
            if ($package) {
                $profile = $package['mikrotik_profile'] ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($package['name'] ?? ''));
                if (!$profile) $profile = 'default';
            }
            foreach ($generated as $code) {
                try {
                    $api->comm('/ip/hotspot/user/add', [
                        '=name='         . $code,
                        '=password='     . $code,
                        '=profile='      . $profile,
                        '=limit-uptime=' . $limitUptime,
                        '=server=all',
                        '=comment=voucher',
                    ]);
                } catch (Throwable $_) {}
            }
            $api->disconnect();
            $routerSynced = true;
        }
    } catch (Throwable $re) {
        $routerError = $re->getMessage();
    }
}

echo json_encode([
    'success'       => true,
    'count'         => count($generated),
    'codes'         => $generated,
    'router_synced' => $routerSynced,
    'router_error'  => $routerError,
]);
