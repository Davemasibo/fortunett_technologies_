<?php
/**
 * One-time hotspot fix script.
 * Open in browser as admin: /hotspot/apply_hotspot_fix.php
 * DELETE THIS FILE after it runs successfully.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/db_master.php';

// Must be logged in as admin
if (empty($_SESSION['user_id'])) {
    die('<pre>Not logged in. <a href="/login.php">Login</a> first, then return here.</pre>');
}

$userSt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$userSt->execute([$_SESSION['user_id']]);
$tenantId = (int)$userSt->fetchColumn();
if (!$tenantId) die('<pre>No tenant found for your account.</pre>');

// Get router
$rSt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
$rSt->execute([$tenantId]);
$router = $rSt->fetch(PDO::FETCH_ASSOC);
if (!$router) die('<pre>No active router found for your tenant.</pre>');

// Get tenant token + portal info
$tSt = $pdo->prepare("SELECT subdomain, provisioning_token FROM tenants WHERE id = ? LIMIT 1");
$tSt->execute([$tenantId]);
$tenant = $tSt->fetch(PDO::FETCH_ASSOC);

$platformDomain = 'fortunetttech.site';
try {
    $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
    $pd   = $pdSt ? $pdSt->fetchColumn() : null;
    if ($pd) $platformDomain = $pd;
} catch (Throwable $_e) {}

$sub        = $tenant['subdomain'] ?? '';
$portalHost = $sub ? "$sub.$platformDomain" : $platformDomain;
$token      = $tenant['provisioning_token'] ?? '';
$loginServeUrl = 'https://' . $portalHost . '/hotspot/login_serve.php/' . rawurlencode($token);

require_once __DIR__ . '/../classes/RouterOSAPI.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?? 8728);

echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px;'>";
echo "Router: {$router['name']} @ {$connectIp}:{$port}\n";
echo "Portal: $portalHost\n";
echo "Token:  " . substr($token, 0, 12) . "...\n\n";

$results = [];

try {
    $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $mk->connect();
    echo "[OK] Connected to router via API\n\n";

    // ── Fix 1: hotspot profile — html-directory + login-by ───────────────────
    echo "── Fix 1: hotspot profile html-directory + login-by ────────────────\n";
    $profiles = $mk->comm('/ip/hotspot/profile/print');
    $effectiveDirs = ['flash/hotspot', 'flash/flash/hotspot'];
    $fixed = 0;
    foreach ($profiles as $p) {
        if (empty($p['.id'])) continue;
        $updates = ['=.id=' . $p['.id']];
        $curDir      = trim($p['html-directory']          ?? '');
        $overrideDir = trim($p['html-directory-override'] ?? '');
        $effectiveDir = $overrideDir ?: $curDir;
        if ($effectiveDir && !in_array($effectiveDir, $effectiveDirs)) {
            $effectiveDirs[] = $effectiveDir;
        }
        $curLB  = trim($p['login-by'] ?? '');

        if ($curDir !== 'flash/hotspot' && $curDir !== 'flash/flash/hotspot') {
            $updates[] = '=html-directory=flash/hotspot';
        }
        $parts = $curLB ? array_map('trim', explode(',', $curLB)) : [];
        if (!in_array('http-pap', $parts)) $parts[] = 'http-pap';
        if (!in_array('cookie', $parts) && !in_array('http-cookie', $parts)) $parts[] = 'cookie';
        $newLB = implode(',', array_unique($parts));
        if ($newLB !== $curLB) $updates[] = '=login-by=' . $newLB;

        if (count($updates) >= 2) {
            $mk->comm('/ip/hotspot/profile/set', $updates);
            echo "  [OK] Profile '{$p['name']}' → html-directory=flash/hotspot login-by=$newLB\n";
            $fixed++;
        } else {
            echo "  [--] Profile '{$p['name']}' already correct\n";
        }
    }
    if ($fixed === 0 && empty($profiles)) echo "  [!!] No hotspot profiles found\n";

    // ── Fix 2: NAT masquerade ─────────────────────────────────────────────────
    echo "\n── Fix 2: NAT masquerade for 10.5.50.0/24 ─────────────────────────\n";
    $natRules = $mk->comm('/ip/firewall/nat/print');
    $natExists = false;
    foreach ($natRules as $r) {
        if (($r['comment'] ?? '') === 'FortuNett-Hotspot-NAT') { $natExists = true; break; }
        // Also consider an existing masquerade covering this subnet
        if (($r['action'] ?? '') === 'masquerade' && ($r['src-address'] ?? '') === '10.5.50.0/24') {
            $natExists = true; break;
        }
    }
    if (!$natExists) {
        $mk->comm('/ip/firewall/nat/add', [
            '=chain=srcnat',
            '=src-address=10.5.50.0/24',
            '=action=masquerade',
            '=comment=FortuNett-Hotspot-NAT',
        ]);
        echo "  [OK] Masquerade rule added for 10.5.50.0/24\n";
    } else {
        echo "  [--] Masquerade rule already exists\n";
    }

    // ── Fix 3: walled garden ──────────────────────────────────────────────────
    echo "\n── Fix 3: walled garden for $portalHost ─────────────────────────────\n";
    try {
        $wgList = $mk->comm('/ip/hotspot/walled-garden/print');
        $wgExists = false;
        foreach ($wgList as $wg) {
            if (($wg['dst-host'] ?? '') === $portalHost) { $wgExists = true; break; }
        }
        if (!$wgExists) {
            $mk->comm('/ip/hotspot/walled-garden/add', [
                '=dst-host=' . $portalHost,
                '=comment=FortuNett-Portal',
            ]);
            echo "  [OK] Walled garden entry added for $portalHost\n";
        } else {
            echo "  [--] Walled garden entry already exists\n";
        }
    } catch (Throwable $wgEx) {
        echo "  [!!] Walled garden error: " . $wgEx->getMessage() . "\n";
    }

    // ── Fix 4: download login.html to ALL effective html-directory paths ────────
    echo "\n── Fix 4: fetch branded login.html to all hotspot html-directory paths ──\n";
    if ($token) {
        foreach (array_unique($effectiveDirs) as $htmlDir) {
            $dstPath = rtrim($htmlDir, '/') . '/login.html';
            try {
                $fetchResp = $mk->comm('/tool/fetch', [
                    '=url='              . $loginServeUrl,
                    '=dst-path='         . $dstPath,
                    '=mode=https',
                    '=check-certificate=no',
                ]);
                $trapped = false;
                foreach ($fetchResp as $fr) {
                    if (isset($fr['!trap'])) {
                        echo "  [!!] $dstPath — fetch error: " . ($fr['message'] ?? '?') . "\n";
                        $trapped = true;
                    }
                }
                if (!$trapped) echo "  [OK] $dstPath downloaded\n";
            } catch (Throwable $fetchEx) {
                echo "  [!!] $dstPath — " . $fetchEx->getMessage() . "\n";
            }
        }
    } else {
        echo "  [!!] No provisioning token — skipped\n";
    }

    try { $mk->disconnect(); } catch (Throwable $_e) {}
    echo "\n[DONE] All fixes applied. Verify with /ip hotspot print and /ip firewall nat print\n";

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    echo "\nIf the API is unreachable, run these in WinBox/SSH terminal:\n\n";
    echo "/ip hotspot profile set [find] html-directory=flash/hotspot login-by=http-pap,cookie\n";
    echo "/ip firewall nat add chain=srcnat src-address=10.5.50.0/24 action=masquerade comment=\"FortuNett-Hotspot-NAT\"\n";
    echo "/ip hotspot walled-garden add dst-host=\"$portalHost\" comment=\"FortuNett-Portal\"\n";
    echo "/tool fetch mode=https url=\"$loginServeUrl\" dst-path=flash/hotspot/login.html check-certificate=no\n";
}

echo "</pre>";
echo "<p style='color:#888;font-size:12px;margin:10px 20px;'>Delete this file after use: <code>hotspot/apply_hotspot_fix.php</code></p>";
