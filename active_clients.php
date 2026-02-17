<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// MikroTik connection settings
$MK_HOST = '192.168.88.1';
$MK_USER = 'admin';
$MK_PASS = '';
$MK_PORT = 8728;

// Function to fetch active clients from MikroTik
function getActiveClientsFromMikrotik($host, $user, $pass, $port = 8728) {
    $clients = [];

    $apiPath = __DIR__ . '/includes/routeros_api.class.php';
    if (file_exists($apiPath)) {
        require_once $apiPath;
        $apiClass = 'RouterosAPI';
        if (class_exists($apiClass)) {
            try {
                $api = new $apiClass();
                $api->debug = false;
                if ($api->connect($host, $user, $pass, $port)) {
                    $rows = $api->comm("/ip/hotspot/active/print");
                    if (empty($rows)) {
                        $rows = $api->comm("/ip/active/print");
                    }
                    foreach ($rows as $r) {
                        $clients[] = [
                            'username' => $r['user'] ?? ($r['name'] ?? '—'),
                            'ip' => $r['address'] ?? ($r['ip'] ?? '—'),
                            'mac' => $r['mac-address'] ?? ($r['mac'] ?? ''),
                            'router' => basename($host),
                            'start' => $r['uptime'] ?? ($r['start_time'] ?? '—'),
                            'end' => $r['expires-after'] ?? ($r['session-time-left'] ?? '—'),
                            'type' => 'hotspot'
                        ];
                    }
                    // disconnect if method exists
                    if (method_exists($api, 'disconnect')) {
                        $api->disconnect();
                    }
                    return $clients;
                }
            } catch (\Throwable $e) {
                // ignore and fall back
            }
        }
    }

    // Try modern Composer package dynamically to avoid static analyzer errors
    $clientClass = '\\RouterOS\\Client';
    $queryClass  = '\\RouterOS\\Query';
    if (class_exists($clientClass) && class_exists($queryClass)) {
        try {
            $client = new $clientClass([
                'host' => $host,
                'user' => $user,
                'pass' => $pass,
                'port' => $port,
            ]);

            // build query dynamically
            $q = new $queryClass('/ip/hotspot/active/print');
            $response = $client->query($q)->read();
            if (empty($response)) {
                $q = new $queryClass('/ip/active/print');
                $response = $client->query($q)->read();
            }

            foreach ($response as $r) {
                // $r may be object (with getProperty) or array depending on lib
                if (is_object($r) && method_exists($r, 'getProperty')) {
                    $username = $r->getProperty('user') ?? ($r->getProperty('name') ?? '—');
                    $ip = $r->getProperty('address') ?? ($r->getProperty('ip') ?? '—');
                    $mac = $r->getProperty('mac-address') ?? ($r->getProperty('mac') ?? '');
                    $start = $r->getProperty('uptime') ?? '—';
                    $end = $r->getProperty('expires-after') ?? '—';
                } else {
                    $username = $r['user'] ?? ($r['name'] ?? '—');
                    $ip = $r['address'] ?? ($r['ip'] ?? '—');
                    $mac = $r['mac-address'] ?? ($r['mac'] ?? '');
                    $start = $r['uptime'] ?? '—';
                    $end = $r['expires-after'] ?? '—';
                }

                $clients[] = [
                    'username' => $username,
                    'ip' => $ip,
                    'mac' => $mac,
                    'router' => basename($host),
                    'start' => $start,
                    'end' => $end,
                    'type' => 'pppoe'
                ];
            }

            return $clients;
        } catch (\Throwable $e) {
            // ignore and fall back
        }
    }

    // Return empty array if connection fails
    return [];
}

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Fetch clients from MikroTik
$all_clients = getActiveClientsFromMikrotik($MK_HOST, $MK_USER, $MK_PASS, $MK_PORT);

// Filter by Tenant's Customers
$stmt = $db->prepare("SELECT mikrotik_username FROM clients WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$tenant_usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Normalize usernames for comparison
$tenant_usernames_lower = array_map('strtolower', $tenant_usernames);

$clients = [];
foreach ($all_clients as $client) {
    // Check if client username exists in tenant's client list
    if (in_array(strtolower($client['username']), $tenant_usernames_lower)) {
        $clients[] = $client;
    }
}

// Get filter from query parameter
$filter = $_GET['filter'] ?? 'all';

// Filter clients
$filteredClients = $clients;
if ($filter === 'hotspot') {
    $filteredClients = array_filter($clients, fn($c) => ($c['type'] ?? '') === 'hotspot');
} elseif ($filter === 'pppoe') {
    $filteredClients = array_filter($clients, fn($c) => ($c['type'] ?? '') === 'pppoe');
} elseif ($filter === 'without_expiry') {
    $filteredClients = array_filter($clients, fn($c) => ($c['end'] ?? '') === '—');
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div>
        <!-- Page Header -->
        <div style="margin-bottom: 30px;">
            <h1 style="font-size: 28px; margin: 0 0 5px 0;">Active Users</h1>
            <div style="color: #666; font-size: 14px;">Currently connected devices on the network</div>
        </div>

        <!-- Filter Tabs (improved styling without icons) -->
        <div style="display: flex; gap: 24px; margin-bottom: 24px; border-bottom: 2px solid #f3f4f6; padding-bottom: 0;">
            <a href="?filter=all" style="padding: 12px 0; border-bottom: 3px solid <?php echo $filter === 'all' ? '#667eea' : 'transparent'; ?>; color: <?php echo $filter === 'all' ? '#667eea' : '#6b7280'; ?>; text-decoration: none; font-weight: <?php echo $filter === 'all' ? '600' : '500'; ?>; font-size: 14px; transition: all 0.2s ease; white-space: nowrap;">
                All <span style="color: #999; font-size: 12px; margin-left: 6px; font-weight: 400;">(<?php echo count($clients); ?>)</span>
            </a>
            <a href="?filter=hotspot" style="padding: 12px 0; border-bottom: 3px solid <?php echo $filter === 'hotspot' ? '#667eea' : 'transparent'; ?>; color: <?php echo $filter === 'hotspot' ? '#667eea' : '#6b7280'; ?>; text-decoration: none; font-weight: <?php echo $filter === 'hotspot' ? '600' : '500'; ?>; font-size: 14px; transition: all 0.2s ease; white-space: nowrap;">
                Hotspot <span style="color: #999; font-size: 12px; margin-left: 6px; font-weight: 400;">(<?php echo count(array_filter($clients, fn($c) => ($c['type'] ?? '') === 'hotspot')); ?>)</span>
            </a>
            <a href="?filter=pppoe" style="padding: 12px 0; border-bottom: 3px solid <?php echo $filter === 'pppoe' ? '#667eea' : 'transparent'; ?>; color: <?php echo $filter === 'pppoe' ? '#667eea' : '#6b7280'; ?>; text-decoration: none; font-weight: <?php echo $filter === 'pppoe' ? '600' : '500'; ?>; font-size: 14px; transition: all 0.2s ease; white-space: nowrap;">
                PPPoE <span style="color: #999; font-size: 12px; margin-left: 6px; font-weight: 400;">(<?php echo count(array_filter($clients, fn($c) => ($c['type'] ?? '') === 'pppoe')); ?>)</span>
            </a>
            <a href="?filter=without_expiry" style="padding: 12px 0; border-bottom: 3px solid <?php echo $filter === 'without_expiry' ? '#667eea' : 'transparent'; ?>; color: <?php echo $filter === 'without_expiry' ? '#667eea' : '#6b7280'; ?>; text-decoration: none; font-weight: <?php echo $filter === 'without_expiry' ? '600' : '500'; ?>; font-size: 14px; transition: all 0.2s ease; white-space: nowrap;">
                Without Expiry <span style="color: #999; font-size: 12px; margin-left: 6px; font-weight: 400;">(<?php echo count(array_filter($clients, fn($c) => ($c['end'] ?? '') === '—')); ?>)</span>
            </a>
        </div>

        <!-- Search and Controls -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 12px;">
            <div style="position: relative; flex: 1; max-width: 300px;">
                <input id="searchBox" type="search" placeholder="Search username or IP..." style="width: 100%; padding: 8px 12px 8px 35px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: border-color 0.2s;">
                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; font-size: 13px;"></i>
            </div>
            <button id="refreshBtn" style="padding: 8px 14px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; cursor: pointer; color: #333; font-size: 14px; font-weight: 500; transition: all 0.2s; white-space: nowrap;">
                <i class="fas fa-sync-alt me-1"></i>Refresh
            </button>
        </div>

        <!-- Active Users Table -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="overflow: auto;">
                <table id="activeTable" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #eee;">
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333; width: 30px;"><input type="checkbox" /></th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333;">Username</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333;">IP/MAC</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333;">Router</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333;">Session Start</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; color: #333;">Session End</th>
                            <th style="padding: 12px 8px; text-align: right; font-weight: 600; color: #333;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredClients as $row): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s;">
                            <td style="padding: 12px 8px; text-align: left;"><input type="checkbox" /></td>
                            <td style="padding: 12px 8px; text-align: left;">
                                <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($row['username'] ?? '—'); ?></div>
                                <div style="color: #6b7280; font-size: 12px;"><?php echo htmlspecialchars($row['username'] ?? ''); ?> (Acc. <?php echo strtoupper(substr(md5($row['username'] ?? ''),0,6)); ?>)</div>
                            </td>
                            <td style="padding: 12px 8px; text-align: left;">
                                <div style="font-weight: 600; color: #667eea;"><?php echo htmlspecialchars($row['ip'] ?? '—'); ?></div>
                                <div style="font-size: 12px; color: #6b7280;">MAC: <?php echo htmlspecialchars($row['mac'] ?? '—'); ?></div>
                            </td>
                            <td style="padding: 12px 8px; text-align: left;">
                                <span style="background: #fff3e6; color: #d97706; padding: 4px 8px; border-radius: 12px; font-size: 12px; border: 1px solid rgba(0,0,0,0.04);"><?php echo htmlspecialchars($row['router'] ?? '—'); ?></span>
                            </td>
                            <td style="padding: 12px 8px; text-align: left; color: #333;">
                                <?php echo htmlspecialchars($row['start'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px 8px; text-align: left; color: #333;">
                                <?php echo htmlspecialchars($row['end'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px 8px; text-align: right;">
                                <a href="#" class="disconnect-link" data-username="<?php echo htmlspecialchars($row['username'] ?? ''); ?>" style="color: #667eea; text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s;">
                                    <i class="fas fa-power-off me-1"></i>Disconnect
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($filteredClients)): ?>
                        <tr><td colspan="7" style="padding: 32px; text-align: center; color: #6b7280;">No active users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; color: #6b7280; font-size: 13px;">
            <div>Showing <?php echo count($filteredClients) > 0 ? '1' : '0'; ?> to <?php echo count($filteredClients); ?> of <?php echo count($filteredClients); ?> results</div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span>Per page</span>
                <select style="padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 4px; cursor: pointer; font-size: 13px;">
                    <option>10</option>
                    <option>25</option>
                    <option>50</option>
                </select>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
(function(){
    const search = document.getElementById('searchBox');
    const table = document.getElementById('activeTable');
    const refresh = document.getElementById('refreshBtn');

    if (search && table) {
        search.addEventListener('input', function(){
            const q = this.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function(tr){
                const text = tr.innerText.toLowerCase();
                tr.style.display = q === '' || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    if (refresh) {
        refresh.addEventListener('click', function(){
            location.reload();
        });
    }

    document.querySelectorAll('.disconnect-link').forEach(function(el){
        el.addEventListener('click', function(e){
            e.preventDefault();
            const user = this.dataset.username || '';
            if (!user) return;
            if (!confirm('Disconnect ' + user + '?')) return;
            alert('Disconnect request for ' + user + ' would be sent (implement backend endpoint).');
        });
    });

    // Add hover effect to table rows
    table.querySelectorAll('tbody tr').forEach(function(tr){
        tr.addEventListener('mouseover', function(){
            this.style.background = '#f9fafb';
        });
        tr.addEventListener('mouseout', function(){
            this.style.background = '';
        });
    });
})();
</script>

<style>
.main-content-wrapper > div {
    width: 100%;
    max-width: 1350px;
    margin: 0 auto;
    padding: 0 40px;
}

.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid rgba(0,0,0,0.06);
}

table tbody tr:hover {
    background: #f9fafb;
}

table a {
    transition: color 0.2s ease;
}

table a:hover {
    color: #764ba2 !important;
}

@media (max-width: 900px) {
    .main-content-wrapper > div {
        padding: 0 16px;
    }
    
    div[style*="gap: 24px"] {
        gap: 16px !important;
    }
}
</style>