<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
require_once 'classes/MikrotikAPI.php';
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$stmt    = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

$rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
$rSt->execute([$tenant_id]);
$routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

$onlineUsers = [];
$routerStats = [];
$fetchErrors = [];

function uptimeToSeconds(string $uptime): int {
    $secs = 0;
    if (preg_match('/(\d+)w/', $uptime, $m)) $secs += (int)$m[1] * 604800;
    if (preg_match('/(\d+)d/', $uptime, $m)) $secs += (int)$m[1] * 86400;
    if (preg_match('/(\d+)h/', $uptime, $m)) $secs += (int)$m[1] * 3600;
    if (preg_match('/(\d+)m/', $uptime, $m)) $secs += (int)$m[1] * 60;
    if (preg_match('/(\d+)s/', $uptime, $m)) $secs += (int)$m[1];
    return $secs;
}

function fmtBytes(int $b): string {
    if ($b < 1024)        return $b . ' B';
    if ($b < 1048576)     return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824)  return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}

foreach ($routers as $router) {
    $port      = (int)($router['api_port'] ?: 8728);
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];

    $routerStats[$router['id']] = [
        'name'    => $router['name'],
        'ip'      => $router['ip_address'],
        'online'  => false,
        'pppoe'   => 0,
        'hotspot' => 0,
    ];

    try {
        $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
        $mk->connect();
        $routerStats[$router['id']]['online'] = true;

        foreach ($mk->getActiveSessionsMap() as $uname => $d) {
            $uptimeSecs = uptimeToSeconds($d['uptime'] ?? '');
            $onlineUsers[] = [
                'username'     => $uname,
                'type'         => 'pppoe',
                'ip'           => $d['address'] ?? '—',
                'mac'          => $d['caller']  ?? '—',
                'uptime'       => $d['uptime']  ?? '—',
                'session_start'=> $uptimeSecs > 0 ? date('M d, H:i', time() - $uptimeSecs) : '—',
                'rx_bytes'     => (int)($d['rx_byte'] ?? 0),
                'tx_bytes'     => (int)($d['tx_byte'] ?? 0),
                'router_id'    => $router['id'],
                'router'       => $router['name'],
            ];
            $routerStats[$router['id']]['pppoe']++;
        }

        foreach ($mk->getActiveHotspotSessionsMap() as $uname => $d) {
            $uptimeSecs = uptimeToSeconds($d['uptime'] ?? '');
            $onlineUsers[] = [
                'username'     => $uname,
                'type'         => 'hotspot',
                'ip'           => $d['address'] ?? '—',
                'mac'          => '—',
                'uptime'       => $d['uptime']  ?? '—',
                'session_start'=> $uptimeSecs > 0 ? date('M d, H:i', time() - $uptimeSecs) : '—',
                'rx_bytes'     => (int)($d['rx_byte'] ?? 0),
                'tx_bytes'     => (int)($d['tx_byte'] ?? 0),
                'router_id'    => $router['id'],
                'router'       => $router['name'],
            ];
            $routerStats[$router['id']]['hotspot']++;
        }

        $mk->disconnect();
    } catch (Exception $e) {
        $fetchErrors[] = htmlspecialchars($router['name'] . ': ' . $e->getMessage());
    }
}

// Enrich with DB client info (name, phone, package, expiry_date)
$clientMap = [];
if (!empty($onlineUsers)) {
    $unames       = array_unique(array_column($onlineUsers, 'username'));
    $placeholders = implode(',', array_fill(0, count($unames), '?'));
    $cSt = $pdo->prepare("
        SELECT c.mikrotik_username AS mk_u, c.name, c.phone, c.expiry_date,
               p.name AS package_name
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.mikrotik_username IN ($placeholders) AND c.tenant_id = ?
    ");
    $cSt->execute(array_merge($unames, [$tenant_id]));
    foreach ($cSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $clientMap[strtolower($row['mk_u'])] = $row;
    }
}

foreach ($onlineUsers as &$u) {
    $info = $clientMap[$u['username']] ?? null;
    $u['name']         = $info['name']         ?? $u['username'];
    $u['phone']        = $info['phone']        ?? '—';
    $u['package_name'] = $info['package_name'] ?? '—';
    $u['expiry_date']  = !empty($info['expiry_date'])
        ? date('M d, Y', strtotime($info['expiry_date']))
        : '—';
}
unset($u);

$totalOnline  = count($onlineUsers);
$pppoeCount   = count(array_filter($onlineUsers, fn($u) => $u['type'] === 'pppoe'));
$hotspotCount = count(array_filter($onlineUsers, fn($u) => $u['type'] === 'hotspot'));

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
<div class="oc-page">

<style>
/* ── Page header ─────────────────────────────────────────── */
.oc-page { padding: 0; }
.oc-hdr  { margin-bottom: 24px; }
.oc-title {
    font-size: 22px; font-weight: 700; color: #e2e2e0;
    display: flex; align-items: center; gap: 10px; margin: 0 0 4px;
}
.oc-title-dot { width: 10px; height: 10px; border-radius: 50%; background: #10B981;
    box-shadow: 0 0 8px #10B981; animation: pulse-dot 2s ease-in-out infinite; }
.oc-sub { font-size: 13px; color: rgba(255,255,255,.38); margin: 0; }

/* ── Summary pills ───────────────────────────────────────── */
.oc-summary { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; align-items: center; }
.oc-pill {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500;
    background: #1c1c1b; border: 1px solid rgba(255,255,255,.08);
    box-shadow: 3px 3px 8px rgba(0,0,0,.35), -2px -2px 5px rgba(255,255,255,.03);
    color: rgba(255,255,255,.55);
}
.oc-pill strong { color: #e2e2e0; }
.oc-pill-dot { width: 7px; height: 7px; border-radius: 50%; }
.oc-pill-dot.g { background: #10B981; }
.oc-pill-dot.b { background: #60a5fa; }
.oc-pill-dot.p { background: #a78bfa; }
.oc-pill.refresh-pill { margin-left: auto; cursor: pointer; gap: 6px; }
.oc-pill.refresh-pill:hover { border-color: rgba(255,255,255,.18); color: #e2e2e0; }

/* ── Router cards ────────────────────────────────────────── */
.oc-routers { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
.oc-rcard {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-radius: 10px;
    background: #1c1c1b; border: 1px solid rgba(255,255,255,.06);
    box-shadow: 4px 4px 10px rgba(0,0,0,.35), -2px -2px 6px rgba(255,255,255,.025);
    font-size: 12px;
}
.oc-ricon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
.oc-ricon.on  { background: rgba(16,185,129,.15); color: #34d399; }
.oc-ricon.off { background: rgba(239,68,68,.12);  color: #f87171; }
.oc-rname { font-weight: 600; color: #e2e2e0; font-size: 13px; }
.oc-rip   { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 1px; }
.oc-rcounts { display: flex; gap: 10px; margin-left: 4px; color: rgba(255,255,255,.4); }
.oc-rcounts span strong { color: #d4d4d2; }
.oc-rbadge { padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-left: 4px; }
.oc-rbadge.on  { background: rgba(16,185,129,.12); color: #6ee7b7; border: 1px solid rgba(16,185,129,.22); }
.oc-rbadge.off { background: rgba(239,68,68,.1);   color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }

/* ── Toolbar ─────────────────────────────────────────────── */
.oc-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; align-items: center; }
.oc-search {
    flex: 1; min-width: 200px; max-width: 320px;
    padding: 8px 12px 8px 34px; border-radius: 8px;
    background: #1a1a19 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,.3)' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='M21 21l-4.35-4.35'/%3E%3C/svg%3E") no-repeat 9px center;
    border: 1px solid rgba(255,255,255,.1); color: #e2e2e0; font-size: 13px; outline: none;
}
.oc-search::placeholder { color: rgba(255,255,255,.25); }
.oc-search:focus { border-color: var(--primary-color, #3B6EA5); }
.oc-fbtn {
    padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600;
    background: #1c1c1b; border: 1px solid rgba(255,255,255,.1); color: rgba(255,255,255,.5);
    cursor: pointer; transition: all .15s;
}
.oc-fbtn:hover { border-color: rgba(255,255,255,.22); color: #e2e2e0; }
.oc-fbtn.active {
    background: linear-gradient(135deg, var(--primary-dark, #0d2a4e), var(--primary-color, #3B6EA5));
    border-color: var(--primary-color, #3B6EA5); color: #fff;
}
.oc-cnt { margin-left: auto; font-size: 12px; color: rgba(255,255,255,.3); }

/* ── Main card / table ───────────────────────────────────── */
.oc-card {
    background: #1c1c1b;
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 8px 8px 20px rgba(0,0,0,.45), -4px -4px 10px rgba(255,255,255,.03);
}
.oc-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.oc-tbl { width: 100%; border-collapse: collapse; min-width: 860px; }
.oc-tbl thead { background: rgba(255,255,255,.03); border-bottom: 1px solid rgba(255,255,255,.07); }
.oc-tbl th {
    padding: 10px 14px; text-align: left;
    font-size: 10px; font-weight: 700; color: rgba(255,255,255,.3);
    text-transform: uppercase; letter-spacing: .06em; white-space: nowrap;
}
.oc-tbl td {
    padding: 13px 14px; border-bottom: 1px solid rgba(255,255,255,.04);
    font-size: 13px; color: rgba(255,255,255,.7); vertical-align: middle;
}
.oc-tbl tbody tr:last-child td { border-bottom: none; }
.oc-tbl tbody tr:hover { background: rgba(255,255,255,.025); }

/* Customer cell */
.oc-cust-name { font-weight: 600; color: #e2e2e0; }
.oc-cust-sub  { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 2px; }

/* Type badge */
.oc-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
}
.oc-type.pppoe   { background: rgba(96,165,250,.12); color: #93c5fd; border: 1px solid rgba(96,165,250,.2); }
.oc-type.hotspot { background: rgba(167,139,250,.12); color: #c4b5fd; border: 1px solid rgba(167,139,250,.2); }

/* Data cell */
.oc-data { font-size: 12px; color: rgba(255,255,255,.4); }
.oc-data strong { color: rgba(255,255,255,.7); }

/* Session time cells */
.oc-time { font-size: 12px; color: rgba(255,255,255,.45); font-variant-numeric: tabular-nums; }
.oc-time.expired { color: #f87171; }

/* Disconnect button */
.oc-disc-btn {
    padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
    background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.2);
    color: #fca5a5; cursor: pointer; transition: all .15s; white-space: nowrap;
}
.oc-disc-btn:hover { background: rgba(239,68,68,.2); border-color: rgba(239,68,68,.4); color: #fff; }
.oc-disc-btn:disabled { opacity: .4; cursor: not-allowed; }
.oc-disc-btn.loading { opacity: .6; }

/* Empty / error states */
.oc-empty { text-align: center; padding: 56px 24px; color: rgba(255,255,255,.25); }
.oc-empty i { font-size: 38px; display: block; margin-bottom: 14px; color: rgba(255,255,255,.15); }
.oc-empty h3 { font-size: 16px; font-weight: 600; color: rgba(255,255,255,.35); margin-bottom: 6px; }
.oc-empty p  { font-size: 12px; }

.oc-err-bar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 8px; margin-bottom: 10px;
    background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.18);
    font-size: 12px; color: #fca5a5;
}

/* Toast */
#oc-toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 500;
    z-index: 9999; display: none; max-width: 360px; text-align: center;
    box-shadow: 0 4px 18px rgba(0,0,0,.5);
}
#oc-toast.ok  { background: #064e3b; color: #6ee7b7; border: 1px solid rgba(16,185,129,.3); }
#oc-toast.err { background: #450a0a; color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }

@media (max-width: 768px) {
    .oc-routers { flex-direction: column; }
    .oc-toolbar  { flex-direction: column; align-items: stretch; }
    .oc-search   { max-width: 100%; }
    .oc-cnt      { margin-left: 0; }
}
</style>

<!-- Toast -->
<div id="oc-toast"></div>

<div class="oc-hdr">
    <h1 class="oc-title">
        <span class="oc-title-dot"></span>
        Online Customers
    </h1>
    <p class="oc-sub">Live sessions fetched directly from your MikroTik routers</p>
</div>

<?php foreach ($fetchErrors as $err): ?>
<div class="oc-err-bar"><i class="fas fa-exclamation-triangle"></i><?php echo $err; ?></div>
<?php endforeach; ?>

<!-- Summary pills -->
<div class="oc-summary">
    <div class="oc-pill"><span class="oc-pill-dot g"></span><strong><?php echo $totalOnline; ?></strong> Total Online</div>
    <div class="oc-pill"><span class="oc-pill-dot b"></span><strong><?php echo $pppoeCount; ?></strong> PPPoE</div>
    <div class="oc-pill"><span class="oc-pill-dot p"></span><strong><?php echo $hotspotCount; ?></strong> Hotspot</div>
    <div class="oc-pill refresh-pill" onclick="location.reload()" title="Reload page">
        <i class="fas fa-sync-alt" style="font-size:11px;color:rgba(255,255,255,.4);"></i>
        <?php echo date('H:i:s'); ?>
    </div>
</div>

<!-- Per-router status -->
<?php if (!empty($routerStats)): ?>
<div class="oc-routers">
    <?php foreach ($routerStats as $rs): ?>
    <div class="oc-rcard">
        <div class="oc-ricon <?php echo $rs['online'] ? 'on' : 'off'; ?>"><i class="fas fa-server"></i></div>
        <div>
            <div class="oc-rname"><?php echo htmlspecialchars($rs['name']); ?></div>
            <div class="oc-rip"><?php echo htmlspecialchars($rs['ip']); ?></div>
        </div>
        <div class="oc-rcounts">
            <span title="PPPoE"><i class="fas fa-plug" style="font-size:9px;margin-right:3px;"></i><strong><?php echo $rs['pppoe']; ?></strong></span>
            <span title="Hotspot"><i class="fas fa-wifi" style="font-size:9px;margin-right:3px;"></i><strong><?php echo $rs['hotspot']; ?></strong></span>
        </div>
        <span class="oc-rbadge <?php echo $rs['online'] ? 'on' : 'off'; ?>"><?php echo $rs['online'] ? 'Online' : 'Offline'; ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Search + filter -->
<div class="oc-toolbar">
    <input type="text" class="oc-search" id="oc-s" placeholder="Search by name, username, IP, MAC…" oninput="ocFilter()">
    <button class="oc-fbtn active" id="f-all"     onclick="ocSetFilter('all')">All</button>
    <button class="oc-fbtn"        id="f-pppoe"   onclick="ocSetFilter('pppoe')">PPPoE</button>
    <button class="oc-fbtn"        id="f-hotspot" onclick="ocSetFilter('hotspot')">Hotspot</button>
    <span class="oc-cnt" id="oc-cnt"><?php echo $totalOnline; ?> session<?php echo $totalOnline !== 1 ? 's' : ''; ?></span>
</div>

<!-- Table -->
<div class="oc-card">
    <?php if (!empty($onlineUsers)): ?>
    <div class="oc-wrap">
        <table class="oc-tbl" id="oc-tbl">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>IP Address</th>
                    <th>MAC / Caller-ID</th>
                    <th>Session Start</th>
                    <th>Subscription End</th>
                    <th>Data ↓ / ↑</th>
                    <th>Router</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($onlineUsers as $u):
                $expiryExpired = ($u['expiry_date'] !== '—' && strtotime($u['expiry_date']) < time());
            ?>
            <tr data-type="<?php echo htmlspecialchars($u['type']); ?>"
                data-search="<?php echo strtolower(htmlspecialchars($u['name'] . ' ' . $u['username'] . ' ' . $u['ip'] . ' ' . $u['mac'])); ?>">
                <td>
                    <div class="oc-cust-name"><?php echo htmlspecialchars($u['name']); ?></div>
                    <div class="oc-cust-sub">
                        <?php echo htmlspecialchars($u['username']); ?>
                        <?php if ($u['phone'] !== '—'): ?>&nbsp;·&nbsp;<?php echo htmlspecialchars($u['phone']); ?><?php endif; ?>
                        <?php if ($u['package_name'] !== '—'): ?>
                        <span style="color:rgba(255,255,255,.22);">&nbsp;·&nbsp;<?php echo htmlspecialchars($u['package_name']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <span class="oc-type <?php echo $u['type']; ?>">
                        <i class="fas fa-<?php echo $u['type'] === 'pppoe' ? 'plug' : 'wifi'; ?>" style="font-size:8px;"></i>
                        <?php echo strtoupper($u['type']); ?>
                    </span>
                </td>
                <td style="font-family:monospace;font-size:12px;color:#93c5fd;"><?php echo htmlspecialchars($u['ip']); ?></td>
                <td style="font-family:monospace;font-size:11px;color:rgba(255,255,255,.35);"><?php echo htmlspecialchars($u['mac']); ?></td>
                <td class="oc-time"><?php echo htmlspecialchars($u['session_start']); ?></td>
                <td class="oc-time<?php echo $expiryExpired ? ' expired' : ''; ?>">
                    <?php echo htmlspecialchars($u['expiry_date']); ?>
                    <?php if ($expiryExpired): ?><div style="font-size:10px;color:#f87171;">Expired</div><?php endif; ?>
                </td>
                <td>
                    <div class="oc-data"><strong>↓</strong> <?php echo fmtBytes($u['rx_bytes']); ?></div>
                    <div class="oc-data"><strong>↑</strong> <?php echo fmtBytes($u['tx_bytes']); ?></div>
                </td>
                <td style="font-size:12px;color:rgba(255,255,255,.35);"><?php echo htmlspecialchars($u['router']); ?></td>
                <td>
                    <button class="oc-disc-btn"
                        onclick="ocDisconnect(this,'<?php echo htmlspecialchars(addslashes($u['username'])); ?>','<?php echo $u['type']; ?>',<?php echo (int)$u['router_id']; ?>)"
                        title="Disconnect <?php echo htmlspecialchars($u['username']); ?>">
                        <i class="fas fa-unlink" style="margin-right:4px;"></i>Disconnect
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="oc-empty">
        <i class="fas fa-satellite-dish"></i>
        <h3><?php echo empty($routers) ? 'No Routers Configured' : 'No Active Sessions'; ?></h3>
        <p><?php echo empty($routers) ? 'Add a MikroTik router under Routers first.' : 'No PPPoE or Hotspot sessions are currently active.'; ?></p>
    </div>
    <?php endif; ?>
</div>

</div><!-- .oc-page -->
</div><!-- .main-content-wrapper -->

<script>
let ocActiveFilter = 'all';

function ocSetFilter(type) {
    ocActiveFilter = type;
    document.querySelectorAll('.oc-fbtn').forEach(b => b.classList.remove('active'));
    document.getElementById('f-' + type).classList.add('active');
    ocFilter();
}

function ocFilter() {
    const q = document.getElementById('oc-s').value.toLowerCase().trim();
    let vis = 0;
    document.querySelectorAll('#oc-tbl tbody tr').forEach(row => {
        const mt = ocActiveFilter === 'all' || row.dataset.type === ocActiveFilter;
        const ms = !q || row.dataset.search.includes(q);
        row.style.display = (mt && ms) ? '' : 'none';
        if (mt && ms) vis++;
    });
    const c = document.getElementById('oc-cnt');
    if (c) c.textContent = vis + ' session' + (vis !== 1 ? 's' : '');
}

function ocToast(msg, ok) {
    const t = document.getElementById('oc-toast');
    t.textContent = msg;
    t.className = ok ? 'ok' : 'err';
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3500);
}

function ocDisconnect(btn, username, type, routerId) {
    if (!confirm('Disconnect ' + username + ' from the router?')) return;
    btn.disabled = true;
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>Disconnecting…';

    const fd = new FormData();
    fd.append('username', username);
    fd.append('type', type);
    fd.append('router_id', routerId);

    fetch('api/clients/disconnect_session.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                ocToast(d.message, true);
                const row = btn.closest('tr');
                if (row) {
                    row.style.transition = 'opacity .4s';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        row.remove();
                        ocFilter();
                    }, 400);
                }
            } else {
                ocToast(d.message || 'Disconnect failed', false);
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="fas fa-unlink" style="margin-right:4px;"></i>Disconnect';
            }
        })
        .catch(() => {
            ocToast('Network error — could not reach server', false);
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="fas fa-unlink" style="margin-right:4px;"></i>Disconnect';
        });
}
</script>

<?php include 'includes/footer.php'; ?>
