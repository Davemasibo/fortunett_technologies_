<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

$statusFilter = $_GET['status'] ?? '';
$monthFilter  = $_GET['month']  ?? date('Y-m');
$search       = trim($_GET['q'] ?? '');
$invoiceId    = (int)($_GET['invoice'] ?? 0);

// The month filter defaulted to the current month with no way to escape it, so
// overdue invoices from previous months — precisely the ones keeping a tenant
// suspended — were invisible, and the Mark as Paid buttons on them unreachable.
// ?all=1 lists every period.
$showAllMonths = isset($_GET['all']) && $_GET['all'] !== '0';

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
$where  = [];
$params = [];

if (!$showAllMonths) {
    $where[]  = 'billing_period LIKE ?';
    $params[] = $monthFilter . '%';
}

if ($statusFilter) { $where[] = 'pi.status = ?'; $params[] = $statusFilter; }
if ($search) {
    $where[] = '(t.company_name LIKE ? OR t.subdomain LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%"]);
}

$invoices = $pdo->prepare("
    SELECT pi.*, t.company_name, t.subdomain
    FROM platform_invoices pi
    JOIN tenants t ON t.id = pi.tenant_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY pi.billing_period DESC, pi.status ASC
    LIMIT 500
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
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="css/dark.css?v=2" rel="stylesheet">
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;
      --neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);--neu-text:#e2e2e0;--neu-muted:#9a9a95;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--neu-bg);display:flex;min-height:100vh;color:var(--neu-text);}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#111827 0%,#16213e 55%,#0f3460 100%);color:#fff;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(0,0,0,.5);border-right:1px solid rgba(255,255,255,.06);}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:linear-gradient(135deg,#e94560,#c0305a);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;box-shadow:0 2px 8px rgba(233,69,96,.35);}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;color:#fff;}
.sidebar-brand p{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;overflow-y:auto;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.72);text-decoration:none;font-size:14px;border-left:3px solid transparent;transition:all .2s;}
.sidebar-menu a:hover{background:rgba(255,255,255,.08);color:#fff;border-left-color:rgba(255,255,255,.25);}
.sidebar-menu a.active{background:rgba(255,255,255,.13);color:#fff;border-left-color:#e94560;}
.sidebar-menu a i{width:18px;text-align:center;font-size:15px;}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.45);}
.main{margin-left:var(--sidebar-w);flex:1;background:var(--neu-bg);}
.topbar{background:var(--neu-surf);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--neu-border);position:sticky;top:0;z-index:99;box-shadow:0 2px 20px rgba(0,0,0,.4);}
.topbar h1{font-size:18px;font-weight:700;color:#fff;}
.topbar .user-info{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--neu-muted);}
.content{padding:28px;background:var(--neu-bg);}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;}
.stat-card{background:var(--neu-s2);border-radius:14px;padding:18px 20px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);transition:transform .2s,box-shadow .2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:18px 18px 36px rgba(0,0,0,.6),-8px -8px 22px rgba(255,255,255,.04),0 0 0 1px var(--neu-border);}
.stat-card .label{font-size:11px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.5px;}
.stat-card .value{font-size:24px;font-weight:700;color:#fff;margin:6px 0 3px;}
.stat-card .sub{font-size:12px;color:var(--neu-muted);}
.filters-bar{background:var(--neu-s2);border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;border:1px solid var(--neu-border);box-shadow:8px 8px 20px rgba(0,0,0,.4),-4px -4px 10px rgba(255,255,255,.025);}
.filters-bar input,.filters-bar select{padding:7px 12px;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;background:#1a1a19;color:var(--neu-text);box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);}
.filters-bar input:focus,.filters-bar select:focus{outline:none;border-color:#3B6EA5;}
.btn-filter{padding:7px 16px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border:1px solid rgba(255,255,255,.12);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s;}
.btn-filter:hover{opacity:.88;}
.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);}
table{width:100%;border-collapse:collapse;}
th{padding:10px 16px;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--neu-muted);background:rgba(255,255,255,.04);text-align:left;border-bottom:1px solid var(--neu-border);}
td{padding:11px 16px;font-size:13px;color:var(--neu-text);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.035);}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-paid{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-pending{background:rgba(245,158,11,.18);color:#fcd34d;}
.badge-overdue{background:rgba(239,68,68,.18);color:#fca5a5;}
.badge-waived,.badge-cancelled{background:rgba(107,114,128,.18);color:#9ca3af;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .18s;}
.btn-paid{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.btn-paid:hover{background:rgba(16,185,129,.28);color:#fff;}
.btn-view{background:rgba(255,255,255,.07);color:var(--neu-muted);border:1px solid var(--neu-border);}
.btn-view:hover{background:rgba(255,255,255,.13);color:var(--neu-text);}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#93c5fd;text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;}
.back-link:hover{color:#fff;}
.detail-panel{background:var(--neu-s2);border-radius:14px;padding:28px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0;}
.detail-item label{display:block;font-size:11px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.detail-item span{font-size:14px;color:var(--neu-text);font-weight:500;}
.line-item{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--neu-border);font-size:14px;color:var(--neu-text);}
.line-total{display:flex;justify-content:space-between;padding:14px 0 0;font-size:16px;font-weight:700;color:#fff;}
table a{color:#3B6EA5;text-decoration:none;}
table a:hover{color:#93c5fd;}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="badge-sa">SUPER ADMIN</div>
        <h2><i class="fas fa-shield-alt me-2"></i>FortuNett</h2>
        <p>Platform Administration</p>
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
                <input type="month" name="month" value="<?= $monthFilter ?>" <?= $showAllMonths ? 'disabled' : '' ?>>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Period</label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#e2e8f0;padding:8px 0;white-space:nowrap;">
                    <input type="checkbox" name="all" value="1" <?= $showAllMonths ? 'checked' : '' ?>
                           onchange="this.form.submit()">
                    All months
                </label>
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
            <a href="?all=1&status=pending" class="btn-filter"
               style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25);"
               title="Every unpaid invoice across all billing periods — these are what keep tenants suspended">
                <i class="fas fa-triangle-exclamation"></i> All Outstanding
            </a>
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
