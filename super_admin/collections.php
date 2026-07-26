<?php
/**
 * Super Admin — Collections
 *
 * Answers the two questions that had no home anywhere in the platform:
 *
 *   1. What have tenants paid US?        (platform_payments, and what each
 *                                         payment settled)
 *   2. What are we holding FOR tenants?  (end-customer money collected through
 *                                         the shared paybill and not yet paid
 *                                         out — i.e. whom it is channelled to)
 *
 * The second figure is computed exactly as the tenant's own billing.php
 * computes it, so the two pages can never quote different numbers at each other.
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
require_once __DIR__ . '/../includes/platform_billing.php';

$tab   = $_GET['tab'] ?? 'in';
$days  = max(1, min(365, (int)($_GET['days'] ?? 30)));
$since = date('Y-m-d 00:00:00', strtotime("-$days days"));

// ── 1. Money in from tenants ─────────────────────────────────────────────────
$payments = [];
$inTotals = ['count' => 0, 'amount' => 0.0, 'allocated' => 0.0];
try {
    $st = $pdo->prepare("
        SELECT pp.*, t.company_name, t.subdomain, t.platform_billing_code,
               (SELECT GROUP_CONCAT(pi.invoice_number ORDER BY pi.billing_period SEPARATOR ', ')
                  FROM platform_payment_allocations a
                  JOIN platform_invoices pi ON pi.id = a.invoice_id
                 WHERE a.payment_id = pp.id) AS settled_invoices
        FROM platform_payments pp
        LEFT JOIN tenants t ON t.id = pp.tenant_id
        WHERE pp.paid_at >= ?
        ORDER BY pp.paid_at DESC
        LIMIT 300
    ");
    $st->execute([$since]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);

    $sum = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(allocated),0) al
        FROM platform_payments WHERE paid_at >= ?
    ");
    $sum->execute([$since]);
    $row = $sum->fetch(PDO::FETCH_ASSOC) ?: [];
    $inTotals = ['count' => (int)($row['c'] ?? 0), 'amount' => (float)($row['a'] ?? 0), 'allocated' => (float)($row['al'] ?? 0)];
} catch (Throwable $e) {
    $collectionsError = $e->getMessage();
}

// ── 2. Money held for tenants (float awaiting payout) ────────────────────────
$held = [];
$heldTotals = ['unreleased' => 0.0, 'released' => 0.0, 'commission' => 0.0];
try {
    $st = $pdo->query("
        SELECT t.id, t.company_name, t.subdomain,
               COALESCE(SUM(CASE WHEN p.released_at IS NULL THEN p.amount END), 0) AS unreleased,
               COUNT(CASE WHEN p.released_at IS NULL THEN 1 END)                   AS unreleased_count,
               COALESCE(SUM(CASE WHEN p.released_at IS NOT NULL THEN p.amount END), 0) AS released,
               MIN(CASE WHEN p.released_at IS NULL THEN p.payment_date END)        AS oldest_unreleased
        FROM tenants t
        JOIN payments p ON p.tenant_id = t.id
        WHERE p.collection_type = 'platform' AND p.status = 'completed'
        GROUP BY t.id, t.company_name, t.subdomain
        HAVING unreleased > 0 OR released > 0
        ORDER BY unreleased DESC
    ");
    $held = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($held as $h) {
        $heldTotals['unreleased'] += (float)$h['unreleased'];
        $heldTotals['released']   += (float)$h['released'];
    }
} catch (Throwable $e) {
    $heldError = $e->getMessage();
}

// Commission earned on that float — what FortuNett keeps before paying out
try {
    $c = $pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM platform_commissions")->fetchColumn();
    $heldTotals['commission'] = (float)$c;
} catch (Throwable $e) { /* table may not exist */ }

// ── 3. Unmatched paybill money — arrived but routed nowhere ──────────────────
$unmatched = [];
$logFile = __DIR__ . '/../logs/mpesa_c2b.log';
if (is_readable($logFile)) {
    foreach (array_slice(array_filter(explode("\n", (string)@file_get_contents($logFile))), -400) as $line) {
        if (stripos($line, 'UNROUTABLE') !== false || stripos($line, 'UNMATCHED') !== false) {
            $unmatched[] = $line;
        }
    }
    $unmatched = array_slice(array_reverse($unmatched), 0, 25);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Collections — FortuNett Super Admin</title>
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
.content{padding:28px;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:22px;}
.stat-card{background:var(--neu-s2);border-radius:14px;padding:18px 20px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035);}
.stat-card .label{font-size:11px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.06em;}
.stat-card .value{font-size:26px;font-weight:800;color:#fff;margin:6px 0 3px;}
.stat-card .sub{font-size:12px;color:var(--neu-muted);}
.tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.tab{padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;text-decoration:none;background:rgba(255,255,255,.05);border:1px solid var(--neu-border);color:var(--neu-muted);transition:all .18s;}
.tab:hover{color:var(--neu-text);background:rgba(255,255,255,.09);}
.tab.active{background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border-color:rgba(255,255,255,.16);}
.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035);overflow:hidden;margin-bottom:22px;}
.card-head{padding:16px 20px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.card-head h3{font-size:15px;font-weight:700;color:#fff;}
.card-head p{font-size:12.5px;color:var(--neu-muted);margin-top:3px;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;min-width:720px;}
th{padding:10px 16px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--neu-muted);background:rgba(255,255,255,.04);text-align:left;border-bottom:1px solid var(--neu-border);white-space:nowrap;}
td{padding:11px 16px;font-size:13.5px;color:var(--neu-text);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.03);}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;}
.muted{color:var(--neu-muted);}
.pos{color:#6ee7b7;font-weight:700;}
.warn{color:#fcd34d;font-weight:700;}
.code-chip{display:inline-block;padding:2px 8px;border-radius:6px;background:rgba(59,110,165,.2);color:#93c5fd;font-family:ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;}
.empty{padding:36px 20px;text-align:center;color:var(--neu-muted);font-size:13.5px;}
.note{padding:14px 20px;background:rgba(59,110,165,.09);border-top:1px solid var(--neu-border);font-size:12.5px;color:var(--neu-muted);line-height:1.6;}
.logline{font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#fca5a5;padding:6px 20px;border-bottom:1px solid rgba(255,255,255,.04);word-break:break-all;}
</style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <span class="badge-sa">SUPER ADMIN</span>
        <h2>FortuNett</h2>
        <p>Platform Administration</p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="collections.php" class="active"><i class="fas fa-hand-holding-dollar"></i><span>Collections</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Super Admin') ?></strong></div>
</div>

<div class="main">
    <div class="topbar"><h1>Collections</h1></div>
    <div class="content">

        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Received from tenants</div>
                <div class="value">KSH <?= number_format($inTotals['amount'], 0) ?></div>
                <div class="sub"><?= $inTotals['count'] ?> payment(s) in <?= $days ?> days</div>
            </div>
            <div class="stat-card">
                <div class="label">Applied to invoices</div>
                <div class="value">KSH <?= number_format($inTotals['allocated'], 0) ?></div>
                <div class="sub"><?= $inTotals['amount'] > $inTotals['allocated']
                        ? 'KSH ' . number_format($inTotals['amount'] - $inTotals['allocated'], 0) . ' sitting as credit'
                        : 'fully allocated' ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Held for ISPs</div>
                <div class="value warn">KSH <?= number_format($heldTotals['unreleased'], 0) ?></div>
                <div class="sub">not yet paid out</div>
            </div>
            <div class="stat-card">
                <div class="label">Commission earned</div>
                <div class="value pos">KSH <?= number_format($heldTotals['commission'], 0) ?></div>
                <div class="sub">on hotspot collections</div>
            </div>
        </div>

        <div class="tabs">
            <a class="tab <?= $tab === 'in'   ? 'active' : '' ?>" href="?tab=in&days=<?= $days ?>">Money in from tenants</a>
            <a class="tab <?= $tab === 'held' ? 'active' : '' ?>" href="?tab=held">Held for ISPs</a>
            <a class="tab <?= $tab === 'unmatched' ? 'active' : '' ?>" href="?tab=unmatched">Unmatched<?= $unmatched ? ' (' . count($unmatched) . ')' : '' ?></a>
        </div>

        <?php if ($tab === 'in'): ?>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>Tenant payments to FortuNett</h3>
                    <p>Money paid into the platform paybill against a tenant billing code, and the invoices it settled.</p>
                </div>
                <div>
                    <?php foreach ([7, 30, 90, 365] as $d): ?>
                    <a class="tab <?= $days === $d ? 'active' : '' ?>" style="padding:5px 12px;font-size:12px;" href="?tab=in&days=<?= $d ?>"><?= $d ?>d</a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>When</th><th>Tenant</th><th>Ref typed</th><th>Amount</th>
                    <th>Applied</th><th>Settled</th><th>M-Pesa</th><th>Via</th>
                </tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="muted"><?= date('d M H:i', strtotime($p['paid_at'])) ?></td>
                    <td>
                        <?php if ($p['tenant_id']): ?>
                        <a href="tenants.php?id=<?= (int)$p['tenant_id'] ?>" style="color:#93c5fd;text-decoration:none;">
                            <?= htmlspecialchars($p['company_name'] ?? ('Tenant #' . $p['tenant_id'])) ?>
                        </a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td><span class="code-chip"><?= htmlspecialchars($p['bill_ref'] ?? '—') ?></span></td>
                    <td><strong>KSH <?= number_format((float)$p['amount'], 2) ?></strong></td>
                    <td class="<?= (float)$p['allocated'] < (float)$p['amount'] ? 'warn' : 'pos' ?>">
                        KSH <?= number_format((float)$p['allocated'], 2) ?>
                    </td>
                    <td class="muted" style="font-size:12px;"><?= htmlspecialchars($p['settled_invoices'] ?? '—') ?></td>
                    <td class="mono muted"><?= htmlspecialchars($p['mpesa_receipt'] ?? '—') ?></td>
                    <td class="muted"><?= htmlspecialchars(strtoupper($p['source'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                <tr><td colspan="8" class="empty">
                    No tenant payments recorded in the last <?= $days ?> days.<br>
                    Tenants pay the platform paybill using their billing code (shown on their billing page and on the tenant detail view).
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php elseif ($tab === 'held'): ?>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>Money collected on behalf of ISPs</h3>
                    <p>End-customer payments that came through the shared paybill. This is float — it belongs to the ISP, less commission.</p>
                </div>
            </div>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Tenant</th><th>Awaiting payout</th><th>Payments</th><th>Oldest</th><th>Already released</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($held as $h): ?>
                <tr>
                    <td><a href="tenants.php?id=<?= (int)$h['id'] ?>" style="color:#93c5fd;text-decoration:none;"><?= htmlspecialchars($h['company_name']) ?></a>
                        <div class="muted" style="font-size:11.5px;"><?= htmlspecialchars($h['subdomain']) ?></div></td>
                    <td class="<?= (float)$h['unreleased'] > 0 ? 'warn' : 'muted' ?>">KSH <?= number_format((float)$h['unreleased'], 2) ?></td>
                    <td class="muted"><?= (int)$h['unreleased_count'] ?></td>
                    <td class="muted"><?= $h['oldest_unreleased'] ? date('d M Y', strtotime($h['oldest_unreleased'])) : '—' ?></td>
                    <td class="pos">KSH <?= number_format((float)$h['released'], 2) ?></td>
                    <td><a href="tenants.php?id=<?= (int)$h['id'] ?>" class="tab" style="padding:4px 12px;font-size:12px;">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$held): ?>
                <tr><td colspan="6" class="empty">No platform-collected payments recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <div class="note">
                <strong>Releasing</strong> marks the money as settled with the ISP — it does not move funds.
                <code>cron/auto_release_settlements.php</code> releases anything older than 48 hours; the actual
                transfer is made by you, manually or by B2C.
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-head">
                <div>
                    <h3>Unmatched paybill payments</h3>
                    <p>Money that arrived but could not be tied to a tenant or a customer. Each of these is someone who paid and got nothing.</p>
                </div>
            </div>
            <?php if ($unmatched): ?>
                <?php foreach ($unmatched as $line): ?>
                <div class="logline"><?= htmlspecialchars($line) ?></div>
                <?php endforeach; ?>
                <div class="note">
                    Usually the payer's phone is not on any client record, or they typed a reference nobody recognises.
                    Fix the client's phone number, or hand them their account number, then reconcile the payment manually.
                </div>
            <?php else: ?>
                <div class="empty">Nothing unmatched — every payment found an owner.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
