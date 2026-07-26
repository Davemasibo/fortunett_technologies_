<?php
/**
 * Super Admin — Tenant Management API
 * POST /api/super_admin/tenants.php
 * Actions: set_status | save_notes | set_plan | extend_days | mark_invoice_paid
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

        case 'extend_days':
            $days = (int)($data['days'] ?? 0);
            if ($days < 1 || $days > 3650) {
                echo json_encode(['success' => false, 'message' => 'days must be between 1 and 3650']);
                exit;
            }

            // Fetch current tenant to know which date field to extend
            $tRow = $pdo->prepare("SELECT status, trial_ends_at, subscription_ends_at FROM tenants WHERE id = ?");
            $tRow->execute([$tenantId]);
            $tRow = $tRow->fetch(PDO::FETCH_ASSOC);

            // Determine the base date: if the date is already in the future use it,
            // otherwise start the extension from today so we don't lose days.
            $today = date('Y-m-d');
            if ($tRow['status'] === 'trial') {
                $current = $tRow['trial_ends_at'] ?? $today;
                $base    = $current > $today ? $current : $today;
                $newDate = date('Y-m-d', strtotime($base . ' +' . $days . ' days'));
                $pdo->prepare("UPDATE tenants SET trial_ends_at = ?, status = 'trial' WHERE id = ?")
                    ->execute([$newDate, $tenantId]);
                $field = 'trial_ends_at';
            } else {
                $current = $tRow['subscription_ends_at'] ?? $today;
                $base    = $current > $today ? $current : $today;
                $newDate = date('Y-m-d', strtotime($base . ' +' . $days . ' days'));
                // If tenant was suspended/expired because of date, reactivate them
                $newStatus = in_array($tRow['status'], ['suspended', 'expired']) ? 'active' : $tRow['status'];
                $pdo->prepare("UPDATE tenants SET subscription_ends_at = ?, status = ?, suspended_at = NULL, suspended_reason = NULL WHERE id = ?")
                    ->execute([$newDate, $newStatus, $tenantId]);
                $field = 'subscription_ends_at';
            }

            echo json_encode([
                'success'  => true,
                'message'  => "Extended by $days day(s). New {$field}: $newDate",
                'new_date' => $newDate,
            ]);
            break;

        case 'mark_invoice_paid':
            $invoiceId = (int)($data['invoice_id'] ?? 0);
            $ref       = trim($data['transaction_ref'] ?? '');
            if (!$invoiceId) {
                echo json_encode(['success' => false, 'message' => 'invoice_id required']);
                exit;
            }
            $upd = $pdo->prepare("
                UPDATE platform_invoices
                SET status = 'paid', paid_at = NOW(), payment_method = 'manual', transaction_ref = ?
                WHERE id = ? AND tenant_id = ?
            ");
            $upd->execute([$ref ?: 'MANUAL-' . strtoupper(bin2hex(random_bytes(3))), $invoiceId, $tenantId]);

            if ($upd->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Invoice not found for this tenant']);
                exit;
            }

            // Settling the last outstanding invoice should restore service now, not
            // whenever check_suspensions.php next runs. Waiting up to 24h after the
            // operator has confirmed payment is the sort of delay that generates
            // support calls.
            $message = 'Invoice marked as paid';
            try {
                $out = $pdo->prepare("
                    SELECT COUNT(*) FROM platform_invoices
                    WHERE tenant_id = ? AND status <> 'paid'
                ");
                $out->execute([$tenantId]);
                $stillOwing = (int)$out->fetchColumn();

                if ($stillOwing === 0) {
                    $st = $pdo->prepare("SELECT status FROM tenants WHERE id = ? LIMIT 1");
                    $st->execute([$tenantId]);
                    if ($st->fetchColumn() === 'suspended') {
                        $pdo->prepare("
                            UPDATE tenants
                            SET status = 'active', suspended_at = NULL, suspended_reason = NULL
                            WHERE id = ?
                        ")->execute([$tenantId]);
                        $message = 'Invoice marked as paid — tenant reactivated (no outstanding invoices)';
                    }
                } else {
                    $message = "Invoice marked as paid — {$stillOwing} invoice(s) still outstanding";
                }
            } catch (Throwable $e) {
                error_log('mark_invoice_paid reactivation check: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'message' => $message]);
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
