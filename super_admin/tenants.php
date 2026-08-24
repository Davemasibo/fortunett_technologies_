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

/**
 * The moment a tenant's access ends. Mirrors tenantExpiryTimestamp() in
 * includes/auth.php — a deployment still on the DATE columns returns
 * 'YYYY-MM-DD', which meant "through the end of that day", so reading it as
 * midnight would show a tenant as expired while the portal still lets them in.
 */
function saExpiryTs($value): ?int
{
    if (empty($value)) return null;
    $value = trim((string)$value);
    if (strncmp($value, '0000-00-00', 10) === 0) return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return strlen($value) <= 10 ? strtotime('+1 day -1 second', $ts) : $ts;
}

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
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="css/dark.css?v=2" rel="stylesheet">
<link href="css/shell.css?v=1" rel="stylesheet">
<script src="js/shell.js?v=1" defer></script>
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
.filters-bar{background:var(--neu-s2);border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;border:1px solid var(--neu-border);box-shadow:8px 8px 20px rgba(0,0,0,.4),-4px -4px 10px rgba(255,255,255,.025);}
.filters-bar input,.filters-bar select{padding:8px 12px;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;background:#1a1a19;color:var(--neu-text);box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);}
.filters-bar input:focus,.filters-bar select:focus{outline:none;border-color:#3B6EA5;}
.btn-filter{padding:8px 18px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border:1px solid rgba(255,255,255,.12);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s;}
.btn-filter:hover{opacity:.88;}
.card{background:var(--neu-s2);border-radius:14px;box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);}
table{width:100%;border-collapse:collapse;}
th{padding:11px 16px;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--neu-muted);background:rgba(255,255,255,.04);text-align:left;border-bottom:1px solid var(--neu-border);}
td{padding:12px 16px;font-size:13px;color:var(--neu-text);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.035);}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-trial{background:rgba(245,158,11,.18);color:#fcd34d;}
.badge-suspended{background:rgba(239,68,68,.18);color:#fca5a5;}
.badge-expired{background:rgba(107,114,128,.18);color:#9ca3af;}
.badge-paid{background:rgba(16,185,129,.18);color:#6ee7b7;}
.badge-pending{background:rgba(245,158,11,.18);color:#fcd34d;}
.badge-overdue{background:rgba(239,68,68,.18);color:#fca5a5;}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .18s;}
.btn-suspend{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25);}
.btn-suspend:hover{background:rgba(239,68,68,.28);color:#fff;}
.btn-activate{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.btn-activate:hover{background:rgba(16,185,129,.28);color:#fff;}
.btn-mark-paid{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all .18s;
  background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.btn-mark-paid:hover:not(:disabled){background:rgba(16,185,129,.28);color:#fff;}
.btn-mark-paid:disabled{opacity:.5;cursor:not-allowed;}
.btn-view{background:rgba(255,255,255,.07);color:var(--neu-muted);border:1px solid var(--neu-border);}
.btn-view:hover{background:rgba(255,255,255,.13);color:var(--neu-text);}
.btn-danger{background:linear-gradient(135deg,#b91c1c,#ef4444);color:#fff;border:none;}
/* Subscription extend controls */
.btn-extend{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;
  background:rgba(59,110,165,.18);color:#93c5fd;border:1px solid rgba(59,110,165,.35);transition:all .18s;}
.btn-extend:hover:not(:disabled){background:rgba(59,110,165,.34);color:#fff;transform:translateY(-1px);}
.btn-extend:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.btn-extend i{font-size:11px;}
/* In a table row it sits beside .btn-sm buttons — match their metrics. */
.btn-extend.row-extend{padding:4px 10px;}
#extAmount:focus,#extUnit:focus,#extUntil:focus{outline:none;border-color:#3B6EA5;}
/* Per-row extend popover. Lives on <body> rather than inside the row: the card
   is overflow-x:auto on narrow screens, which would clip an in-table dropdown. */
.sa-extend-pop{position:fixed;z-index:200;min-width:190px;padding:6px;display:none;
  background:#1c1c1b;border:1px solid rgba(255,255,255,.1);border-radius:11px;
  box-shadow:0 18px 44px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.04);}
.sa-extend-pop.open{display:block;}
.sa-extend-pop .pop-head{padding:7px 10px 8px;font-size:11px;font-weight:700;letter-spacing:.4px;
  text-transform:uppercase;color:var(--neu-muted);border-bottom:1px solid var(--neu-border);margin-bottom:4px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:230px;}
.sa-extend-pop button{display:block;width:100%;text-align:left;padding:7px 10px;border:none;background:none;
  color:var(--neu-text);font-size:13px;border-radius:7px;cursor:pointer;transition:background .14s;}
.sa-extend-pop button:hover:not(:disabled){background:rgba(59,110,165,.28);color:#fff;}
.sa-extend-pop button:disabled{opacity:.5;cursor:not-allowed;}
.sa-extend-pop .pop-sep{height:1px;background:var(--neu-border);margin:5px 0;}
.sa-extend-pop .pop-group{padding:5px 10px 2px;font-size:10px;font-weight:700;letter-spacing:.5px;
  text-transform:uppercase;color:#6b7280;}
.empty-row td{text-align:center;color:var(--neu-muted);padding:36px;}
/* Detail panel */
.detail-panel{background:var(--neu-s2);border-radius:14px;padding:28px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0;}
.detail-item label{display:block;font-size:11px;font-weight:600;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.detail-item span{font-size:14px;color:var(--neu-text);font-weight:500;}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#93c5fd;text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;}
.back-link:hover{color:#fff;}
table a{color:#3B6EA5;text-decoration:none;}
table a:hover{color:#93c5fd;}
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
        <li><a href="tenants.php" class="active"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="collections.php"><i class="fas fa-hand-holding-dollar"></i><span>Collections</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Super Admin') ?></strong></div>
</div>

<div class="main">
    <div class="topbar">
        <h1><?= $detailTenant ? 'Tenant Detail' : 'Tenant Management' ?></h1>
        <div class="user-info">
            <span style="font-size:13px;"><?= count($tenants) ?> tenants total</span>
            <div class="avatar" style="width:34px;height:34px;background:rgba(255,255,255,.12);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,.15);"><?= strtoupper(substr($_SESSION['username'] ?? 'S',0,1)) ?></div>
        </div>
    </div>
    <div class="content">

    <?php if ($detailTenant): ?>
        <a href="tenants.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to all tenants</a>
        <div class="detail-panel">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h2 style="font-size:20px;font-weight:700;color:var(--neu-text);"><?= htmlspecialchars($detailTenant['company_name']) ?></h2>
                    <a href="https://<?= $detailTenant['subdomain'] ?>.fortunetttech.site" target="_blank" style="color:#2563eb;font-size:13px;"><?= $detailTenant['subdomain'] ?>.fortunetttech.site</a>
                </div>
                <div style="display:flex;gap:8px;">
                    <?php
                        // Outstanding invoices decide whether Activate can hold. While any
                        // exist, check_suspensions.php re-suspends on its next run, so the
                        // button is hidden rather than offered and silently undone.
                        $owedSt = $pdo->prepare("SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
                        $owedSt->execute([(int)$detailTenant['id']]);
                        $owedCount = (int)$owedSt->fetchColumn();
                    ?>
                    <?php if ($detailTenant['status'] === 'suspended'): ?>
                        <?php if ($owedCount === 0): ?>
                        <button class="btn-sm btn-activate" onclick="changeTenantStatus(<?= $detailTenant['id'] ?>,'active')"><i class="fas fa-play"></i> Activate</button>
                        <?php else: ?>
                        <span style="font-size:12px;color:var(--neu-muted);">
                            Settle the outstanding invoices below to reactivate
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                    <button class="btn-sm btn-suspend" onclick="changeTenantStatus(<?= $detailTenant['id'] ?>,'suspended')"><i class="fas fa-pause"></i> Suspend</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-grid">
                <div class="detail-item"><label>Status</label><span><span class="badge badge-<?= $detailTenant['status'] ?>"><?= ucfirst($detailTenant['status']) ?></span></span></div>
                <div class="detail-item"><label>Admin Email</label><span><?= htmlspecialchars($detailTenant['admin_email'] ?? 'N/A') ?></span></div>
                <div class="detail-item"><label>Plan</label><span><?= htmlspecialchars($detailTenant['plan_name'] ?? 'Starter') ?></span></div>
                <div class="detail-item"><label>Trial Ends</label><span><?= !empty($detailTenant['trial_ends_at']) ? date('d M Y, H:i', strtotime($detailTenant['trial_ends_at'])) : 'N/A' ?></span></div>
                <div class="detail-item"><label>Subscription Ends</label><span><?= !empty($detailTenant['subscription_ends_at']) ? date('d M Y, H:i', strtotime($detailTenant['subscription_ends_at'])) : 'N/A' ?></span></div>
                <div class="detail-item"><label>Registered</label><span><?= date('d M Y', strtotime($detailTenant['created_at'])) ?></span></div>
                <div class="detail-item"><label>PPPoE Fee / User</label><span>KSH <?= number_format($detailTenant['pppoe_fee_per_user'] ?? 25, 2) ?></span></div>
                <div class="detail-item"><label>Hotspot Commission</label><span><?= round(($detailTenant['hotspot_commission_rate'] ?? 0.03)*100, 2) ?>%</span></div>
                <?php if ($detailTenant['notes']): ?>
                <div class="detail-item" style="grid-column:1/-1;"><label>Admin Notes</label><span><?= nl2br(htmlspecialchars($detailTenant['notes'])) ?></span></div>
                <?php endif; ?>
            </div>
            <!-- Notes edit form -->
            <form onsubmit="saveNotes(event, <?= $detailTenant['id'] ?>)" style="margin-top:12px;">
                <label style="font-size:12px;font-weight:600;color:var(--neu-muted);display:block;margin-bottom:6px;">INTERNAL NOTES</label>
                <textarea id="notesField" rows="3" style="width:100%;border:1px solid var(--neu-border);border-radius:7px;padding:9px;font-size:13px;resize:vertical;"><?= htmlspecialchars($detailTenant['notes'] ?? '') ?></textarea>
                <button type="submit" class="btn-sm btn-view" style="margin-top:8px;"><i class="fas fa-save"></i> Save Notes</button>
            </form>
        </div>

        <?php
        // ── Subscription access ───────────────────────────────────────────────
        // A tenant reading "active" can still be bounced to
        // billing.php?subscription_expired=1, because requireTenantActive() also
        // checks the date. This panel is the only place that date can be moved.
        $accessField   = $detailTenant['status'] === 'trial' ? 'trial_ends_at' : 'subscription_ends_at';
        $accessLabel   = $accessField === 'trial_ends_at' ? 'Trial' : 'Subscription';
        $accessTs      = saExpiryTs($detailTenant[$accessField] ?? null);
        $accessExpired = $accessTs !== null && $accessTs < time();

        if (!function_exists('saRemainingHuman')) {
            function saRemainingHuman(int $secs): string {
                $secs = abs($secs);
                if ($secs < 3600)   return max(1, intdiv($secs, 60)) . ' min';
                if ($secs < 86400)  return intdiv($secs, 3600) . 'h ' . intdiv($secs % 3600, 60) . 'm';
                if ($secs < 2592000) return intdiv($secs, 86400) . ' day' . (intdiv($secs, 86400) === 1 ? '' : 's');
                return round($secs / 2592000, 1) . ' months';
            }
        }
        ?>
        <div class="card" style="margin-bottom:18px;<?= $accessExpired ? 'border:1px solid rgba(245,158,11,.35);' : '' ?>">
            <div style="padding:16px 20px 8px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;">
                        <i class="fas fa-hourglass-half" style="color:#93c5fd;"></i> <?= $accessLabel ?> access
                    </h3>
                    <p style="font-size:13px;color:var(--neu-muted);margin:0;">
                        <?php if ($accessTs === null): ?>
                            No end date set — this tenant is never date-blocked.
                        <?php elseif ($accessExpired): ?>
                            <span style="color:#fcd34d;font-weight:600;">
                                <?= $accessField === 'trial_ends_at' ? 'Trial ended' : 'Subscription ended' ?>
                                <?= saRemainingHuman(time() - $accessTs) ?> ago
                                (<?= date('D d M Y, H:i', $accessTs) ?>).
                            </span>
                            Their team is being redirected to <code style="color:#93c5fd;">billing.php?<?= $accessField === 'trial_ends_at' ? 'trial_expired' : 'subscription_expired' ?>=1</code> on every page.
                        <?php else: ?>
                            Runs until <strong style="color:var(--neu-text);"><?= date('D d M Y, H:i', $accessTs) ?></strong>
                            — <?= saRemainingHuman($accessTs - time()) ?> left.
                        <?php endif; ?>
                    </p>
                </div>
                <label style="font-size:12px;color:var(--neu-muted);display:flex;align-items:center;gap:6px;flex-shrink:0;cursor:pointer;">
                    <input type="checkbox" id="extFromNow"> Start from now (discard time left)
                </label>
            </div>

            <div style="padding:4px 20px 18px;">
                <?php
                $groups = [
                    'Hours'  => ['hours',  [1, 3, 6, 12, 24]],
                    'Days'   => ['days',   [1, 3, 7, 10, 14]],
                    'Months' => ['months', [1, 3, 6]],
                ];
                foreach ($groups as $gLabel => [$gUnit, $gAmounts]): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                    <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--neu-muted);width:56px;flex-shrink:0;"><?= $gLabel ?></span>
                    <?php foreach ($gAmounts as $n): ?>
                    <button class="btn-extend" onclick="extendSub('<?= $gUnit ?>', <?= $n ?>, this)">
                        +<?= $n ?><?= $gUnit === 'hours' ? 'h' : ($gUnit === 'days' ? ($n === 1 ? ' day' : ' days') : ($n === 1 ? ' month' : ' months')) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <div style="display:flex;align-items:center;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--neu-border);flex-wrap:wrap;">
                    <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--neu-muted);width:56px;flex-shrink:0;">Custom</span>
                    <input type="number" id="extAmount" min="1" value="30" style="width:80px;padding:6px 10px;border:1px solid var(--neu-border);border-radius:7px;background:#1a1a19;color:var(--neu-text);font-size:13px;">
                    <select id="extUnit" style="padding:6px 10px;border:1px solid var(--neu-border);border-radius:7px;background:#1a1a19;color:var(--neu-text);font-size:13px;">
                        <option value="hours">hours</option>
                        <option value="days" selected>days</option>
                        <option value="months">months</option>
                    </select>
                    <button class="btn-extend" onclick="extendSubCustom(this)"><i class="fas fa-plus"></i> Extend</button>

                    <span style="width:18px;"></span>

                    <span style="font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--neu-muted);flex-shrink:0;">Exact</span>
                    <input type="datetime-local" id="extUntil" style="padding:6px 10px;border:1px solid var(--neu-border);border-radius:7px;background:#1a1a19;color:var(--neu-text);font-size:13px;color-scheme:dark;">
                    <button class="btn-extend" onclick="extendSubUntil(this)"><i class="fas fa-calendar-check"></i> Set</button>
                </div>

                <p style="font-size:12px;color:var(--neu-muted);margin:14px 0 0;">
                    Extensions stack onto whatever time is left unless <em>Start from now</em> is ticked.
                    <?php if ($detailTenant['status'] === 'trial'): ?>
                        This tenant is on <strong>trial</strong>, so the trial end date moves.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php
        require_once __DIR__ . '/../includes/platform_billing.php';
        $billingCode = platformBillingCode($pdo, (int)$_GET['id']);
        $platformPaybill = '';
        try {
            $platformPaybill = (string)$pdo->query("SELECT shortcode FROM platform_mpesa_config LIMIT 1")->fetchColumn();
        } catch (Throwable $_e) {}
        ?>
        <div class="card" style="margin-bottom:18px;">
            <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h3 style="font-size:15px;font-weight:700;margin-bottom:4px;">How this tenant pays you</h3>
                    <p style="font-size:13px;color:var(--neu-muted);margin:0;">
                        Paybill <strong style="color:var(--neu-text);"><?= htmlspecialchars($platformPaybill ?: 'not configured') ?></strong>,
                        account <strong style="color:#93c5fd;font-family:ui-monospace,Menlo,monospace;"><?= htmlspecialchars($billingCode) ?></strong>.
                        Payments settle their oldest unpaid invoice first and reactivate them automatically.
                    </p>
                </div>
                <a href="collections.php?tab=in" class="btn-sm btn-view" style="text-decoration:none;flex-shrink:0;">
                    <i class="fas fa-hand-holding-dollar"></i> View collections
                </a>
            </div>
        </div>

        <?php
        // Anything unpaid is what check_suspensions.php acts on. Show it plainly:
        // "Activate" alone never sticks while these exist, which is the single
        // most confusing behaviour in this panel.
        $openInv = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_due),0) t FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
        $openInv->execute([(int)$_GET['id']]);
        $open = $openInv->fetch(PDO::FETCH_ASSOC);
        ?>
        <?php if ((int)$open['c'] > 0): ?>
        <div class="card" style="border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.08);margin-bottom:18px;">
            <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h3 style="font-size:15px;font-weight:700;color:#fca5a5;margin-bottom:4px;">
                        <i class="fas fa-triangle-exclamation"></i>
                        <?= (int)$open['c'] ?> outstanding invoice<?= (int)$open['c'] === 1 ? '' : 's' ?> — KSH <?= number_format((float)$open['t'], 2) ?>
                    </h3>
                    <p style="font-size:13px;color:#cbd5e1;margin:0;">
                        While these are unpaid, the daily suspension check will keep re-suspending this tenant.
                        Activating or extending days will not hold — settle or waive the invoices instead.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                    <button class="btn-mark-paid" onclick="settleAll('paid', this)">Mark All Paid</button>
                    <button class="btn-mark-paid" style="background:rgba(148,163,184,.15);color:#cbd5e1;border-color:rgba(148,163,184,.3);"
                            onclick="settleAll('waived', this)">Waive All</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Invoice history -->
        <div class="card">
            <div style="padding:16px 20px;border-bottom:1px solid var(--neu-border);"><h3 style="font-size:15px;font-weight:700;">Invoice History</h3></div>
            <table>
                <thead><tr><th>Invoice #</th><th>Period</th><th>PPPoE Users</th><th>Hotspot Rev.</th><th>Total Due</th><th>Status</th><th>Paid At</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoicesDetail as $inv): ?>
                <tr id="inv-row-<?= (int)$inv['id'] ?>">
                    <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                    <td><?= date('M Y', strtotime($inv['billing_period'])) ?></td>
                    <td><?= $inv['pppoe_user_count'] ?></td>
                    <td>KSH <?= number_format($inv['hotspot_collections'], 0) ?></td>
                    <td><strong>KSH <?= number_format($inv['total_due'], 2) ?></strong></td>
                    <td><span class="badge badge-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                    <td><?= $inv['paid_at'] ? date('d M Y', strtotime($inv['paid_at'])) : '—' ?></td>
                    <td style="text-align:right;">
                        <?php if ($inv['status'] !== 'paid'): ?>
                        <button class="btn-mark-paid"
                                onclick="markInvoicePaid(<?= (int)$inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>', '<?= number_format($inv['total_due'], 2) ?>', this)">
                            Mark Paid
                        </button>
                        <?php else: ?>
                        <span style="color:var(--neu-muted);font-size:12px;">
                            <?= htmlspecialchars($inv['transaction_ref'] ?? '') ?>
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($invoicesDetail)): ?>
                <tr class="empty-row"><td colspan="8">No invoices generated yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--neu-muted);display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="q" placeholder="Company, subdomain, email..." value="<?= htmlspecialchars($search) ?>" style="width:240px;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--neu-muted);display:block;margin-bottom:4px;">Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
                    <option value="trial" <?= $statusFilter==='trial'?'selected':'' ?>>Trial</option>
                    <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
                    <option value="expired" <?= $statusFilter==='expired'?'selected':'' ?>>Expired</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--neu-muted);display:block;margin-bottom:4px;">Plan</label>
                <select name="plan">
                    <option value="">All Plans</option>
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= $planFilter==$pl['id']?'selected':'' ?>><?= htmlspecialchars($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search me-1"></i> Filter</button>
            <a href="tenants.php" style="padding:8px 14px;font-size:13px;color:var(--neu-muted);text-decoration:none;">Clear</a>
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
                        <?php if ($t['admin_email']): ?><br><small style="color:var(--neu-muted);"><?= htmlspecialchars($t['admin_email']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['plan_name'] ?? 'Starter') ?></td>
                    <td><?= $t['client_count'] ?></td>
                    <td><?= $t['router_count'] ?></td>
                    <td><?= $t['outstanding'] > 0 ? '<strong style="color:#fca5a5;">KSH '.number_format($t['outstanding'],0).'</strong>' : '<span style="color:var(--neu-muted);">—</span>' ?></td>
                    <td>
                        <span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                        <?php
                        // "Active" is not the whole story — requireTenantActive()
                        // also walls a tenant off once the date passes, which is
                        // the state that reads as working here but locks them out.
                        $lclField = $t['status'] === 'trial' ? 'trial_ends_at' : 'subscription_ends_at';
                        $lclTs    = saExpiryTs($t[$lclField] ?? null);
                        $lclGated = in_array($t['status'], ['active', 'trial'], true) && $lclTs !== null;
                        if ($lclGated && $lclTs < time()):
                        ?>
                        <br><small style="color:#fcd34d;font-size:11px;" title="Locked out by date — extend to restore access">
                            <i class="fas fa-hourglass-end"></i>
                            <?= $t['status'] === 'trial' ? 'trial ended' : 'subscription ended' ?>
                        </small>
                        <?php elseif ($lclGated): ?>
                        <br><small style="color:var(--neu-muted);font-size:11px;" title="<?= date('D d M Y, H:i', $lclTs) ?>">
                            until <?= date('d M, H:i', $lclTs) ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                    <td style="white-space:nowrap;">
                        <a href="tenants.php?id=<?= $t['id'] ?>" class="btn-sm btn-view" title="Open tenant"><i class="fas fa-eye"></i></a>
                        <button class="btn-sm btn-extend row-extend"
                                title="Grant more time without opening this tenant"
                                data-tenant-id="<?= (int)$t['id'] ?>"
                                data-tenant-name="<?= htmlspecialchars($t['company_name'], ENT_QUOTES) ?>"
                                data-clock="<?= $t['status'] === 'trial' ? 'trial' : 'subscription' ?>">
                            <i class="fas fa-hourglass-half"></i> Extend
                        </button>
                        <?php if ($t['status'] === 'suspended'): ?>
                            <?php if ((float)($t['outstanding'] ?? 0) > 0): ?>
                            <!-- Activating would be reverted by the suspension cron; send
                                 the operator to the invoices that actually control this. -->
                            <a href="tenants.php?id=<?= (int)$t['id'] ?>" class="btn-sm btn-view"
                               style="text-decoration:none;" title="Outstanding invoices must be settled first">
                                <i class="fas fa-file-invoice-dollar"></i> Settle
                            </a>
                            <?php else: ?>
                            <button class="btn-sm btn-activate" onclick="changeTenantStatus(<?= $t['id'] ?>,'active')"><i class="fas fa-play"></i> Activate</button>
                            <?php endif; ?>
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

/* Move the tenant's access expiry. This is what unsticks a tenant whose status
   reads "active" but who is being redirected to billing.php?subscription_expired=1
   — requireTenantActive() checks the date as well as the status. */
function sendExtend(payload, btn, tenantId) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '…';

    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({
            action: 'extend_subscription',
            /* The list view passes an id per row; the detail view has only one. */
            tenant_id: tenantId || <?= isset($_GET['id']) ? (int)$_GET['id'] : 0 ?>,
            from_now: document.getElementById('extFromNow')?.checked || false
        }, payload))
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message + (d.warning ? '\n\n⚠ ' + d.warning : ''));
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = original;
            alert(d.message || 'Could not extend the subscription.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = original;
        alert('Network error.');
    });
}

function extendSub(unit, amount, btn) {
    sendExtend({ unit: unit, amount: amount }, btn);
}

function extendSubCustom(btn) {
    const amount = parseInt(document.getElementById('extAmount').value, 10);
    const unit   = document.getElementById('extUnit').value;
    if (!amount || amount < 1) { alert('Enter how many ' + unit + ' to add.'); return; }
    sendExtend({ unit: unit, amount: amount }, btn);
}

/* ── Per-row extend, list view ────────────────────────────────────────────────
   Same API as the detail panel, reached without opening the tenant. The menu is
   built once and re-anchored to whichever row was clicked. */
(function () {
    var rows = document.querySelectorAll('.row-extend');
    if (!rows.length) return;   // detail view — nothing to wire

    var PRESETS = [
        ['Hours',  [[1, 'hours'], [3, 'hours'], [6, 'hours'], [12, 'hours'], [24, 'hours']]],
        ['Days',   [[1, 'days'], [3, 'days'], [7, 'days'], [10, 'days'], [14, 'days']]],
        ['Months', [[1, 'months'], [3, 'months'], [6, 'months']]]
    ];

    var pop = document.createElement('div');
    pop.className = 'sa-extend-pop';
    var head = document.createElement('div');
    head.className = 'pop-head';
    pop.appendChild(head);

    var current = null;   // the .row-extend button that opened the menu

    function label(n, unit) {
        return '+' + n + ' ' + (n === 1 ? unit.replace(/s$/, '') : unit);
    }

    function apply(payload) {
        if (!current) return;
        var btn = current;
        close();
        sendExtend(payload, btn, parseInt(btn.dataset.tenantId, 10));
    }

    PRESETS.forEach(function (group) {
        var title = document.createElement('div');
        title.className = 'pop-group';
        title.textContent = group[0];
        pop.appendChild(title);
        group[1].forEach(function (p) {
            var item = document.createElement('button');
            item.type = 'button';
            item.textContent = label(p[0], p[1]);
            item.addEventListener('click', function () { apply({ unit: p[1], amount: p[0] }); });
            pop.appendChild(item);
        });
    });

    var sep = document.createElement('div');
    sep.className = 'pop-sep';
    pop.appendChild(sep);

    var custom = document.createElement('button');
    custom.type = 'button';
    custom.textContent = 'Custom…';
    custom.addEventListener('click', function () {
        var raw = prompt('How much time to add?\n\nExamples: 2 hours, 45 days, 3 months', '30 days');
        if (raw === null) return;
        var m = /^\s*(\d+)\s*(hours?|hrs?|h|days?|d|months?|mo)\s*$/i.exec(raw);
        if (!m) { alert('Could not read "' + raw + '".\n\nUse a number and a unit, e.g. "6 hours", "10 days", "2 months".'); return; }
        var n = parseInt(m[1], 10);
        var u = m[2].toLowerCase();
        var unit = (u[0] === 'h') ? 'hours' : (u[0] === 'd' ? 'days' : 'months');
        apply({ unit: unit, amount: n });
    });
    pop.appendChild(custom);

    document.body.appendChild(pop);

    function close() {
        pop.classList.remove('open');
        current = null;
    }

    function open(btn) {
        current = btn;
        head.textContent = btn.dataset.tenantName + ' · ' + btn.dataset.clock;
        pop.classList.add('open');   // must be visible to measure

        var r = btn.getBoundingClientRect();
        var w = pop.offsetWidth, h = pop.offsetHeight;
        /* Keep it on screen: flip above when the row is near the bottom, and
           pull left when the Actions column is hard against the right edge. */
        var top  = (r.bottom + h + 8 > window.innerHeight) ? r.top - h - 6 : r.bottom + 6;
        var left = Math.min(r.left, window.innerWidth - w - 12);
        pop.style.top  = Math.max(8, top) + 'px';
        pop.style.left = Math.max(8, left) + 'px';
    }

    Array.prototype.forEach.call(rows, function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (current === btn) { close(); return; }
            open(btn);
        });
    });

    document.addEventListener('click', function (e) {
        if (!pop.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') close();
    });
    window.addEventListener('resize', close);
    /* position:fixed does not follow the page, so a scroll would leave the menu
       floating away from its row. */
    window.addEventListener('scroll', close, true);
})();

function extendSubUntil(btn) {
    const until = document.getElementById('extUntil').value;
    if (!until) { alert('Pick the exact date and time access should end.'); return; }
    /* datetime-local has no timezone; the server reads it in its own zone, which
       is what the operator sees everywhere else on this page. */
    sendExtend({ until: until.replace('T', ' ') }, btn);
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

/* Clear everything this tenant owes in one go. 'paid' records real money that
   arrived off-platform; 'waived' writes it off. Either way the suspension cron
   stops re-suspending them, which Activate on its own never achieved. */
function settleAll(mode, btn) {
    const tenantId = <?= isset($_GET['id']) ? (int)$_GET['id'] : 0 ?>;
    const verb = mode === 'waived' ? 'WAIVE (write off)' : 'mark PAID';

    let ref = '';
    if (mode === 'paid') {
        ref = prompt('Mark ALL outstanding invoices for this tenant as PAID.\n\n' +
                     'Enter the payment reference (M-Pesa code, bank ref, or note).\n' +
                     'Leave blank to auto-generate one.', '');
        if (ref === null) return;
        ref = ref.trim();
    } else if (!confirm('Write off ALL outstanding invoices for this tenant?\n\n' +
                        'This records them as waived, not paid. Revenue reports will reflect that.')) {
        return;
    }

    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Working…';

    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'settle_all_invoices',
            tenant_id: tenantId,
            mode: mode,
            transaction_ref: ref
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { alert(d.message); location.reload(); }
        else { btn.disabled = false; btn.textContent = original; alert(d.message || 'Failed to ' + verb); }
    })
    .catch(() => { btn.disabled = false; btn.textContent = original; alert('Network error.'); });
}

/* Mark a platform invoice as manually settled (bank transfer, cash, off-platform
   M-Pesa). The reference is optional but strongly encouraged — it is the only
   audit trail linking the invoice to the money that actually arrived. */
function markInvoicePaid(invoiceId, invoiceNumber, amount, btn) {
    const ref = prompt(
        'Mark ' + invoiceNumber + ' (KSH ' + amount + ') as PAID.\n\n' +
        'Enter the payment reference (M-Pesa code, bank ref, or note).\n' +
        'Leave blank to auto-generate one.',
        ''
    );
    if (ref === null) return;   // cancelled

    const tenantId = <?= isset($_GET['id']) ? (int)$_GET['id'] : 0 ?>;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('../api/super_admin/tenants.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'mark_invoice_paid',
            tenant_id: tenantId,
            invoice_id: invoiceId,
            transaction_ref: ref.trim()
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { location.reload(); }
        else {
            btn.disabled = false;
            btn.textContent = original;
            alert(d.message || 'Could not mark the invoice as paid.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = original;
        alert('Network error.');
    });
}
</script>
</body>
</html>
