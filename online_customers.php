<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
require_once 'classes/MikrotikAPI.php';
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];
$stmt    = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Load all active routers for this tenant
$rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
$rSt->execute([$tenant_id]);
$routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

$onlineUsers  = [];
$routerStats  = [];
$fetchErrors  = [];

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
            $onlineUsers[] = [
                'username'  => $uname,
                'type'      => 'pppoe',
                'ip'        => $d['address'] ?? '—',
                'mac'       => $d['caller']  ?? '—',
                'uptime'    => $d['uptime']  ?? '—',
                'rx_bytes'  => (int)($d['rx_byte'] ?? 0),
                'tx_bytes'  => (int)($d['tx_byte'] ?? 0),
                'router_id' => $router['id'],
                'router'    => $router['name'],
            ];
            $routerStats[$router['id']]['pppoe']++;
        }

        foreach ($mk->getActiveHotspotSessionsMap() as $uname => $d) {
            $onlineUsers[] = [
                'username'  => $uname,
                'type'      => 'hotspot',
                'ip'        => $d['address'] ?? '—',
                'mac'       => '—',
                'uptime'    => $d['uptime']  ?? '—',
                'rx_bytes'  => (int)($d['rx_byte'] ?? 0),
                'tx_bytes'  => (int)($d['tx_byte'] ?? 0),
                'router_id' => $router['id'],
                'router'    => $router['name'],
            ];
            $routerStats[$router['id']]['hotspot']++;
        }

        $mk->disconnect();
    } catch (Exception $e) {
        $fetchErrors[] = htmlspecialchars($router['name'] . ': ' . $e->getMessage());
    }
}

// Enrich with DB client info (name, phone, package)
$clientMap = [];
if (!empty($onlineUsers)) {
    $unames       = array_unique(array_column($onlineUsers, 'username'));
    $placeholders = implode(',', array_fill(0, count($unames), '?'));
    $cSt = $pdo->prepare("
        SELECT c.username AS mk_username, c.name, c.phone, p.name AS package_name
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.mikrotik_username IN ($placeholders) AND c.tenant_id = ?
    ");
    $cSt->execute(array_merge($unames, [$tenant_id]));
    foreach ($cSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $clientMap[strtolower($row['mk_username'])] = $row;
    }
}

foreach ($onlineUsers as &$u) {
    $info = $clientMap[$u['username']] ?? null;
    $u['name']         = $info['name']         ?? $u['username'];
    $u['phone']        = $info['phone']        ?? '—';
    $u['package_name'] = $info['package_name'] ?? '—';
}
unset($u);

function fmtBytes(int $b): string {
    if ($b < 1024)        return $b . ' B';
    if ($b < 1048576)     return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824)  return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}

include 'includes/header.php';
?>

<style>
.oc-page { padding: 28px 32px; }
.oc-page-title { font-size: 24px; font-weight: 700; color: #1f2937; margin: 0 0 4px; }
.oc-page-sub   { font-size: 14px; color: #6B7280; margin: 0 0 24px; }

/* Summary pills */
.oc-summary { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.oc-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 22px;
    font-size: 13px; font-weight: 500;
    background: #fff; border: 1px solid #E5E7EB;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.oc-pill-dot { width: 8px; height: 8px; border-radius: 50%; }
.oc-pill-dot.green  { background: #10B981; }
.oc-pill-dot.blue   { background: #3B82F6; }
.oc-pill-dot.purple { background: #8B5CF6; }
.oc-pill strong { color: #111827; }

/* Router stats row */
.oc-routers { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.oc-router-card {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-radius: 10px;
    background: #fff; border: 1px solid #E5E7EB;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
    font-size: 13px;
}
.oc-r-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
}
.oc-r-icon.on  { background: rgba(16,185,129,.1); color: #10B981; }
.oc-r-icon.off { background: rgba(239,68,68,.08); color: #EF4444; }
.oc-r-name { font-weight: 600; color: #1f2937; }
.oc-r-ip   { font-size: 11px; color: #6B7280; }
.oc-r-counts { margin-left: 6px; display: flex; gap: 8px; font-size: 12px; color: #6B7280; }
.oc-r-counts span strong { color: #374151; }
.oc-r-badge {
    padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-left: 6px;
}
.oc-r-badge.on  { background: rgba(16,185,129,.1); color: #059669; border: 1px solid rgba(16,185,129,.2); }
.oc-r-badge.off { background: rgba(239,68,68,.08); color: #DC2626; border: 1px solid rgba(239,68,68,.15); }

/* Search / filter bar */
.oc-toolbar {
    display: flex; gap: 12px; flex-wrap: wrap;
    margin-bottom: 16px; align-items: center;
}
.oc-search {
    flex: 1; min-width: 200px; max-width: 340px;
    padding: 8px 12px 8px 36px; border-radius: 8px;
    border: 1px solid #E5E7EB; font-size: 13px;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239CA3AF' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='M21 21l-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
    outline: none; color: #374151;
}
.oc-search:focus { border-color: var(--primary-color, #3B6EA5); }
.oc-filter-btn {
    padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 600;
    border: 1px solid #E5E7EB; background: #fff; color: #6B7280; cursor: pointer;
    transition: all .15s;
}
.oc-filter-btn.active, .oc-filter-btn:hover {
    background: var(--primary-color, #3B6EA5); color: #fff; border-color: var(--primary-color, #3B6EA5);
}
.oc-count-badge {
    margin-left: auto; font-size: 12px; color: #6B7280;
}

/* Main table card */
.oc-card {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.oc-table-wrap { overflow-x: auto; }
.oc-table { width: 100%; border-collapse: collapse; min-width: 700px; }
.oc-table thead { background: #F9FAFB; border-bottom: 1px solid #E5E7EB; }
.oc-table th {
    padding: 11px 16px; text-align: left;
    font-size: 11px; font-weight: 700; color: #9CA3AF;
    text-transform: uppercase; letter-spacing: .05em; white-space: nowrap;
}
.oc-table td {
    padding: 14px 16px; border-bottom: 1px solid #F3F4F6;
    font-size: 13px; color: #374151; vertical-align: middle;
}
.oc-table tbody tr:last-child td { border-bottom: none; }
.oc-table tbody tr:hover { background: #F9FAFB; }

/* Type badge */
.type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
}
.type-badge.pppoe   { background: rgba(59,130,246,.08); color: #1D4ED8; border: 1px solid rgba(59,130,246,.2); }
.type-badge.hotspot { background: rgba(139,92,246,.08); color: #6D28D9; border: 1px solid rgba(139,92,246,.2); }

/* Data usage */
.data-usage { font-size: 12px; color: #9CA3AF; }
.data-usage strong { color: #374151; }

/* Empty / error */
.oc-empty { text-align: center; padding: 64px 24px; color: #9CA3AF; }
.oc-empty i { font-size: 40px; display: block; margin-bottom: 14px; }
.oc-empty h3 { font-size: 17px; font-weight: 600; color: #6B7280; margin-bottom: 6px; }
.oc-empty p  { font-size: 13px; }

.oc-error { margin-bottom: 16px; }
.oc-error-item {
    padding: 10px 14px; border-radius: 8px;
    background: rgba(239,68,68,.05); border: 1px solid rgba(239,68,68,.15);
    font-size: 12px; color: #DC2626; margin-bottom: 6px;
}

@media (max-width: 768px) {
    .oc-page { padding: 16px; }
    .oc-routers { flex-direction: column; }
    .oc-toolbar { flex-direction: column; align-items: stretch; }
    .oc-search { max-width: 100%; }
}
</style>

<div class="oc-page">
    <h1 class="oc-page-title"><i class="fas fa-circle" style="color:#10B981;font-size:14px;margin-right:8px;"></i>Online Customers</h1>
    <p class="oc-page-sub">Live sessions fetched directly from your MikroTik routers</p>

    <?php if (!empty($fetchErrors)): ?>
    <div class="oc-error">
        <?php foreach ($fetchErrors as $err): ?>
        <div class="oc-error-item"><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i><?php echo $err; ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Summary pills -->
    <?php
    $totalOnline  = count($onlineUsers);
    $pppoeCount   = count(array_filter($onlineUsers, fn($u) => $u['type'] === 'pppoe'));
    $hotspotCount = count(array_filter($onlineUsers, fn($u) => $u['type'] === 'hotspot'));
    ?>
    <div class="oc-summary">
        <div class="oc-pill">
            <div class="oc-pill-dot green"></div>
            <strong><?php echo $totalOnline; ?></strong> Total Online
        </div>
        <div class="oc-pill">
            <div class="oc-pill-dot blue"></div>
            <strong><?php echo $pppoeCount; ?></strong> PPPoE
        </div>
        <div class="oc-pill">
            <div class="oc-pill-dot purple"></div>
            <strong><?php echo $hotspotCount; ?></strong> Hotspot
        </div>
        <div class="oc-pill" style="margin-left:auto;">
            <i class="fas fa-sync-alt" style="color:#9CA3AF;font-size:12px;cursor:pointer;" title="Refresh page" onclick="location.reload()"></i>
            Last updated: <?php echo date('H:i:s'); ?>
        </div>
    </div>

    <!-- Per-router status -->
    <?php if (!empty($routerStats)): ?>
    <div class="oc-routers">
        <?php foreach ($routerStats as $rs): ?>
        <div class="oc-router-card">
            <div class="oc-r-icon <?php echo $rs['online'] ? 'on' : 'off'; ?>">
                <i class="fas fa-server"></i>
            </div>
            <div>
                <div class="oc-r-name"><?php echo htmlspecialchars($rs['name']); ?></div>
                <div class="oc-r-ip"><?php echo htmlspecialchars($rs['ip']); ?></div>
            </div>
            <div class="oc-r-counts">
                <span title="PPPoE"><i class="fas fa-plug" style="font-size:10px;margin-right:3px;"></i><strong><?php echo $rs['pppoe']; ?></strong></span>
                <span title="Hotspot"><i class="fas fa-wifi" style="font-size:10px;margin-right:3px;"></i><strong><?php echo $rs['hotspot']; ?></strong></span>
            </div>
            <span class="oc-r-badge <?php echo $rs['online'] ? 'on' : 'off'; ?>">
                <?php echo $rs['online'] ? 'Online' : 'Offline'; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Search + filter -->
    <div class="oc-toolbar">
        <input type="text" class="oc-search" id="oc-search" placeholder="Search by name, username, IP, MAC…" oninput="filterTable()">
        <button class="oc-filter-btn active" id="filter-all"     onclick="setFilter('all')">All</button>
        <button class="oc-filter-btn"        id="filter-pppoe"   onclick="setFilter('pppoe')">PPPoE</button>
        <button class="oc-filter-btn"        id="filter-hotspot" onclick="setFilter('hotspot')">Hotspot</button>
        <span class="oc-count-badge" id="oc-count"><?php echo $totalOnline; ?> session<?php echo $totalOnline !== 1 ? 's' : ''; ?></span>
    </div>

    <!-- Table -->
    <div class="oc-card">
        <?php if (!empty($onlineUsers)): ?>
        <div class="oc-table-wrap">
            <table class="oc-table" id="oc-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>IP Address</th>
                        <th>MAC / Caller-ID</th>
                        <th>Uptime</th>
                        <th>Data (↓/↑)</th>
                        <th>Router</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($onlineUsers as $u): ?>
                <tr data-type="<?php echo htmlspecialchars($u['type']); ?>"
                    data-search="<?php echo strtolower(htmlspecialchars($u['name'] . ' ' . $u['username'] . ' ' . $u['ip'] . ' ' . $u['mac'])); ?>">
                    <td>
                        <div style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($u['name']); ?></div>
                        <div style="font-size:11px;color:#9CA3AF;margin-top:2px;">
                            <?php echo htmlspecialchars($u['username']); ?>
                            <?php if ($u['phone'] !== '—'): ?>&nbsp;·&nbsp;<?php echo htmlspecialchars($u['phone']); ?><?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge <?php echo $u['type']; ?>">
                            <i class="fas fa-<?php echo $u['type'] === 'pppoe' ? 'plug' : 'wifi'; ?>" style="font-size:9px;"></i>
                            <?php echo strtoupper($u['type']); ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($u['ip']); ?></td>
                    <td style="font-family:monospace;font-size:12px;color:#6B7280;"><?php echo htmlspecialchars($u['mac']); ?></td>
                    <td style="font-size:12px;color:#6B7280;"><?php echo htmlspecialchars($u['uptime']); ?></td>
                    <td>
                        <div class="data-usage">
                            <strong>↓</strong> <?php echo fmtBytes($u['rx_bytes']); ?>
                            &nbsp;&nbsp;<strong>↑</strong> <?php echo fmtBytes($u['tx_bytes']); ?>
                        </div>
                    </td>
                    <td style="font-size:12px;color:#6B7280;"><?php echo htmlspecialchars($u['router']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="oc-empty">
            <i class="fas fa-satellite-dish"></i>
            <h3><?php echo empty($routers) ? 'No Routers Configured' : 'No Active Sessions'; ?></h3>
            <p><?php echo empty($routers) ? 'Add a MikroTik router in the Routers section first.' : 'There are currently no active PPPoE or Hotspot sessions on any router.'; ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let activeFilter = 'all';

function setFilter(type) {
    activeFilter = type;
    document.querySelectorAll('.oc-filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('filter-' + type).classList.add('active');
    filterTable();
}

function filterTable() {
    const q   = document.getElementById('oc-search').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#oc-table tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const matchType   = activeFilter === 'all' || row.dataset.type === activeFilter;
        const matchSearch = !q || row.dataset.search.includes(q);
        const show = matchType && matchSearch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const badge = document.getElementById('oc-count');
    if (badge) badge.textContent = visible + ' session' + (visible !== 1 ? 's' : '');
}
</script>

<?php include 'includes/footer.php'; ?>
