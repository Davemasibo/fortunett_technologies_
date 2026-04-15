<?php
/**
 * Temporary diagnostic — shows router IPs stored in DB and tests API connection.
 * DELETE THIS FILE after use.
 */
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { die('Not logged in'); }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();

$routers = $pdo->prepare("SELECT id, name, ip_address, username, api_port, status, last_seen FROM mikrotik_routers WHERE tenant_id = ?");
$routers->execute([$tenant_id]);
$rows = $routers->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Routers in DB</h2><table border=1 cellpadding=6>";
echo "<tr><th>ID</th><th>Name</th><th>IP (stored)</th><th>Port</th><th>Status</th><th>Last Seen</th><th>TCP Reachable?</th></tr>";
foreach ($rows as $r) {
    $port = (int)($r['api_port'] ?: 8728);
    $fp = @fsockopen($r['ip_address'], $port, $e, $es, 3);
    $reach = $fp ? '<b style="color:green">YES</b>' : '<b style="color:red">NO ('.$es.')</b>';
    if ($fp) fclose($fp);
    echo "<tr>
        <td>{$r['id']}</td>
        <td>{$r['name']}</td>
        <td>{$r['ip_address']}</td>
        <td>{$port}</td>
        <td>{$r['status']}</td>
        <td>{$r['last_seen']}</td>
        <td>{$reach}</td>
    </tr>";
}
echo "</table>";

echo "<h2>Server Info</h2>";
echo "<b>SERVER_ADDR (outbound IP):</b> " . ($_SERVER['SERVER_ADDR'] ?? 'unknown') . "<br>";
echo "<b>HTTP_HOST:</b> " . ($_SERVER['HTTP_HOST'] ?? '') . "<br>";

echo "<h2>Fix: Update Router IP</h2>";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_ip'])) {
    $newIp = trim($_POST['new_ip']);
    $rid   = (int)$_POST['router_id'];
    if (filter_var($newIp, FILTER_VALIDATE_IP) && $rid) {
        $pdo->prepare("UPDATE mikrotik_routers SET ip_address = ?, status = 'active' WHERE id = ? AND tenant_id = ?")
            ->execute([$newIp, $rid, $tenant_id]);
        echo "<p style='color:green'>Updated router $rid IP to $newIp. <a href='diag_router.php'>Reload</a></p>";
    } else {
        echo "<p style='color:red'>Invalid IP or router ID.</p>";
    }
}

foreach ($rows as $r) {
    echo "<form method='POST' style='margin:8px 0'>
        <input type='hidden' name='router_id' value='{$r['id']}'>
        Router <b>{$r['name']}</b> — new IP:
        <input type='text' name='new_ip' value='{$r['ip_address']}' size='20'>
        <button type='submit' name='fix_ip'>Update IP</button>
    </form>";
}
