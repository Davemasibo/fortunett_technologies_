<?php
ob_start(); ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

// Accept single id or array of ids
$ids = [];
if (!empty($_POST['ids'])) {
    $raw = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', $_POST['ids']);
    foreach ($raw as $v) { $id = (int)$v; if ($id > 0) $ids[] = $id; }
} elseif (!empty($_POST['id'])) {
    $ids = [(int)$_POST['id']];
}
if (empty($ids)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'No IDs provided']); exit; }

// Fetch the voucher codes (for router cleanup)
$ph = implode(',', array_fill(0, count($ids), '?'));
$vSt = $pdo->prepare("SELECT voucher_code, connection_type, status FROM vouchers WHERE id IN ($ph) AND tenant_id = ?");
$vSt->execute(array_merge($ids, [$tenant_id]));
$vouchers = $vSt->fetchAll(PDO::FETCH_ASSOC);

if (empty($vouchers)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Vouchers not found']); exit; }

// Delete from DB
$del = $pdo->prepare("DELETE FROM vouchers WHERE id IN ($ph) AND tenant_id = ?");
$del->execute(array_merge($ids, [$tenant_id]));

// Remove from router (best-effort, hotspot users only)
$hotspotCodes = array_map(fn($v) => $v['voucher_code'], array_filter($vouchers, fn($v) => ($v['connection_type'] ?? 'hotspot') === 'hotspot' && $v['status'] !== 'used'));
if (!empty($hotspotCodes)) {
    try {
        $rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') LIMIT 1");
        $rSt->execute([$tenant_id]);
        $router = $rSt->fetch(PDO::FETCH_ASSOC);
        if ($router) {
            $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
            $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
            $api->connect();
            foreach ($hotspotCodes as $code) {
                try {
                    $found = $api->comm('/ip/hotspot/user/print', ['?name=' . $code]);
                    foreach ($found as $u) {
                        if (isset($u['!re'], $u['.id']) && ($u['name'] ?? '') === $code) {
                            $api->comm('/ip/hotspot/user/remove', ['=.id=' . $u['.id']]);
                            break;
                        }
                    }
                } catch (Throwable $_e) {}
            }
            $api->disconnect();
        }
    } catch (Throwable $re) {
        error_log('Voucher delete router cleanup: ' . $re->getMessage());
    }
}

ob_clean();
echo json_encode(['success' => true, 'deleted' => count($ids)]);
