<?php
/**
 * POST /api/super_admin/release_payments.php
 * Mark platform-collected payments as released to tenant(s).
 *
 * Actions:
 *   release_tenant  — release all unreleased platform payments for one tenant
 *   release_all     — release all unreleased platform payments across all tenants
 *   auto_release    — release payments completed more than $threshold_hours ago
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();

// Ensure released_at column exists (one-time migration)
try { $pdo->exec("ALTER TABLE payments ADD COLUMN released_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN release_note VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

$action     = $_POST['action']     ?? '';
$tenant_id  = (int)($_POST['tenant_id'] ?? 0);
$note       = trim($_POST['note'] ?? 'Released by super admin');
$threshold  = max(1, (int)($_POST['threshold_hours'] ?? 48)); // hours before auto-release

try {
    $now = date('Y-m-d H:i:s');

    if ($action === 'release_tenant') {
        if (!$tenant_id) throw new Exception('tenant_id required');

        $stmt = $pdo->prepare("
            UPDATE payments
            SET released_at = ?, release_note = ?
            WHERE tenant_id = ?
              AND collection_type = 'platform'
              AND status = 'completed'
              AND released_at IS NULL
        ");
        $stmt->execute([$now, $note, $tenant_id]);
        $affected = $stmt->rowCount();

        // Fetch tenant name for response
        $tStmt = $pdo->prepare("SELECT company_name FROM tenants WHERE id = ?");
        $tStmt->execute([$tenant_id]);
        $tName = $tStmt->fetchColumn() ?: "Tenant #$tenant_id";

        echo json_encode([
            'success'  => true,
            'released' => $affected,
            'message'  => "Released $affected payment(s) for $tName",
        ]);
        exit;
    }

    if ($action === 'release_all') {
        $stmt = $pdo->prepare("
            UPDATE payments
            SET released_at = ?, release_note = ?
            WHERE collection_type = 'platform'
              AND status = 'completed'
              AND released_at IS NULL
        ");
        $stmt->execute([$now, $note]);
        $affected = $stmt->rowCount();
        echo json_encode([
            'success'  => true,
            'released' => $affected,
            'message'  => "Released $affected payment(s) across all tenants",
        ]);
        exit;
    }

    if ($action === 'auto_release') {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$threshold} hours"));
        $stmt = $pdo->prepare("
            UPDATE payments
            SET released_at = ?, release_note = 'Auto-released'
            WHERE collection_type = 'platform'
              AND status = 'completed'
              AND released_at IS NULL
              AND payment_date <= ?
        ");
        $stmt->execute([$now, $cutoff]);
        $affected = $stmt->rowCount();
        echo json_encode([
            'success'   => true,
            'released'  => $affected,
            'threshold' => $threshold,
            'message'   => "Auto-released $affected payment(s) older than {$threshold}h",
        ]);
        exit;
    }

    if ($action === 'summary') {
        // Per-tenant unreleased + released totals
        $stmt = $pdo->query("
            SELECT
                p.tenant_id,
                t.company_name,
                t.subdomain,
                COUNT(CASE WHEN p.released_at IS NULL THEN 1 END)                          AS unreleased_count,
                COALESCE(SUM(CASE WHEN p.released_at IS NULL THEN p.amount END), 0)        AS unreleased_amount,
                COUNT(CASE WHEN p.released_at IS NOT NULL THEN 1 END)                      AS released_count,
                COALESCE(SUM(CASE WHEN p.released_at IS NOT NULL THEN p.amount END), 0)    AS released_amount,
                MAX(CASE WHEN p.released_at IS NOT NULL THEN p.released_at END)            AS last_released_at
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            WHERE p.collection_type = 'platform'
              AND p.status = 'completed'
            GROUP BY p.tenant_id, t.company_name, t.subdomain
            ORDER BY unreleased_amount DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'settlements' => $rows]);
        exit;
    }

    throw new Exception("Unknown action: $action");

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
