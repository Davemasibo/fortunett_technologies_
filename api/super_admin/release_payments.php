<?php
/**
 * Read-only legacy settlement summary. Mutations require a completed-transfer
 * record in super_admin/disbursements.php; age-based release is disabled.
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();
require_once __DIR__ . '/../../includes/disbursements.php';
ensureDisbursementBalance($pdo);

// Ensure released_at column exists (one-time migration)
try { $pdo->exec("ALTER TABLE payments ADD COLUMN released_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN release_note VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

$action     = $_POST['action']     ?? '';

try {
    if (in_array($action, ['release_tenant', 'release_all', 'auto_release'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Record the completed transfer in Collections > Held for ISPs > Record / view disbursements. Automatic age-based release is disabled.']);
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
                COALESCE(SUM(CASE WHEN p.released_at IS NULL THEN p.amount - p.disbursed_amount END), 0)        AS unreleased_amount,
                COUNT(CASE WHEN p.released_at IS NOT NULL OR p.disbursed_amount > 0 THEN 1 END) AS released_count,
                COALESCE(SUM(CASE WHEN p.released_at IS NOT NULL THEN p.amount ELSE p.disbursed_amount END), 0)    AS released_amount,
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
