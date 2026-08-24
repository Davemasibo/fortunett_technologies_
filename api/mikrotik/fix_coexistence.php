<?php
/**
 * Repair the conflicts coexistence_check.php reports.
 *
 * Every repair here is additive or reversible — nothing that could cut an
 * operator off their own router. Specifically it will NEVER re-range an address
 * pool or delete a firewall rule it did not create; overlapping pools are
 * reported only, because silently renumbering a live subnet drops every session
 * on it.
 *
 * POST router_id, fixes (csv of check ids from coexistence_check.php)
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenantId = (int)$t->fetchColumn();

$routerId = (int)($_POST['router_id'] ?? 0);
$rq = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$rq->execute([$routerId, $tenantId]);
$router = $rq->fetch(PDO::FETCH_ASSOC);
if (!$router) { ob_clean(); echo json_encode(['success' => false, 'error' => 'Router not found']); exit; }

$wanted = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['fixes'] ?? '')))));
$all    = ['both_on_bridge', 'nat_coverage', 'dhcp_unique', 'walled_garden', 'block_rule', 'default_profile'];
if (!$wanted || in_array('all', $wanted, true)) $wanted = $all;
$wanted = array_values(array_intersect($wanted, $all));

function fx_rows(array $res): array { return array_values(array_filter($res, fn($r) => isset($r['!re']))); }

/** Smallest CIDR covering a RouterOS ranges string, e.g. "10.5.50.2-10.5.50.254" → "10.5.50.0/24". */
function fx_cidr(string $ranges): ?string {
    $min = PHP_INT_MAX; $max = 0; $seen = false;
    foreach (preg_split('/[,\s]+/', trim($ranges)) as $part) {
        if ($part === '') continue;
        if (strpos($part, '-') !== false) {
            [$a, $b] = explode('-', $part, 2);
            $sa = ip2long($a);
            if ($sa === false) continue;
            $sb = (strpos($b, '.') === false && ctype_digit($b)) ? ($sa & 0xFFFFFF00) | ((int)$b & 0xFF) : ip2long($b);
            if ($sb === false) continue;
        } else {
            $sa = $sb = ip2long(explode('/', $part)[0]);
            if ($sa === false) continue;
        }
        $min = min($min, $sa, $sb); $max = max($max, $sa, $sb); $seen = true;
    }
    if (!$seen) return null;
    for ($bits = 32; $bits >= 0; $bits--) {
        $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xFFFFFFFF);
        if (($min & $mask) === ($max & $mask)) return long2ip($min & $mask) . '/' . $bits;
    }
    return null;
}

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?: 8728);
$applied   = [];
$skipped   = [];
$failed    = [];

$sock = @fsockopen($connectIp, $port, $errno, $errstr, 4);
if (!$sock) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => "Router unreachable at $connectIp:$port — " . ($errstr ?: 'no route')]);
    exit;
}
fclose($sock);

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $api->connect();

    $bridges = fx_rows($api->comm('/interface/bridge/print'));
    $bridgeName = null;
    foreach ($bridges as $b) {
        if (($b['disabled'] ?? 'false') === 'false' && !empty($b['name'])) { $bridgeName = $b['name']; break; }
    }

    $pools = fx_rows($api->comm('/ip/pool/print'));
    $poolByName = [];
    foreach ($pools as $p) { if (!empty($p['name'])) $poolByName[$p['name']] = $p['ranges'] ?? ''; }

    $hsServers = fx_rows($api->comm('/ip/hotspot/print'));
    $ppServers = fx_rows($api->comm('/interface/pppoe-server/server/print'));
    $pppProfs  = fx_rows($api->comm('/ppp/profile/print'));

    // ── 1. Bind both servers to the bridge and enable them ────────────────────
    if (in_array('both_on_bridge', $wanted, true)) {
        if (!$bridgeName) {
            $skipped[] = 'Bind services to the bridge — no enabled bridge exists to bind to.';
        } else {
            foreach ($hsServers as $s) {
                if (empty($s['.id'])) continue;
                $need = [];
                if (($s['interface'] ?? '') !== $bridgeName) $need[] = '=interface=' . $bridgeName;
                if (($s['disabled'] ?? 'true') === 'true')   $need[] = '=disabled=no';
                if (!$need) continue;
                try {
                    $api->comm('/ip/hotspot/set', array_merge(['=.id=' . $s['.id']], $need));
                    $applied[] = "Hotspot '" . ($s['name'] ?? '?') . "' bound to {$bridgeName} and enabled.";
                } catch (Throwable $e) { $failed[] = 'Hotspot rebind: ' . $e->getMessage(); }
                break;   // only the first server — never touch an operator's extras
            }
            foreach ($ppServers as $s) {
                if (empty($s['.id'])) continue;
                $need = [];
                if (($s['interface'] ?? '') !== $bridgeName) $need[] = '=interface=' . $bridgeName;
                if (($s['disabled'] ?? 'true') === 'true')   $need[] = '=disabled=no';
                if (!$need) continue;
                try {
                    $api->comm('/interface/pppoe-server/server/set', array_merge(['=.id=' . $s['.id']], $need));
                    $applied[] = "PPPoE server '" . ($s['service-name'] ?? '?') . "' bound to {$bridgeName} and enabled.";
                } catch (Throwable $e) { $failed[] = 'PPPoE rebind: ' . $e->getMessage(); }
                break;
            }
        }
    }

    // ── 2. Masquerade every subnet in play ────────────────────────────────────
    if (in_array('nat_coverage', $wanted, true)) {
        $subnets = [];

        foreach ($hsServers as $s) {
            $pn = (string)($s['address-pool'] ?? '');
            if ($pn !== '' && $pn !== 'none' && isset($poolByName[$pn])) {
                $c = fx_cidr($poolByName[$pn]);
                if ($c) $subnets[$c] = 'hotspot';
            }
        }
        foreach ($ppServers as $s) {
            $profName = (string)($s['default-profile'] ?? '');
            foreach ($pppProfs as $pr) {
                if (($pr['name'] ?? '') !== $profName) continue;
                $remote = (string)($pr['remote-address'] ?? '');
                $ranges = $poolByName[$remote] ?? $remote;
                $c = fx_cidr((string)$ranges);
                if ($c) $subnets[$c] = 'pppoe';
                break;
            }
        }
        if (isset($poolByName['fortunett-limited-pool'])) {
            $c = fx_cidr($poolByName['fortunett-limited-pool']);
            if ($c) $subnets[$c] = 'unpaid pppoe';
        }

        $natRules = fx_rows($api->comm('/ip/firewall/nat/print'));
        foreach ($subnets as $cidr => $label) {
            $exists = false;
            foreach ($natRules as $r) {
                if (($r['chain'] ?? '') !== 'srcnat') continue;
                if (($r['action'] ?? '') !== 'masquerade' && ($r['action'] ?? '') !== 'src-nat') continue;
                if (($r['disabled'] ?? 'false') !== 'false') continue;
                $src = trim((string)($r['src-address'] ?? ''));
                if ($src === '' || $src === $cidr) { $exists = true; break; }
            }
            if ($exists) continue;
            try {
                $api->comm('/ip/firewall/nat/add', [
                    '=chain=srcnat',
                    '=src-address=' . $cidr,
                    '=action=masquerade',
                    '=comment=FortuNett-NAT-' . $cidr,
                ]);
                $applied[] = "Masquerade added for {$cidr} ({$label}) — that subnet now reaches the internet.";
            } catch (Throwable $e) {
                $failed[] = "Masquerade for {$cidr}: " . $e->getMessage();
            }
        }
    }

    // ── 3. One DHCP server per bridge ─────────────────────────────────────────
    if (in_array('dhcp_unique', $wanted, true) && $bridgeName) {
        $dhcp = fx_rows($api->comm('/ip/dhcp-server/print'));
        $onBridge = array_values(array_filter($dhcp, fn($d) =>
            ($d['interface'] ?? '') === $bridgeName && ($d['disabled'] ?? 'true') !== 'true'));

        if (count($onBridge) > 1) {
            // Keep the one whose pool is the hotspot pool; else keep 'hs-dhcp'; else keep the first.
            $hsPool = '';
            foreach ($hsServers as $s) { $hsPool = (string)($s['address-pool'] ?? ''); if ($hsPool) break; }

            $keepIdx = 0;
            foreach ($onBridge as $i => $d) {
                if ($hsPool !== '' && ($d['address-pool'] ?? '') === $hsPool) { $keepIdx = $i; break; }
                if (($d['name'] ?? '') === 'hs-dhcp') { $keepIdx = $i; }
            }
            foreach ($onBridge as $i => $d) {
                if ($i === $keepIdx || empty($d['.id'])) continue;
                try {
                    $api->comm('/ip/dhcp-server/set', ['=.id=' . $d['.id'], '=disabled=yes']);
                    $applied[] = "Disabled competing DHCP server '" . ($d['name'] ?? '?') . "' on {$bridgeName} "
                               . '(kept ' . ($onBridge[$keepIdx]['name'] ?? '?') . ').';
                } catch (Throwable $e) { $failed[] = 'Disable DHCP: ' . $e->getMessage(); }
            }
        }
    }

    // ── 4. Walled garden — add the portal IP so HTTPS/STK push works ─────────
    if (in_array('walled_garden', $wanted, true) && $hsServers) {
        $serverIp = '';
        try {
            $st = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $serverIp = $st ? (string)($st->fetchColumn() ?: '') : '';
        } catch (Throwable $_e) {}

        $portalHost = '';
        try {
            $ts = $pdo->prepare("SELECT subdomain FROM tenants WHERE id = ? LIMIT 1");
            $ts->execute([$tenantId]);
            $sub = (string)($ts->fetchColumn() ?: '');
            $platformDomain = 'fortunetttech.site';
            try {
                $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
                $pd = $pdSt ? $pdSt->fetchColumn() : null;
                if ($pd) $platformDomain = $pd;
            } catch (Throwable $_e) {}
            $portalHost = $sub ? "$sub.$platformDomain" : $platformDomain;
        } catch (Throwable $_e) {}

        if (!$serverIp && $portalHost) {
            $resolved = @gethostbyname($portalHost);
            if ($resolved && $resolved !== $portalHost && filter_var($resolved, FILTER_VALIDATE_IP)) $serverIp = $resolved;
        }

        if ($portalHost) {
            try {
                $wgHosts = fx_rows($api->comm('/ip/hotspot/walled-garden/print'));
                $have = false;
                foreach ($wgHosts as $w) { if (($w['dst-host'] ?? '') === $portalHost) { $have = true; break; } }
                if (!$have) {
                    $api->comm('/ip/hotspot/walled-garden/add', [
                        '=dst-host=' . $portalHost,
                        '=comment=FortuNett-Portal',
                    ]);
                    $applied[] = "Walled garden: allowed {$portalHost} (HTTP).";
                }
            } catch (Throwable $e) { $failed[] = 'Walled garden host: ' . $e->getMessage(); }
        }

        if ($serverIp) {
            try {
                $wgIps = fx_rows($api->comm('/ip/hotspot/walled-garden/ip/print'));
                $have  = false;
                foreach ($wgIps as $w) {
                    $d = (string)($w['dst-address'] ?? '');
                    if ($d === $serverIp || $d === $serverIp . '/32') { $have = true; break; }
                }
                if (!$have) {
                    $api->comm('/ip/hotspot/walled-garden/ip/add', [
                        '=dst-address=' . $serverIp . '/32',
                        '=action=accept',
                        '=comment=FortuNett-Portal-IP',
                    ]);
                    $applied[] = "Walled garden: allowed {$serverIp}/32 (HTTPS + M-Pesa STK push).";
                }
            } catch (Throwable $e) { $failed[] = 'Walled garden IP: ' . $e->getMessage(); }
        } elseif (in_array('walled_garden', $wanted, true)) {
            $skipped[] = 'Walled-garden IP entry — no server_external_ip in platform_settings and the portal host did not resolve. '
                       . 'Set platform_settings.server_external_ip to your VPS public IP.';
        }
    }

    // ── 5. Portal allow rule must sit above the reject rule ──────────────────
    if (in_array('block_rule', $wanted, true)) {
        try {
            $filters = fx_rows($api->comm('/ip/firewall/filter/print'));
            $blockIdx = null; $allowIdx = null; $blockId = null;
            foreach ($filters as $i => $r) {
                if (($r['comment'] ?? '') === 'FortuNett-PPPoE-Block') { $blockIdx = $i; $blockId = $r['.id'] ?? null; }
                if (($r['comment'] ?? '') === 'FortuNett-PPPoE-Allow') { $allowIdx = $i; }
            }
            if ($blockIdx !== null && $allowIdx !== null && $allowIdx > $blockIdx && $blockId) {
                // Move the reject rule to the very end so every allow precedes it
                $api->comm('/ip/firewall/filter/move', ['=numbers=' . $blockId]);
                $applied[] = 'Moved the unpaid-PPPoE reject rule below the portal allow rule.';
            }
        } catch (Throwable $e) { $failed[] = 'Reorder filter rules: ' . $e->getMessage(); }
    }

    // ── 6. Strip the invented speed off the default hotspot profile ──────────
    if (in_array('default_profile', $wanted, true)) {
        try {
            $profs = fx_rows($api->comm('/ip/hotspot/user/profile/print', ['?name=default']));
            foreach ($profs as $p) {
                if (($p['name'] ?? '') !== 'default' || empty($p['.id'])) continue;
                if (trim((string)($p['rate-limit'] ?? '')) === '') break;
                $api->comm('/ip/hotspot/user/profile/set', ['=.id=' . $p['.id'], '=rate-limit=']);
                $applied[] = "Cleared rate-limit on the 'default' hotspot profile — speeds now come from the package profile only.";
                break;
            }
        } catch (Throwable $e) { $failed[] = 'Default profile: ' . $e->getMessage(); }
    }

    $api->disconnect();

    ob_clean();
    echo json_encode([
        'success' => empty($failed),
        'applied' => $applied,
        'skipped' => $skipped,
        'failed'  => $failed,
        'message' => $applied
            ? count($applied) . ' repair' . (count($applied) === 1 ? '' : 's') . ' applied.'
            : ($failed ? 'No repairs applied — see errors.' : 'Nothing to repair.'),
    ]);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'applied' => $applied, 'failed' => $failed]);
}
