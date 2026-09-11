<?php
/**
 * POST /api/clients/bulk_action.php
 *
 * Handles bulk operations on selected clients.
 *
 * POST params:
 *   action      — delete | send_sms | change_package | export
 *   ids[]       — array of client IDs
 *   message     — (send_sms) SMS text to send
 *   package_id  — (change_package) target package ID
 */
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$userId = (int)$_SESSION['user_id'];
$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$userId]);
$tenantId = (int)$st->fetchColumn();
if (!$tenantId) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$action = trim($_POST['action'] ?? '');
$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) $rawIds = [];
$ids = array_map('intval', array_filter($rawIds, fn($v) => (int)$v > 0));

if (empty($ids)) { echo json_encode(['success'=>false,'message'=>'No customers selected.']); exit; }

// Verify all IDs belong to this tenant
$ph = implode(',', array_fill(0, count($ids), '?'));
$chk = $pdo->prepare("SELECT id FROM clients WHERE id IN ($ph) AND tenant_id = ?");
$chk->execute(array_merge($ids, [$tenantId]));
$validIds = array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id');
if (empty($validIds)) { echo json_encode(['success'=>false,'message'=>'No valid customers found.']); exit; }
$ids = array_map('intval', $validIds);
$ph  = implode(',', array_fill(0, count($ids), '?'));

switch ($action) {

    // ── DELETE ────────────────────────────────────────────────────────────────
    case 'delete':
        try {
            require_once __DIR__ . '/../../classes/MikrotikAPI.php';

            // Load clients for router cleanup
            $cSt = $pdo->prepare("SELECT id, mikrotik_username, connection_type FROM clients WHERE id IN ($ph) AND tenant_id = ?");
            $cSt->execute(array_merge($ids, [$tenantId]));
            $clients = $cSt->fetchAll(PDO::FETCH_ASSOC);

            // Try to remove from router
            $rSt = $pdo->prepare("SELECT ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE tenant_id = ? AND status IN ('active','online') ORDER BY id ASC LIMIT 1");
            $rSt->execute([$tenantId]);
            $router = $rSt->fetch(PDO::FETCH_ASSOC);
            $api = null;
            if ($router) {
                $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
                $port = (int)($router['api_port'] ?: 8728);
                $sock = @fsockopen($connectIp, $port, $errno, $errstr, 3);
                if ($sock) { fclose($sock); try { $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port); $api->connect(); } catch (Throwable $_e) { $api = null; } }
            }
            foreach ($clients as $c) {
                if ($api && !empty($c['mikrotik_username'])) {
                    try {
                        if (strtolower($c['connection_type'] ?? '') === 'pppoe') $api->deletePPPoEUser($c['mikrotik_username']);
                        else $api->deleteHotspotUser($c['mikrotik_username']);
                    } catch (Throwable $_e) {}
                }
            }
            if ($api) try { $api->disconnect(); } catch (Throwable $_e) {}

            $del = $pdo->prepare("DELETE FROM clients WHERE id IN ($ph) AND tenant_id = ?");
            $del->execute(array_merge($ids, [$tenantId]));
            $count = $del->rowCount();
            echo json_encode(['success'=>true,'message'=>"$count customer(s) deleted.",'count'=>$count]);
        } catch (Throwable $e) {
            echo json_encode(['success'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
        }
        break;

    // ── SEND SMS ──────────────────────────────────────────────────────────────
    case 'send_sms':
        $message = trim($_POST['message'] ?? '');
        if (!$message) { echo json_encode(['success'=>false,'message'=>'Message text is required.']); exit; }

        require_once __DIR__ . '/../../classes/SMSHelper.php';
        $sms = new SMSHelper($pdo, $tenantId);
        if (!$sms->hasConfig()) {
            echo json_encode(['success'=>false,'message'=>'SMS is not configured for your account. Go to Settings → SMS to set it up.']);
            exit;
        }

        $cSt = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE id IN ($ph) AND tenant_id = ? AND phone IS NOT NULL AND phone != ''");
        $cSt->execute(array_merge($ids, [$tenantId]));
        $clients = $cSt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0; $failed = 0; $errors = [];
        foreach ($clients as $c) {
            try {
                require_once __DIR__ . '/../../includes/customer_sms.php';
                $result = sendCustomerSms($pdo,$tenantId,(int)$c['id'],$message);
                if ($result['success']) $sent++; else { $failed++; $errors[] = $result['message'] ?? 'Sending failed'; }
            } catch (Throwable $_e) { $failed++; }
        }
        $msg = "Sent $sent SMS" . ($failed ? ", $failed failed" : '') . '.';
        if ($errors) $msg .= ' ' . implode(' ', array_unique($errors));
        echo json_encode(['success'=>$failed===0 && $sent>0,'message'=>$msg,'sent'=>$sent,'failed'=>$failed]);
        break;

    // ── CHANGE PACKAGE ────────────────────────────────────────────────────────
    case 'change_package':
        $packageId = (int)($_POST['package_id'] ?? 0);
        if (!$packageId) { echo json_encode(['success'=>false,'message'=>'No package selected.']); exit; }

        // Verify package belongs to tenant
        $pkgSt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? AND status = 'active'");
        $pkgSt->execute([$packageId, $tenantId]);
        $pkg = $pkgSt->fetch(PDO::FETCH_ASSOC);
        if (!$pkg) { echo json_encode(['success'=>false,'message'=>'Package not found.']); exit; }

        $locks = [];
        try {
            require_once __DIR__ . '/../../includes/dashboard_sync.php';
            dashboardSyncSchema($pdo);
            $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $key = 'payment-client-' . $tenantId . '-' . $id;
                $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$key]);
                if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('A selected customer is being updated. Please try again.');
                $locks[] = $key;
            }
            $pdo->beginTransaction();
            $selectedPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $selected = $pdo->prepare("SELECT * FROM clients WHERE id IN ($selectedPlaceholders) AND tenant_id=? FOR UPDATE");
            $selected->execute(array_merge($ids, [$tenantId]));
            $customers = $selected->fetchAll(PDO::FETCH_ASSOC);
            foreach ($customers as $customer) {
                if ($customer['connection_type'] !== ($pkg['connection_type'] ?: $pkg['type'])) throw new RuntimeException('All selected customers must match the package connection type.');
                $pdo->prepare('UPDATE clients SET package_id=?, subscription_plan=?, updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$packageId, $pkg['name'], $tenantId, $customer['id']]);
                dashboardQueueCustomer($pdo, $tenantId, (int)$customer['id']);
            }
            $pdo->commit();
            echo json_encode(['success'=>true,'sync_pending'=>true,'message'=>'Packages saved. Applying access settings; paid expiry is unchanged.','count'=>count($customers)]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Update failed: '.$e->getMessage()]);
        } finally {
            foreach ($locks as $key) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
        }
        break;

    // ── EXPORT (CSV) ──────────────────────────────────────────────────────────
    case 'export':
        $cSt = $pdo->prepare("
            SELECT c.*, COALESCE((SELECT name FROM packages WHERE id = c.package_id LIMIT 1), c.subscription_plan) AS package_name,
                   COALESCE((SELECT price FROM packages WHERE id = c.package_id LIMIT 1), 0) AS package_price
            FROM clients c WHERE c.id IN ($ph) AND c.tenant_id = ?
            ORDER BY c.created_at DESC
        ");
        $cSt->execute(array_merge($ids, [$tenantId]));
        $rows = $cSt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="customers_export_'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Account Number','Name','Phone','Email','Address','Package','Price','Connection Type','Username','Status','Expiry Date']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['account_number']??'',$r['full_name']??'',$r['phone']??'',$r['email']??'',$r['address']??'',$r['package_name']??'',$r['package_price']??0,$r['connection_type']??'',$r['mikrotik_username']??'',$r['status']??'',$r['expiry_date']??'']);
        }
        fclose($out);
        exit;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action]);
}
