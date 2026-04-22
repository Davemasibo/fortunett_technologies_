<?php
/**
 * POST /api/clients/change_expiry.php
 * Change a client's expiry date
 * Body: client_id, action (add_minutes|set_date|change_package), minutes|expiry_date|package_id, grace_hours
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id = (int)($_POST['client_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');
if (!$client_id || !$action) {
    echo json_encode(['success'=>false,'message'=>'client_id and action required']);
    exit;
}

// Verify ownership
$chk = $pdo->prepare("SELECT id, expiry_date, package_id FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
$client = $chk->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo json_encode(['success'=>false,'message'=>'Client not found']); exit; }

try {
    $newExpiry   = null;
    $newPackage  = null;

    if ($action === 'add_minutes') {
        $minutes = (int)($_POST['minutes'] ?? 0);
        if ($minutes <= 0) throw new Exception('minutes must be positive');
        // Start from current expiry, or now if already expired
        $base = ($client['expiry_date'] && strtotime($client['expiry_date']) > time())
            ? strtotime($client['expiry_date'])
            : time();
        $newExpiry = date('Y-m-d H:i:s', $base + ($minutes * 60));

    } elseif ($action === 'set_date') {
        $dateStr = trim($_POST['expiry_date'] ?? '');
        if (!$dateStr) throw new Exception('expiry_date required');
        $ts = strtotime($dateStr);
        if (!$ts) throw new Exception('Invalid date format');
        $newExpiry = date('Y-m-d H:i:s', $ts);

    } elseif ($action === 'change_package') {
        $pkg_id = (int)($_POST['package_id'] ?? 0);
        if (!$pkg_id) throw new Exception('package_id required');
        $pkgSt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
        $pkgSt->execute([$pkg_id, $tenant_id]);
        $pkg = $pkgSt->fetch(PDO::FETCH_ASSOC);
        if (!$pkg) throw new Exception('Package not found');

        $newPackage = $pkg_id;
        // Calculate new expiry from now based on package validity
        $validValue = (int)($pkg['validity_value'] ?? 30);
        $validUnit  = strtolower($pkg['validity_unit'] ?? 'days');
        $unitMap = ['minutes'=>60,'hours'=>3600,'days'=>86400,'weeks'=>604800,'months'=>2592000];
        $seconds = ($unitMap[$validUnit] ?? 86400) * $validValue;
        $newExpiry = date('Y-m-d H:i:s', time() + $seconds);

    } else {
        throw new Exception('Unknown action');
    }

    // Apply grace period offset if provided
    $graceHours = (int)($_POST['grace_hours'] ?? 0);
    if ($graceHours > 0 && $newExpiry) {
        $newExpiry = date('Y-m-d H:i:s', strtotime($newExpiry) + ($graceHours * 3600));
    }

    // Update DB
    if ($newPackage) {
        $upd = $pdo->prepare("UPDATE clients SET expiry_date = ?, package_id = ?, status = 'active', updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $upd->execute([$newExpiry, $newPackage, $client_id, $tenant_id]);
    } else {
        $upd = $pdo->prepare("UPDATE clients SET expiry_date = ?, status = 'active', updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $upd->execute([$newExpiry, $client_id, $tenant_id]);
    }

    // Re-enable on router (in case the account was disabled via Disconnect button)
    $routerNote = '';
    try {
        $cInfo = $pdo->prepare("SELECT mikrotik_username, connection_type FROM clients WHERE id = ? AND tenant_id = ?");
        $cInfo->execute([$client_id, $tenant_id]);
        $cRow = $cInfo->fetch(PDO::FETCH_ASSOC);

        if ($cRow && !empty($cRow['mikrotik_username'])) {
            $rSt = $pdo->prepare("SELECT ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ? LIMIT 1");
            $rSt->execute([$tenant_id]);
            $router = $rSt->fetch(PDO::FETCH_ASSOC);

            if ($router) {
                $port      = (int)($router['api_port'] ?: 8728);
                $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
                $mk        = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                $mk->connect();

                if (strtolower($cRow['connection_type']) === 'hotspot') {
                    $mk->enableHotspotUser($cRow['mikrotik_username']);
                } else {
                    $mk->enablePPPoEUser($cRow['mikrotik_username']);
                }

                $mk->disconnect();
                $routerNote = ' Router account re-enabled.';
            }
        }
    } catch (Exception $routerEx) {
        $routerNote = ' (Router re-enable failed: ' . $routerEx->getMessage() . ')';
    }

    echo json_encode(['success'=>true,'message'=>'Expiry updated.' . $routerNote,'new_expiry'=>$newExpiry]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
