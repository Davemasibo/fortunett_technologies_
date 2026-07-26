<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

$successMsg = '';
$errorMsg   = '';

// ── Handle form POST (save plan) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $planId               = (int)($_POST['plan_id'] ?? 0);
    $name                 = trim($_POST['name'] ?? '');
    $pppoe_fee            = (float)($_POST['pppoe_fee_per_user'] ?? 0);
    $hotspot_rate         = (float)($_POST['hotspot_commission_rate'] ?? 0);
    $base_fee             = (float)($_POST['base_monthly_fee'] ?? 0);
    $max_clients          = ($_POST['max_clients'] ?? '') === '' ? null : (int)$_POST['max_clients'];
    $max_routers          = ($_POST['max_routers'] ?? '') === '' ? null : (int)$_POST['max_routers'];
    $trial_days           = (int)($_POST['trial_days'] ?? 30);

    if (!$planId || !$name) {
        $errorMsg = 'Plan ID and name are required.';
    } elseif ($pppoe_fee < 0 || $hotspot_rate < 0 || $base_fee < 0) {
        $errorMsg = 'Fees and rates cannot be negative.';
    } else {
        // Convert percentage to decimal fraction (e.g. 3 → 0.0300)
        $hotspot_rate_decimal = $hotspot_rate / 100;

        $stmt = $pdo->prepare("
            UPDATE platform_subscription_plans
            SET name = ?,
                pppoe_fee_per_user = ?,
                hotspot_commission_rate = ?,
                base_monthly_fee = ?,
                max_clients = ?,
                max_routers = ?,
                trial_days = ?
            WHERE id = ?
        ");
        $ok = $stmt->execute([
            $name,
            $pppoe_fee,
            $hotspot_rate_decimal,
            $base_fee,
            $max_clients,
            $max_routers,
            $trial_days,
            $planId,
        ]);

        if ($ok && $stmt->rowCount() >= 0) {
            $successMsg = 'Plan "' . htmlspecialchars($name) . '" saved successfully.';
        } else {
            $errorMsg = 'Failed to update plan. Please try again.';
        }
    }
}

// ── Handle toggle active/inactive ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $planId    = (int)($_POST['plan_id'] ?? 0);
    $newStatus = (int)($_POST['is_active'] ?? 0);

    if ($planId) {
        $pdo->prepare("UPDATE platform_subscription_plans SET is_active = ? WHERE id = ?")
            ->execute([$newStatus, $planId]);
        $successMsg = 'Plan status updated.';
    }
}

// ── Read plans with tenant counts ─────────────────────────────────────────────
$plans = $pdo->query("
    SELECT p.*,
           COUNT(t.id) AS tenant_count
    FROM platform_subscription_plans p
    LEFT JOIN tenants t ON t.subscription_plan_id = p.id
    GROUP BY p.id
    ORDER BY p.pppoe_fee_per_user DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Stats per plan for the summary row
$totalTenants = array_sum(array_column($plans, 'tenant_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscription Plans — FortuNett Super Admin</title>
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
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;}
.stat-card{background:var(--neu-s2);border-radius:14px;padding:18px 20px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);transition:transform .2s,box-shadow .2s;}
.stat-card:hover{transform:translateY(-3px);}
.stat-card .label{font-size:11px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.5px;}
.stat-card .value{font-size:26px;font-weight:700;color:#fff;margin:6px 0 3px;}
.stat-card .sub{font-size:12px;color:var(--neu-muted);}
.stat-card .icon-wrap{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;float:right;margin-top:-2px;background:rgba(255,255,255,.07);box-shadow:inset 2px 2px 6px rgba(0,0,0,.3);}
/* Card */
.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;}
.card-head{padding:16px 20px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.02);border-radius:14px 14px 0 0;}
.card-head h3{font-size:15px;font-weight:700;color:#fff;}
/* Table */
table{width:100%;border-collapse:collapse;}
th{padding:10px 16px;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--neu-muted);background:rgba(255,255,255,.04);text-align:left;border-bottom:1px solid var(--neu-border);}
td{padding:12px 16px;font-size:13px;color:var(--neu-text);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.035);}
table a{color:#3B6EA5;text-decoration:none;}
table a:hover{color:#93c5fd;}
/* Badges */
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-inactive{background:rgba(107,114,128,.18);color:#9ca3af;}
/* Buttons */
.btn-sm{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:none;transition:all .18s;}
.btn-edit{background:rgba(59,110,165,.18);color:#93c5fd;border:1px solid rgba(59,110,165,.25);}
.btn-edit:hover{background:rgba(59,110,165,.32);color:#fff;}
.btn-deactivate{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25);}
.btn-deactivate:hover{background:rgba(239,68,68,.28);color:#fff;}
.btn-activate{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.btn-activate:hover{background:rgba(16,185,129,.28);color:#fff;}
.btn-view{background:rgba(255,255,255,.07);color:var(--neu-muted);border:1px solid var(--neu-border);}
.btn-view:hover{background:rgba(255,255,255,.13);color:var(--neu-text);}
/* Alerts */
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;border-radius:9px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:14px;}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;border-radius:9px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:14px;}
/* Edit form panel */
.edit-panel{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;overflow:hidden;}
.edit-panel-head{padding:16px 20px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.1);}
.edit-panel-head h3{font-size:15px;font-weight:700;}
.edit-panel-body{padding:24px 20px;}
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.form-group input{width:100%;padding:9px 12px;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;background:#1a1a19;color:var(--neu-text);box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);}
.form-group input:focus{outline:none;border-color:#3B6EA5;}
.form-group .hint{font-size:11px;color:var(--neu-muted);margin-top:4px;}
.form-actions{margin-top:22px;padding-top:18px;border-top:1px solid var(--neu-border);display:flex;align-items:center;gap:12px;}
.btn-save{padding:9px 22px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:opacity .2s;}
.btn-save:hover{opacity:.88;}
.btn-cancel{padding:9px 18px;background:rgba(255,255,255,.07);color:var(--neu-muted);border:1px solid var(--neu-border);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-cancel:hover{background:rgba(255,255,255,.12);color:var(--neu-text);}
/* Plan name display chip */
.plan-chip{display:inline-flex;align-items:center;gap:7px;font-weight:700;font-size:13px;}
.plan-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr);}.form-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:700px){.form-grid{grid-template-columns:1fr;}}
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
        <li><a href="index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="collections.php"><i class="fas fa-hand-holding-dollar"></i><span>Collections</span></a></li>
        <li><a href="plans.php" class="active"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Super Admin') ?></strong></div>
</div>

<!-- Main -->
<div class="main">
    <div class="topbar">
        <h1>Subscription Plans</h1>
        <div class="user-info">
            <span><?= count($plans) ?> plans &bull; <?= $totalTenants ?> tenants</span>
            <div style="width:34px;height:34px;background:rgba(255,255,255,.12);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,.15);"><?= strtoupper(substr($_SESSION['username'] ?? 'S',0,1)) ?></div>
        </div>
    </div>

    <div class="content">

        <?php if ($successMsg): ?>
        <div class="alert-success"><i class="fas fa-check-circle"></i> <?= $successMsg ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Per-plan stat cards -->
        <div class="stats-grid">
            <?php
            $planColors = [
                'starter'    => ['bg' => '#eff6ff', 'color' => '#2563eb', 'dot' => '#2563eb'],
                'growth'     => ['bg' => '#f0fdf4', 'color' => '#16a34a', 'dot' => '#16a34a'],
                'enterprise' => ['bg' => '#faf5ff', 'color' => '#7c3aed', 'dot' => '#7c3aed'],
            ];
            foreach ($plans as $plan):
                $colors = $planColors[$plan['slug']] ?? ['bg' => '#f8fafc', 'color' => '#475569', 'dot' => '#94a3b8'];
            ?>
            <div class="stat-card">
                <div class="icon-wrap" style="background:<?= $colors['bg'] ?>;color:<?= $colors['color'] ?>;"><i class="fas fa-layer-group"></i></div>
                <div class="label"><?= htmlspecialchars($plan['name']) ?></div>
                <div class="value"><?= $plan['tenant_count'] ?></div>
                <div class="sub">
                    KSH <?= number_format($plan['pppoe_fee_per_user'], 0) ?>/user &bull;
                    <?= round($plan['hotspot_commission_rate'] * 100, 2) ?>% hotspot
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Pad to 4 if fewer than 4 plans -->
            <?php if (count($plans) < 4): ?>
            <div class="stat-card">
                <div class="icon-wrap" style="background:#f8fafc;color:#94a3b8;"><i class="fas fa-users"></i></div>
                <div class="label">All Tenants</div>
                <div class="value"><?= $totalTenants ?></div>
                <div class="sub">across all plans</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Edit form (hidden until "Edit" clicked) -->
        <div class="edit-panel" id="editPanel" style="display:none;">
            <div class="edit-panel-head">
                <h3><i class="fas fa-edit me-2"></i>Edit Plan: <span id="editPanelTitle"></span></h3>
                <button onclick="closeEditPanel()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="edit-panel-body">
                <form method="POST" action="plans.php" id="editForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="plan_id" id="fPlanId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Plan Name</label>
                            <input type="text" name="name" id="fName" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label>PPPoE Fee / User (KSH)</label>
                            <input type="number" name="pppoe_fee_per_user" id="fPppoeFee" step="0.01" min="0" required>
                            <div class="hint">Monthly charge per active PPPoE user</div>
                        </div>
                        <div class="form-group">
                            <label>Hotspot Commission (%)</label>
                            <input type="number" name="hotspot_commission_rate" id="fHotspotRate" step="0.01" min="0" max="100" required>
                            <div class="hint">Percentage of hotspot revenue collected</div>
                        </div>
                        <div class="form-group">
                            <label>Base Monthly Fee (KSH)</label>
                            <input type="number" name="base_monthly_fee" id="fBaseFee" step="0.01" min="0" required>
                            <div class="hint">Fixed platform fee added to every invoice</div>
                        </div>
                        <div class="form-group">
                            <label>Max Clients</label>
                            <input type="number" name="max_clients" id="fMaxClients" min="0" placeholder="0 = unlimited">
                            <div class="hint">Leave blank or 0 for unlimited</div>
                        </div>
                        <div class="form-group">
                            <label>Max Routers</label>
                            <input type="number" name="max_routers" id="fMaxRouters" min="0" placeholder="0 = unlimited">
                            <div class="hint">Leave blank or 0 for unlimited</div>
                        </div>
                        <div class="form-group">
                            <label>Trial Days</label>
                            <input type="number" name="trial_days" id="fTrialDays" min="0" max="365" required>
                            <div class="hint">Free trial period for new tenants on this plan</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
                        <button type="button" class="btn-cancel" onclick="closeEditPanel()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Plans table -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-layer-group me-2" style="color:var(--sa-dark);"></i>All Subscription Plans</h3>
                <span style="font-size:12px;color:#94a3b8;">Click "Edit" to modify pricing — changes apply to the next billing cycle</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Slug</th>
                        <th>PPPoE Fee / User</th>
                        <th>Hotspot Commission</th>
                        <th>Base Monthly Fee</th>
                        <th>Max Clients</th>
                        <th>Max Routers</th>
                        <th>Trial Days</th>
                        <th>Tenants</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($plans as $plan):
                    $colors = $planColors[$plan['slug']] ?? ['bg' => '#f8fafc', 'color' => '#475569', 'dot' => '#94a3b8'];
                ?>
                <tr id="planRow<?= $plan['id'] ?>">
                    <td>
                        <div class="plan-chip">
                            <div class="plan-dot" style="background:<?= $colors['dot'] ?>;"></div>
                            <?= htmlspecialchars($plan['name']) ?>
                        </div>
                    </td>
                    <td><code style="background:#f1f5f9;padding:2px 7px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($plan['slug']) ?></code></td>
                    <td><strong>KSH <?= number_format($plan['pppoe_fee_per_user'], 2) ?></strong></td>
                    <td><?= round($plan['hotspot_commission_rate'] * 100, 2) ?>%</td>
                    <td>KSH <?= number_format($plan['base_monthly_fee'], 2) ?></td>
                    <td><?= $plan['max_clients'] !== null ? number_format($plan['max_clients']) : '<span style="color:#94a3b8;">Unlimited</span>' ?></td>
                    <td><?= $plan['max_routers'] !== null ? number_format($plan['max_routers']) : '<span style="color:#94a3b8;">Unlimited</span>' ?></td>
                    <td><?= (int)$plan['trial_days'] ?> days</td>
                    <td>
                        <?php if ($plan['tenant_count'] > 0): ?>
                        <a href="tenants.php?plan=<?= $plan['id'] ?>" style="color:#2563eb;text-decoration:none;font-weight:600;"><?= $plan['tenant_count'] ?></a>
                        <?php else: ?>
                        <span style="color:#94a3b8;">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($plan['is_active']): ?>
                        <span class="badge badge-active">Active</span>
                        <?php else: ?>
                        <span class="badge badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn-sm btn-edit" onclick="openEditPanel(<?= htmlspecialchars(json_encode([
                            'id'                     => (int)$plan['id'],
                            'name'                   => $plan['name'],
                            'pppoe_fee_per_user'     => (float)$plan['pppoe_fee_per_user'],
                            'hotspot_commission_rate'=> round((float)$plan['hotspot_commission_rate'] * 100, 4),
                            'base_monthly_fee'       => (float)$plan['base_monthly_fee'],
                            'max_clients'            => $plan['max_clients'],
                            'max_routers'            => $plan['max_routers'],
                            'trial_days'             => (int)$plan['trial_days'],
                        ]), ENT_QUOTES) ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <?php if ($plan['is_active']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this plan? Existing tenants are not affected.');">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                            <input type="hidden" name="is_active" value="0">
                            <button type="submit" class="btn-sm btn-deactivate"><i class="fas fa-ban"></i> Deactivate</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate this plan?');">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                            <input type="hidden" name="is_active" value="1">
                            <button type="submit" class="btn-sm btn-activate"><i class="fas fa-check-circle"></i> Activate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($plans)): ?>
                <tr><td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;">No subscription plans found. Run the SaaS upgrade migration to seed default plans.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Usage note -->
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:14px 18px;font-size:13px;color:#92400e;display:flex;gap:10px;align-items:flex-start;">
            <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
            <span>
                <strong>Pricing changes take effect on the next billing cycle.</strong>
                Existing invoices already generated will not be recalculated.
                Max clients / routers of <strong>0</strong> or blank is treated as unlimited.
                The hotspot commission rate is stored internally as a decimal fraction (e.g. 3% is stored as 0.0300).
            </span>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script>
function openEditPanel(plan) {
    document.getElementById('editPanelTitle').textContent = plan.name;
    document.getElementById('fPlanId').value          = plan.id;
    document.getElementById('fName').value            = plan.name;
    document.getElementById('fPppoeFee').value        = plan.pppoe_fee_per_user;
    document.getElementById('fHotspotRate').value     = plan.hotspot_commission_rate;
    document.getElementById('fBaseFee').value         = plan.base_monthly_fee;
    document.getElementById('fMaxClients').value      = plan.max_clients !== null ? plan.max_clients : '';
    document.getElementById('fMaxRouters').value      = plan.max_routers !== null ? plan.max_routers : '';
    document.getElementById('fTrialDays').value       = plan.trial_days;

    var panel = document.getElementById('editPanel');
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeEditPanel() {
    document.getElementById('editPanel').style.display = 'none';
    document.getElementById('editForm').reset();
}
</script>
</body>
</html>
