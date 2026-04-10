<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

$statusFilter = $_GET['status'] ?? '';
$monthFilter  = $_GET['month']  ?? date('Y-m');
$search       = trim($_GET['q'] ?? '');
$invoiceId    = (int)($_GET['invoice'] ?? 0);

// Single invoice detail
$invoiceDetail = null;
if ($invoiceId) {
    $stmt = $pdo->prepare("
        SELECT pi.*, t.company_name, t.subdomain, u.email AS admin_email
        FROM platform_invoices pi
        JOIN tenants t ON t.id = pi.tenant_id
        LEFT JOIN users u ON u.id = t.admin_user_id
        WHERE pi.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $invoiceDetail = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Build invoice list query
$where  = ["billing_period LIKE ?"];
$params = [$monthFilter . '%'];

if ($statusFilter) { $where[] = 'pi.status = ?'; $params[] = $statusFilter; }
if ($search) {
    $where[] = '(t.company_name LIKE ? OR t.subdomain LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%"]);
}

$invoices = $pdo->prepare("
    SELECT pi.*, t.company_name, t.subdomain
    FROM platform_invoices pi
    JOIN tenants t ON t.id = pi.tenant_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pi.billing_period DESC, pi.status ASC
");
$invoices->execute($params);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

// Summary for selected month
$billingPeriod = $monthFilter . '-01';
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(total_due), 0) AS total_billed,
        COALESCE(SUM(CASE WHEN status='paid' THEN total_due ELSE 0 END),0) AS total_collected,
        COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN total_due ELSE 0 END),0) AS total_outstanding,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END) AS overdue_count
    FROM platform_invoices
    WHERE billing_period = ?
");
$summaryStmt->execute([$billingPeriod]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Billing — FortuNett Super Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,var(--sa-mid) 0%,var(--sa-dark) 100%);color:#fff;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:var(--sa-accent);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.75);text-decoration:none;font-size:14px;border-left:3px solid transparent;transition:all .2s;}
.sidebar-menu a:hover,.sidebar-menu a.active{background:rgba(255,255,255,.1);color:#fff;}
.sidebar-menu a.active{border-left-color:var(--sa-accent);}
.sidebar-menu a i{width:18px;text-align:center;}
.sidebar-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;opacity:.5;}
.main{margin-left:var(--sidebar-w);flex:1;}
.topbar{background:#fff;padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:99;}
.topbar h1{font-size:18px;font-weight:700;color:#1e293b;}
.content{padding:28px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;}
.stat-card{background:#fff;border-radius:10px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.stat-card .label{font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;}
.stat-card .value{font-size:24px;font-weight:700;color:#1e293b;margin:6px 0 3px;}
.stat-card .sub{font-size:12px;color:#64748b;}
.filters-bar{background:#fff;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.filters-bar input,.filters-bar select{padding:7px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;background:#f9fafb;}
.btn-filter{padding:7px 16px;background:var(--sa-dark);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
table{width:100%;border-collapse:collapse;}
th{padding:10px 16px;font-size:12px;font-weight:600;text-transform:uppercase;color:#94a3b8;background:#f8fafc;text-align:left;border-bottom:1px solid #f1f5f9;}
td{padding:11px 16px;font-size:13px;color:#374151;border-bottom:1px solid #f8fafc;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-paid{background:#dcfce7;color:#166534;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-overdue{background:#fee2e2;color:#991b1b;}
.badge-waived,.badge-cancelled{background:#f1f5f9;color:#475569;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;}
.btn-paid{background:#dcfce7;color:#166534;}
.btn-paid:hover{background:#86efac;}
.btn-view{background:#f1f5f9;color:#475569;}
.btn-view:hover{background:#e2e8f0;}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--sa-dark);text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;}
.detail-panel{background:#fff;border-radius:12px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:24px;}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0;}
.detail-item label{display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.detail-item span{font-size:14px;color:#1e293b;font-weight:500;}
.line-item{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:14px;}
.line-total{display:flex;justify-content:space-between;padding:14px 0 0;font-size:16px;font-weight:700;color:#1e293b;}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="badge-sa">SUPER ADMIN</div>
        <h2><i class="fas fa-shield-alt me-2"></i>FortuNett</h2>
    </div>
    <ul class="sidebar-menu">
        <li><a href="index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php" class="active"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></div>
</div>

<div class="main">
    <div class="topbar">
        <h1><?= $invoiceDetail ? 'Invoice Detail' : 'Platform Billing' ?></h1>
        <div style="display:flex;gap:10px;align-items:center;">
            <form method="POST" action="../api/super_admin/billing.php" style="display:inline;">
                <input type="hidden" name="action" value="generate_month">
                <input type="hidden" name="month" value="<?= $monthFilter ?>">
                <button type="submit" style="padding:8px 16px;background:var(--sa-dark);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;" onclick="return confirm('Generate invoices for all tenants for <?= $monthFilter ?>?')">
                    <i class="fas fa-bolt me-1"></i> Generate Invoices for <?= date('M Y', strtotime($monthFilter.'-01')) ?>
                </button>
            </form>
        </div>
    </div>
    <div class="content">

    <?php if ($invoiceDetail): ?>
        <a href="billing.php?month=<?= $monthFilter ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back to billing</a>
        <div class="detail-panel">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
                <div>
                    <div style="font-size:12px;color:#94a3b8;font-weight:600;letter-spacing:.5px;">INVOICE</div>
                    <h2 style="font-size:22px;font-weight:700;"><?= htmlspecialchars($invoiceDetail['invoice_number']) ?></h2>
                    <div style="font-size:14px;color:#64748b;">Tenant: <strong><?= htmlspecialchars($invoiceDetail['company_name']) ?></strong> &bull; <?= date('M Y', strtotime($invoiceDetail['billing_period'])) ?></div>
                </div>
                <span class="badge badge-<?= $invoiceDetail['status'] ?>" style="font-size:14px;padding:6px 16px;"><?= ucfirst($invoiceDetail['status']) ?></span>
            </div>

            <!-- Line items -->
            <div style="background:#f8fafc;border-radius:10px;padding:18px;">
                <div class="line-item">
                    <span>PPPoE Users (<?= $invoiceDetail['pppoe_user_count'] ?> × KSH <?= number_format($invoiceDetail['pppoe_fee_per_user'], 2) ?>)</span>
                    <strong>KSH <?= number_format($invoiceDetail['pppoe_subtotal'], 2) ?></strong>
                </div>
                <div class="line-item">
                    <span>Hotspot Commission (<?= round($invoiceDetail['hotspot_commission_rate']*100, 2) ?>% × KSH <?= number_format($invoiceDetail['hotspot_collections'], 2) ?> collections)</span>
                    <strong>KSH <?= number_format($invoiceDetail['hotspot_commission'], 2) ?></strong>
                </div>
                <?php if ($invoiceDetail['base_fee'] > 0): ?>
                <div class="line-item">
                    <span>Base Platform Fee</span>
                    <strong>KSH <?= number_format($invoiceDetail['base_fee'], 2) ?></strong>
                </div>
                <?php endif; ?>
                <div class="line-total">
                    <span>Total Due</span>
                    <span>KSH <?= number_format($invoiceDetail['total_due'], 2) ?></span>
                </div>
            </div>

            <div class="detail-grid" style="margin-top:20px;">
                <div class="detail-item"><label>Billing Period</label><span><?= date('M Y', strtotime($invoiceDetail['billing_period'])) ?></span></div>
                <div class="detail-item"><label>Due Date</label><span><?= date('d M Y', strtotime($invoiceDetail['due_date'])) ?></span></div>
                <div class="detail-item"><label>Paid At</label><span><?= $invoiceDetail['paid_at'] ? date('d M Y H:i', strtotime($invoiceDetail['paid_at'])) : '—' ?></span></div>
                <div class="detail-item"><label>Transaction Ref</label><span><?= htmlspecialchars($invoiceDetail['transaction_ref'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Admin Email</label><span><?= htmlspecialchars($invoiceDetail['admin_email'] ?? '—') ?></span></div>
            </div>

            <?php if ($invoiceDetail['status'] !== 'paid'): ?>
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid #f1f5f9;display:flex;gap:12px;align-items:flex-end;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:6px;">Transaction Reference (optional)</label>
                    <input type="text" id="txRef" placeholder="e.g. QHK2YXM3Q1" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;width:260px;">
                </div>
                <button class="btn-sm btn-paid" style="padding:9px 18px;font-size:13px;" onclick="markPaid(<?= $invoiceDetail['id'] ?>, <?= $invoiceDetail['tenant_id'] ?>)">
                    <i class="fas fa-check"></i> Mark as Paid
                </button>
            </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Summary stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Billed</div>
                <div class="value">KSH <?= number_format($summary['total_billed'] ?? 0, 0) ?></div>
                <div class="sub"><?= $summary['total_invoices'] ?? 0 ?> invoices</div>
            </div>
            <div class="stat-card">
                <div class="label">Collected</div>
                <div class="value">KSH <?= number_format($summary['total_collected'] ?? 0, 0) ?></div>
                <div class="sub"><?= $summary['paid_count'] ?? 0 ?> paid</div>
            </div>
            <div class="stat-card">
                <div class="label">Outstanding</div>
                <div class="value" style="color:#dc2626;">KSH <?= number_format($summary['total_outstanding'] ?? 0, 0) ?></div>
                <div class="sub"><?= $summary['overdue_count'] ?? 0 ?> overdue</div>
            </div>
            <div class="stat-card">
                <div class="label">Collection Rate</div>
                <div class="value"><?= ($summary['total_billed'] ?? 0) > 0 ? round(($summary['total_collected']/$summary['total_billed'])*100) : 0 ?>%</div>
                <div class="sub">of billed revenue</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Month</label>
                <input type="month" name="month" value="<?= $monthFilter ?>">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
                    <option value="paid" <?= $statusFilter==='paid'?'selected':'' ?>>Paid</option>
                    <option value="overdue" <?= $statusFilter==='overdue'?'selected':'' ?>>Overdue</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="q" placeholder="Company or subdomain" value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search me-1"></i> Filter</button>
        </form>

        <!-- Invoice table -->
        <div class="card">
            <table>
                <thead>
                    <tr><th>Invoice #</th><th>Tenant</th><th>Period</th><th>PPPoE</th><th>Hotspot</th><th>Total Due</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td style="font-family:monospace;"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($inv['company_name']) ?></strong><br>
                        <small style="color:#94a3b8;"><?= $inv['subdomain'] ?></small>
                    </td>
                    <td><?= date('M Y', strtotime($inv['billing_period'])) ?></td>
                    <td><?= $inv['pppoe_user_count'] ?> users</td>
                    <td>KSH <?= number_format($inv['hotspot_collections'], 0) ?></td>
                    <td><strong>KSH <?= number_format($inv['total_due'], 2) ?></strong></td>
                    <td><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
                    <td><span class="badge badge-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                    <td>
                        <a href="billing.php?invoice=<?= $inv['id'] ?>" class="btn-sm btn-view"><i class="fas fa-eye"></i> View</a>
                        <?php if ($inv['status'] !== 'paid'): ?>
                        <button class="btn-sm btn-paid" onclick="markPaid(<?= $inv['id'] ?>, <?= $inv['tenant_id'] ?>)"><i class="fas fa-check"></i> Paid</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices)): ?>
                <tr><td colspan="9" style="text-align:center;padding:36px;color:#94a3b8;">No invoices found. Use "Generate Invoices" above to create them.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    </div>
</div>

<script>
function markPaid(invoiceId, tenantId) {
    const ref = document.getElementById('txRef') ? document.getElementById('txRef').value : '';
    if (!confirm('Mark this invoice as paid?')) return;
    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_invoice_paid', tenant_id: tenantId, invoice_id: invoiceId, transaction_ref: ref })
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.message || 'Failed'); });
}
</script>
</body>
</html>
