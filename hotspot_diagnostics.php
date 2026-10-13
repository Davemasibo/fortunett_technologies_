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
$packageSt = $pdo->prepare("SELECT id,name,price,download_speed,upload_speed,validity_value,validity_unit,device_limit FROM packages WHERE tenant_id=? AND status='active' AND COALESCE(NULLIF(connection_type,''),'hotspot')='hotspot' ORDER BY price,id");
$packageSt->execute([$tenantId]);
$hotspotPackages = $packageSt->fetchAll(PDO::FETCH_ASSOC);

if (empty($_SESSION['hotspot_diagnostics_csrf'])) $_SESSION['hotspot_diagnostics_csrf'] = bin2hex(random_bytes(32));
$pageTitle = 'Hotspot Diagnostics';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/diagnostics.css?v=20261013">

<div class="main-content-wrapper" id="mainContent">
<div class="hd-container">

  <div class="hd-header">
    <div class="hd-title">
      <div class="hd-eyebrow">Network health / Hotspot</div><h1>Get hotspot customers connected</h1>
      <p class="hd-subtitle">Check the LAN, payment portal and paid access separately. Each failed check shows the next action.</p>
    </div>
    <button class="hd-btn primary" onclick="runAllChecks()">
      <i class="fas fa-arrows-rotate"></i> Run all checks
    </button>
  </div>

  <div class="hd-guide" aria-label="Diagnostic workflow">
    <strong>Check in this order</strong>
    <span><span class="step">1</span>Router &amp; customer IP</span>
    <span><span class="step">2</span>Portal &amp; packages</span>
    <span><span class="step">3</span>Payment &amp; access</span>
    <span><span class="step">4</span>Verify on Wi-Fi</span>
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

        <!-- Hotspot + PPPoE coexistence on the same bridge -->
        <details class="hd-sec hd-details"><summary>Hotspot + PPPoE compatibility</summary>
          <div class="hd-sec-title">
            <span>Hotspot + PPPoE on one bridge</span>
            <button class="hd-mini" id="coexfix-<?= (int)$r['id'] ?>" style="display:none;"
                    onclick="fixCoex(<?= (int)$r['id'] ?>, this)">Repair conflicts</button>
          </div>
          <div class="hd-verdict loading" id="coexverdict-<?= (int)$r['id'] ?>">
            <span class="v-icon"><span class="hd-spinner"></span></span>
            <div><h4>Checking coexistence…</h4><p>Pools, NAT, DHCP and interface bindings.</p></div>
          </div>
          <div id="coex-<?= (int)$r['id'] ?>"></div>
        </details>

        <!-- WireGuard management tunnel -->
        <details class="hd-sec hd-details"><summary>Management VPN details</summary>
          <div class="hd-sec-title"><span>WireGuard management tunnel</span></div>
          <div class="hd-verdict loading" id="vpnverdict-<?= (int)$r['id'] ?>">
            <span class="v-icon"><span class="hd-spinner"></span></span>
            <div><h4>Checking tunnel…</h4><p>Peer, handshake and API reachability.</p></div>
          </div>
          <div id="vpn-<?= (int)$r['id'] ?>"></div>
        </details>

        <div class="hd-result" id="repair-result-<?= (int)$r['id'] ?>" role="status" aria-live="polite"></div>
        <div class="hd-actions">
          <button class="hd-act deploy" id="portal-repair-<?= (int)$r['id'] ?>" hidden onclick="repairPortal(<?= (int)$r['id'] ?>, this)">Repair portal access</button>
          <button class="hd-act primary" onclick="recheck(<?= (int)$r['id'] ?>)">
            <i class="fas fa-stethoscope"></i> Verify Hotspot
          </button>
          <button class="hd-act primary" onclick="checkCoex(<?= (int)$r['id'] ?>); checkVpn(<?= (int)$r['id'] ?>)">
            <i class="fas fa-diagram-project"></i> Re-check Coexistence + VPN
          </button>
          <button class="hd-act deploy" onclick="redeploy(<?= (int)$r['id'] ?>, this)">
            <i class="fas fa-cloud-arrow-up"></i> Re-deploy Login Page
          </button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="hd-auto">
    <h3>Packages customers can buy</h3>
    <p class="sub">Active hotspot packages for this tenant. Prices, duration and device limits come from your package settings.</p>
    <?php if (!$hotspotPackages): ?>
      <div class="hd-alert">No active hotspot packages. <a href="packages.php">Create a hotspot package</a> before testing a purchase.</div>
    <?php else: ?>
      <div class="lr-grid">
      <?php foreach ($hotspotPackages as $package): ?>
        <div class="lr-cell">
          <div class="lc-val"><?= htmlspecialchars($package['name']) ?></div>
          <div class="lc-sub">KSh <?= number_format((float)$package['price'], 2) ?> &middot; <?= (int)$package['validity_value'] ?> <?= htmlspecialchars($package['validity_unit']) ?></div>
          <div class="lc-sub"><?= (int)$package['download_speed'] ?> Mbps down / <?= (int)$package['upload_speed'] ?> Mbps up &middot; <?= (int)$package['device_limit'] ?> device(s)</div>
          <?php if ((int)$package['download_speed'] <= 0 || (int)$package['validity_value'] <= 0): ?><span class="hd-badge fail">Review speed / duration</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="hd-actions"><a class="hd-btn" href="packages.php">Manage packages</a></div>
  </div>
  <!-- Payment → Access automation chain (tenant-wide, independent of any router) -->
  <div class="hd-auto">
    <h3><i class="fas fa-bolt me-2" style="color:var(--primary-color)"></i>Payment &amp; access records</h3>
    <p class="sub">Hotspot payment evidence for this tenant. Expired access is shown separately from current access problems; platform issues show who handles them.</p>
    <div class="hd-verdict loading" id="auto-verdict">
      <span class="v-icon"><span class="hd-spinner"></span></span>
      <div><h4>Checking the chain…</h4><p>How you collect, your routers and packages, and the last 7 days of live payments.</p></div>
    </div>
    <div class="hd-auto-groups" id="auto-groups"></div>
  </div>

  <div class="hd-lookup">
    <h3><i class="fas fa-user-check me-2" style="color:var(--primary-color)"></i>Client Router Lookup</h3>
    <p class="sub">Search a hotspot client to see their provisioning status and live session on the router.</p>
    <div class="hd-lookup-row">
      <select id="clientSelect" class="hd-select" aria-label="Hotspot client">
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

  <div class="hd-auto">
    <h3>Verify the customer experience on Wi-Fi</h3>
    <p class="sub">Connect a phone to a LAN access point in bridge mode. It should receive a hotspot IP, open this tenant's portal, show the packages above, and allow a paid customer to log in and browse. An access point that runs its own DHCP or NAT can hide customer devices from this router.</p>
    <div class="hd-guide">
      <span><span class="step">1</span>Get a hotspot IP</span>
      <span><span class="step">2</span>Open the portal</span>
      <span><span class="step">3</span>Buy or use paid login</span>
      <span><span class="step">4</span>Browse &amp; check live session</span>
    </div>
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
    document.getElementById('portal-repair-' + routerId).hidden = true;
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
    el.setAttribute('role', 'status');
    el.innerHTML = '<span class="v-icon" aria-hidden="true">' + icon + '</span><div><h4>' + escHtml(title) + '</h4><p>' + escHtml(msg || '') + '</p></div>';
}

function renderChecks(routerId, d) {
    const checks  = document.getElementById('checks-' + routerId);
    const verdict = document.getElementById('verdict-' + routerId);

    // ── Router unreachable ────────────────────────────────────────────────
    if (!d.success && !d.reachable) {
        setVerdict(verdict, 'fail', '<i class="fas fa-plug-circle-xmark"></i>',
            'Router unreachable', d.error || d.message || 'The API port is not reachable from the server.');
        checks.innerHTML = row('Router API', 'fail', d.error || 'Unreachable');
        return;
    }

    if (d.checks && d.verdict) {
        const v = d.verdict;
        setVerdict(verdict, v.level, VERDICT_ICON[v.level] || VERDICT_ICON.warn, v.title, v.message);
        checks.innerHTML = row('Router API', 'ok', 'Authenticated') + d.checks.map(c => rowWide(c.label, c.level, c.detail, c.action)).join('');
        checks.insertAdjacentHTML('beforeend', row('Hotspot accounts', 'skip', (d.router_users ?? '?') + ' on router; ' + d.db_active + ' active accounts in tenant')
            + row('Live hotspot sessions', 'skip', d.active_sessions === null ? 'Could not read sessions' : d.active_sessions + ' connected now'));
        const portal = d.checks.find(c => c.id === 'portal');
        document.getElementById('portal-repair-' + routerId).hidden = !portal || portal.level !== 'fail';
        if (d.checked_at) checks.insertAdjacentHTML('beforeend', '<div class="hd-timestamp">Checked ' + escHtml(new Date(d.checked_at).toLocaleString()) + '</div>');
    } else {
        setVerdict(verdict, 'warn', VERDICT_ICON.warn, 'Incomplete diagnostic response', 'Reload this page and run the checks again.');
    }

}

function row(label, badge, val) {
    return '<div class="hd-check"><span class="lbl">' + escHtml(label) + '</span><span class="val">' + escHtml(val) + '</span><span class="hd-badge ' + badge + '">' + statusLabel(badge) + '</span></div>';
}

// Wrapping variant — coexistence/VPN details are full sentences, not values.
function rowWide(label, level, detail, hint) {
    return '<div class="hd-check wrap"><span class="lbl">' + escHtml(label) + '</span>'
         + '<span class="val">' + escHtml(detail)
         + (hint ? '<span class="fixhint">' + escHtml(hint) + '</span>' : '')
         + '</span><span class="hd-badge ' + level + '">' + statusLabel(level) + '</span></div>';
}

const VERDICT_ICON = {
    ok:   '<i class="fas fa-circle-check"></i>',
    warn: '<i class="fas fa-triangle-exclamation"></i>',
    fail: '<i class="fas fa-circle-xmark"></i>'
};

// ─── Hotspot + PPPoE coexistence ─────────────────────────────────────────────
function checkCoex(routerId) {
    const body    = document.getElementById('coex-' + routerId);
    const verdict = document.getElementById('coexverdict-' + routerId);
    const fixBtn  = document.getElementById('coexfix-' + routerId);
    body.innerHTML = '';
    fixBtn.style.display = 'none';
    setVerdict(verdict, 'loading', '<span class="hd-spinner"></span>', 'Checking coexistence…', 'Pools, NAT, DHCP and interface bindings.');

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch(checkUrl('coexistence_check.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Check failed', d.error || 'Unknown error');
                return;
            }
            const v = d.verdict || { level: 'warn', title: '—', message: '' };
            setVerdict(verdict, v.level, VERDICT_ICON[v.level] || VERDICT_ICON.warn, v.title, v.message);

            if ((d.checks || []).some(c => c.level === 'fail')) body.closest('details').open = true;
            body.innerHTML = (d.checks || []).map(c =>
                rowWide(c.label, c.level, c.detail, c.fixable && c.level !== 'ok' ? 'Repairable from this page.' : null)
            ).join('');

            // Summary line — the two servers side by side, which is what the
            // question "can both run on this bridge" actually asks.
            if (d.bridge) {
                const hs = d.hotspot, pp = d.pppoe;
                body.insertAdjacentHTML('afterbegin',
                    '<div class="lr-grid" style="margin-bottom:12px">'
                    + '<div class="lr-cell"><div class="lc-label">Hotspot server</div><div class="lc-val">'
                    + (hs.present ? escHtml(hs.name || 'unnamed') : '—')
                    + '</div><div class="lc-sub">' + (hs.present ? 'on ' + escHtml(hs.interface || '?')
                        + (hs.cidr ? ' · ' + escHtml(hs.cidr) : '')
                        + (hs.sessions !== null ? ' · ' + hs.sessions + ' online' : '') : 'not configured') + '</div></div>'
                    + '<div class="lr-cell"><div class="lc-label">PPPoE server</div><div class="lc-val">'
                    + (pp.present ? escHtml(pp.service_name || 'unnamed') : '—')
                    + '</div><div class="lc-sub">' + (pp.present ? 'on ' + escHtml(pp.interface || '?')
                        + (pp.cidr ? ' · ' + escHtml(pp.cidr) : '')
                        + (pp.sessions !== null ? ' · ' + pp.sessions + ' online' : '') : 'not configured') + '</div></div>'
                    + '</div>');
            }

            if (d.fixes && d.fixes.length) {
                fixBtn.style.display = '';
                fixBtn.dataset.fixes = d.fixes.join(',');
                fixBtn.textContent = 'Repair ' + d.fixes.length + ' conflict' + (d.fixes.length === 1 ? '' : 's');
            }
        })
        .catch(e => setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Request failed', e.message));
}

function fixCoex(routerId, btn) {
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Repairing…';

    const fd = new FormData();
    fd.append('router_id', routerId);
    fd.append('fixes', btn.dataset.fixes || 'all');

    fetch(checkUrl('fix_coexistence.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.textContent = orig;
            const lines = [].concat(d.applied || [], d.skipped || [], d.failed || []);
            document.getElementById('repair-result-' + routerId).textContent = [d.message || d.error, ...lines].filter(Boolean).join(' ');
            if (typeof showToast === 'function') {
                showToast(d.message || d.error || (lines[0] || 'Done'), d.success ? 'success' : 'error');
            }
            setTimeout(() => checkCoex(routerId), 800);
        })
        .catch(e => {
            btn.disabled = false;
            btn.textContent = orig;
            if (typeof showToast === 'function') showToast('Repair failed: ' + e.message, 'error');
        });
}

// ─── WireGuard tunnel ────────────────────────────────────────────────────────
function checkVpn(routerId) {
    const body    = document.getElementById('vpn-' + routerId);
    const verdict = document.getElementById('vpnverdict-' + routerId);
    body.innerHTML = '';
    setVerdict(verdict, 'loading', '<span class="hd-spinner"></span>', 'Checking tunnel…', 'Peer, handshake and API reachability.');

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch('api/routers/wireguard_status.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Check failed', d.error || 'Unknown error');
                return;
            }
            const v = d.verdict || { level: 'warn', title: '—', message: '' };
            setVerdict(verdict, v.level, VERDICT_ICON[v.level] || VERDICT_ICON.warn, v.title, v.message);
            if ((d.checks || []).some(c => c.level === 'fail')) body.closest('details').open = true;
            body.innerHTML = (d.checks || []).map(c => rowWide(c.label, c.level, c.detail)).join('');
        })
        .catch(e => setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Request failed', e.message));
}

// ─── Payment → Access automation chain ───────────────────────────────────────
function checkAutomation() {
    const verdict = document.getElementById('auto-verdict');
    if (!verdict) return;
    const groups  = document.getElementById('auto-groups');
    groups.innerHTML = '';
    setVerdict(verdict, 'loading', '<span class="hd-spinner"></span>', 'Checking the chain…',
        'Gateway, callbacks, schema, routers and the last 7 days of live payments.');

    fetch('api/diagnostics/automation_chain.php', { method: 'POST', body: new URLSearchParams({service: 'hotspot'}) })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Check failed', d.error || 'Unknown error');
                return;
            }
            const v = d.verdict;
            setVerdict(verdict, v.level, VERDICT_ICON[v.level] || VERDICT_ICON.warn, v.title, v.message);

            let html = '';
            Object.keys(d.groups).forEach(key => {
                const g = d.groups[key];
                if (!g.checks.length) return;
                html += '<div class="hd-auto-group"><h5>' + escHtml(g.title) + '</h5>'
                     +  g.checks.map(c => rowWide(c.label, c.level, c.detail, c.action) + actionLink(c)).join('')
                     + '</div>';
            });
            groups.innerHTML = html;
        })
        .catch(e => setVerdict(verdict, 'fail', VERDICT_ICON.fail, 'Request failed', e.message));
}

function runAllChecks() {
    checkAutomation();
    <?php foreach ($routers as $r): ?>
    recheck(<?= (int)$r['id'] ?>);
    checkCoex(<?= (int)$r['id'] ?>);
    checkVpn(<?= (int)$r['id'] ?>);
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
            document.getElementById('repair-result-' + routerId).textContent = d.message || 'Deployment finished; run checks to verify.';
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

    const mkUser   = d.db_username ? escHtml(d.db_username) : '<em style="color:#fca5a5;">not set</em>';
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

const diagnosticsCsrf = <?= json_encode($_SESSION['hotspot_diagnostics_csrf']) ?>;
function statusLabel(level) { return ({ok:'PASS', warn:'REVIEW', fail:'FIX', skip:'INFO'})[level] || 'INFO'; }
function actionLink(c) {
    if (c.level === 'ok') return '';
    let url = '', text = '';
    if (/credentials|callback|collect|C2B/i.test(c.label)) { url='settings.php'; text='Open payment settings'; }
    else if (/package|speed/i.test(c.label)) { url='packages.php'; text='Review packages'; }
    else if (/customer|access records/i.test(c.label)) { url='clients.php'; text='Review clients'; }
    else if (/matched|transactions/i.test(c.label)) { url='payments.php'; text='Review payments'; }
    else if (/target/i.test(c.label)) { url='mikrotik.php'; text='Open routers'; }
    return url ? '<a class="hd-btn" href="' + url + '">' + text + '</a>' : '';
}
function repairPortal(routerId, btn) {
    const output = document.getElementById('repair-result-' + routerId);
    btn.disabled = true; output.className='hd-result'; output.textContent='Updating portal allow rules…';
    fetch(checkUrl('repair_hotspot_portal.php'), {method:'POST', body:new URLSearchParams({router_id:routerId, csrf_token:diagnosticsCsrf})})
        .then(r=>r.json()).then(d=>{
            output.className='hd-result' + (d.success ? '' : ' error');
            output.textContent=[d.message, ...(d.applied || [])].join(' ');
            recheck(routerId);
        }).catch(e=>{output.className='hd-result error'; output.textContent='Repair failed: ' + e.message;})
        .finally(()=>{btn.disabled=false;});
}
// Auto-run on load
document.addEventListener('DOMContentLoaded', runAllChecks);
</script>

<?php require_once 'includes/footer.php'; ?>
