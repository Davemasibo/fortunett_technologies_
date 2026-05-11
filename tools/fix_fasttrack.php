<?php
/**
 * tools/fix_fasttrack.php
 * Disables FastTrack on RB4011 and kicks all PPPoE sessions.
 * Run once on the server: php tools/fix_fasttrack.php
 */
$_SERVER['HTTP_HOST'] = 'ecolandattic.fortunetttech.site';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

// Find RB4011 in DB
$router = $pdo->query("SELECT * FROM mikrotik_routers WHERE name LIKE '%RB4011%' OR name LIKE '%rb4011%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$router) {
    // fallback: find by VPN IP
    $router = $pdo->query("SELECT * FROM mikrotik_routers WHERE vpn_ip='10.200.200.8' OR ip_address='10.200.200.8' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
if (!$router) { die("RB4011 not found in database.\n"); }

$ip   = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$api  = new MikrotikAPI($ip, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
$api->connect();
echo "Connected to {$router['name']} ({$ip})\n\n";

// ── Step 1: Disable FastTrack rule ────────────────────────────────────────────
echo "Step 1: Finding FastTrack firewall rule...\n";
$rules = $api->comm('/ip/firewall/filter/print');
$ftId  = null;
foreach ($rules as $r) {
    if (!isset($r['!re'])) continue;
    if (($r['action'] ?? '') === 'fasttrack-connection') {
        $ftId = $r['.id'];
        echo "  Found: .id={$ftId}  comment=" . ($r['comment'] ?? '') . "\n";
        break;
    }
}

if ($ftId) {
    $api->comm('/ip/firewall/filter/set', ['=.id=' . $ftId, '=disabled=yes']);
    echo "  FastTrack rule DISABLED.\n\n";
} else {
    echo "  No FastTrack rule found (may already be disabled).\n\n";
}

// ── Step 2: Kick all active PPPoE sessions ────────────────────────────────────
echo "Step 2: Kicking all active PPPoE sessions...\n";
$sessions = $api->comm('/ppp/active/print');
$kicked   = 0;
foreach ($sessions as $s) {
    if (!isset($s['!re'])) continue;
    $sid  = $s['.id'] ?? null;
    $name = $s['name'] ?? ($s['user'] ?? '?');
    if (!$sid) continue;
    $api->comm('/ppp/active/remove', ['=.id=' . $sid]);
    echo "  Kicked: {$name}\n";
    $kicked++;
}
echo "\n  Total kicked: {$kicked}\n\n";

$api->disconnect();
echo "Done. Test your speed now — all clients will reconnect with rate-limits enforced.\n";
echo "Monitor CPU: check /system/resource/print in the router terminal.\n";
