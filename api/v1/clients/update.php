<?php
/**
 * POST /api/v1/clients/update.php
 * Body (JSON): id, [full_name, phone, email, address, status, package_id,
 *              expiry_date, extend_days]
 *
 * Special actions:
 *   action=renew  — extends expiry by package validity (or extend_days)
 *   action=suspend / action=activate / action=expire
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = (int)($body['id'] ?? 0);
$action = trim($body['action'] ?? '');

if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

$clientStmt = $pdo->prepare("SELECT c.*, p.validity_value, p.validity_unit FROM clients c LEFT JOIN packages p ON p.id = c.package_id WHERE c.id = ? AND c.tenant_id = ? LIMIT 1");
$clientStmt->execute([$id, $tenantId]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }

try {
    // ── Status-only quick actions ─────────────────────────────────────────────
    $statusMap = ['suspend' => 'suspended', 'activate' => 'active', 'expire' => 'expired'];
    if (isset($statusMap[$action])) {
        $pdo->prepare("UPDATE clients SET status = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$statusMap[$action], $id, $tenantId]);
        echo json_encode(['success' => true, 'message' => ucfirst($action) . 'd successfully']);
        exit;
    }

    // ── Renew ─────────────────────────────────────────────────────────────────
    if ($action === 'renew') {
        $extendDays = (int)($body['extend_days'] ?? 0);
        if ($extendDays > 0) {
            $newExpiry = date('Y-m-d H:i:s', strtotime(($client['expiry_date'] ?? 'now') . " +$extendDays days"));
        } elseif (!empty($client['validity_value'])) {
            $val = (int)$client['validity_value'];
            $unit = $client['validity_unit'] ?? 'days';
            $newExpiry = date('Y-m-d H:i:s', strtotime("+$val $unit"));
        } else {
            $newExpiry = date('Y-m-d H:i:s', strtotime('+1 month'));
        }
        $pdo->prepare("UPDATE clients SET expiry_date = ?, status = 'active' WHERE id = ? AND tenant_id = ?")
            ->execute([$newExpiry, $id, $tenantId]);
        echo json_encode(['success' => true, 'new_expiry' => $newExpiry, 'message' => 'Renewed successfully']);
        exit;
    }

    // ── General profile update ────────────────────────────────────────────────
    $set  = [];
    $vals = [];

    if (isset($body['full_name'])) { $set[] = 'full_name=?'; $set[] = 'name=?'; $vals[] = trim($body['full_name']); $vals[] = trim($body['full_name']); }
    if (isset($body['phone']))     { $set[] = 'phone=?';     $vals[] = trim($body['phone']); }
    if (isset($body['email']))     { $set[] = 'email=?';     $vals[] = trim($body['email']); }
    if (isset($body['address']))   { $set[] = 'address=?';   $vals[] = trim($body['address']); }
    if (isset($body['status']) && in_array($body['status'], ['active','inactive','suspended','expired'])) {
        $set[] = 'status=?'; $vals[] = $body['status'];
    }
    if (!empty($body['package_id'])) {
        $pkgCheck = $pdo->prepare("SELECT name FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
        $pkgCheck->execute([(int)$body['package_id'], $tenantId]);
        $pkg = $pkgCheck->fetch(PDO::FETCH_ASSOC);
        if ($pkg) {
            $set[] = 'package_id=?'; $vals[] = (int)$body['package_id'];
            $set[] = 'subscription_plan=?'; $vals[] = $pkg['name'];
        }
    }
    if (isset($body['expiry_date'])) {
        $set[] = 'expiry_date=?';
        $vals[] = date('Y-m-d H:i:s', strtotime($body['expiry_date']));
    }

    if (empty($set)) { http_response_code(422); echo json_encode(['error' => 'No updatable fields provided']); exit; }

    $vals[] = $id;
    $vals[] = $tenantId;
    $pdo->prepare("UPDATE clients SET " . implode(',', $set) . " WHERE id=? AND tenant_id=?")->execute($vals);

    echo json_encode(['success' => true, 'message' => 'Client updated']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
