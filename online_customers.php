<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
require_once 'classes/MikrotikAPI.php';
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$stmt    = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Packages for the expiry modal package-change select
$pkgSt = $pdo->prepare("SELECT id, name, price, type FROM packages WHERE tenant_id = ? ORDER BY name");
$pkgSt->execute([$tenant_id]);
$ocPackages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);

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

        // PPPoE sessions — isolated so a hotspot failure cannot hide these
        try {
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
        } catch (Exception $pppoeEx) {
            $fetchErrors[] = htmlspecialchars($router['name'] . ' [PPPoE]: ' . $pppoeEx->getMessage());
        }

        // Hotspot sessions — isolated so a PPPoE failure cannot hide these
        try {
            foreach ($mk->getActiveHotspotSessionsMap() as $uname => $d) {
                $uptimeSecs = uptimeToSeconds($d['uptime'] ?? '');
                $onlineUsers[] = [
                    'username'     => $uname,
                    'type'         => 'hotspot',
                    'ip'           => $d['address'] ?? '—',
                    'mac'          => !empty($d['mac']) ? $d['mac'] : '—',
                    'uptime'       => $d['uptime']  ?? '—',
                    'session_start'=> $uptimeSecs > 0 ? date('M d, H:i', time() - $uptimeSecs) : '—',
                    'rx_bytes'     => (int)($d['rx_byte'] ?? 0),
                    'tx_bytes'     => (int)($d['tx_byte'] ?? 0),
                    'router_id'    => $router['id'],
                    'router'       => $router['name'],
                ];
                $routerStats[$router['id']]['hotspot']++;
            }
        } catch (Exception $hsEx) {
            $fetchErrors[] = htmlspecialchars($router['name'] . ' [Hotspot]: ' . $hsEx->getMessage());
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
        SELECT c.id, c.mikrotik_username AS mk_u, c.full_name, c.name, c.phone,
               c.email, c.address, c.account_number, c.mikrotik_password,
               c.status, c.package_id, c.connection_type, c.expiry_date,
               p.name AS package_name, COALESCE(p.price, 0) AS package_price
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
    $info = $clientMap[strtolower($u['username'])] ?? null;
    $u['name']            = $info['full_name'] ?? ($info['name'] ?? $u['username']);
    $u['phone']           = $info['phone']         ?? '—';
    $u['package_name']    = $info['package_name']  ?? '—';
    $u['expiry_date_raw'] = $info['expiry_date']   ?? '';
    $u['expiry_date']     = !empty($info['expiry_date'])
        ? date('M d, Y', strtotime($info['expiry_date']))
        : '—';
    // Extra fields for modal
    $u['client_id']     = (int)($info['id']               ?? 0);
    $u['account_number']= $info['account_number']          ?? '';
    $u['mikrotik_password'] = $info['mikrotik_password']   ?? '';
    $u['status']        = $info['status']                  ?? 'active';
    $u['email']         = $info['email']                   ?? '';
    $u['address']       = $info['address']                 ?? '';
    $u['package_id']    = (int)($info['package_id']        ?? 0);
    $u['package_price'] = (float)($info['package_price']   ?? 0);
    $u['connection_type'] = $info['connection_type']       ?? $u['type'];
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
                $custJson = htmlspecialchars(json_encode([
                    'id'                => $u['client_id'],
                    'full_name'         => $u['name'],
                    'name'              => $u['name'],
                    'account_number'    => $u['account_number'],
                    'mikrotik_username' => $u['username'],
                    'mikrotik_password' => $u['mikrotik_password'],
                    'status'            => $u['status'],
                    'phone'             => $u['phone'] !== '—' ? $u['phone'] : '',
                    'email'             => $u['email'],
                    'address'           => $u['address'],
                    'package_name'      => $u['package_name'] !== '—' ? $u['package_name'] : '',
                    'package_price'     => $u['package_price'],
                    'package_id'        => $u['package_id'],
                    'connection_type'   => $u['connection_type'],
                    'expiry_date'       => $u['expiry_date_raw'],
                ], JSON_HEX_APOS | JSON_HEX_TAG), ENT_COMPAT, 'UTF-8');
            ?>
            <tr data-type="<?php echo htmlspecialchars($u['type']); ?>"
                data-search="<?php echo strtolower(htmlspecialchars($u['name'] . ' ' . $u['username'] . ' ' . $u['ip'] . ' ' . $u['mac'])); ?>"
                data-customer="<?php echo $custJson; ?>"
                style="cursor:pointer;"
                onclick="ocOpenCustomer(this)">
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
                <td onclick="event.stopPropagation()">
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

<!-- ═══════════════════════════════════════════════════════════════
     CUSTOMER DETAIL MODAL  (5 tabs — same as clients.php)
════════════════════════════════════════════════════════════════ -->
<style>
/* ── Modal tabs ───────────────────────────────────────── */
.modal-tabs { display:flex; gap:2px; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.modal-tab-btn {
    padding:10px 16px; background:transparent; border:none; font-size:13px;
    font-weight:500; color:rgba(255,255,255,.45); cursor:pointer; white-space:nowrap;
    border-bottom:2px solid transparent; transition:all .15s;
}
.modal-tab-btn.active, .modal-tab-btn:hover { color:#e2e2e0; }
.modal-tab-btn.active { border-bottom-color:var(--primary-color,#3B6EA5); color:#e2e2e0; }
.modal-tab-panel { display:none; }
.modal-tab-panel.active { display:block; }

/* ── Report stats ─────────────────────────────────────── */
.report-stat { background:#222221; border:1px solid rgba(255,255,255,.06); border-radius:8px; padding:10px 12px; }
.report-stat-label { font-size:10px; font-weight:600; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.report-stat-value { font-size:18px; font-weight:700; color:#e2e2e0; }
.report-stat-sub   { font-size:11px; color:rgba(255,255,255,.35); margin-top:3px; }

/* ── Data table inside modal ──────────────────────────── */
.modal-data-table { width:100%; border-collapse:collapse; font-size:12px; }
.modal-data-table th { padding:8px 12px; font-size:10px; font-weight:700; color:rgba(255,255,255,.3); text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid rgba(255,255,255,.07); background:rgba(255,255,255,.03); white-space:nowrap; }
.modal-data-table td { padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.04); color:rgba(255,255,255,.7); }
.modal-data-table tbody tr:last-child td { border-bottom:none; }
.modal-data-table tbody tr:hover { background:rgba(255,255,255,.02); }

/* ── Status pills ─────────────────────────────────────── */
.pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.pill.success   { background:rgba(16,185,129,.12); color:#6ee7b7; border:1px solid rgba(16,185,129,.2); }
.pill.pending   { background:rgba(251,191,36,.1); color:#fcd34d; border:1px solid rgba(251,191,36,.2); }
.pill.delivered { background:rgba(96,165,250,.1); color:#93c5fd; border:1px solid rgba(96,165,250,.2); }
.pill.failed    { background:rgba(239,68,68,.1); color:#fca5a5; border:1px solid rgba(239,68,68,.2); }
.pill.sent      { background:rgba(167,139,250,.1); color:#c4b5fd; border:1px solid rgba(167,139,250,.2); }

/* ── Expiry quick buttons ─────────────────────────────── */
.expiry-quick-btn { padding:5px 11px; background:#222221; border:1px solid rgba(255,255,255,.1); border-radius:6px; font-size:12px; color:rgba(255,255,255,.6); cursor:pointer; transition:all .15s; }
.expiry-quick-btn:hover { background:rgba(255,255,255,.07); color:#e2e2e0; border-color:rgba(255,255,255,.22); }
</style>

<!-- USER DETAIL MODAL -->
<div id="userModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1000;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#1e1e1d;width:100%;max-width:820px;border-radius:16px;max-height:92vh;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.07);display:flex;flex-direction:column;">
    <!-- HEADER -->
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-shrink:0;background:rgba(255,255,255,.02);">
        <div style="display:flex;gap:12px;align-items:center;min-width:0;">
            <div id="modalAvatar" style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:white;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.4);"></div>
            <div style="min-width:0;">
                <div style="font-size:16px;font-weight:700;color:#e2e2e0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="modalUserName"></div>
                <div style="display:flex;align-items:center;gap:8px;margin-top:3px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:700;color:var(--primary-light,#93c5fd);font-family:monospace;" id="modalAcctNum"></span>
                    <span id="modalStatusBadge"></span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-shrink:0;">
            <button onclick="openExpiryModal()" style="padding:6px 10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:12px;font-weight:500;color:#d4d4d2;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-calendar-alt" style="color:var(--primary-light,#93c5fd);"></i> Change Expiry
            </button>
            <button id="pauseSubBtn" onclick="pauseSubscription()" style="padding:6px 10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:12px;font-weight:500;color:#d4d4d2;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-pause" style="color:#fbbf24;"></i> Pause
            </button>
            <div style="position:relative;">
                <button style="padding:6px 10px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;" onclick="toggleActionsMenu()">
                    Actions <i class="fas fa-chevron-down" style="font-size:10px;"></i>
                </button>
                <div id="actionsMenu" style="display:none;position:absolute;top:100%;right:0;width:195px;background:#2a2a29;border:1px solid rgba(255,255,255,.08);border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.6);margin-top:4px;z-index:20;overflow:hidden;">
                    <a href="#" onclick="promptPayment();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-mobile-alt" style="color:#34d399;width:14px;"></i> Payment Prompt</a>
                    <a href="#" onclick="switchToTab('sms');openSMSModal(currentCustomer);return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-comment" style="color:#60a5fa;width:14px;"></i> Send SMS</a>
                    <a href="#" onclick="provisionToRouter();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-network-wired" style="color:#a78bfa;width:14px;"></i> Provision to Router</a>
                    <a href="#" onclick="verifyOnRouter();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-clipboard-check" style="color:#fcd34d;width:14px;"></i> Verify on Router</a>
                    <div style="border-top:1px solid rgba(255,255,255,.06);margin:3px 0;"></div>
                    <a href="#" onclick="ocDisconnectFromModal();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#fca5a5;text-decoration:none;font-size:13px;"><i class="fas fa-unlink" style="width:14px;"></i> Disconnect Session</a>
                </div>
            </div>
            <button onclick="closeModal()" style="padding:4px 8px;background:transparent;border:none;font-size:22px;cursor:pointer;color:rgba(255,255,255,.4);line-height:1;">&times;</button>
        </div>
    </div>
    <!-- PACKAGE INFO BAR -->
    <div style="padding:7px 20px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;color:rgba(255,255,255,.45);flex-shrink:0;" id="modalPackageInfo"></div>
    <!-- TABS -->
    <div class="modal-tabs" style="padding:0 20px;background:#1e1e1d;border-bottom:1px solid rgba(255,255,255,.06);">
        <button class="modal-tab-btn active" onclick="switchToTab('general')">General</button>
        <button class="modal-tab-btn" onclick="switchToTab('reports')">Reports</button>
        <button class="modal-tab-btn" onclick="switchToTab('payments')">Payments</button>
        <button class="modal-tab-btn" onclick="switchToTab('sms')">SMS</button>
        <button class="modal-tab-btn" onclick="switchToTab('fup')"><i class="fas fa-tachometer-alt" style="font-size:10px;margin-right:4px;"></i>FUP</button>
    </div>
    <!-- TAB CONTENT -->
    <div style="overflow-y:auto;flex:1;background:#181817;">
        <!-- GENERAL TAB -->
        <div class="modal-tab-panel active" id="tab-general" style="padding:16px 20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Account Number</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:700;color:#e2e2e0;font-family:monospace;font-size:15px;" id="infoId"></span>
                        <button onclick="copyField('infoId')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Full Name</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoName"></span>
                        <button onclick="copyField('infoName')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Username</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-size:13px;font-family:monospace;" id="infoUsername"></span>
                        <button onclick="copyField('infoUsername')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Password</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-family:monospace;">
                            <span id="pwdHidden">••••••••</span>
                            <span id="pwdValue" style="display:none;font-size:13px;"></span>
                        </span>
                        <div style="display:flex;gap:4px;flex-shrink:0;">
                            <button onclick="togglePwd()" style="color:rgba(255,255,255,.4);background:none;border:none;cursor:pointer;padding:2px 4px;"><i class="fas fa-eye" id="pwdEye" style="font-size:13px;"></i></button>
                            <button onclick="copyField('pwdValue')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                        </div>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Package</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoPackage"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Status</div>
                    <div id="infoStatus"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Phone Number</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoPhone"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Connection Type</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoType"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Connectivity</div>
                    <div id="infoOnlineStatus" style="font-weight:600;font-size:13px;">
                        <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulse-dot 1.5s ease-in-out infinite;"></span><span style="color:#6ee7b7;font-size:13px;">Online Now</span></span>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Address</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoAddress"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);grid-column:span 2;">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Expiry / Time Remaining</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoTime"></div>
                </div>
            </div>
        </div>
        <!-- REPORTS TAB -->
        <div class="modal-tab-panel" id="tab-reports" style="padding:16px 20px;">
            <div id="reportsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading analytics…</div>
            <div id="reportsContent" style="display:none;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;" id="reportStats1"></div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;" id="reportStats2"></div>
                <div style="background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:14px;margin-bottom:12px;">
                    <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.55);margin-bottom:10px;">Monthly Payments (Last 6 Months)</div>
                    <div style="height:180px;"><canvas id="clientPaymentChart"></canvas></div>
                </div>
            </div>
        </div>
        <!-- PAYMENTS TAB -->
        <div class="modal-tab-panel" id="tab-payments" style="padding:16px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:13px;font-weight:600;color:#e2e2e0;" id="paymentsTabTitle">Payments</span>
                <button onclick="openRecordPaymentForm()" style="padding:7px 14px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;"><i class="fas fa-plus"></i> Record Payment</button>
            </div>
            <div id="recordPaymentForm" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:14px;margin-bottom:12px;">
                <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.55);margin-bottom:10px;">Record a Payment</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div><label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Amount (KES) *</label><input type="number" id="rpAmount" placeholder="e.g. 1500" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;"></div>
                    <div><label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Reference / Code *</label><input type="text" id="rpReference" placeholder="e.g. QAB123456" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;"></div>
                    <div><label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Method</label><select id="rpMethod" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;background:#1c1c1b;color:#e2e2e0;"><option value="M-Pesa">M-Pesa</option><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option></select></div>
                    <div><label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Date</label><input type="datetime-local" id="rpDate" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;"></div>
                </div>
                <div style="margin-bottom:10px;"><label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Notes (optional)</label><input type="text" id="rpNotes" placeholder="Optional note" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;"></div>
                <div style="display:flex;gap:8px;">
                    <button onclick="submitRecordPayment()" style="padding:7px 16px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;">Save Payment</button>
                    <button onclick="document.getElementById('recordPaymentForm').style.display='none'" style="padding:7px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:12px;cursor:pointer;color:#d4d4d2;">Cancel</button>
                </div>
            </div>
            <div id="paymentsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
            <div id="paymentsTableWrap" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;overflow:hidden;">
                <div style="overflow-x:auto;"><table class="modal-data-table"><thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Phone</th><th>Ref / Code</th><th>Confirmed</th></tr></thead><tbody id="paymentsTableBody"></tbody></table></div>
                <div id="paymentsEmpty" style="display:none;padding:24px;text-align:center;color:rgba(255,255,255,.3);font-size:13px;">No payments recorded yet.</div>
            </div>
        </div>
        <!-- SMS TAB -->
        <div class="modal-tab-panel" id="tab-sms" style="padding:16px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:13px;font-weight:600;color:#e2e2e0;">SMS History</span>
                <button onclick="openSMSModal(currentCustomer)" style="padding:7px 14px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;"><i class="fas fa-paper-plane"></i> Send SMS</button>
            </div>
            <div id="smsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
            <div id="smsTableWrap" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;overflow:hidden;">
                <div style="overflow-x:auto;"><table class="modal-data-table"><thead><tr><th>Date</th><th>Phone</th><th>Message</th><th>Status</th></tr></thead><tbody id="smsTableBody"></tbody></table></div>
                <div id="smsEmpty" style="display:none;padding:24px;text-align:center;color:rgba(255,255,255,.3);font-size:13px;">No SMS messages sent yet.</div>
            </div>
        </div>
        <!-- FUP TAB -->
        <div class="modal-tab-panel" id="tab-fup" style="padding:16px 20px;">
            <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:7px;"><i class="fas fa-tachometer-alt" style="color:#a5b4fc;"></i> Bandwidth Policy Details</div>
            <div style="background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.18);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;"><i class="fas fa-check-circle" style="color:#34d399;font-size:16px;"></i><div><div style="font-size:12px;font-weight:700;color:#34d399;">Current State: Normal</div><div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:2px;">No bandwidth policy is currently active for this user.</div></div></div>
        </div>
    </div><!-- end scroll area -->
</div><!-- end modal card -->
</div><!-- end overlay -->

<!-- CHANGE EXPIRY MODAL -->
<div id="expiryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1050;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#1e1e1d;width:100%;max-width:500px;border-radius:16px;padding:0;box-shadow:0 32px 80px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.07);overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;background:#222221;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(251,191,36,.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-calendar-alt" style="color:#fbbf24;font-size:14px;"></i></div>
            <div style="font-size:15px;font-weight:700;color:#e2e2e0;">Change Expiry</div>
        </div>
        <button onclick="closeExpiryModal()" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:7px;width:28px;height:28px;font-size:16px;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;line-height:1;">&times;</button>
    </div>
    <div style="padding:20px;">
        <div style="margin-bottom:18px;">
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Quick Extend</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap;">
                <button onclick="applyQuickExpiry(60)" class="expiry-quick-btn">+1 Hour</button>
                <button onclick="applyQuickExpiry(720)" class="expiry-quick-btn">+12 Hours</button>
                <button onclick="applyQuickExpiry(1440)" class="expiry-quick-btn">+1 Day</button>
                <button onclick="applyQuickExpiry(10080)" class="expiry-quick-btn">+7 Days</button>
                <button onclick="applyQuickExpiry(43200)" class="expiry-quick-btn">+1 Month</button>
                <button onclick="applyQuickExpiry(129600)" class="expiry-quick-btn">+3 Months</button>
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Set Specific Date</div>
            <div style="display:flex;gap:8px;">
                <input type="datetime-local" id="expiryDateInput" style="flex:1;padding:8px 10px;background:#1c1c1b;border:1px solid rgba(255,255,255,.15);border-radius:7px;font-size:13px;color:#e2e2e0;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;box-sizing:border-box;color-scheme:dark;">
                <button onclick="applySetDate()" style="padding:8px 14px;background:var(--primary-color,#3B6EA5);color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Set Date</button>
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Change Package</div>
            <div style="display:flex;gap:8px;">
                <select id="expiryPackageSelect" style="flex:1;padding:8px 10px;background:#1c1c1b;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;color:#e2e2e0;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;">
                    <option value="" style="background:#1c1c1b;">— Keep current package —</option>
                    <?php foreach ($ocPackages as $pkg): ?>
                    <option value="<?php echo $pkg['id']; ?>" data-type="<?php echo htmlspecialchars($pkg['type']); ?>" style="background:#1c1c1b;"><?php echo htmlspecialchars($pkg['name']); ?> — KES <?php echo number_format($pkg['price']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="applyChangePackage()" style="padding:8px 14px;background:#059669;color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Apply</button>
            </div>
        </div>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px;">
            <div style="font-size:10px;font-weight:700;color:#fcd34d;text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Grace Period (added on top)</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <input type="number" id="graceHoursInput" min="0" max="720" value="0" style="width:80px;padding:7px;background:#1c1c1b;border:1px solid rgba(251,191,36,.25);border-radius:6px;font-size:13px;text-align:center;color:#fcd34d;outline:none;">
                <span style="font-size:13px;font-weight:500;color:#fcd34d;">hours of grace period</span>
            </div>
        </div>
        <div style="margin-top:14px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:7px;font-size:12px;color:rgba(255,255,255,.75);">
            Current expiry: <strong id="currentExpiryDisplay" style="color:#e2e2e0;font-size:13px;"></strong>
        </div>
    </div>
</div>
</div>

<!-- SMS MODAL -->
<div id="smsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#1e1e1d;width:100%;max-width:500px;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.6);display:flex;flex-direction:column;overflow:hidden;border:1px solid rgba(255,255,255,.07);">
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:#222221;">
        <div><div style="font-size:15px;font-weight:700;color:#e2e2e0;">Send SMS</div><div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;">To: <span id="smsCustomerName" style="color:#d4d4d2;font-weight:500;"></span></div></div>
        <button onclick="closeSMSModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:rgba(255,255,255,.4);line-height:1;padding:4px;">&times;</button>
    </div>
    <div style="padding:20px;">
        <form onsubmit="handleSendSMS(event)" id="smsForm">
            <input type="hidden" name="client_id" id="smsClientId">
            <input type="hidden" name="phone" id="smsClientPhone">
            <div style="margin-bottom:14px;"><label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.55);margin-bottom:5px;">Template</label><select id="smsTemplate" onchange="applyTemplate()" style="width:100%;padding:9px 11px;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;background:#1c1c1b;color:#e2e2e0;"><option value="">— Select a Template —</option><option value="credentials">Login Credentials</option><option value="payment">Payment Details</option><option value="alert">Service Alert</option></select></div>
            <div><label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.55);margin-bottom:5px;">Message *</label><textarea name="message" id="smsMessage" rows="5" required style="width:100%;padding:9px 11px;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;" placeholder="Type your message here…"></textarea><div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:3px;text-align:right;" id="smsCharCount">0 characters</div></div>
        </form>
    </div>
    <div style="padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;background:#222221;">
        <button type="button" onclick="closeSMSModal()" style="padding:9px 18px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:rgba(255,255,255,.6);cursor:pointer;">Cancel</button>
        <button type="submit" form="smsForm" style="padding:9px 20px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-paper-plane"></i> Send SMS</button>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
let currentCustomer = null;
let clientPaymentChart = null;

/* ── Open modal from online customers row ─────────────────── */
function ocOpenCustomer(row) {
    try {
        const raw = row.getAttribute ? row.getAttribute('data-customer') : row;
        const c = JSON.parse(raw || '{}');
        if (c.id) viewCustomer(c);
    } catch (e) { console.error('ocOpenCustomer parse error', e); }
}

/* ── Disconnect from modal Actions menu ───────────────────── */
function ocDisconnectFromModal() {
    if (!currentCustomer) return;
    document.getElementById('actionsMenu').style.display = 'none';
    const btn = { disabled: false }; // virtual btn placeholder
    ocDisconnect(null, currentCustomer.mikrotik_username, currentCustomer.connection_type,
        /* router_id not stored — fall back to a page-reload after disconnect */ 0);
}

/* ── viewCustomer ─────────────────────────────────────────── */
function viewCustomer(customerJson) {
    currentCustomer = (typeof customerJson === 'string') ? JSON.parse(customerJson) : customerJson;
    const fullName = currentCustomer.full_name || currentCustomer.name || 'Unknown';
    const acctNum  = currentCustomer.account_number || ('C' + String(currentCustomer.id).padStart(3,'0'));
    const status   = (currentCustomer.status || 'active').toLowerCase();

    const parts = fullName.trim().split(' ');
    const initials = ((parts[0]||'?')[0] + ((parts[1]||'')[0]||'')).toUpperCase();
    document.getElementById('modalAvatar').textContent = initials;
    document.getElementById('modalUserName').textContent = fullName;
    document.getElementById('modalAcctNum').textContent  = acctNum;

    const badgeStyles = { active:'background:#D1FAE5;color:#065F46;', inactive:'background:#F3F4F6;color:#6B7280;', suspended:'background:#FEF3C7;color:#92400E;', expired:'background:#FEE2E2;color:#991B1B;' };
    const badgeEl = document.getElementById('modalStatusBadge');
    if (badgeEl) {
        badgeEl.style.cssText = 'padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;' + (badgeStyles[status] || badgeStyles.active);
        badgeEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    }

    document.getElementById('infoId').textContent       = acctNum;
    document.getElementById('infoName').textContent     = fullName;
    document.getElementById('infoUsername').textContent = currentCustomer.mikrotik_username || '—';
    document.getElementById('pwdValue').textContent = currentCustomer.mikrotik_password || '(hidden)';
    document.getElementById('pwdHidden').style.display = 'inline';
    document.getElementById('pwdValue').style.display  = 'none';
    document.getElementById('pwdEye').className = 'fas fa-eye';
    document.getElementById('infoPhone').textContent   = currentCustomer.phone   || '—';
    document.getElementById('infoAddress').textContent = currentCustomer.address || '—';
    document.getElementById('infoPackage').textContent = currentCustomer.package_name || 'N/A';
    document.getElementById('infoType').textContent    = (currentCustomer.connection_type || 'PPPoE').toUpperCase();

    const sc = { active:'#D1FAE5|#065F46', inactive:'#F3F4F6|#6B7280', suspended:'#FEF3C7|#92400E', expired:'#FEE2E2|#991B1B' };
    const [bg, fg] = (sc[status] || sc.active).split('|');
    document.getElementById('infoStatus').innerHTML = `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:${bg};color:${fg};"><span style="width:6px;height:6px;border-radius:50%;background:${fg};"></span>${status.charAt(0).toUpperCase()+status.slice(1)}</span>`;

    const timeEl = document.getElementById('infoTime');
    if (currentCustomer.expiry_date) {
        const tl  = calculateTimeLeft(currentCustomer.expiry_date);
        const exp = new Date(currentCustomer.expiry_date) < new Date();
        timeEl.innerHTML = `${formatDate(currentCustomer.expiry_date)} &nbsp;<span style="color:${exp?'#EF4444':'#059669'};font-size:12px;">(${tl})</span>`;
    } else { timeEl.textContent = '—'; }

    document.getElementById('modalPackageInfo').textContent =
        'Package: ' + (currentCustomer.package_name || 'N/A') +
        ' · KES ' + parseFloat(currentCustomer.package_price||0).toLocaleString() +
        ' · Expires: ' + formatDate(currentCustomer.expiry_date);

    updatePauseBtn(status);
    switchToTab('general');
    document.getElementById('userModal').style.display = 'flex';
}

/* ── Tabs ─────────────────────────────────────────────────── */
function switchToTab(name) {
    document.querySelectorAll('.modal-tab-btn').forEach((b,i) => {
        const tabs = ['general','reports','payments','sms','fup'];
        b.classList.toggle('active', tabs[i] === name);
    });
    document.querySelectorAll('.modal-tab-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    if (name === 'reports'  && currentCustomer) loadReports(currentCustomer.id);
    if (name === 'payments' && currentCustomer) loadPayments(currentCustomer.id);
    if (name === 'sms'      && currentCustomer) loadSMSHistory(currentCustomer.id);
}

function closeModal() {
    document.getElementById('userModal').style.display = 'none';
    document.getElementById('actionsMenu').style.display = 'none';
}
function toggleActionsMenu() {
    const m = document.getElementById('actionsMenu');
    m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.getElementById('userModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

/* ── Expiry modal ─────────────────────────────────────────── */
function openExpiryModal() {
    if (!currentCustomer) return;
    const expEl = document.getElementById('currentExpiryDisplay');
    if (expEl) expEl.textContent = currentCustomer.expiry_date ? formatDate(currentCustomer.expiry_date) : 'Not set';
    document.getElementById('expiryModal').style.display = 'flex';
}
function closeExpiryModal() { document.getElementById('expiryModal').style.display = 'none'; }
document.getElementById('expiryModal').addEventListener('click', function(e) { if (e.target === this) closeExpiryModal(); });

function applyQuickExpiry(minutes) {
    if (!currentCustomer) return;
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('action', 'add_minutes');
    fd.append('minutes', minutes);
    fd.append('grace_hours', grace);
    submitExpiryChange(fd, '+' + (minutes >= 43200 ? Math.round(minutes/43200)+'mo' : minutes >= 1440 ? Math.round(minutes/1440)+'d' : Math.round(minutes/60)+'h'));
}
function applySetDate() {
    if (!currentCustomer) return;
    const dateVal = document.getElementById('expiryDateInput').value;
    if (!dateVal) { ocToast('Please select a date.', false); return; }
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id); fd.append('action', 'set_date');
    fd.append('expiry_date', dateVal); fd.append('grace_hours', grace);
    submitExpiryChange(fd, 'specific date');
}
function applyChangePackage() {
    if (!currentCustomer) return;
    const pkgId = document.getElementById('expiryPackageSelect').value;
    if (!pkgId) { ocToast('Please select a package.', false); return; }
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id); fd.append('action', 'change_package');
    fd.append('package_id', pkgId); fd.append('grace_hours', grace);
    submitExpiryChange(fd, 'package change');
}
function submitExpiryChange(fd, label) {
    fetch('api/clients/change_expiry.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                ocToast('Expiry updated (' + label + ').', true);
                currentCustomer.expiry_date = d.new_expiry;
                document.getElementById('currentExpiryDisplay').textContent = formatDate(d.new_expiry);
                const timeEl = document.getElementById('infoTime');
                if (timeEl) {
                    const tl = calculateTimeLeft(d.new_expiry);
                    const exp = new Date(d.new_expiry) < new Date();
                    timeEl.innerHTML = formatDate(d.new_expiry) + ' &nbsp;<span style="color:' + (exp?'#EF4444':'#059669') + ';font-size:12px;">(' + tl + ')</span>';
                }
                setTimeout(() => closeExpiryModal(), 1200);
            } else { ocToast('Error: ' + d.message, false); }
        })
        .catch(() => ocToast('Network error.', false));
}

/* ── Password toggle / copy ───────────────────────────────── */
function togglePwd() {
    const h = document.getElementById('pwdHidden');
    const v = document.getElementById('pwdValue');
    const eye = document.getElementById('pwdEye');
    if (v.style.display === 'none') { v.style.display='inline'; h.style.display='none'; eye.className='fas fa-eye-slash'; }
    else { v.style.display='none'; h.style.display='inline'; eye.className='fas fa-eye'; }
}
function copyField(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = el.textContent.trim();
    if (!text || text === '(hidden)') return;
    navigator.clipboard.writeText(text)
        .then(() => ocToast('Copied!', true))
        .catch(() => {
            const ta = document.createElement('textarea'); ta.value = text;
            document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
            ocToast('Copied!', true);
        });
}

/* ── Pause / resume ───────────────────────────────────────── */
function pauseSubscription() {
    if (!currentCustomer) return;
    const status = (currentCustomer.status || '').toLowerCase();
    const isPaused = status === 'suspended';
    const action = isPaused ? 'resume' : 'pause';
    if (!confirm(isPaused ? 'Resume this subscription?' : 'Pause this subscription?')) return;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id); fd.append('action', action);
    fetch('api/clients/pause_subscription.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { currentCustomer.status = d.new_status; ocToast(d.message, true); updatePauseBtn(d.new_status); }
            else ocToast('Error: ' + d.message, false);
        }).catch(() => ocToast('Network error.', false));
}
function updatePauseBtn(status) {
    const btn = document.getElementById('pauseSubBtn');
    if (!btn) return;
    const isPaused = (status||'').toLowerCase() === 'suspended';
    btn.innerHTML = isPaused ? '<i class="fas fa-play" style="color:#34d399;"></i> Resume' : '<i class="fas fa-pause" style="color:#fbbf24;"></i> Pause';
}

/* ── Payment prompt ───────────────────────────────────────── */
function promptPayment() {
    if (!currentCustomer) return;
    const phone = currentCustomer.phone || '';
    if (!phone) { ocToast('No phone number on file.', false); return; }
    const amount = currentCustomer.package_price || '1000';
    if (!confirm('Send M-Pesa STK Push to ' + phone + ' for KES ' + amount + '?')) return;
    ocToast('Initiating STK Push…', true);
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id); fd.append('phone', phone); fd.append('amount', amount);
    fetch('api/mpesa/stk_push.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { ocToast(d.success ? (d.sandbox ? 'SANDBOX: no real prompt sent.' : 'STK Push sent!') : 'STK Push failed: ' + (d.message||''), d.success); })
        .catch(() => ocToast('Network error.', false));
}

/* ── Provision / Verify ───────────────────────────────────── */
function provisionToRouter() {
    if (!currentCustomer) return;
    if (!confirm('Provision "' + (currentCustomer.full_name||currentCustomer.name) + '" to the router?')) return;
    const fd = new FormData(); fd.append('client_id', currentCustomer.id);
    ocToast('Provisioning…', true);
    fetch('api/customers/provision.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { ocToast(d.success ? 'Provisioned: ' + (d.username||'') + ' on ' + (d.router||'') : 'Provision failed: ' + d.message, d.success); })
        .catch(e => ocToast('Provisioning failed: ' + e.message, false));
}
function verifyOnRouter() {
    if (!currentCustomer) return;
    document.getElementById('actionsMenu').style.display = 'none';
    ocToast('Checking router…', true);
    fetch('api/mikrotik/verify_user.php?client_id=' + currentCustomer.id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { ocToast('Verify error: ' + d.message, false); return; }
            const lines = ['DB: ' + (d.db_ok ? '✓ Found' : '✗ Missing') + ' · Status: ' + d.db_status, 'Username: ' + (d.db_username||'(none)') + ' · Type: ' + d.db_connection];
            if (d.router_error) { lines.push('Router: ✗ ' + d.router_error); }
            else {
                lines.push('Router (' + d.router_ip + '): ' + (d.router_ok ? '✓ User exists' : '✗ User NOT found'));
                if (d.router_ok) {
                    lines.push('Profile: ' + (d.router_profile||'unknown') + ' · Expected: ' + d.expected_profile + ' · Match: ' + (d.profile_match ? '✓' : '✗'));
                    if (d.is_online) { const s = d.session; lines.push('🟢 ONLINE — IP: ' + s.address + ' · Uptime: ' + s.uptime); }
                    else lines.push('⚫ Not currently connected');
                }
            }
            alert('Router Verification — ' + (d.db_username||'unknown') + '\n\n' + lines.join('\n'));
        })
        .catch(() => ocToast('Network error.', false));
}

/* ── SMS modal ────────────────────────────────────────────── */
function openSMSModal(customer) {
    if (!customer) return;
    document.getElementById('smsClientId').value = customer.id;
    document.getElementById('smsClientPhone').value = customer.phone;
    document.getElementById('smsCustomerName').textContent = customer.full_name || customer.name;
    document.getElementById('smsMessage').value = '';
    document.getElementById('smsTemplate').value = '';
    document.getElementById('smsCharCount').textContent = '0 characters';
    document.getElementById('smsModal').style.display = 'flex';
}
function closeSMSModal() { document.getElementById('smsModal').style.display = 'none'; }
document.getElementById('smsModal').addEventListener('click', function(e) { if (e.target === this) closeSMSModal(); });
document.getElementById('smsMessage').addEventListener('input', function() {
    const cc = document.getElementById('smsCharCount'); if (cc) cc.textContent = this.value.length + ' characters';
});
function applyTemplate() {
    const t = document.getElementById('smsTemplate').value;
    const msgBox = document.getElementById('smsMessage');
    if (!currentCustomer) return;
    const name = currentCustomer.full_name || currentCustomer.name || 'Customer';
    const username = currentCustomer.mikrotik_username || '[Username]';
    const password = currentCustomer.mikrotik_password || '[Password]';
    const expiry = currentCustomer.expiry_date ? formatDate(currentCustomer.expiry_date) : '[Date]';
    const account = currentCustomer.account_number || currentCustomer.id;
    const price = currentCustomer.package_price || '0';
    const texts = {
        credentials: `Hello ${name}, your internet login:\nUsername: ${username}\nPassword: ${password}\nExpires: ${expiry}`,
        payment: `Dear ${name}, please pay KES ${price} to Paybill: 247247, Account: ${account}. Before: ${expiry}.`,
        alert: `Dear ${name}, your internet subscription expires on ${expiry}. Please renew to avoid disconnection.`
    };
    if (texts[t]) msgBox.value = texts[t];
}
function handleSendSMS(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const original = btn.textContent; btn.textContent = 'Sending...'; btn.disabled = true;
    const fd = new FormData(e.target);
    fetch('api/clients/send_sms.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { ocToast(d.success ? 'SMS sent.' : 'SMS failed: ' + (d.message||''), d.success); if (d.success) closeSMSModal(); })
        .catch(() => ocToast('Network error.', false))
        .finally(() => { btn.textContent = original; btn.disabled = false; });
}

/* ── Record payment ───────────────────────────────────────── */
function openRecordPaymentForm() {
    const f = document.getElementById('recordPaymentForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
    if (f.style.display === 'block') document.getElementById('rpDate').value = new Date().toISOString().slice(0,16);
}
function submitRecordPayment() {
    if (!currentCustomer) return;
    const amount = document.getElementById('rpAmount').value;
    const ref    = document.getElementById('rpReference').value;
    if (!amount || !ref) { ocToast('Amount and reference code are required.', false); return; }
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id); fd.append('amount', amount); fd.append('reference_code', ref);
    fd.append('method', document.getElementById('rpMethod').value);
    fd.append('transaction_date', document.getElementById('rpDate').value || new Date().toISOString().slice(0,16));
    fd.append('is_verified', '1'); fd.append('notes', document.getElementById('rpNotes').value);
    fetch('api/payments/record_manual.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { ocToast('Payment recorded.', true); document.getElementById('recordPaymentForm').style.display='none'; loadPayments(currentCustomer.id); }
            else ocToast('Error: ' + d.message, false);
        }).catch(() => ocToast('Network error.', false));
}

/* ── Reports tab ──────────────────────────────────────────── */
function loadReports(clientId) {
    const loading = document.getElementById('reportsLoading');
    const content = document.getElementById('reportsContent');
    if (!loading || !content) return;
    loading.style.display = 'block'; content.style.display = 'none';
    fetch('api/clients/reports.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed to load reports.</span>'; loading.style.display='block'; return; }
            content.style.display = 'block';
            const churnColor = { Low:'#10B981', Medium:'#F59E0B', High:'#EF4444' };
            const churnBg    = { Low:'#D1FAE5', Medium:'#FEF3C7', High:'#FEE2E2' };
            const cr = d.churn_risk || 'Low';
            const reliabilityColor = d.payment_reliability >= 80 ? '#10B981' : d.payment_reliability >= 50 ? '#F59E0B' : '#EF4444';
            document.getElementById('reportStats1').innerHTML = `
                <div class="report-stat"><div class="report-stat-label">Lifetime Value</div><div class="report-stat-value">KES ${(d.lifetime_value||0).toLocaleString()}</div><div class="report-stat-sub">${d.total_payments||0} payments</div></div>
                <div class="report-stat"><div class="report-stat-label">Avg Monthly</div><div class="report-stat-value">KES ${(d.avg_monthly||0).toLocaleString()}</div><div class="report-stat-sub">Last 6 months</div></div>
                <div class="report-stat"><div class="report-stat-label">Payment Reliability</div><div class="report-stat-value" style="color:${reliabilityColor}">${d.payment_reliability||0}%</div><div class="report-stat-sub">Months with payment / 6</div></div>`;
            const rankLabel = d.value_rank ? `#${d.value_rank} of ${d.total_clients}` : 'Unranked';
            const daysExp = d.days_to_expiry !== null ? (d.days_to_expiry < 0 ? Math.abs(d.days_to_expiry)+'d overdue' : d.days_to_expiry+'d remaining') : '—';
            document.getElementById('reportStats2').innerHTML = `
                <div class="report-stat"><div class="report-stat-label">Value Rank</div><div class="report-stat-value">${rankLabel}</div><div class="report-stat-sub">By total spend</div></div>
                <div class="report-stat"><div class="report-stat-label">Churn Risk</div><div class="report-stat-value" style="color:${churnColor[cr]}">${cr}</div><div class="report-stat-sub" style="background:${churnBg[cr]};color:${churnColor[cr]};border-radius:4px;padding:1px 6px;display:inline-block;">${d.days_since_payment !== null ? d.days_since_payment+'d since last payment' : 'No payments'}</div></div>
                <div class="report-stat"><div class="report-stat-label">Expiry</div><div class="report-stat-value" style="font-size:15px;">${daysExp}</div><div class="report-stat-sub">Account age: ${d.account_age_days||0}d</div></div>`;
            const ctx = document.getElementById('clientPaymentChart');
            if (ctx) {
                if (clientPaymentChart) clientPaymentChart.destroy();
                const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#3B6EA5';
                clientPaymentChart = new Chart(ctx, { type:'bar', data:{ labels:d.monthly_labels||[], datasets:[{ label:'KES', data:d.monthly_data||[], backgroundColor:primary, borderRadius:4 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ callback:v => 'KES '+v.toLocaleString() } } } } });
            }
        })
        .catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error loading reports.</span>'; loading.style.display='block'; });
}

/* ── Payments tab ─────────────────────────────────────────── */
function loadPayments(clientId) {
    const loading = document.getElementById('paymentsLoading');
    const wrap    = document.getElementById('paymentsTableWrap');
    const body    = document.getElementById('paymentsTableBody');
    if (!loading||!wrap||!body) return;
    loading.style.display = 'block'; wrap.style.display = 'none';
    fetch('api/clients/payments.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed.</span>'; loading.style.display='block'; return; }
            wrap.style.display = 'block';
            const payments = d.payments || [];
            document.getElementById('paymentsTabTitle').textContent = 'Payments (' + payments.length + ')';
            const empty = document.getElementById('paymentsEmpty');
            if (!payments.length) { body.innerHTML = ''; if(empty) empty.style.display='block'; return; }
            if(empty) empty.style.display = 'none';
            const methodMap = { mpesa:'M-Pesa', cash:'Cash', bank_transfer:'Bank', 'M-Pesa':'M-Pesa' };
            body.innerHTML = payments.map(p => `<tr>
                <td>${fmtShortDate(p.paid_at)}</td>
                <td>${methodMap[p.method]||p.method||'—'}</td>
                <td style="font-weight:600;">KES ${parseFloat(p.amount||0).toLocaleString()}</td>
                <td>${p.phone||'—'}</td>
                <td style="font-family:monospace;font-size:11px;">${p.mpesa_code||p.reference||'—'}</td>
                <td>${p.confirmed ? '<span class="pill success"><i class="fas fa-check"></i> Confirmed</span>' : '<span class="pill pending">Pending</span>'}</td>
            </tr>`).join('');
        }).catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error.</span>'; loading.style.display='block'; });
}

/* ── SMS history tab ──────────────────────────────────────── */
function loadSMSHistory(clientId) {
    const loading = document.getElementById('smsLoading');
    const wrap    = document.getElementById('smsTableWrap');
    const body    = document.getElementById('smsTableBody');
    if (!loading||!wrap||!body) return;
    loading.style.display = 'block'; wrap.style.display = 'none';
    fetch('api/clients/sms_history.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed.</span>'; loading.style.display='block'; return; }
            wrap.style.display = 'block';
            const msgs = d.messages || [];
            const empty = document.getElementById('smsEmpty');
            if (!msgs.length) { body.innerHTML = ''; if(empty) empty.style.display='block'; return; }
            if(empty) empty.style.display='none';
            const pillClass = { sent:'sent', delivered:'delivered', failed:'failed', pending:'pending' };
            body.innerHTML = msgs.map(m => `<tr>
                <td>${fmtShortDate(m.sent_at)}</td><td>${m.phone||'—'}</td>
                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(m.message)}">${escHtml(m.message)}</td>
                <td><span class="pill ${pillClass[m.status]||'pending'}">${ucFirst(m.status||'pending')}</span></td>
            </tr>`).join('');
        }).catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error.</span>'; loading.style.display='block'; });
}

/* ── Helpers ──────────────────────────────────────────────── */
function calculateTimeLeft(expiryDate) {
    if (!expiryDate) return 'N/A';
    const diff = new Date(expiryDate) - new Date();
    if (diff < 0) return 'Expired';
    const days = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    if (days > 0) return days + 'd ' + hours + 'h remaining';
    if (hours > 0) return hours + 'h remaining';
    return 'Expiring soon';
}
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString(undefined, { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
}
function fmtShortDate(ds) {
    if (!ds) return '—';
    const d = new Date(ds);
    return d.toLocaleDateString('en-KE', { day:'2-digit', month:'short', year:'numeric' }) + ' ' +
           d.toLocaleTimeString('en-KE', { hour:'2-digit', minute:'2-digit' });
}
function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// Close actions menu on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('#actionsMenu') && !e.target.closest('[onclick*="toggleActionsMenu"]')) {
        const am = document.getElementById('actionsMenu');
        if (am) am.style.display = 'none';
    }
});
</script>

<?php include 'includes/footer.php'; ?>
