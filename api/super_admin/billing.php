<?php
/**
 * Super Admin — Billing API
 * Handles manual invoice generation trigger from the UI.
 * POST /api/super_admin/billing.php  action=generate_month&month=YYYY-MM
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';

if (!isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

// Support both form POST and JSON
$action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');
$month  = $_POST['month']  ?? (json_decode(file_get_contents('php://input'), true)['month']  ?? date('Y-m'));

if ($action !== 'generate_month') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => 'Invalid month format (YYYY-MM)']);
    exit;
}

$billingPeriod = $month . '-01';
$dueDate       = date('Y-m-d', strtotime($billingPeriod . ' +15 days'));

// Run the core billing logic inline (same as the cron)
require_once __DIR__ . '/../../includes/db_master.php';

try {
    $tenants = $pdo->query("
        SELECT t.id, t.company_name,
               COALESCE(p.pppoe_fee_per_user, 25.00)      AS pppoe_fee,
               COALESCE(p.hotspot_commission_rate, 0.03)  AS commission_rate,
               COALESCE(p.base_monthly_fee, 0.00)         AS base_fee,
               p.id AS plan_id
        FROM tenants t
        LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
        WHERE t.status IN ('active','trial')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $generated = 0;
    $skipped   = 0;

    foreach ($tenants as $tenant) {
        $tenantId = (int)$tenant['id'];

        // Skip if invoice already exists for this period
        $exists = $pdo->prepare("SELECT id FROM platform_invoices WHERE tenant_id = ? AND billing_period = ?");
        $exists->execute([$tenantId, $billingPeriod]);
        if ($exists->fetchColumn()) { $skipped++; continue; }

        // PPPoE user count
        $pppoeStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND connection_type = 'pppoe' AND status = 'active'");
        $pppoeStmt->execute([$tenantId]);
        $pppoeCount = (int)$pppoeStmt->fetchColumn();

        // Hotspot collections this period
        $hotspotStmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount),0)
            FROM payments p
            JOIN clients c ON c.id = p.client_id
            WHERE p.tenant_id = ?
              AND c.connection_type = 'hotspot'
              AND p.status = 'completed'
              AND p.payment_date BETWEEN ? AND LAST_DAY(?)
        ");
        $hotspotStmt->execute([$tenantId, $billingPeriod, $billingPeriod]);
        $hotspotCollections = (float)$hotspotStmt->fetchColumn();

        // Generate invoice number: INV-YYYY-MM-TENANTID
        $invoiceNumber = sprintf('INV-%s-%04d', date('Y-m', strtotime($billingPeriod)), $tenantId);

        $pdo->prepare("
            INSERT INTO platform_invoices
                (invoice_number, tenant_id, billing_period, plan_id,
                 pppoe_user_count, pppoe_fee_per_user,
                 hotspot_collections, hotspot_commission_rate,
                 base_fee, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ")->execute([
            $invoiceNumber, $tenantId, $billingPeriod, $tenant['plan_id'],
            $pppoeCount, $tenant['pppoe_fee'],
            $hotspotCollections, $tenant['commission_rate'],
            $tenant['base_fee'], $dueDate
        ]);

        $generated++;
    }

    // Redirect back to billing page after form POST
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
        header("Location: ../../super_admin/billing.php?month=$month&generated=$generated");
        exit;
    }

    echo json_encode([
        'success'   => true,
        'message'   => "Generated $generated invoice(s), skipped $skipped existing.",
        'generated' => $generated,
        'skipped'   => $skipped
    ]);

} catch (PDOException $e) {
    error_log("Billing generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
