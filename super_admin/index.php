<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

// ── Platform-wide stats ───────────────────────────────────────────────────────
$currentMonth = date('Y-m-01');

// Total tenants by status
$tenantStats = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM tenants GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalTenants  = array_sum($tenantStats);
$activeTenants = ($tenantStats['active'] ?? 0) + ($tenantStats['trial'] ?? 0);

// MRR: sum of total_due from current-month platform_invoices
$mrrRow = $pdo->prepare("SELECT COALESCE(SUM(total_due),0) FROM platform_invoices WHERE billing_period = ? AND status != 'cancelled'");
$mrrRow->execute([$currentMonth]);
$mrr = (float)$mrrRow->fetchColumn();

// Collected this month
$collectedRow = $pdo->prepare("SELECT COALESCE(SUM(total_due),0) FROM platform_invoices WHERE billing_period = ? AND status = 'paid'");
$collectedRow->execute([$currentMonth]);
$collected = (float)$collectedRow->fetchColumn();

// Overdue invoices
$overdueRow = $pdo->query("SELECT COUNT(*) FROM platform_invoices WHERE status = 'overdue'");
$overdueCount = (int)$overdueRow->fetchColumn();

// Revenue trend – last 6 months
$trendStmt = $pdo->query("
    SELECT DATE_FORMAT(billing_period,'%b %Y') AS month,
           COALESCE(SUM(total_due),0)          AS billed,
           COALESCE(SUM(CASE WHEN status='paid' THEN total_due ELSE 0 END),0) AS collected
    FROM platform_invoices
    WHERE billing_period >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
    GROUP BY billing_period
    ORDER BY billing_period ASC
");
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent tenant signups
$recentTenants = $pdo->query("
    SELECT t.id, t.subdomain, t.company_name, t.status, t.created_at,
           u.email AS admin_email,
           p.name  AS plan_name
    FROM tenants t
    LEFT JOIN users u ON u.id = t.admin_user_id
    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
    ORDER BY t.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Invoices needing attention
$pendingInvoices = $pdo->query("
    SELECT pi.*, t.company_name, t.subdomain
    FROM platform_invoices pi
    JOIN tenants t ON t.id = pi.tenant_id
    WHERE pi.status IN ('pending','overdue')
    ORDER BY pi.due_date ASC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$statusColor = ['active'=>'#22c55e','trial'=>'#f59e0b','suspended'=>'#ef4444','expired'=>'#6b7280'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Dashboard — FortuNett Technologies</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="css/dark.css?v=2" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;
      --neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);--neu-text:#e2e2e0;--neu-muted:#9a9a95;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--neu-bg);display:flex;min-height:100vh;color:var(--neu-text);}
/* Sidebar */
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#111827 0%,#16213e 55%,#0f3460 100%);color:#fff;flex-shrink:0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;box-shadow:4px 0 24px rgba(0,0,0,.5);border-right:1px solid rgba(255,255,255,.06);}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:linear-gradient(135deg,#e94560,#c0305a);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;box-shadow:0 2px 8px rgba(233,69,96,.35);}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;color:#fff;}
.sidebar-brand p{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;overflow-y:auto;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.72);text-decoration:none;transition:all .2s;font-size:14px;border-left:3px solid transparent;}
.sidebar-menu a:hover{background:rgba(255,255,255,.08);color:#fff;border-left-color:rgba(255,255,255,.25);}
.sidebar-menu a.active{background:rgba(255,255,255,.13);color:#fff;border-left-color:#e94560;}
.sidebar-menu a i{width:18px;text-align:center;font-size:15px;}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.45);}
/* Main */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;background:var(--neu-bg);}
.topbar{background:var(--neu-surf);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--neu-border);position:sticky;top:0;z-index:99;box-shadow:0 2px 20px rgba(0,0,0,.4);}
.topbar h1{font-size:18px;font-weight:700;color:#fff;}
.topbar .user-info{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--neu-muted);}
.topbar .user-info .avatar{width:34px;height:34px;background:rgba(255,255,255,.12);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,.15);}
.content{padding:28px;background:var(--neu-bg);}
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:28px;}
.stat-card{background:var(--neu-s2);border-radius:14px;padding:22px 24px;box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);transition:transform .2s,box-shadow .2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:18px 18px 36px rgba(0,0,0,.6),-8px -8px 22px rgba(255,255,255,.04),0 0 0 1px var(--neu-border);}
.stat-card .label{font-size:12px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.5px;}
.stat-card .value{font-size:30px;font-weight:700;color:#fff;margin:8px 0 4px;}
.stat-card .sub{font-size:12px;color:var(--neu-muted);}
.stat-card .sub a{color:var(--neu-muted);text-decoration:none;}
.stat-card .icon-wrap{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;float:right;margin-top:-4px;background:rgba(255,255,255,.07);box-shadow:inset 2px 2px 6px rgba(0,0,0,.3),inset -1px -1px 4px rgba(255,255,255,.07);}
/* Cards */
.card{background:var(--neu-s2);border-radius:14px;box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;}
.card-head{padding:18px 22px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.02);border-radius:14px 14px 0 0;}
.card-head h3{font-size:15px;font-weight:700;color:#fff;}
.card-head a{font-size:13px;color:#3B6EA5;text-decoration:none;}
.card-head a:hover{color:#93c5fd;}
.card-body{padding:4px 0;}
/* Table */
table{width:100%;border-collapse:collapse;}
th{padding:11px 18px;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--neu-muted);background:rgba(255,255,255,.04);text-align:left;border-bottom:1px solid var(--neu-border);}
td{padding:12px 18px;font-size:14px;color:var(--neu-text);border-bottom:1px solid rgba(255,255,255,.04);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.035);}
/* Badges */
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;}
.badge-active{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-trial{background:rgba(245,158,11,.18);color:#fcd34d;}
.badge-suspended{background:rgba(239,68,68,.18);color:#fca5a5;}
.badge-expired{background:rgba(107,114,128,.18);color:#9ca3af;}
.badge-paid{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-pending{background:rgba(245,158,11,.18);color:#fcd34d;}
.badge-overdue{background:rgba(239,68,68,.18);color:#fca5a5;}
/* Buttons */
.btn-sm{padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;border:none;cursor:pointer;transition:all .18s;}
.btn-suspend{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25);}
.btn-suspend:hover{background:rgba(239,68,68,.28);color:#fff;}
.btn-activate{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.btn-activate:hover{background:rgba(16,185,129,.28);color:#fff;}
.btn-view{background:rgba(255,255,255,.07);color:var(--neu-muted);border:1px solid var(--neu-border);}
.btn-view:hover{background:rgba(255,255,255,.13);color:var(--neu-text);}
/* Links */
table a{color:#3B6EA5;text-decoration:none;}
table a:hover{color:#93c5fd;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr);}.two-col{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="badge-sa">SUPER ADMIN</div>
        <h2><i class="fas fa-shield-alt me-2"></i>FortuNett</h2>
        <p>Platform Administration</p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></div>
</div>

<!-- Main -->
<div class="main">
    <div class="topbar">
        <h1>Platform Dashboard</h1>
        <div class="user-info">
            <span><?= date('l, d M Y') ?></span>
            <div class="avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        </div>
    </div>

    <div class="content">

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon-wrap" style="background:rgba(99,102,241,.18);color:#a5b4fc;"><i class="fas fa-building"></i></div>
                <div class="label">Total Tenants</div>
                <div class="value"><?= number_format($totalTenants) ?></div>
                <div class="sub"><?= $activeTenants ?> active / trial</div>
            </div>
            <div class="stat-card">
                <div class="icon-wrap" style="background:rgba(16,185,129,.18);color:#6ee7b7;"><i class="fas fa-dollar-sign"></i></div>
                <div class="label">MRR (this month)</div>
                <div class="value">KSH <?= number_format($mrr, 0) ?></div>
                <div class="sub">Billed across all tenants</div>
            </div>
            <div class="stat-card">
                <div class="icon-wrap" style="background:rgba(245,158,11,.18);color:#fcd34d;"><i class="fas fa-check-circle"></i></div>
                <div class="label">Collected</div>
                <div class="value">KSH <?= number_format($collected, 0) ?></div>
                <div class="sub"><?= $mrr > 0 ? round($collected/$mrr*100) : 0 ?>% of MRR</div>
            </div>
            <div class="stat-card">
                <div class="icon-wrap" style="background:rgba(239,68,68,.18);color:#fca5a5;"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="label">Overdue Invoices</div>
                <div class="value"><?= $overdueCount ?></div>
                <div class="sub"><a href="billing.php?status=overdue">View all &rarr;</a></div>
            </div>
        </div>

        <!-- Charts + Recent signups -->
        <div class="two-col">
            <!-- Revenue Trend -->
            <div class="card">
                <div class="card-head">
                    <h3><i class="fas fa-chart-bar me-2" style="color:#2563eb;"></i>Revenue Trend (6 months)</h3>
                </div>
                <div style="padding:20px;">
                    <canvas id="revChart" height="220"></canvas>
                </div>
            </div>

            <!-- Tenant Status Distribution -->
            <div class="card">
                <div class="card-head">
                    <h3><i class="fas fa-chart-pie me-2" style="color:#7c3aed;"></i>Tenant Status</h3>
                </div>
                <div style="padding:20px;display:flex;align-items:center;justify-content:center;">
                    <canvas id="statusChart" height="220" style="max-width:260px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Pending Invoices -->
        <?php if (!empty($pendingInvoices)): ?>
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-clock me-2" style="color:#d97706;"></i>Invoices Awaiting Payment</h3>
                <a href="billing.php" style="font-size:13px;color:#2563eb;text-decoration:none;">View all &rarr;</a>
            </div>
            <div class="card-body">
                <table>
                    <thead><tr><th>Tenant</th><th>Period</th><th>Amount Due</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingInvoices as $inv): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($inv['company_name']) ?></strong><br><small style="color:#94a3b8;"><?= $inv['subdomain'] ?>.fortunetttech.site</small></td>
                        <td><?= date('M Y', strtotime($inv['billing_period'])) ?></td>
                        <td><strong>KSH <?= number_format($inv['total_due'], 2) ?></strong></td>
                        <td><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
                        <td><span class="badge badge-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                        <td><a href="billing.php?invoice=<?= $inv['id'] ?>" class="btn-sm btn-view">Details</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Tenant Signups -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-user-plus me-2" style="color:#16a34a;"></i>Recent Tenant Signups</h3>
                <a href="tenants.php" style="font-size:13px;color:#2563eb;text-decoration:none;">View all &rarr;</a>
            </div>
            <div class="card-body">
                <table>
                    <thead><tr><th>Company</th><th>Subdomain</th><th>Plan</th><th>Status</th><th>Signed Up</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTenants as $t): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($t['company_name']) ?></strong><br>
                            <small style="color:#94a3b8;"><?= htmlspecialchars($t['admin_email'] ?? '') ?></small>
                        </td>
                        <td><a href="https://<?= $t['subdomain'] ?>.fortunetttech.site" target="_blank" style="color:#2563eb;text-decoration:none;"><?= $t['subdomain'] ?></a></td>
                        <td><?= htmlspecialchars($t['plan_name'] ?? 'Starter') ?></td>
                        <td><span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                        <td><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                        <td>
                            <a href="tenants.php?id=<?= $t['id'] ?>" class="btn-sm btn-view">View</a>
                            <?php if ($t['status'] === 'suspended'): ?>
                            <button class="btn-sm btn-activate" onclick="changeTenantStatus(<?= $t['id'] ?>,'active')">Activate</button>
                            <?php elseif ($t['status'] !== 'expired'): ?>
                            <button class="btn-sm btn-suspend" onclick="changeTenantStatus(<?= $t['id'] ?>,'suspended')">Suspend</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentTenants)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:30px;">No tenants yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script>
// Revenue trend chart
const trendData = <?= json_encode($trendData) ?>;
new Chart(document.getElementById('revChart'), {
    type: 'bar',
    data: {
        labels: trendData.map(d => d.month),
        datasets: [
            { label: 'Billed', data: trendData.map(d => d.billed), backgroundColor: 'rgba(37,99,235,.15)', borderColor: '#2563eb', borderWidth: 2, borderRadius: 4 },
            { label: 'Collected', data: trendData.map(d => d.collected), backgroundColor: 'rgba(22,163,74,.2)', borderColor: '#16a34a', borderWidth: 2, borderRadius: 4 }
        ]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true, ticks:{ callback: v => 'KSH ' + v.toLocaleString() } } } }
});

// Status donut
const statusData = <?= json_encode(array_values([
    'active'    => $tenantStats['active']    ?? 0,
    'trial'     => $tenantStats['trial']     ?? 0,
    'suspended' => $tenantStats['suspended'] ?? 0,
    'expired'   => $tenantStats['expired']   ?? 0,
])) ?>;
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active','Trial','Suspended','Expired'],
        datasets: [{ data: statusData, backgroundColor: ['#22c55e','#f59e0b','#ef4444','#94a3b8'], borderWidth: 0 }]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom' } } }
});

function changeTenantStatus(tenantId, status) {
    if (!confirm('Are you sure you want to ' + status + ' this tenant?')) return;
    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_status', tenant_id: tenantId, status: status })
    })
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); else alert(d.message || 'Failed'); });
}
</script>
</body>
</html>
