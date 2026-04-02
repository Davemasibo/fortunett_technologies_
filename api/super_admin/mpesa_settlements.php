<?php
/**
 * GET /api/super_admin/mpesa_settlements.php
 * Returns per-tenant platform-collected payment totals pending settlement
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();

try {
    // Sum platform-collected payments per tenant (not yet settled)
    $stmt = $pdo->query("
        SELECT p.tenant_id,
               t.company_name,
               t.subdomain,
               COUNT(*)                  AS payment_count,
               COALESCE(SUM(p.amount),0) AS total_collected,
               MAX(p.payment_date)       AS last_payment_date
        FROM payments p
        JOIN tenants t ON t.id = p.tenant_id
        WHERE p.collection_type = 'platform'
          AND p.status = 'completed'
        GROUP BY p.tenant_id, t.company_name, t.subdomain
        ORDER BY total_collected DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'settlements'=>$rows]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage(),'settlements'=>[]]);
}
