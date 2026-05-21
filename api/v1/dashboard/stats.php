<?php
/**
 * GET /api/v1/dashboard/stats.php
 *
 * Dashboard metrics for the Kotlin admin app home screen.
 * Returns counts and recent activity for the authenticated tenant.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant context required']);
    exit;
}

try {
    // Client counts by status
    $clientStats = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'active')    AS active,
            SUM(status = 'inactive')  AS inactive,
            SUM(status = 'expired')   AS expired,
            SUM(status = 'suspended') AS suspended
        FROM clients WHERE tenant_id = ?
    ");
    $clientStats->execute([$tenantId]);
    $clients = $clientStats->fetch(PDO::FETCH_ASSOC);

    // Revenue — current month completed payments
    $revStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS month_revenue
        FROM payments
        WHERE tenant_id = ? AND status = 'completed'
          AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $revStmt->execute([$tenantId]);
    $monthRevenue = (float)$revStmt->fetchColumn();

    // Revenue — last 6 months trend
    $trendStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
               COALESCE(SUM(amount), 0)         AS total
        FROM payments
        WHERE tenant_id = ? AND status = 'completed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $trendStmt->execute([$tenantId]);
    $revenueTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

    // Router count and online status
    $routerStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(status = 'online') AS online
        FROM mikrotik_routers WHERE tenant_id = ?
    ");
    $routerStmt->execute([$tenantId]);
    $routers = $routerStmt->fetch(PDO::FETCH_ASSOC);

    // Recent payments (last 5)
    $recentStmt = $pdo->prepare("
        SELECT p.id, p.amount,
               p.payment_method AS method,
               p.status,
               p.created_at,
               COALESCE(c.full_name, c.username, c.phone) AS client_name
        FROM payments p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.tenant_id = ?
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $recentStmt->execute([$tenantId]);
    $recentPayments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'clients' => [
            'total'    => (int)($clients['total']    ?? 0),
            'active'   => (int)($clients['active']   ?? 0),
            'inactive' => (int)($clients['inactive'] ?? 0),
            'expired'  => (int)($clients['expired']  ?? 0),
        ],
        'revenue' => [
            'this_month' => $monthRevenue,
            'trend'      => array_map(fn($r) => [
                'month' => $r['month'],
                'total' => (float)$r['total'],
            ], $revenueTrend),
        ],
        'routers' => [
            'total'  => (int)($routers['total']  ?? 0),
            'online' => (int)($routers['online'] ?? 0),
        ],
        'recent_payments' => array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'client_name' => $r['client_name'] ?? 'Unknown',
            'amount'      => (float)$r['amount'],
            'method'      => $r['method'] ?? '',
            'status'      => $r['status'],
            'created_at'  => $r['created_at'],
        ], $recentPayments),
    ]);

} catch (Throwable $e) {
    error_log('API dashboard/stats error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load dashboard stats']);
}
