<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

// Filters
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$planFilter   = (int)($_GET['plan'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($statusFilter) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($search) {
    $where[] = '(t.company_name LIKE ? OR t.subdomain LIKE ? OR u.email LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($planFilter) {
    $where[] = 't.subscription_plan_id = ?';
    $params[] = $planFilter;
}

$whereSQL = implode(' AND ', $where);

$tenants = $pdo->prepare("
    SELECT t.*,
           u.email       AS admin_email,
           u.username    AS admin_username,
           p.name        AS plan_name,
           (SELECT COUNT(*) FROM clients c WHERE c.tenant_id = t.id) AS client_count,
           (SELECT COUNT(*) FROM mikrotik_routers r WHERE r.tenant_id = t.id) AS router_count,
           (SELECT COALESCE(SUM(total_due),0) FROM platform_invoices pi WHERE pi.tenant_id = t.id AND pi.status = 'pending') AS outstanding
    FROM tenants t
    LEFT JOIN users u ON u.id = t.admin_user_id
    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
    WHERE $whereSQL
    ORDER BY t.created_at DESC
");
$tenants->execute($params);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

$plans = $pdo->query("SELECT id, name FROM platform_subscription_plans WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Single tenant detail view
$detailTenant = null;
if (isset($_GET['id'])) {
    $dt = $pdo->prepare("
        SELECT t.*, u.email AS admin_email, u.username AS admin_username,
               p.name AS plan_name, p.pppoe_fee_per_user, p.hotspot_commission_rate
        FROM tenants t
        LEFT JOIN users u ON u.id = t.admin_user_id
        LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
        WHERE t.id = ?
    ");
    $dt->execute([(int)$_GET['id']]);
    $detailTenant = $dt->fetch(PDO::FETCH_ASSOC);

    $invoicesDetail = $pdo->prepare("
        SELECT * FROM platform_invoices WHERE tenant_id = ? ORDER BY billing_period DESC LIMIT 12
    ");
    $invoicesDetail->execute([(int)$_GET['id']]);
    $invoicesDetail = $invoicesDetail->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tenants — FortuNett Super Admin</title>
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
.filters-bar{background:#fff;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.filters-bar input,.filters-bar select{padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;background:#f9fafb;}
.filters-bar input:focus,.filters-bar select:focus{outline:none;border-color:var(--sa-dark);}
.btn-filter{padding:8px 18px;background:var(--sa-dark);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
table{width:100%;border-collapse:collapse;}
th{padding:11px 16px;font-size:12px;font-weight:600;text-transform:uppercase;color:#94a3b8;background:#f8fafc;text-align:left;border-bottom:1px solid #f1f5f9;}
td{padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #f8fafc;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:#dcfce7;color:#166534;}
.badge-trial{background:#fef3c7;color:#92400e;}
.badge-suspended{background:#fee2e2;color:#991b1b;}
.badge-expired{background:#f1f5f9;color:#475569;}
.badge-paid{background:#dcfce7;color:#166534;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-overdue{background:#fee2e2;color:#991b1b;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
.btn-suspend{background:#fee2e2;color:#991b1b;}
.btn-activate{background:#dcfce7;color:#166534;}
.btn-view{background:#f1f5f9;color:#475569;}
.btn-danger{background:#ef4444;color:#fff;}
.empty-row td{text-align:center;color:#94a3b8;padding:36px;}
/* Detail panel */
.detail-panel{background:#fff;border-radius:12px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:24px;}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0;}
.detail-item label{display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.detail-item span{font-size:14px;color:#1e293b;font-weight:500;}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--sa-dark);text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;}
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
        <li><a href="tenants.php" class="active"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></div>
</div>

<div class="main">
    <div class="topbar">
        <h1><?= $detailTenant ? 'Tenant Detail' : 'Tenant Management' ?></h1>
        <span style="font-size:13px;color:#94a3b8;"><?= count($tenants) ?> tenants total</span>
    </div>
    <div class="content">

    <?php if ($detailTenant): ?>
        <a href="tenants.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to all tenants</a>
        <div class="detail-panel">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h2 style="font-size:20px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($detailTenant['company_name']) ?></h2>
                    <a href="https://<?= $detailTenant['subdomain'] ?>.fortunetttech.site" target="_blank" style="color:#2563eb;font-size:13px;"><?= $detailTenant['subdomain'] ?>.fortunetttech.site</a>
                </div>
                <div style="display:flex;gap:8px;">
                    <?php if ($detailTenant['status'] === 'suspended'): ?>
                    <button class="btn-sm btn-activate" onclick="changeTenantStatus(<?= $detailTenant['id'] ?>,'active')"><i class="fas fa-play"></i> Activate</button>
                    <?php else: ?>
                    <button class="btn-sm btn-suspend" onclick="changeTenantStatus(<?= $detailTenant['id'] ?>,'suspended')"><i class="fas fa-pause"></i> Suspend</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-grid">
                <div class="detail-item"><label>Status</label><span><span class="badge badge-<?= $detailTenant['status'] ?>"><?= ucfirst($detailTenant['status']) ?></span></span></div>
                <div class="detail-item"><label>Admin Email</label><span><?= htmlspecialchars($detailTenant['admin_email'] ?? 'N/A') ?></span></div>
                <div class="detail-item"><label>Plan</label><span><?= htmlspecialchars($detailTenant['plan_name'] ?? 'Starter') ?></span></div>
                <div class="detail-item"><label>Trial Ends</label><span><?= $detailTenant['trial_ends_at'] ? date('d M Y', strtotime($detailTenant['trial_ends_at'])) : 'N/A' ?></span></div>
                <div class="detail-item"><label>Subscription Ends</label><span><?= $detailTenant['subscription_ends_at'] ? date('d M Y', strtotime($detailTenant['subscription_ends_at'])) : 'N/A' ?></span></div>
                <div class="detail-item"><label>Registered</label><span><?= date('d M Y', strtotime($detailTenant['created_at'])) ?></span></div>
                <div class="detail-item"><label>PPPoE Fee / User</label><span>KSH <?= number_format($detailTenant['pppoe_fee_per_user'] ?? 25, 2) ?></span></div>
                <div class="detail-item"><label>Hotspot Commission</label><span><?= round(($detailTenant['hotspot_commission_rate'] ?? 0.03)*100, 2) ?>%</span></div>
                <?php if ($detailTenant['notes']): ?>
                <div class="detail-item" style="grid-column:1/-1;"><label>Admin Notes</label><span><?= nl2br(htmlspecialchars($detailTenant['notes'])) ?></span></div>
                <?php endif; ?>
            </div>
            <!-- Notes edit form -->
            <form onsubmit="saveNotes(event, <?= $detailTenant['id'] ?>)" style="margin-top:12px;">
                <label style="font-size:12px;font-weight:600;color:#94a3b8;display:block;margin-bottom:6px;">INTERNAL NOTES</label>
                <textarea id="notesField" rows="3" style="width:100%;border:1px solid #d1d5db;border-radius:7px;padding:9px;font-size:13px;resize:vertical;"><?= htmlspecialchars($detailTenant['notes'] ?? '') ?></textarea>
                <button type="submit" class="btn-sm btn-view" style="margin-top:8px;"><i class="fas fa-save"></i> Save Notes</button>
            </form>
        </div>

        <!-- Invoice history -->
        <div class="card">
            <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;"><h3 style="font-size:15px;font-weight:700;">Invoice History</h3></div>
            <table>
                <thead><tr><th>Invoice #</th><th>Period</th><th>PPPoE Users</th><th>Hotspot Rev.</th><th>Total Due</th><th>Status</th><th>Paid At</th></tr></thead>
                <tbody>
                <?php foreach ($invoicesDetail as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                    <td><?= date('M Y', strtotime($inv['billing_period'])) ?></td>
                    <td><?= $inv['pppoe_user_count'] ?></td>
                    <td>KSH <?= number_format($inv['hotspot_collections'], 0) ?></td>
                    <td><strong>KSH <?= number_format($inv['total_due'], 2) ?></strong></td>
                    <td><span class="badge badge-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                    <td><?= $inv['paid_at'] ? date('d M Y', strtotime($inv['paid_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($invoicesDetail)): ?>
                <tr class="empty-row"><td colspan="7">No invoices generated yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="q" placeholder="Company, subdomain, email..." value="<?= htmlspecialchars($search) ?>" style="width:240px;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                    <option value="trial" <?= $statusFilter==='trial'?'selected':'' ?>>Trial</option>
                    <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
                    <option value="expired" <?= $statusFilter==='expired'?'selected':'' ?>>Expired</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Plan</label>
                <select name="plan">
                    <option value="">All Plans</option>
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= $planFilter==$pl['id']?'selected':'' ?>><?= htmlspecialchars($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search me-1"></i> Filter</button>
            <a href="tenants.php" style="padding:8px 14px;font-size:13px;color:#64748b;text-decoration:none;">Clear</a>
        </form>

        <!-- Tenant Table -->
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Company / Subdomain</th>
                        <th>Plan</th>
                        <th>Clients</th>
                        <th>Routers</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tenants as $t): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($t['company_name']) ?></strong><br>
                        <a href="https://<?= $t['subdomain'] ?>.fortunetttech.site" target="_blank" style="color:#2563eb;font-size:12px;"><?= $t['subdomain'] ?>.fortunetttech.site</a>
                        <?php if ($t['admin_email']): ?><br><small style="color:#94a3b8;"><?= htmlspecialchars($t['admin_email']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['plan_name'] ?? 'Starter') ?></td>
                    <td><?= $t['client_count'] ?></td>
                    <td><?= $t['router_count'] ?></td>
                    <td><?= $t['outstanding'] > 0 ? '<strong style="color:#dc2626;">KSH '.number_format($t['outstanding'],0).'</strong>' : '<span style="color:#94a3b8;">—</span>' ?></td>
                    <td><span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
                    <td style="white-space:nowrap;"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                    <td style="white-space:nowrap;">
                        <a href="tenants.php?id=<?= $t['id'] ?>" class="btn-sm btn-view"><i class="fas fa-eye"></i></a>
                        <?php if ($t['status'] === 'suspended'): ?>
                        <button class="btn-sm btn-activate" onclick="changeTenantStatus(<?= $t['id'] ?>,'active')"><i class="fas fa-play"></i> Activate</button>
                        <?php else: ?>
                        <button class="btn-sm btn-suspend" onclick="changeTenantStatus(<?= $t['id'] ?>,'suspended')"><i class="fas fa-pause"></i> Suspend</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tenants)): ?>
                <tr class="empty-row"><td colspan="8">No tenants found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    </div>
</div>

<script>
function changeTenantStatus(tenantId, status) {
    const label = status === 'suspended' ? 'suspend' : 'activate';
    if (!confirm('Are you sure you want to ' + label + ' this tenant?')) return;
    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_status', tenant_id: tenantId, status: status })
    })
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); else alert(d.message || 'Operation failed'); });
}

function saveNotes(e, tenantId) {
    e.preventDefault();
    const notes = document.getElementById('notesField').value;
    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_notes', tenant_id: tenantId, notes: notes })
    })
    .then(r => r.json())
    .then(d => { alert(d.success ? 'Notes saved.' : (d.message || 'Failed')); });
}
</script>
</body>
</html>
