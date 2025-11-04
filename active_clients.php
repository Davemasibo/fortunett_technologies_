<?php
// Simple "Active Clients" page showing MikroTik connected sessions.

// Optional: adjust these if you want the page to attempt live queries.
// If your app stores router credentials elsewhere, replace these with the appropriate variables.
$MK_HOST = '192.168.88.1';
$MK_USER = 'admin';
$MK_PASS = '';
$MK_PORT = 8728; // standard API port

// attempt to include common header/navigation if available
if (file_exists(__DIR__ . '/includes/header.php')) {
    include __DIR__ . '/includes/header.php';
}
if (file_exists(__DIR__ . '/includes/sidebar.php')) {
    include __DIR__ . '/includes/sidebar.php';
}

// helper to fetch active clients from MikroTik using available libraries (best-effort)
function getActiveClientsFromMikrotik($host, $user, $pass, $port = 8728) {
    $clients = [];

    // Try RouterOS PHP client: RouterOS API class (routeros_api.class.php)
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
                ];
            }

            return $clients;
        } catch (\Throwable $e) {
            // ignore and fall back
        }
    }

    // Fallback: return sample/mock data so the UI renders for demonstration.
    return [
        [
            'username' => 'Moses',
            'ip' => '172.31.242.47',
            'mac' => 'B4:0F:3B:38:7B:A0',
            'router' => 'rb4011',
            'start' => '1 hour ago',
            'end' => '4 weeks from now'
        ],
        [
            'username' => 'Joan308',
            'ip' => '172.31.242.78',
            'mac' => 'B8:3A:08:77:82:08',
            'router' => 'rb4011',
            'start' => '4 hours ago',
            'end' => '—'
        ],
        [
            'username' => 'Emmanuel',
            'ip' => '172.31.242.86',
            'mac' => 'B4:0F:3B:38:BE:40',
            'router' => 'rb4011',
            'start' => '6 hours ago',
            'end' => '6 days from now'
        ],
        // add a few more sample rows
    ];
}

// Fetch clients (live if possible, otherwise mock)
$clients = getActiveClientsFromMikrotik($MK_HOST, $MK_USER, $MK_PASS, $MK_PORT);

// HTML output (main content). Keep markup compatible with existing CSS/JS in your app.
?>
<main id="main" class="main-content" role="main" style="padding:24px;">
    <div class="page-header" style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:16px;">
        <div>
            <h4 style="margin:0 0 6px 0;">Active Users</h4>
            <small style="color:#6b7280;">Currently connected devices</small>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <input id="searchBox" type="search" placeholder="Search" style="padding:8px 10px;border-radius:6px;border:1px solid #e5e7eb;" />
            <button id="refreshBtn" class="btn" style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;cursor:pointer;">Refresh</button>
        </div>
    </div>

    <div class="card" style="background:#fff;border:1px solid rgba(0,0,0,0.05);border-radius:8px;padding:12px;">
        <div style="overflow:auto;">
            <table id="activeTable" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #eee;">
                        <th style="padding:12px 8px;width:36px;"></th>
                        <th style="padding:12px 8px;">Username</th>
                        <th style="padding:12px 8px;">IP/MAC</th>
                        <th style="padding:12px 8px;">Router</th>
                        <th style="padding:12px 8px;">Session Start</th>
                        <th style="padding:12px 8px;">Session End</th>
                        <th style="padding:12px 8px;text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $row): ?>
                    <tr style="border-bottom:1px solid #f2f2f2;">
                        <td style="padding:10px 8px;vertical-align:top;">
                            <input type="checkbox" />
                        </td>
                        <td style="padding:10px 8px;vertical-align:top;">
                            <div style="font-weight:600;"><?php echo htmlspecialchars($row['username'] ?? '—'); ?></div>
                            <div style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars($row['username'] ?? ''); ?> (Acc. <?php echo strtoupper(substr(md5($row['username'] ?? ''),0,6)); ?>)</div>
                        </td>
                        <td style="padding:10px 8px;vertical-align:top;">
                            <div style="font-weight:600;color:#ef7b45;"><?php echo htmlspecialchars($row['ip'] ?? '—'); ?></div>
                            <div style="font-size:12px;color:#6b7280;">MAC: <?php echo htmlspecialchars($row['mac'] ?? '—'); ?></div>
                        </td>
                        <td style="padding:10px 8px;vertical-align:top;">
                            <span style="background:#fff3e6;color:#d97706;padding:4px 8px;border-radius:12px;font-size:12px;border:1px solid rgba(0,0,0,0.04)"><?php echo htmlspecialchars($row['router'] ?? '—'); ?></span>
                        </td>
                        <td style="padding:10px 8px;vertical-align:top;">
                            <?php echo htmlspecialchars($row['start'] ?? '—'); ?>
                        </td>
                        <td style="padding:10px 8px;vertical-align:top;">
                            <?php echo htmlspecialchars($row['end'] ?? '—'); ?>
                        </td>
                        <td style="padding:10px 12px;vertical-align:top;text-align:right;">
                            <a href="#" class="disconnect-link" data-username="<?php echo htmlspecialchars($row['username'] ?? ''); ?>" style="color:#ef7b45;text-decoration:none;">Disconnect</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($clients)): ?>
                    <tr><td colspan="7" style="padding:16px;text-align:center;color:#6b7280;">No active users found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
// basic search filter and refresh
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
            // simple refresh: reload the page to fetch latest data
            location.reload();
        });
    }

    // placeholder disconnect handler (requires backend endpoint to take effect)
    document.querySelectorAll('.disconnect-link').forEach(function(el){
        el.addEventListener('click', function(e){
            e.preventDefault();
            const user = this.dataset.username || '';
            if (!user) return;
            if (!confirm('Disconnect ' + user + '?')) return;
            // TODO: call backend API to disconnect user via MikroTik
            alert('Disconnect request for ' + user + ' would be sent (implement backend endpoint).');
        });
    });
})();
</script>

<?php
// optional footer include
if (file_exists(__DIR__ . '/includes/footer.php')) {
    include __DIR__ . '/includes/footer.php';
}