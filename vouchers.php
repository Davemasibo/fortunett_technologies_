<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$user_id]);
$tenant_id = (int)$t->fetchColumn();

// Ensure columns exist
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN tenant_id INT NULL AFTER id"); } catch (Throwable $_e) {}
try { $pdo->exec("ALTER TABLE vouchers ADD COLUMN connection_type VARCHAR(20) DEFAULT 'hotspot' AFTER tenant_id"); } catch (Throwable $_e) {}

// Stats
$stats = ['total' => 0, 'active' => 0, 'used' => 0, 'expired' => 0, 'revenue' => 0];
try {
    $sRow = $pdo->prepare("SELECT status, COUNT(*) AS n, SUM(price) AS rev FROM vouchers WHERE tenant_id = ? GROUP BY status");
    $sRow->execute([$tenant_id]);
    foreach ($sRow->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stats[$r['status']] = (int)$r['n'];
        $stats['total'] += (int)$r['n'];
        if ($r['status'] === 'used') $stats['revenue'] += (float)$r['rev'];
    }
} catch (Throwable $_e) {}

// Ensure optional package columns exist
try { $pdo->exec("ALTER TABLE packages ADD COLUMN connection_type VARCHAR(20) DEFAULT 'pppoe'"); } catch (Throwable $_e) {}
try { $pdo->exec("ALTER TABLE packages ADD COLUMN mikrotik_profile VARCHAR(100) DEFAULT NULL"); } catch (Throwable $_e) {}

// Packages for form
$packages = [];
try {
    $pkgSt = $pdo->prepare("SELECT id, name,
        COALESCE(NULLIF(connection_type,''), type, 'pppoe') AS connection_type,
        COALESCE(mikrotik_profile,'') AS mikrotik_profile
        FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY name");
    $pkgSt->execute([$tenant_id]);
    $packages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_e) {
    try {
        $pkgSt = $pdo->prepare("SELECT id, name, type AS connection_type FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY name");
        $pkgSt->execute([$tenant_id]);
        $packages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_e2) {}
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<style>
.vch-wrap { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }
.vch-title { font-size: 28px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px; }
.vch-sub   { font-size: 14px; color: #9a9a95; margin: 0 0 24px; }

/* Stats */
.vch-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px,1fr)); gap: 16px; margin-bottom: 24px; }
.vch-stat  { background: #222221; border-radius: 12px; padding: 18px; border: 1px solid rgba(255,255,255,.06); }
.vch-stat-val  { font-size: 26px; font-weight: 700; color: #fff; }
.vch-stat-lbl  { font-size: 12px; color: #9a9a95; margin-top: 4px; }
.vch-stat.active .vch-stat-val  { color: #6ee7b7; }
.vch-stat.used   .vch-stat-val  { color: #a5f3fc; }
.vch-stat.expired .vch-stat-val { color: #fca5a5; }
.vch-stat.revenue .vch-stat-val { color: #fcd34d; }

/* Two-col layout */
.vch-layout { display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start; }
@media(max-width:900px) { .vch-layout { grid-template-columns: 1fr; } }

/* Generate panel */
.vch-panel { background: #222221; border-radius: 14px; border: 1px solid rgba(255,255,255,.07); padding: 22px; }
.vch-panel-title { font-size: 14px; font-weight: 700; color: #e2e2e0; margin: 0 0 18px; display: flex; align-items: center; gap: 8px; }
.vch-panel-title i { color: var(--primary-color, #3B6EA5); }
.vch-label { display: block; font-size: 11px; font-weight: 700; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 5px; }
.vch-input, .vch-select { width: 100%; padding: 9px 11px; background: #1a1a19; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; color: #e2e2e0; font-size: 13px; box-shadow: inset 2px 2px 6px rgba(0,0,0,.4); margin-bottom: 14px; box-sizing: border-box; }
.vch-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,.35)'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 28px; -webkit-appearance: none; appearance: none; }
.vch-row2 { display: grid; grid-template-columns: 80px 1fr; gap: 8px; }
.vch-btn { width: 100%; padding: 11px; border: none; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .18s; display: flex; align-items: center; justify-content: center; gap: 7px; }
.vch-btn-gen { background: linear-gradient(135deg, var(--primary-dark,#2C5282) 0%, var(--primary-color,#3B6EA5) 100%); color: #fff; box-shadow: 0 4px 14px rgba(59,110,165,.35); }
.vch-btn-gen:hover { transform: translateY(-1px); }
.vch-btn-print { background: rgba(251,191,36,.12); border: 1px solid rgba(251,191,36,.25); color: #fbbf24; margin-top: 8px; }
.vch-btn-print:hover { background: rgba(251,191,36,.2); }
.conn-pills { display: flex; gap: 8px; margin-bottom: 14px; }
.conn-pill { flex:1; padding: 8px; border-radius: 8px; text-align: center; border: 1.5px solid rgba(255,255,255,.08); background: rgba(255,255,255,.04); cursor: pointer; transition: all .18s; font-size: 12px; font-weight: 600; color: rgba(255,255,255,.45); }
.conn-pill.active { border-color: var(--primary-color,#3B6EA5); background: rgba(59,110,165,.18); color: #e2e2e0; }

/* Table */
.vch-table-wrap { background: #222221; border-radius: 14px; border: 1px solid rgba(255,255,255,.07); overflow: hidden; }
.vch-toolbar { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.06); flex-wrap: wrap; }
.vch-search { flex: 1; min-width: 180px; padding: 8px 12px; background: #1a1a19; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; color: #e2e2e0; font-size: 13px; }
.vch-filter { padding: 8px 28px 8px 10px; background: #1a1a19; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; color: #e2e2e0; font-size: 13px; -webkit-appearance: none; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,.35)'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; }
.vch-del-sel { padding: 8px 14px; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); border-radius: 8px; color: #fca5a5; font-size: 13px; cursor: pointer; display: none; }
.vch-table { width: 100%; border-collapse: collapse; min-width: 700px; }
.vch-table th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 700; color: #9a9a95; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid rgba(255,255,255,.07); background: rgba(255,255,255,.03); }
.vch-table td { padding: 12px 14px; font-size: 13px; color: #e2e2e0; border-bottom: 1px solid rgba(255,255,255,.04); }
.vch-table tr:hover td { background: rgba(255,255,255,.03); }
.vch-code { font-family: 'Courier New', monospace; font-size: 14px; font-weight: 700; color: #a5f3fc; letter-spacing: .05em; }
.vch-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.vch-badge.active  { background: rgba(16,185,129,.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
.vch-badge.used    { background: rgba(59,130,246,.15);  color: #93c5fd; border: 1px solid rgba(59,130,246,.25); }
.vch-badge.expired { background: rgba(239,68,68,.12);   color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
.vch-action-btn { padding: 5px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.05); color: #9a9a95; cursor: pointer; font-size: 12px; transition: all .15s; }
.vch-action-btn:hover { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.25); }
.vch-table-scroll { overflow-x: auto; }
.vch-pagination { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; font-size: 13px; color: #9a9a95; }
.vch-pg-btn { padding: 6px 14px; border-radius: 7px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.05); color: #d4d4d2; cursor: pointer; font-size: 13px; }
.vch-pg-btn:disabled { opacity: .35; cursor: not-allowed; }

/* Print sheet */
@media print {
    .sidebar, .main-layout > header, #vch-print-btn, .vch-toolbar, .vch-pagination, .vch-layout > .vch-panel:first-child, .vch-stats { display: none !important; }
    .main-content-wrapper { margin: 0 !important; width: 100% !important; }
    .vch-wrap { padding: 0 !important; }
    .vch-print-grid { display: grid !important; }
}
.vch-print-grid { display: none; grid-template-columns: repeat(3,1fr); gap: 12px; padding: 12px; }
.vch-ticket { border: 1.5px dashed #999; border-radius: 8px; padding: 12px; text-align: center; }
.vch-ticket-code { font-family: monospace; font-size: 17px; font-weight: 900; letter-spacing: .1em; margin: 6px 0; }
.vch-ticket-meta { font-size: 11px; color: #555; }
</style>

<div class="main-content-wrapper">
<div class="vch-wrap">
    <h1 class="vch-title">Vouchers</h1>
    <p class="vch-sub">Generate, distribute and manage one-time internet access codes</p>

    <!-- Stats -->
    <div class="vch-stats">
        <div class="vch-stat">
            <div class="vch-stat-val"><?= $stats['total'] ?></div>
            <div class="vch-stat-lbl">Total</div>
        </div>
        <div class="vch-stat active">
            <div class="vch-stat-val"><?= $stats['active'] ?></div>
            <div class="vch-stat-lbl">Available</div>
        </div>
        <div class="vch-stat used">
            <div class="vch-stat-val"><?= $stats['used'] ?></div>
            <div class="vch-stat-lbl">Redeemed</div>
        </div>
        <div class="vch-stat expired">
            <div class="vch-stat-val"><?= $stats['expired'] ?></div>
            <div class="vch-stat-lbl">Expired</div>
        </div>
        <div class="vch-stat revenue">
            <div class="vch-stat-val">KES <?= number_format($stats['revenue'], 0) ?></div>
            <div class="vch-stat-lbl">Revenue collected</div>
        </div>
    </div>

    <div class="vch-layout">
        <!-- Generate Panel -->
        <div class="vch-panel">
            <div class="vch-panel-title"><i class="fas fa-ticket-alt"></i> Generate Vouchers</div>

            <label class="vch-label">Connection Type</label>
            <div class="conn-pills" style="margin-bottom:14px;">
                <div class="conn-pill active" id="vpill-hotspot" onclick="setVchType('hotspot')">
                    <i class="fas fa-wifi"></i> Hotspot
                </div>
                <div class="conn-pill" id="vpill-pppoe" onclick="setVchType('pppoe')">
                    <i class="fas fa-network-wired"></i> PPPoE
                </div>
            </div>
            <input type="hidden" id="vchConnType" value="hotspot">

            <label class="vch-label">Package (optional)</label>
            <select id="vchPackage" class="vch-select">
                <option value="">— No package (duration-only) —</option>
                <?php foreach ($packages as $p): ?>
                <option value="<?= $p['id'] ?>"
                    data-type="<?= htmlspecialchars($p['connection_type'] ?: $p['type']) ?>">
                    <?= htmlspecialchars($p['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label class="vch-label">Duration</label>
            <div class="vch-row2" style="margin-bottom:14px;">
                <input type="number" id="vchDurVal" class="vch-input" style="margin:0" value="1" min="1">
                <select id="vchDurUnit" class="vch-select" style="margin:0">
                    <option value="hours">Hours</option>
                    <option value="days" selected>Days</option>
                    <option value="months">Months</option>
                </select>
            </div>

            <label class="vch-label">Price per Voucher (KES)</label>
            <input type="number" id="vchPrice" class="vch-input" value="0" min="0" step="0.01">

            <label class="vch-label">Quantity</label>
            <input type="number" id="vchCount" class="vch-input" value="10" min="1" max="500">

            <label class="vch-label">Code Prefix (optional)</label>
            <input type="text" id="vchPrefix" class="vch-input" placeholder="e.g. DAY" maxlength="6"
                   oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
            <div style="font-size:11px;color:rgba(255,255,255,.25);margin-top:-10px;margin-bottom:14px;">Preview: <span id="vchCodePreview" style="color:#a5f3fc;font-family:monospace;">ABCD-EFGH</span></div>

            <label class="vch-label">Voucher Expiry (optional)</label>
            <input type="date" id="vchExpiry" class="vch-input" placeholder="Leave blank = never expires">

            <button class="vch-btn vch-btn-gen" id="vchGenBtn" onclick="generateVouchers()">
                <i class="fas fa-bolt"></i> Generate Vouchers
            </button>
            <button class="vch-btn vch-btn-print" onclick="printVouchers()">
                <i class="fas fa-print"></i> Print Last Batch
            </button>
        </div>

        <!-- Table Panel -->
        <div>
            <div class="vch-table-wrap">
                <div class="vch-toolbar">
                    <input type="text" class="vch-search" id="vchSearch" placeholder="Search code…" oninput="debounceLoad()">
                    <select class="vch-filter" id="vchFilter" onchange="loadVouchers(1)">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="used">Used</option>
                        <option value="expired">Expired</option>
                    </select>
                    <button class="vch-del-sel" id="vchDelSelBtn" onclick="deleteSelected()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                </div>
                <div class="vch-table-scroll">
                    <table class="vch-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="vchSelectAll" onchange="toggleAll(this)"></th>
                                <th>Code</th>
                                <th>Package</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Used By</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="vchTableBody">
                            <tr><td colspan="10" style="text-align:center;padding:40px;color:#9a9a95;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="vch-pagination">
                    <span id="vchPagInfo">—</span>
                    <div style="display:flex;gap:8px;">
                        <button class="vch-pg-btn" id="vchPrevBtn" onclick="loadVouchers(currentPage-1)">← Prev</button>
                        <button class="vch-pg-btn" id="vchNextBtn" onclick="loadVouchers(currentPage+1)">Next →</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Print grid (hidden unless printing) -->
<div class="vch-print-grid" id="vchPrintGrid"></div>

<script>
const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
    ? '/fortunett_technologies_' : '';

let currentPage = 1;
let totalVouchers = 0;
let debounceTimer = null;
let lastBatch = [];

function setVchType(t) {
    document.getElementById('vchConnType').value = t;
    document.getElementById('vpill-hotspot').classList.toggle('active', t === 'hotspot');
    document.getElementById('vpill-pppoe').classList.toggle('active', t === 'pppoe');
}

document.getElementById('vchPrefix').addEventListener('input', updatePreview);
function updatePreview() {
    const pfx = document.getElementById('vchPrefix').value;
    document.getElementById('vchCodePreview').textContent = (pfx ? pfx + '-' : '') + 'ABCD-EFGH';
}

function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadVouchers(1), 350);
}

function loadVouchers(page) {
    currentPage = page;
    const search = document.getElementById('vchSearch').value;
    const status = document.getElementById('vchFilter').value;
    const url    = _base + '/api/vouchers/list.php?page=' + page + '&search=' + encodeURIComponent(search) + '&status=' + status;
    const tbody  = document.getElementById('vchTableBody');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#9a9a95;"><i class="fas fa-spinner fa-spin"></i></td></tr>';

    fetch(url).then(r => r.json()).then(data => {
        if (!data.success) { tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#fca5a5;padding:30px;">' + (data.message||'Error') + '</td></tr>'; return; }
        totalVouchers = data.total;
        const rows = data.vouchers;
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#9a9a95;">No vouchers found</td></tr>';
            document.getElementById('vchPagInfo').textContent = '0 vouchers';
            document.getElementById('vchPrevBtn').disabled = true;
            document.getElementById('vchNextBtn').disabled = true;
            return;
        }
        let html = '';
        rows.forEach(v => {
            const badgeClass = v.status === 'active' ? 'active' : v.status === 'used' ? 'used' : 'expired';
            const dur = v.duration_days <= 0 ? '—' : (v.duration_days >= 30 ? Math.round(v.duration_days/30) + ' mo' : v.duration_days + 'd');
            const exp = v.expires_at ? v.expires_at.split(' ')[0] : '—';
            const usedBy = v.used_by_name ? `<span style="color:#e2e2e0">${esc(v.used_by_name)}</span><br><span style="color:#9a9a95;font-size:11px">${esc(v.used_by_phone||'')}</span>` : '—';
            const type = (v.connection_type||'hotspot') === 'pppoe' ? '<i class="fas fa-network-wired" style="color:#a5b4fc" title="PPPoE"></i>' : '<i class="fas fa-wifi" style="color:#93c5fd" title="Hotspot"></i>';
            html += `<tr>
                <td><input type="checkbox" class="vch-cb" data-id="${v.id}"></td>
                <td><span class="vch-code">${esc(v.voucher_code)}</span></td>
                <td>${esc(v.package_name||'—')}</td>
                <td>${dur}</td>
                <td>${v.price > 0 ? 'KES '+parseFloat(v.price).toLocaleString() : '—'}</td>
                <td>${type}</td>
                <td><span class="vch-badge ${badgeClass}">${v.status}</span></td>
                <td>${usedBy}</td>
                <td style="font-size:12px;color:#9a9a95;">${exp}</td>
                <td><button class="vch-action-btn" onclick="deleteOne(${v.id})"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        });
        tbody.innerHTML = html;

        // Pagination info
        const start = (page - 1) * data.per_page + 1;
        const end   = Math.min(page * data.per_page, data.total);
        document.getElementById('vchPagInfo').textContent = start + '–' + end + ' of ' + data.total;
        document.getElementById('vchPrevBtn').disabled = page <= 1;
        document.getElementById('vchNextBtn').disabled = end >= data.total;

        // Checkbox listeners
        document.querySelectorAll('.vch-cb').forEach(cb => cb.addEventListener('change', updateDelBtn));
    }).catch(() => {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#fca5a5;padding:30px;">Network error</td></tr>';
    });
}

function toggleAll(cb) {
    document.querySelectorAll('.vch-cb').forEach(c => c.checked = cb.checked);
    updateDelBtn();
}
function updateDelBtn() {
    const sel = document.querySelectorAll('.vch-cb:checked').length;
    const btn = document.getElementById('vchDelSelBtn');
    btn.style.display = sel > 0 ? 'block' : 'none';
    btn.textContent = '\u{1F5D1} Delete (' + sel + ')';
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete (' + sel + ')';
}

function deleteOne(id) {
    if (!confirm('Delete this voucher? This cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    fetch(_base + '/api/vouchers/delete.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (d.success) { showToast('Voucher deleted.', 'success'); loadVouchers(currentPage); }
            else showToast('Error: ' + d.message, 'error');
        });
}

function deleteSelected() {
    const ids = [...document.querySelectorAll('.vch-cb:checked')].map(c => c.dataset.id);
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' voucher(s)? This cannot be undone.')) return;
    const fd = new FormData();
    ids.forEach(id => fd.append('ids[]', id));
    fetch(_base + '/api/vouchers/delete.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (d.success) { showToast(d.deleted + ' voucher(s) deleted.', 'success'); loadVouchers(currentPage); }
            else showToast('Error: ' + d.message, 'error');
        });
}

function generateVouchers() {
    const btn = document.getElementById('vchGenBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('connection_type', document.getElementById('vchConnType').value);
    fd.append('package_id',   document.getElementById('vchPackage').value);
    fd.append('duration_value', document.getElementById('vchDurVal').value);
    fd.append('duration_unit',  document.getElementById('vchDurUnit').value);
    fd.append('price',   document.getElementById('vchPrice').value);
    fd.append('count',   document.getElementById('vchCount').value);
    fd.append('prefix',  document.getElementById('vchPrefix').value);
    const exp = document.getElementById('vchExpiry').value;
    if (exp) fd.append('expires_at', exp);

    fetch(_base + '/api/vouchers/generate.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            btn.innerHTML = orig; btn.disabled = false;
            if (!data.success) { showToast('Error: ' + (data.message||'Unknown'), 'error'); return; }
            lastBatch = data.codes;
            let msg = data.count + ' vouchers generated.';
            if (data.router_synced) msg += ' Synced to router.';
            if (data.router_error)  msg += ' Router sync failed: ' + data.router_error;
            showToast(msg, data.router_error ? 'error' : 'success');
            buildPrintGrid(data.codes);
            loadVouchers(1);
        })
        .catch(() => { btn.innerHTML = orig; btn.disabled = false; showToast('Network error.', 'error'); });
}

function buildPrintGrid(codes) {
    const grid = document.getElementById('vchPrintGrid');
    const pkg  = document.getElementById('vchPackage').selectedOptions[0]?.text || '';
    const dur  = document.getElementById('vchDurVal').value + ' ' + document.getElementById('vchDurUnit').value;
    const price= parseFloat(document.getElementById('vchPrice').value);
    grid.innerHTML = codes.map(c => `
        <div class="vch-ticket">
            <div class="vch-ticket-meta" style="font-size:12px;color:#333;">${document.title.split('—')[0]?.trim()||'Internet Voucher'}</div>
            <div class="vch-ticket-code">${c}</div>
            <div class="vch-ticket-meta">${pkg ? pkg + ' · ' : ''}${dur}${price > 0 ? ' · KES '+price : ''}</div>
        </div>`).join('');
}

function printVouchers() {
    if (!lastBatch.length) { showToast('Generate a batch first.', 'error'); return; }
    window.print();
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Load on start
loadVouchers(1);

// Auto-filter packages by connection type
document.getElementById('vchConnType').addEventListener('change', function() {
    // Optionally filter package list — left as manual for now
});
</script>

<?php include 'includes/footer.php'; ?>
