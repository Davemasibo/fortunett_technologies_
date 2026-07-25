<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();
// Note: requireTenantActive($pdo, $tenant_id) is called by includes/header.php
// once the tenant is resolved. Do NOT call it here with no arguments — its
// signature requires ($pdo, $tenant_id), so an arg-less call throws
// ArgumentCountError and renders this page blank.

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$tenantId = (int)$stmt->fetchColumn();

// Routers for this tenant
$routerSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, api_port, status FROM mikrotik_routers WHERE tenant_id = ? ORDER BY id");
$routerSt->execute([$tenantId]);
$routers = $routerSt->fetchAll(PDO::FETCH_ASSOC);

// Hotspot clients for the search dropdown (name + username + phone, max 500)
$clientSt = $pdo->prepare("
    SELECT id, full_name, username, phone, account_number, status, mikrotik_username
    FROM clients WHERE tenant_id = ? AND COALESCE(NULLIF(connection_type,''),'hotspot') = 'hotspot'
    ORDER BY full_name ASC LIMIT 500
");
$clientSt->execute([$tenantId]);
$hotspotClients = $clientSt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Hotspot Diagnostics';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    :root { --neu-bg:#141414; --neu-surf:#1c1c1b; --neu-s2:#222221; --neu-border:rgba(255,255,255,.06); --neu-card:8px 8px 20px rgba(0,0,0,.45),-4px -4px 10px rgba(255,255,255,.03); }
    .main-content-wrapper { background: var(--neu-bg) !important; }

    /* Force native controls (select popups, scrollbars, autofill) to render dark
       instead of the OS default white — this is the usual source of stray white. */
    #mainContent, #mainContent select, #mainContent input, #mainContent textarea { color-scheme: dark; }
    #mainContent select option { background: #222221 !important; color: #e2e2e0 !important; }
    /* Belt-and-suspenders: neutralise any themed card class (from premium-theme.css /
       page-layout.css) that defaults to a white background if it ever leaks in here. */
    #mainContent .stat-card, #mainContent .content-card, #mainContent .metric-card,
    #mainContent .stat-card-premium, #mainContent .card { background: var(--neu-s2) !important; color: #e2e2e0 !important; border-color: var(--neu-border) !important; }

    .hd-container { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }
    .hd-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .hd-title h1 { font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px 0; }
    .hd-subtitle { font-size: 14px; color: rgba(255,255,255,.4); margin: 0; }
    .hd-btn { padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; border: 1px solid var(--neu-border); background: var(--neu-s2); color: rgba(255,255,255,.75); transition: all .18s; }
    .hd-btn:hover { background: rgba(255,255,255,.08); color: #fff; }
    .hd-btn.primary { background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color: #fff; border: none; }
    .hd-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.4); }

    .hd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px; }
    .hd-card { background: var(--neu-s2); border-radius: 10px; border: 1px solid var(--neu-border); box-shadow: var(--neu-card); overflow: hidden; }
    .hd-card-header { padding: 16px 20px; border-bottom: 1px solid var(--neu-border); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .hd-router-info { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .hd-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
    .hd-dot.online  { background: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
    .hd-dot.active  { background: #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
    .hd-dot.offline { background: #6B7280; box-shadow: 0 0 0 3px rgba(107,114,128,.2); }
    .hd-router-name { font-weight: 600; font-size: 15px; color: #e2e2e0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hd-router-meta { font-size: 12px; color: rgba(255,255,255,.4); margin-top: 2px; }

    .hd-card-body { padding: 18px 20px 20px; }

    /* Overall verdict banner */
    .hd-verdict { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 10px; border: 1px solid; margin-bottom: 16px; }
    .hd-verdict .v-icon { font-size: 24px; flex-shrink: 0; }
    .hd-verdict h4 { font-size: 14px; font-weight: 700; margin: 0 0 2px; }
    .hd-verdict p  { font-size: 12px; margin: 0; color: rgba(255,255,255,.5); line-height: 1.45; }
    .hd-verdict.ok      { background: rgba(52,211,153,.08);  border-color: rgba(52,211,153,.25); }
    .hd-verdict.ok      .v-icon, .hd-verdict.ok h4   { color: #6ee7b7; }
    .hd-verdict.warn    { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.25); }
    .hd-verdict.warn    .v-icon, .hd-verdict.warn h4 { color: #fbbf24; }
    .hd-verdict.fail    { background: rgba(239,68,68,.08);  border-color: rgba(239,68,68,.25); }
    .hd-verdict.fail    .v-icon, .hd-verdict.fail h4 { color: #fca5a5; }
    .hd-verdict.loading { background: rgba(255,255,255,.03); border-color: var(--neu-border); }
    .hd-verdict.loading h4 { color: rgba(255,255,255,.6); }

    /* Check rows */
    .hd-check { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--neu-border); }
    .hd-check:last-child { border-bottom: none; }
    .hd-check .lbl { flex: 1 1 auto; font-size: 13px; color: #d4d4d2; font-weight: 500; }
    .hd-check .val { font-size: 12px; color: rgba(255,255,255,.4); text-align: right; }

    .hd-badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; flex-shrink: 0; }
    .hd-badge.ok   { background: rgba(52,211,153,.15); color: #6ee7b7; }
    .hd-badge.fail { background: rgba(239,68,68,.15);  color: #fca5a5; }
    .hd-badge.warn { background: rgba(245,158,11,.15); color: #fbbf24; }
    .hd-badge.skip { background: rgba(255,255,255,.06); color: rgba(255,255,255,.4); }

    .hd-spinner { display: inline-block; width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.15); border-top-color: var(--primary-color,#3B6EA5); border-radius: 50%; animation: hd-spin .7s linear infinite; }
    @keyframes hd-spin { to { transform: rotate(360deg); } }

    /* Walled garden */
    .hd-wg { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--neu-border); }
    .hd-wg-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .09em; color: rgba(255,255,255,.35); margin-bottom: 8px; }
    .hd-wg ul { list-style: none; margin: 0; padding: 0; }
    .hd-wg li { font-family: ui-monospace, monospace; font-size: 11px; color: rgba(255,255,255,.5); padding: 2px 0; }
    .hd-wg .empty { color: #fca5a5; }

    .hd-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
    .hd-act { flex: 1; min-width: 120px; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .18s; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.06); color: rgba(255,255,255,.65); }
    .hd-act:hover { background: rgba(255,255,255,.12); color: #fff; }
    .hd-act.primary { background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color: #fff; border: none; }
    .hd-act.deploy  { background: rgba(245,158,11,.1); color: #fbbf24; border-color: rgba(245,158,11,.3); }
    .hd-act.deploy:hover { background: rgba(245,158,11,.2); color: #fcd34d; }

    /* Client lookup */
    .hd-lookup { background: var(--neu-s2); border: 1px solid var(--neu-border); box-shadow: var(--neu-card); border-radius: 10px; padding: 22px 24px; margin-top: 24px; }
    .hd-lookup h3 { font-size: 16px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px; }
    .hd-lookup .sub { font-size: 13px; color: rgba(255,255,255,.4); margin: 0 0 16px; }
    .hd-lookup-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .hd-select { flex: 1; min-width: 240px; padding: 9px 12px; border: 1px solid var(--neu-border); border-radius: 8px; background: var(--neu-surf); color: #d4d4d2; font-size: 14px; box-shadow: inset 3px 3px 7px rgba(0,0,0,.3); }
    .hd-select option { background: #222221; }
    .lr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
    .lr-cell { background: var(--neu-surf); border: 1px solid var(--neu-border); border-radius: 8px; padding: 11px 14px; }
    .lr-cell .lc-label { font-size: 10px; color: rgba(255,255,255,.35); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }
    .lr-cell .lc-val   { font-size: 13px; font-weight: 600; color: #e2e2e0; }
    .lr-cell .lc-sub   { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 2px; }

    .hd-alert { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25); color: #fbbf24; border-radius: 10px; padding: 16px 18px; font-size: 14px; }
    .hd-alert a { color: #fcd34d; font-weight: 600; }

    @media (max-width: 600px) { .hd-container { padding: 16px; } .lr-grid { grid-template-columns: 1fr; } }
</style>

<div class="main-content-wrapper" id="mainContent">
<div class="hd-container">

  <div class="hd-header">
    <div class="hd-title">
      <h1>Hotspot Diagnostics</h1>
      <p class="hd-subtitle">Verify each router's hotspot server, login page, and client provisioning in real time.</p>
    </div>
    <button class="hd-btn primary" onclick="runAllChecks()">
      <i class="fas fa-arrows-rotate"></i> Verify All
    </button>
  </div>

  <?php if (empty($routers)): ?>
    <div class="hd-alert">No routers configured yet. <a href="mikrotik.php">Add a router</a> to begin.</div>
  <?php else: ?>

  <div class="hd-grid" id="hd-grid">
  <?php foreach ($routers as $r): ?>
  <?php
      $ip        = $r['ip_address'];
      $displayIp = !empty($r['vpn_ip']) ? $r['vpn_ip'] . ' (VPN)' : $ip;
      $dotClass  = $r['status'] === 'online' ? 'online' : ($r['status'] === 'active' ? 'active' : 'offline');
  ?>
    <div class="hd-card" id="card-<?= (int)$r['id'] ?>">
      <div class="hd-card-header">
        <div class="hd-router-info">
          <span class="hd-dot <?= $dotClass ?>"></span>
          <div style="min-width:0;">
            <div class="hd-router-name"><?= htmlspecialchars($r['name'] ?: 'Router ' . $r['id']) ?></div>
            <div class="hd-router-meta"><?= htmlspecialchars($displayIp) ?> &middot; Port <?= (int)($r['api_port'] ?: 8728) ?></div>
          </div>
        </div>
      </div>

      <div class="hd-card-body">
        <div class="hd-verdict loading" id="verdict-<?= (int)$r['id'] ?>">
          <span class="v-icon"><span class="hd-spinner"></span></span>
          <div><h4>Checking hotspot…</h4><p>Contacting the router API.</p></div>
        </div>

        <div id="checks-<?= (int)$r['id'] ?>"></div>

        <div class="hd-wg" id="wg-<?= (int)$r['id'] ?>" style="display:none;">
          <div class="hd-wg-title">Walled Garden</div>
          <ul id="wg-list-<?= (int)$r['id'] ?>"></ul>
        </div>

        <div class="hd-actions">
          <button class="hd-act primary" onclick="recheck(<?= (int)$r['id'] ?>)">
            <i class="fas fa-stethoscope"></i> Verify Hotspot
          </button>
          <button class="hd-act deploy" onclick="redeploy(<?= (int)$r['id'] ?>, this)">
            <i class="fas fa-cloud-arrow-up"></i> Re-deploy Login Page
          </button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Client Router Lookup -->
  <div class="hd-lookup">
    <h3><i class="fas fa-user-check me-2" style="color:var(--primary-color)"></i>Client Router Lookup</h3>
    <p class="sub">Search a hotspot client to see their provisioning status and live session on the router.</p>
    <div class="hd-lookup-row">
      <select id="clientSelect" class="hd-select">
        <option value="">— Select a client —</option>
        <?php foreach ($hotspotClients as $c): ?>
        <option value="<?= (int)$c['id'] ?>">
          <?= htmlspecialchars($c['full_name'] ?: $c['username']) ?>
          (<?= htmlspecialchars($c['phone'] ?: $c['account_number']) ?>)<?= $c['status'] !== 'active' ? ' [' . htmlspecialchars($c['status']) . ']' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button class="hd-btn primary" onclick="lookupClient()"><i class="fas fa-magnifying-glass"></i> Check on Router</button>
    </div>
    <div id="lookupResult"></div>
  </div>

  <?php endif; ?>
</div>
</div>

<script>
// Endpoints are relative to the web root (this page lives at the root).
function checkUrl(p) { return 'api/mikrotik/' + p; }

// ─── Per-router hotspot verification ─────────────────────────────────────────
function recheck(routerId) {
    const checks  = document.getElementById('checks-' + routerId);
    const verdict = document.getElementById('verdict-' + routerId);
    checks.innerHTML = '';
    document.getElementById('wg-' + routerId).style.display = 'none';
    setVerdict(verdict, 'loading', '<span class="hd-spinner"></span>', 'Checking hotspot…', 'Contacting the router API.');

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch(checkUrl('hotspot_status.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => renderChecks(routerId, d))
        .catch(e => {
            setVerdict(verdict, 'fail', '<i class="fas fa-triangle-exclamation"></i>', 'Request failed', e.message);
        });
}

function setVerdict(el, cls, icon, title, msg) {
    el.className = 'hd-verdict ' + cls;
    el.innerHTML = '<span class="v-icon">' + icon + '</span><div><h4>' + title + '</h4><p>' + (msg || '') + '</p></div>';
}

function renderChecks(routerId, d) {
    const checks  = document.getElementById('checks-' + routerId);
    const verdict = document.getElementById('verdict-' + routerId);

    // ── Router unreachable ────────────────────────────────────────────────
    if (!d.success && !d.reachable) {
        setVerdict(verdict, 'fail', '<i class="fas fa-plug-circle-xmark"></i>',
            'Router unreachable', d.error || 'The API port is not reachable from the server.');
        checks.innerHTML = row('Router API', 'fail', d.error || 'Unreachable');
        return;
    }

    let html = '';
    html += row('Router API', d.api_ok ? 'ok' : 'fail', d.api_ok ? 'Connected' : (d.error || 'Auth failed'));

    // Derived flags for the hotspot verdict
    const serverRunning = !!d.hotspot_server;
    const onBridge      = d.bridge && d.hotspot_server && d.hotspot_server.indexOf(' on ' + d.bridge) !== -1;
    const hasPortal     = d.walled_garden && d.walled_garden.length > 0;

    if (d.api_ok) {
        // Bridge
        html += d.bridge
            ? row('Bridge Interface', 'ok', d.bridge)
            : row('Bridge Interface', 'fail', 'No bridge found');

        // Hotspot server — the core check
        if (d.hotspot_server) {
            html += row('Hotspot Server', onBridge ? 'ok' : 'warn', d.hotspot_server + (onBridge ? '' : ' — not on bridge'));
        } else if (d.hotspot_server === false) {
            html += row('Hotspot Server', 'fail', 'No server running' + (d.bridge ? ' — add one on ' + d.bridge : ''));
        } else {
            html += row('Hotspot Server', 'skip', 'N/A');
        }

        // Login page
        html += d.login_deployed
            ? row('Login Page', 'ok', 'Deployed' + (d.login_size ? ' (' + (Math.round(d.login_size / 1024 * 10) / 10) + ' KB)' : ''))
            : row('Login Page', 'fail', 'login.html not on flash');

        // Walled garden portal
        html += hasPortal
            ? row('Captive Portal', 'ok', 'Reachable (' + d.walled_garden.length + ' walled-garden entr' + (d.walled_garden.length === 1 ? 'y' : 'ies') + ')')
            : row('Captive Portal', 'warn', 'No walled-garden entries — clients may not reach the portal to pay');

        // User counts
        const routerU = d.router_users !== null ? d.router_users : '?';
        html += row('User Counts', (d.router_users !== null && d.router_users < d.db_active) ? 'warn' : 'ok',
            routerU + ' on router · ' + d.db_active + ' active / ' + d.db_total + ' total in DB');

        if (d.unprovisioned > 0) {
            html += row('Unprovisioned', 'warn', d.unprovisioned + ' active client(s) missing a router username');
        }
        if (d.active_sessions !== null) {
            html += row('Live Sessions', 'ok', d.active_sessions + ' online now');
        }

        // Walled garden list
        const wgSection = document.getElementById('wg-' + routerId);
        const wgList    = document.getElementById('wg-list-' + routerId);
        if (hasPortal) {
            wgList.innerHTML = d.walled_garden.map(e => '<li>' + escHtml(e) + '</li>').join('');
        } else {
            wgList.innerHTML = '<li class="empty">No walled garden entries configured</li>';
        }
        wgSection.style.display = 'block';
    }

    checks.innerHTML = html;

    // ── Overall verdict — is the hotspot actually working? ────────────────
    if (!d.api_ok) {
        setVerdict(verdict, 'fail', '<i class="fas fa-plug-circle-xmark"></i>',
            'Router API failed', d.error || 'Could not authenticate to the router.');
    } else if (d.hotspot_server === false) {
        setVerdict(verdict, 'fail', '<i class="fas fa-circle-xmark"></i>',
            'Hotspot NOT running', 'No hotspot server is active on this router. Re-run the provisioning script from the Routers page to create one on the bridge.');
    } else if (!d.login_deployed) {
        setVerdict(verdict, 'warn', '<i class="fas fa-triangle-exclamation"></i>',
            'Login page missing', 'The hotspot is running but login.html is not on the router. Use "Re-deploy Login Page" below.');
    } else if (!onBridge) {
        setVerdict(verdict, 'warn', '<i class="fas fa-triangle-exclamation"></i>',
            'Hotspot not on the bridge', 'The hotspot server is not bound to the bridge interface, so PPPoE + hotspot may conflict. Re-run provisioning.');
    } else if (!hasPortal) {
        setVerdict(verdict, 'warn', '<i class="fas fa-triangle-exclamation"></i>',
            'Portal unreachable', 'No walled-garden entry for your portal — unauthenticated clients cannot open the payment page.');
    } else {
        setVerdict(verdict, 'ok', '<i class="fas fa-circle-check"></i>',
            'Hotspot operational', 'Server running on ' + d.bridge + ', login page deployed, and the portal is reachable.');
    }
}

function row(label, badge, val) {
    return '<div class="hd-check"><span class="lbl">' + label + '</span><span class="val">' + escHtml(val) + '</span><span class="hd-badge ' + badge + '">' + badge.toUpperCase() + '</span></div>';
}

function runAllChecks() {
    <?php foreach ($routers as $r): ?>
    recheck(<?= (int)$r['id'] ?>);
    <?php endforeach; ?>
}

// ─── Re-deploy login page ────────────────────────────────────────────────────
function redeploy(routerId, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="hd-spinner"></span> Deploying…';

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch(checkUrl('deploy_hotspot_login.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (typeof showToast === 'function') showToast(d.message || (d.success ? 'Deployed successfully' : 'Deploy failed'), d.success ? 'success' : 'error');
            if (d.success) setTimeout(() => recheck(routerId), 1000);
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (typeof showToast === 'function') showToast('Request failed: ' + e.message, 'error');
        });
}

// ─── Client lookup ───────────────────────────────────────────────────────────
function lookupClient() {
    const sel    = document.getElementById('clientSelect');
    const result = document.getElementById('lookupResult');
    const cId    = sel.value;

    if (!cId) { result.innerHTML = '<p style="font-size:13px;color:rgba(255,255,255,.4);margin-top:12px;">Select a client first.</p>'; return; }

    result.innerHTML = '<div style="padding:14px 0;font-size:13px;color:rgba(255,255,255,.5);"><span class="hd-spinner"></span> Checking router…</div>';

    const fd = new FormData();
    fd.append('client_id', cId);

    fetch(checkUrl('verify_user.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => renderLookup(result, d))
        .catch(e => { result.innerHTML = '<p style="font-size:13px;color:#fca5a5;margin-top:12px;">Request failed: ' + escHtml(e.message) + '</p>'; });
}

function renderLookup(container, d) {
    if (!d.success) {
        container.innerHTML = '<p style="font-size:13px;color:#fca5a5;margin-top:12px;">' + escHtml(d.message || 'Error') + '</p>';
        return;
    }

    const mkUser   = d.db_username || '<em style="color:#fca5a5;">not set</em>';
    const dbStatus = pill(d.db_status, d.db_status === 'active' ? 'ok' : (d.db_status === 'suspended' ? 'fail' : 'warn'));
    const onRouter = d.router_ok ? '<span class="hd-badge ok">YES</span>' : '<span class="hd-badge fail">NO</span>';
    const onlineB  = d.is_online ? '<span class="hd-badge ok">ONLINE</span>' : '<span class="hd-badge skip">OFFLINE</span>';
    const profB    = d.router_ok
        ? (d.profile_match ? '<span class="hd-badge ok">' + escHtml(d.router_profile) + '</span>'
                           : '<span class="hd-badge warn">' + escHtml(d.router_profile) + ' (expected ' + escHtml(d.expected_profile) + ')</span>')
        : '—';

    let sessHtml = '';
    if (d.is_online && d.session) {
        const s = d.session;
        sessHtml = '<div class="lr-grid" style="margin-top:10px">'
            + '<div class="lr-cell"><div class="lc-label">Uptime</div><div class="lc-val">' + escHtml(s.uptime || '—') + '</div></div>'
            + '<div class="lr-cell"><div class="lc-label">IP Address</div><div class="lc-val">' + escHtml(s.address || '—') + '</div></div>'
            + '<div class="lr-cell"><div class="lc-label">Download</div><div class="lc-val">' + escHtml(String(s.rx_mb)) + ' MB</div></div>'
            + '<div class="lr-cell"><div class="lc-label">Upload</div><div class="lc-val">' + escHtml(String(s.tx_mb)) + ' MB</div></div>'
            + '</div>';
    }

    let errorNote = '';
    if (d.router_error) {
        errorNote = '<div class="hd-alert" style="margin-top:12px;font-size:12px;">' + escHtml(d.router_error) + '</div>';
    }

    container.innerHTML =
        '<div class="lr-grid">'
        + '<div class="lr-cell"><div class="lc-label">DB Status</div><div class="lc-val">' + dbStatus + '</div></div>'
        + '<div class="lr-cell"><div class="lc-label">MikroTik Username</div><div class="lc-val">' + mkUser + '</div><div class="lc-sub">Connection: ' + escHtml(d.db_connection || 'hotspot') + '</div></div>'
        + '<div class="lr-cell"><div class="lc-label">Exists on Router</div><div class="lc-val">' + onRouter + '</div></div>'
        + '<div class="lr-cell"><div class="lc-label">Profile</div><div class="lc-val">' + profB + '</div></div>'
        + '<div class="lr-cell"><div class="lc-label">Live Session</div><div class="lc-val">' + onlineB + '</div></div>'
        + '<div class="lr-cell"><div class="lc-label">Router IP</div><div class="lc-val">' + escHtml(d.router_ip || '—') + '</div></div>'
        + '</div>' + sessHtml + errorNote;
}

function pill(text, type) { return '<span class="hd-badge ' + type + '">' + escHtml(text) + '</span>'; }
function escHtml(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Auto-run on load
document.addEventListener('DOMContentLoaded', runAllChecks);
</script>

<?php require_once 'includes/footer.php'; ?>
