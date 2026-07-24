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
?>

<style>
.diag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; margin-top: 24px; }
.diag-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 22px 24px; }
.diag-card h5 { font-size: 15px; font-weight: 700; color: #111; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
.diag-card .router-ip { font-size: 12px; color: #6B7280; margin-bottom: 16px; }
.check-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #F3F4F6; font-size: 13px; color: #374151; }
.check-row:last-child { border-bottom: none; }
.check-row .label { flex: 1; }
.check-row .val { font-size: 12px; color: #6B7280; }
.badge-ok   { background: #D1FAE5; color: #065F46; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-fail { background: #FEE2E2; color: #991B1B; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-warn { background: #FEF3C7; color: #92400E; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-skip { background: #F3F4F6; color: #6B7280; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid #D1D5DB; border-top-color: var(--primary-color); border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.card-actions { margin-top: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
.card-actions button { font-size: 12px; padding: 5px 12px; border-radius: 6px; border: 1px solid #D1D5DB; background: #F9FAFB; cursor: pointer; color: #374151; transition: background .15s; }
.card-actions button:hover { background: #E5E7EB; }
.card-actions .btn-redeploy { background: var(--primary-color); color: #fff; border-color: var(--primary-dark); }
.card-actions .btn-redeploy:hover { opacity: .9; }

/* Client lookup */
.lookup-card { margin-top: 24px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 22px 24px; }
.lookup-card h5 { font-size: 15px; font-weight: 700; color: #111; margin: 0 0 16px; }
.lookup-row { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
.lookup-row select { flex: 1; min-width: 220px; }
.lookup-result { margin-top: 16px; }
.lr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
.lr-cell { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 10px 14px; }
.lr-cell .lc-label { font-size: 11px; color: #9CA3AF; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
.lr-cell .lc-val   { font-size: 13px; font-weight: 600; color: #111; }
.lr-cell .lc-sub   { font-size: 11px; color: #6B7280; margin-top: 2px; }
.wg-list { font-size: 12px; color: #6B7280; margin-top: 8px; padding: 0; list-style: none; }
.wg-list li { padding: 2px 0; font-family: monospace; }
.wg-empty { font-size: 12px; color: #EF4444; margin-top: 4px; }
</style>

<div class="container-fluid px-4 py-4">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <div>
      <h4 class="fw-bold mb-0" style="color:#111;">Hotspot Diagnostics</h4>
      <p class="text-muted mb-0" style="font-size:13px;">Real-time checks: router API, login page deployment, and client provisioning</p>
    </div>
    <button class="btn btn-sm btn-outline-secondary" onclick="runAllChecks()" title="Re-run all checks">
      <i class="bi bi-arrow-clockwise me-1"></i> Refresh All
    </button>
  </div>

  <?php if (empty($routers)): ?>
    <div class="alert alert-warning mt-3">No routers configured. <a href="routers.php">Add a router</a> first.</div>
  <?php else: ?>

  <div class="diag-grid" id="diag-grid">
  <?php foreach ($routers as $r): ?>
  <?php
      $ip = $r['ip_address'];
      $displayIp = !empty($r['vpn_ip']) ? $r['vpn_ip'] . ' (VPN)' : $ip;
      $statusDot = $r['status'] === 'online' ? '🟢' : ($r['status'] === 'active' ? '🟡' : '🔴');
  ?>
    <div class="diag-card" id="card-<?= $r['id'] ?>">
      <h5><?= $statusDot ?> <span><?= htmlspecialchars($r['name'] ?: 'Router ' . $r['id']) ?></span></h5>
      <div class="router-ip"><?= htmlspecialchars($displayIp) ?> &nbsp;·&nbsp; Port <?= (int)($r['api_port'] ?: 8728) ?></div>

      <div id="checks-<?= $r['id'] ?>">
        <div class="check-row"><span class="label">Checking…</span><span class="spinner"></span></div>
      </div>

      <div class="wg-section" id="wg-<?= $r['id'] ?>" style="display:none;margin-top:10px;">
        <div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Walled Garden</div>
        <ul class="wg-list" id="wg-list-<?= $r['id'] ?>"></ul>
      </div>

      <div class="card-actions">
        <button class="btn-redeploy" onclick="redeploy(<?= $r['id'] ?>, this)">
          <i class="bi bi-cloud-upload me-1"></i>Re-deploy Login Page
        </button>
        <button onclick="recheck(<?= $r['id'] ?>)">
          <i class="bi bi-arrow-clockwise"></i> Re-check
        </button>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Client Lookup -->
  <div class="lookup-card">
    <h5><i class="bi bi-person-circle me-2" style="color:var(--primary-color)"></i>Client Router Lookup</h5>
    <p style="font-size:13px;color:#6B7280;margin:-8px 0 14px;">Search a hotspot client to see their provisioning status and live session on the router.</p>
    <div class="lookup-row">
      <select id="clientSelect" class="form-select form-select-sm">
        <option value="">— Select a client —</option>
        <?php foreach ($hotspotClients as $c): ?>
        <option value="<?= (int)$c['id'] ?>"
                data-status="<?= htmlspecialchars($c['status']) ?>"
                data-mk="<?= htmlspecialchars($c['mikrotik_username'] ?? '') ?>">
          <?= htmlspecialchars($c['full_name'] ?: $c['username']) ?>
          (<?= htmlspecialchars($c['phone'] ?: $c['account_number']) ?>)
          <?= $c['status'] !== 'active' ? '[' . $c['status'] . ']' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" onclick="lookupClient()" style="white-space:nowrap;">
        <i class="bi bi-search me-1"></i>Check on Router
      </button>
    </div>
    <div class="lookup-result" id="lookupResult"></div>
  </div>

  <?php endif; ?>
</div>

<script>
const ROOT = '<?= rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/') ?>';

// ─── Per-router check ────────────────────────────────────────────────────────
function recheck(routerId) {
    const container = document.getElementById('checks-' + routerId);
    container.innerHTML = '<div class="check-row"><span class="label">Checking…</span><span class="spinner"></span></div>';
    document.getElementById('wg-' + routerId).style.display = 'none';

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch(ROOT + '/api/mikrotik/hotspot_status.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => renderChecks(routerId, d))
        .catch(e => {
            container.innerHTML = '<div class="check-row"><span class="label text-danger">Request failed: ' + e.message + '</span></div>';
        });
}

function renderChecks(routerId, d) {
    const container = document.getElementById('checks-' + routerId);
    if (!d.success && !d.reachable) {
        container.innerHTML = row('Router API', 'FAIL', d.error || 'Unreachable') + row('Login Page', 'SKIP', '—') + row('Hotspot Server', 'SKIP', '—');
        return;
    }

    let html = '';
    html += row('TCP / API Login', d.api_ok ? 'OK' : 'FAIL', d.api_ok ? 'Connected' : (d.error || 'Auth failed'));

    if (d.api_ok) {
        // Bridge interface
        if (d.bridge) {
            html += row('Bridge Interface', 'OK', d.bridge);
        } else {
            html += row('Bridge Interface', 'FAIL', 'No bridge found — provisioning will be blocked');
        }

        // Hotspot server
        if (d.hotspot_server) {
            const onBridge = d.bridge && d.hotspot_server.includes(' on ' + d.bridge);
            html += row('Hotspot Server', onBridge ? 'OK' : 'WARN',
                        (d.hotspot_server) + (onBridge ? '' : ' ⚠ not on bridge'));
        } else if (d.hotspot_server === false) {
            html += row('Hotspot Server', 'FAIL', 'No server running' + (d.bridge ? ' — add one on ' + d.bridge : ''));
        } else {
            html += row('Hotspot Server', 'SKIP', 'N/A');
        }

        // PPPoE server
        if (d.pppoe_server) {
            const ppOnBridge = d.bridge && d.pppoe_server.includes(' on ' + d.bridge);
            html += row('PPPoE Server', ppOnBridge ? 'OK' : 'WARN',
                        (d.pppoe_server) + (ppOnBridge ? '' : ' ⚠ not on bridge'));
        } else if (d.pppoe_server === false) {
            html += row('PPPoE Server', 'SKIP', 'Not configured');
        } else {
            html += row('PPPoE Server', 'SKIP', 'N/A');
        }

        // Login page
        if (d.login_deployed) {
            const sz = d.login_size ? ' (' + (Math.round(d.login_size / 1024 * 10) / 10) + ' KB)' : '';
            html += row('Login Page', 'OK', 'Deployed' + sz + (d.login_mod_time ? ' · ' + d.login_mod_time : ''));
        } else {
            html += row('Login Page', 'FAIL', 'login.html not found on flash');
        }

        // User counts
        const routerU = d.router_users !== null ? d.router_users : '?';
        const dbA     = d.db_active;
        const dbT     = d.db_total;
        const countVal = routerU + ' on router · ' + dbA + ' active / ' + dbT + ' total in DB';
        const countBadge = (d.router_users !== null && d.router_users < d.db_active) ? 'WARN' : 'OK';
        html += row('User Counts', countBadge, countVal);

        // Unprovisioned
        if (d.unprovisioned > 0) {
            html += row('Unprovisioned', 'WARN', d.unprovisioned + ' active client(s) have no router username');
        }

        // Active sessions
        if (d.active_sessions !== null) {
            html += row('Live Sessions', 'OK', d.active_sessions + ' online now');
        }

        // Walled garden
        const wgSection = document.getElementById('wg-' + routerId);
        const wgList    = document.getElementById('wg-list-' + routerId);
        if (d.walled_garden && d.walled_garden.length > 0) {
            wgList.innerHTML = d.walled_garden.map(e => '<li>' + e + '</li>').join('');
            wgSection.style.display = 'block';
        } else if (d.api_ok) {
            wgList.innerHTML = '<li class="wg-empty">No walled garden entries (clients may not reach your portal to pay)</li>';
            wgSection.style.display = 'block';
        }
    }

    container.innerHTML = html;
}

function row(label, badge, val) {
    const cls = badge === 'OK' ? 'badge-ok' : badge === 'FAIL' ? 'badge-fail' : badge === 'WARN' ? 'badge-warn' : 'badge-skip';
    return `<div class="check-row"><span class="label">${label}</span><span class="val">${val}</span><span class="${cls}">${badge}</span></div>`;
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
    btn.innerHTML = '<span class="spinner"></span> Deploying…';

    const fd = new FormData();
    fd.append('router_id', routerId);

    fetch(ROOT + '/api/mikrotik/deploy_hotspot_login.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = orig;
            showToast(d.message || (d.success ? 'Deployed successfully' : 'Deploy failed'), d.success ? 'success' : 'error');
            if (d.success) setTimeout(() => recheck(routerId), 1000);
        })
        .catch(e => {
            btn.disabled = false;
            btn.innerHTML = orig;
            showToast('Request failed: ' + e.message, 'error');
        });
}

// ─── Client lookup ───────────────────────────────────────────────────────────
function lookupClient() {
    const sel    = document.getElementById('clientSelect');
    const result = document.getElementById('lookupResult');
    const cId    = sel.value;

    if (!cId) {
        result.innerHTML = '<p class="text-muted" style="font-size:13px;margin-top:8px;">Select a client first.</p>';
        return;
    }

    result.innerHTML = '<div style="padding:12px 0;font-size:13px;color:#6B7280;"><span class="spinner"></span> Checking router…</div>';

    const fd = new FormData();
    fd.append('client_id', cId);

    fetch(ROOT + '/api/mikrotik/verify_user.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => renderLookup(result, d, sel.options[sel.selectedIndex]))
        .catch(e => {
            result.innerHTML = '<p class="text-danger" style="font-size:13px;margin-top:8px;">Request failed: ' + e.message + '</p>';
        });
}

function renderLookup(container, d, opt) {
    if (!d.success) {
        container.innerHTML = '<p class="text-danger" style="font-size:13px;margin-top:8px;">' + (d.message || 'Error') + '</p>';
        return;
    }

    const mkUser   = d.db_username  || '<em class="text-danger">not set</em>';
    const dbStatus = badge(d.db_status, d.db_status === 'active' ? 'OK' : (d.db_status === 'suspended' ? 'FAIL' : 'WARN'));
    const onRouter = d.router_ok ? '<span class="badge-ok">YES</span>' : '<span class="badge-fail">NO</span>';
    const onlineB  = d.is_online ? '<span class="badge-ok">ONLINE</span>' : '<span class="badge-skip">OFFLINE</span>';
    const profB    = d.router_ok
        ? (d.profile_match ? '<span class="badge-ok">' + d.router_profile + '</span>'
                           : '<span class="badge-warn">' + d.router_profile + ' (expected ' + d.expected_profile + ')</span>')
        : '—';

    let sessHtml = '';
    if (d.is_online && d.session) {
        const s = d.session;
        sessHtml = `<div class="lr-grid" style="margin-top:0">
            <div class="lr-cell"><div class="lc-label">Uptime</div><div class="lc-val">${s.uptime || '—'}</div></div>
            <div class="lr-cell"><div class="lc-label">IP Address</div><div class="lc-val">${s.address || '—'}</div></div>
            <div class="lr-cell"><div class="lc-label">Download</div><div class="lc-val">${s.rx_mb} MB</div></div>
            <div class="lr-cell"><div class="lc-label">Upload</div><div class="lc-val">${s.tx_mb} MB</div></div>
        </div>`;
    }

    let errorNote = '';
    if (d.router_error) {
        errorNote = `<div class="alert alert-warning py-2 px-3 mt-2" style="font-size:12px;">${d.router_error}</div>`;
    }

    container.innerHTML = `
    <div class="lr-grid">
        <div class="lr-cell"><div class="lc-label">DB Status</div><div class="lc-val">${dbStatus}</div></div>
        <div class="lr-cell"><div class="lc-label">MikroTik Username</div><div class="lc-val">${mkUser}</div><div class="lc-sub">Connection: ${d.db_connection || 'hotspot'}</div></div>
        <div class="lr-cell"><div class="lc-label">Exists on Router</div><div class="lc-val">${onRouter}</div></div>
        <div class="lr-cell"><div class="lc-label">Profile</div><div class="lc-val">${profB}</div></div>
        <div class="lr-cell"><div class="lc-label">Live Session</div><div class="lc-val">${onlineB}</div></div>
        <div class="lr-cell"><div class="lc-label">Router IP</div><div class="lc-val">${d.router_ip || '—'}</div></div>
    </div>
    ${sessHtml}
    ${errorNote}`;
}

function badge(text, type) {
    const cls = type === 'OK' ? 'badge-ok' : type === 'FAIL' ? 'badge-fail' : 'badge-warn';
    return `<span class="${cls}">${text}</span>`;
}

// Auto-run checks on page load
document.addEventListener('DOMContentLoaded', runAllChecks);
</script>

<?php require_once 'includes/footer.php'; ?>
