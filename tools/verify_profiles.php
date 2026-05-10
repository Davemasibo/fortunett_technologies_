<?php
$_SERVER['HTTP_HOST'] = 'ecolandattic.fortunetttech.site';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

$routers = $pdo->query("SELECT * FROM mikrotik_routers WHERE status IN ('active','online')")->fetchAll(PDO::FETCH_ASSOC);

foreach ($routers as $r) {
    $ip = !empty($r['vpn_ip']) ? $r['vpn_ip'] : $r['ip_address'];
    echo "\n=== {$r['name']} ({$ip}) PPPoE profiles ===\n";
    try {
        $api = new MikrotikAPI($ip, $r['username'], $r['password'], (int)($r['api_port'] ?? 8728));
        $api->connect();
        foreach ($api->getPPPoEProfiles() as $p) {
            if (($p['name'] ?? '') === 'default') continue;
            printf("  %-30s rate-limit=%s\n", $p['name'], $p['rate-limit'] ?? 'NONE');
        }
        $api->disconnect();
    } catch (Throwable $e) {
        echo "  FAILED: {$e->getMessage()}\n";
    }
}
echo "\nDone.\n";
