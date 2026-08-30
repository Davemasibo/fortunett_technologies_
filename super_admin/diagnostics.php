<?php
/**
 * Super Admin — Platform Diagnostics
 *
 * The shared half of the automation chain, checked once.
 *
 * hotspot_diagnostics.php answers the same question per tenant, which is the
 * wrong altitude for most of it: the shared paybill, the callback host, the
 * schema, the crons and the shared SMS/SMTP accounts are one installation. A
 * fault in any of them surfaced as the same failure on every tenant's page,
 * each phrased as something that tenant should go and fix — and none of them
 * could. Clear this page first; whatever is still red on a tenant's own page
 * afterwards is genuinely theirs.
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Diagnostics — FortuNett Super Admin</title>
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
.content{padding:28px;max-width:1100px;}
.btn{padding:9px 16px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--neu-border);background:rgba(255,255,255,.06);color:var(--neu-text);display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn:hover{background:rgba(255,255,255,.11);color:#fff;}

.verdict{display:flex;align-items:flex-start;gap:16px;padding:18px 20px;border-radius:14px;border:1px solid;margin-bottom:22px;}
.verdict .vi{font-size:26px;flex-shrink:0;line-height:1.2;}
.verdict h4{font-size:16px;font-weight:800;margin-bottom:4px;}
.verdict p{font-size:13px;color:var(--neu-muted);line-height:1.55;}
.verdict.ok{background:rgba(52,211,153,.07);border-color:rgba(52,211,153,.25);}
.verdict.ok .vi,.verdict.ok h4{color:#6ee7b7;}
.verdict.warn{background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.25);}
.verdict.warn .vi,.verdict.warn h4{color:#fbbf24;}
.verdict.fail{background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.25);}
.verdict.fail .vi,.verdict.fail h4{color:#fca5a5;}
.verdict.loading{background:rgba(255,255,255,.03);border-color:var(--neu-border);}
.verdict.loading h4{color:var(--neu-muted);}

.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035);overflow:hidden;margin-bottom:18px;}
.card-head{padding:14px 20px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
.card-head h3{font-size:14px;font-weight:700;color:#fff;}
.pill{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:3px 10px;border-radius:20px;}
.pill.ok{background:rgba(52,211,153,.15);color:#6ee7b7;}
.pill.warn{background:rgba(245,158,11,.15);color:#fcd34d;}
.pill.fail{background:rgba(239,68,68,.15);color:#fca5a5;}

.chk{padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:14px;align-items:flex-start;}
.chk:last-child{border-bottom:none;}
.chk .lbl{font-size:13.5px;font-weight:600;color:var(--neu-text);margin-bottom:3px;}
.chk .det{font-size:12.5px;color:var(--neu-muted);line-height:1.6;}
.chk .act{margin-top:7px;font-size:12.5px;color:#93c5fd;line-height:1.55;}
.chk .act i{margin-right:6px;}
.chk code{background:rgba(255,255,255,.07);padding:2px 7px;border-radius:5px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#cbd5e1;}
.spin{animation:sp 1s linear infinite;}@keyframes sp{to{transform:rotate(360deg);}}

.btn-fix{background:linear-gradient(135deg,#0f3460,#16213e);color:#fff;border-color:rgba(255,255,255,.16);font-weight:700;}
.btn-fix:hover{background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;}
.btn-fix[disabled]{opacity:.55;cursor:not-allowed;}
#repair{display:none;margin-bottom:22px;}
.rep-step{padding:11px 20px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:12px;align-items:flex-start;font-size:13px;}
.rep-step:last-child{border-bottom:none;}
.rep-step .t{font-weight:600;color:var(--neu-text);}
.rep-step .d{font-size:12.5px;color:var(--neu-muted);line-height:1.55;margin-top:2px;}
.rep-step .a{font-size:12.5px;color:#93c5fd;line-height:1.5;margin-top:5px;}
.rep-tag{font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:6px;flex-shrink:0;min-width:62px;text-align:center;}
.rep-tag.fixed{background:rgba(52,211,153,.16);color:#6ee7b7;}
.rep-tag.would{background:rgba(59,130,246,.16);color:#93c5fd;}
.rep-tag.ok{background:rgba(255,255,255,.07);color:var(--neu-muted);}
.rep-tag.manual{background:rgba(245,158,11,.16);color:#fcd34d;}
.rep-tag.error{background:rgba(239,68,68,.16);color:#fca5a5;}
.rep-note{padding:12px 20px;font-size:12.5px;color:var(--neu-muted);line-height:1.6;border-top:1px solid var(--neu-border);background:rgba(59,110,165,.06);}
.rep-note code{background:rgba(255,255,255,.08);padding:2px 7px;border-radius:5px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#cbd5e1;}
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
        <li><a href="collections.php"><i class="fas fa-hand-holding-dollar"></i><span>Collections</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="diagnostics.php" class="active"><i class="fas fa-heart-pulse"></i><span>Diagnostics</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Super Admin') ?></strong></div>
</div>

<div class="main">
    <div class="topbar">
        <h1>Platform Diagnostics</h1>
        <div style="display:flex;gap:10px;">
            <button class="btn" id="preview"><i class="fas fa-list-check"></i> Preview repairs</button>
            <button class="btn btn-fix" id="fixall"><i class="fas fa-wrench"></i> Fix what can be fixed</button>
            <button class="btn" id="reload"><i class="fas fa-rotate"></i> Re-run</button>
        </div>
    </div>
    <div class="content">

        <div class="verdict loading" id="verdict">
            <div class="vi"><i class="fas fa-circle-notch spin"></i></div>
            <div><h4>Checking the shared chain…</h4><p>Reading platform credentials, cron heartbeats, schema and tenant collection modes.</p></div>
        </div>

        <div class="card" id="repair">
            <div class="card-head"><h3 id="repair-title">Repair</h3><span class="pill ok" id="repair-pill">&nbsp;</span></div>
            <div id="repair-steps"></div>
            <div class="rep-note" id="repair-note"></div>
        </div>

        <div id="groups"></div>

        <p style="font-size:12.5px;color:var(--neu-muted);line-height:1.6;margin-top:6px;">
            Everything here is read-only and belongs to FortuNett, not to any tenant. A tenant's own
            <strong>Payment&nbsp;&rarr;&nbsp;Access</strong> panel only reports the links that are actually theirs, so it
            stays quiet about anything on this page.
        </p>
    </div>
</div>

<script>
const ICON = { ok:'fa-circle-check', warn:'fa-triangle-exclamation', fail:'fa-circle-xmark' };
const COL  = { ok:'#6ee7b7', warn:'#fcd34d', fail:'#fca5a5' };
const esc  = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

// A crontab line or a shell command is the whole point of the "action" text, so
// render it as code rather than losing it in a paragraph.
function renderAction(a) {
    if (!a) return '';
    const m = a.match(/^(.*?:\s*)(.+)$/s);
    if (m && /crontab|Run:|Add|php /.test(m[1])) {
        return `<div class="act"><i class="fas fa-wrench"></i>${esc(m[1])}<code>${esc(m[2].trim())}</code></div>`;
    }
    return `<div class="act"><i class="fas fa-wrench"></i>${esc(a)}</div>`;
}

function worst(checks) {
    if (checks.some(c => c.level === 'fail')) return 'fail';
    if (checks.some(c => c.level === 'warn')) return 'warn';
    return 'ok';
}

async function run() {
    const v = document.getElementById('verdict');
    const g = document.getElementById('groups');
    v.className = 'verdict loading';
    v.innerHTML = '<div class="vi"><i class="fas fa-circle-notch spin"></i></div><div><h4>Checking the shared chain…</h4><p>Reading platform credentials, cron heartbeats, schema and tenant collection modes.</p></div>';
    g.innerHTML = '';

    let d;
    try {
        const r = await fetch('../api/diagnostics/platform_chain.php', { method: 'POST', credentials: 'same-origin' });
        d = await r.json();
    } catch (e) {
        d = { success: false, error: e.message };
    }

    if (!d || !d.success) {
        v.className = 'verdict fail';
        v.innerHTML = `<div class="vi"><i class="fas fa-circle-xmark"></i></div><div><h4>Could not run the checks</h4><p>${esc(d && d.error || 'Unknown error')}</p></div>`;
        return;
    }

    v.className = 'verdict ' + d.verdict.level;
    v.innerHTML = `<div class="vi"><i class="fas ${ICON[d.verdict.level]}"></i></div>
        <div><h4>${esc(d.verdict.title)}</h4><p>${esc(d.verdict.message)}</p>
        <p style="margin-top:6px;">${d.counts.fail} failing &middot; ${d.counts.warn} warning &middot; ${d.counts.ok} passing</p></div>`;

    let html = '';
    for (const key of Object.keys(d.groups)) {
        const grp = d.groups[key];
        if (!grp.checks.length) continue;
        const lvl = worst(grp.checks);
        html += `<div class="card"><div class="card-head"><h3>${esc(grp.title)}</h3><span class="pill ${lvl}">${lvl}</span></div>`;
        for (const c of grp.checks) {
            html += `<div class="chk">
                <i class="fas ${ICON[c.level]}" style="color:${COL[c.level]};font-size:15px;margin-top:2px;"></i>
                <div style="flex:1;min-width:0;">
                    <div class="lbl">${esc(c.label)}</div>
                    <div class="det">${esc(c.detail)}</div>
                    ${renderAction(c.action)}
                </div></div>`;
        }
        html += '</div>';
    }
    g.innerHTML = html;
}

// ── One-click repair ────────────────────────────────────────────────────────
// Only the mechanical half: missing migrations, the retired SMS endpoint stored
// in a column, derivable settings, C2B registration. Credentials are reported,
// never guessed at, and crontab lines need a shell this page does not have --
// both come back as `manual` rows with exactly where to go.
const REP_ORDER = { error: 0, manual: 1, fixed: 2, would: 3, ok: 4 };

async function repair(apply) {
    const panel = document.getElementById('repair');
    const btnF  = document.getElementById('fixall');
    const btnP  = document.getElementById('preview');
    btnF.disabled = btnP.disabled = true;
    const active = apply ? btnF : btnP;
    const label  = active.innerHTML;
    active.innerHTML = '<i class="fas fa-circle-notch spin"></i> ' + (apply ? 'Repairing…' : 'Checking…');

    let d;
    try {
        const body = new URLSearchParams();
        if (apply) body.set('apply', '1');
        const r = await fetch('../api/super_admin/platform_repair.php', {
            method: 'POST', credentials: 'same-origin', body
        });
        d = await r.json();
    } catch (e) {
        d = { success: false, error: e.message };
    }

    btnF.disabled = btnP.disabled = false;
    active.innerHTML = label;
    panel.style.display = 'block';

    if (!d || !d.success) {
        document.getElementById('repair-title').textContent = 'Repair failed';
        document.getElementById('repair-pill').className = 'pill fail';
        document.getElementById('repair-pill').textContent = 'error';
        document.getElementById('repair-steps').innerHTML =
            `<div class="rep-step"><span class="rep-tag error">error</span><div>${esc(d && d.error || 'Unknown error')}</div></div>`;
        document.getElementById('repair-note').textContent = '';
        return;
    }

    const c = d.counts;
    const done = apply ? c.fixed : c.would;
    document.getElementById('repair-title').textContent =
        apply ? `Repaired ${c.fixed} item(s)` : `${c.would} item(s) can be repaired automatically`;
    const pill = document.getElementById('repair-pill');
    pill.className = 'pill ' + (c.error ? 'fail' : (c.manual ? 'warn' : 'ok'));
    pill.textContent = c.manual ? `${c.manual} need you` : (c.error ? `${c.error} errored` : 'clean');

    // Worst first: what still needs a human, before what is already handled.
    const steps = d.steps.slice().sort((a, b) => REP_ORDER[a.status] - REP_ORDER[b.status]);
    document.getElementById('repair-steps').innerHTML = steps.map(s => `
        <div class="rep-step">
            <span class="rep-tag ${s.status}">${s.status === 'would' ? 'can fix' : esc(s.status)}</span>
            <div style="flex:1;min-width:0;">
                <div class="t">${esc(s.title)}</div>
                <div class="d">${esc(s.detail)}</div>
                ${s.action ? `<div class="a"><i class="fas fa-arrow-right" style="margin-right:6px;"></i>${esc(s.action)}</div>` : ''}
            </div>
        </div>`).join('');

    document.getElementById('repair-note').innerHTML =
        (apply
            ? 'Re-run the checks above to confirm. '
            : 'Nothing was written. Press <strong>Fix what can be fixed</strong> to apply. ') +
        'Two things this button deliberately cannot do: install <strong>crontab</strong> lines (they need a shell ' +
        'this page does not have) and fill in <strong>credentials</strong> (a Daraja secret, a TalkSasa token, an ' +
        'SMTP password are values only you hold). For the crontab half, run on the server: ' +
        '<code>php tools/platform_repair.php --apply --install-cron</code>';

    if (apply && done > 0) run();
}

document.getElementById('reload').addEventListener('click', run);
document.getElementById('preview').addEventListener('click', () => repair(false));
document.getElementById('fixall').addEventListener('click', () => repair(true));
run();
</script>
</body>
</html>
