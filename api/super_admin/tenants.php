<?php
/**
 * Super Admin — Tenant Management API
 * POST /api/super_admin/tenants.php
 * Actions: set_status | save_notes | set_plan
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';

if (!isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action   = $data['action']    ?? '';
$tenantId = (int)($data['tenant_id'] ?? 0);

if (!$tenantId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'tenant_id required']);
    exit;
}

// Confirm tenant exists
$check = $pdo->prepare("SELECT id, company_name FROM tenants WHERE id = ?");
$check->execute([$tenantId]);
$tenant = $check->fetch();
if (!$tenant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
    exit;
}

try {
    switch ($action) {

        case 'set_status':
            $allowed = ['active', 'suspended', 'trial', 'expired'];
            $status  = $data['status'] ?? '';
            if (!in_array($status, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status']);
                exit;
            }

            $cols  = ['status = ?'];
            $vals  = [$status];

            if ($status === 'suspended') {
                $cols[] = 'suspended_at = NOW()';
                $cols[] = 'suspended_reason = ?';
                $vals[] = $data['reason'] ?? 'Manually suspended by super admin';
            } elseif ($status === 'active') {
                $cols[] = 'suspended_at = NULL';
                $cols[] = 'suspended_reason = NULL';
            }

            $vals[] = $tenantId;
            $pdo->prepare("UPDATE tenants SET " . implode(', ', $cols) . " WHERE id = ?")
                ->execute($vals);

            echo json_encode([
                'success' => true,
                'message' => "Tenant {$tenant['company_name']} status updated to $status"
            ]);
            break;

        case 'save_notes':
            $notes = substr(trim($data['notes'] ?? ''), 0, 2000);
            $pdo->prepare("UPDATE tenants SET notes = ? WHERE id = ?")
                ->execute([$notes, $tenantId]);
            echo json_encode(['success' => true, 'message' => 'Notes saved']);
            break;

        case 'set_plan':
            $planId = (int)($data['plan_id'] ?? 0);
            $plan = $pdo->prepare("SELECT id FROM platform_subscription_plans WHERE id = ? AND is_active = 1");
            $plan->execute([$planId]);
            if (!$plan->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Invalid plan']);
                exit;
            }
            $pdo->prepare("UPDATE tenants SET subscription_plan_id = ? WHERE id = ?")
                ->execute([$planId, $tenantId]);
            echo json_encode(['success' => true, 'message' => 'Plan updated']);
            break;

        case 'mark_invoice_paid':
            $invoiceId = (int)($data['invoice_id'] ?? 0);
            $ref       = trim($data['transaction_ref'] ?? '');
            if (!$invoiceId) {
                echo json_encode(['success' => false, 'message' => 'invoice_id required']);
                exit;
            }
            $pdo->prepare("
                UPDATE platform_invoices
                SET status = 'paid', paid_at = NOW(), payment_method = 'manual', transaction_ref = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$ref ?: 'MANUAL-' . strtoupper(bin2hex(random_bytes(3))), $invoiceId, $tenantId]);
            echo json_encode(['success' => true, 'message' => 'Invoice marked as paid']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    error_log("super_admin/tenants.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
