<?php
/**
 * GET /api/super_admin/mpesa_settlements.php
 * Per-tenant M-Pesa transaction summary — all statuses, all collection types.
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();

try {
    // Per-tenant breakdown: completed (direct + platform), pending, failed/cancelled
    $stmt = $pdo->query("
        SELECT
            p.tenant_id,
            t.company_name,
            t.subdomain,

            -- Completed payments
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END)                        AS completed_count,
            COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount END), 0)      AS completed_amount,

            -- Platform-collected completed (FortuNett owes these to tenant)
            COUNT(CASE WHEN p.status = 'completed'
                            AND p.collection_type = 'platform' THEN 1 END)            AS platform_count,
            COALESCE(SUM(CASE WHEN p.status = 'completed'
                               AND p.collection_type = 'platform' THEN p.amount END), 0) AS platform_amount,

            -- Pending (STK sent, awaiting callback)
            COUNT(CASE WHEN p.status = 'pending' THEN 1 END)                          AS pending_count,

            -- Failed / cancelled
            COUNT(CASE WHEN p.status = 'failed' THEN 1 END)                           AS failed_count,

            MAX(p.payment_date)  AS last_payment_date,
            MAX(p.created_at)    AS last_created_at
        FROM payments p
        JOIN tenants t ON t.id = p.tenant_id
        WHERE p.payment_method = 'mpesa'
        GROUP BY p.tenant_id, t.company_name, t.subdomain
        ORDER BY completed_amount DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'settlements' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'settlements' => []]);
}
