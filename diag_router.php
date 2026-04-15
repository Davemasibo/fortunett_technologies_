<?php
/**
 * Temporary diagnostic — shows router IPs stored in DB and raw API test.
 * DELETE THIS FILE after use.
 */
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { die('Not logged in'); }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();

$routers = $pdo->prepare("SELECT id, name, ip_address, username, password, api_port, status, last_seen FROM mikrotik_routers WHERE tenant_id = ?");
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
$serverAddr = $_SERVER['SERVER_ADDR'] ?? 'unknown';
echo "<b>SERVER_ADDR (VPS outbound IP):</b> $serverAddr<br>";
echo "<b>HTTP_HOST:</b> " . ($_SERVER['HTTP_HOST'] ?? '') . "<br>";

// ── Raw API test ─────────────────────────────────────────────────────────────
echo "<h2>Raw API Socket Test</h2>";
foreach ($rows as $r) {
    $ip   = $r['ip_address'];
    $port = (int)($r['api_port'] ?: 8728);
    $user = $r['username'];
    $pass = $r['password'];

    echo "<h3>Router: {$r['name']} ({$ip}:{$port})</h3><pre>";

    $fp = @fsockopen($ip, $port, $errno, $errstr, 5);
    if (!$fp) {
        echo "CONNECT FAILED: $errstr ($errno)\n";
        echo "</pre>";
        continue;
    }

    echo "CONNECT: OK (TCP handshake succeeded)\n";

    // Who is the source IP for this outbound connection?
    $localName = stream_socket_get_name($fp, false);
    echo "VPS source address: $localName\n";

    stream_set_timeout($fp, 4);

    // Try reading first — some devices send a banner on connect
    $bannerCheck = fread($fp, 64);
    $meta = stream_get_meta_data($fp);
    if ($bannerCheck !== false && $bannerCheck !== '') {
        echo "BANNER received (" . strlen($bannerCheck) . " bytes): " . bin2hex($bannerCheck) . "\n";
    } elseif ($meta['timed_out']) {
        echo "No banner (timeout after 4s — router does not send first, good)\n";
    } else {
        echo "No banner (connection closed immediately after connect — router RST'd)\n";
        fclose($fp);
        echo "</pre>";
        continue;
    }

    // Now send /login sentence: /login =name=xxx =password=yyy
    function writeWord($fp, $word) {
        $len = strlen($word);
        if ($len < 0x80)      { fwrite($fp, chr($len)); }
        elseif ($len < 0x4000){ fwrite($fp, chr(($len >> 8) | 0x80) . chr($len & 0xFF)); }
        else                  { fwrite($fp, chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF)); }
        fwrite($fp, $word);
    }

    echo "SENDING: /login =name=$user =password=*** (as one sentence)\n";
    $ok  = writeWord($fp, '/login');
    $ok &= writeWord($fp, '=name=' . $user);
    $ok &= writeWord($fp, '=password=' . $pass);
    fwrite($fp, chr(0)); // end of sentence

    stream_set_timeout($fp, 4);

    // Read raw response
    $raw = '';
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $chunk = fread($fp, 256);
        if ($chunk === false || $chunk === '') {
            $m = stream_get_meta_data($fp);
            if ($m['timed_out']) { echo "READ TIMEOUT after " . round(microtime(true) - $deadline + 5, 2) . "s\n"; }
            else                 { echo "CONNECTION CLOSED by router\n"; }
            break;
        }
        $raw .= $chunk;
        if (strpos($raw, '!done') !== false || strpos($raw, '!trap') !== false) break;
    }

    if ($raw !== '') {
        echo "RAW RESPONSE (" . strlen($raw) . " bytes hex): " . bin2hex($raw) . "\n";
        echo "RAW RESPONSE (printable):  " . preg_replace('/[^\x20-\x7E]/', '.', $raw) . "\n";
        if (strpos($raw, '!done') !== false) echo "RESULT: !done — LOGIN SUCCEEDED\n";
        elseif (strpos($raw, '!trap') !== false) echo "RESULT: !trap — LOGIN FAILED (wrong credentials?)\n";
    } else {
        echo "RAW RESPONSE: empty (nothing received)\n";
    }

    fclose($fp);
    echo "</pre>";
}

// ── Fix router IP ─────────────────────────────────────────────────────────────
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
