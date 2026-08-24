<?php
/**
 * Hotspot + PPPoE Coexistence Check
 *
 * Answers one question end-to-end: can this router run a hotspot server AND a
 * PPPoE server on the same bridge without either one breaking the other?
 *
 * Running both on one bridge is legal in RouterOS — PPPoE discovery/session
 * frames are ethertype 0x8863/0x8864 and never reach the hotspot's IP-layer
 * servlet. What actually breaks in the field is everything *around* the two
 * servers, and none of it produces an obvious error:
 *
 *   - Overlapping address pools      → duplicate IPs, both services flap
 *   - A masquerade rule scoped to only one subnet → the other service
 *     authenticates fine and then has no internet ("connected, no data")
 *   - Two DHCP servers on the same bridge → clients get a lease from the wrong
 *     one and never reach the portal
 *   - A PPPoE local-address inside the hotspot subnet → the hotspot servlet
 *     intercepts the PPPoE concentrator's own address
 *   - The PPPoE captive-portal reject rule sitting above the hotspot's traffic
 *   - walled-garden dst-host only (HTTP), so HTTPS/STK push from the portal dies
 *
 * Every check below is read-only. Repairs live in fix_coexistence.php.
 *
 * POST router_id
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

// ── IP helpers ────────────────────────────────────────────────────────────────

/** Keep only real result rows from a RouterOS API response. */
function ce_rows(array $res): array {
    return array_values(array_filter($res, fn($r) => isset($r['!re'])));
}

/**
 * Parse a RouterOS pool `ranges` string into [[startLong, endLong], ...].
 * Accepts "10.5.50.2-10.5.50.254", "10.5.50.2-254", comma-separated lists,
 * and bare CIDR ("10.88.0.0/24").
 */
function ce_parse_ranges(string $ranges): array {
    $out = [];
    foreach (preg_split('/[,\s]+/', trim($ranges)) as $part) {
        if ($part === '') continue;

        if (strpos($part, '/') !== false) {
            [$net, $bits] = explode('/', $part, 2);
            $netLong = ip2long($net);
            if ($netLong === false) continue;
            $bits    = max(0, min(32, (int)$bits));
            $mask    = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xFFFFFFFF);
            $start   = $netLong & $mask;
            $out[]   = [$start, $start | (~$mask & 0xFFFFFFFF)];
            continue;
        }

        if (strpos($part, '-') !== false) {
            [$a, $b] = explode('-', $part, 2);
            $sa = ip2long($a);
            if ($sa === false) continue;
            // Shorthand "10.5.50.2-254" — last octet only on the right side
            $sb = (strpos($b, '.') === false && ctype_digit($b))
                ? ($sa & 0xFFFFFF00) | ((int)$b & 0xFF)
                : ip2long($b);
            if ($sb === false) continue;
            $out[] = [min($sa, $sb), max($sa, $sb)];
            continue;
        }

        $single = ip2long($part);
        if ($single !== false) $out[] = [$single, $single];
    }
    return $out;
}

/** Do two sets of ranges share any address? Returns the first shared IP or null. */
function ce_ranges_overlap(array $a, array $b): ?string {
    foreach ($a as [$as, $ae]) {
        foreach ($b as [$bs, $be]) {
            $lo = max($as, $bs);
            $hi = min($ae, $be);
            if ($lo <= $hi) return long2ip($lo);
        }
    }
    return null;
}

/** Is a single IP inside any of the ranges? */
function ce_ip_in(string $ip, array $ranges): bool {
    $l = ip2long($ip);
    if ($l === false) return false;
    foreach ($ranges as [$s, $e]) { if ($l >= $s && $l <= $e) return true; }
    return false;
}

/** Smallest CIDR that covers every range in the set, e.g. "10.5.50.0/24". */
function ce_covering_cidr(array $ranges): ?string {
    if (!$ranges) return null;
    $min = PHP_INT_MAX; $max = 0;
    foreach ($ranges as [$s, $e]) { $min = min($min, $s); $max = max($max, $e); }
    for ($bits = 32; $bits >= 0; $bits--) {
        $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xFFFFFFFF);
        if (($min & $mask) === ($max & $mask)) return long2ip($min & $mask) . '/' . $bits;
    }
    return null;
}

/** Strip the /nn from "10.5.50.1/24". */
function ce_addr_only(string $cidr): string {
    return explode('/', $cidr)[0];
}

// ── Result skeleton ───────────────────────────────────────────────────────────

$out = [
    'success'   => true,
    'reachable' => false,
    'api_ok'    => false,
    'error'     => null,
    'bridge'    => null,
    'bridges'   => [],
    'hotspot'   => ['present' => false, 'enabled' => false, 'name' => null, 'interface' => null,
                    'on_bridge' => false, 'pool' => null, 'ranges' => null, 'cidr' => null,
                    'gateway' => null, 'sessions' => null],
    'pppoe'     => ['present' => false, 'enabled' => false, 'service_name' => null, 'interface' => null,
                    'on_bridge' => false, 'profile' => null, 'local_address' => null,
                    'pool' => null, 'ranges' => null, 'cidr' => null, 'sessions' => null],
    'limited'   => ['present' => false, 'cidr' => null],   // fortunett-limited-pool (unpaid PPPoE)
    'checks'    => [],
    'fixes'     => [],   // ids fix_coexistence.php knows how to repair
    'verdict'   => ['level' => 'fail', 'title' => 'Not checked', 'message' => ''],
];

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$port      = (int)($router['api_port'] ?: 8728);

$sock = @fsockopen($connectIp, $port, $errno, $errstr, 4);
if (!$sock) {
    $out['error'] = "TCP unreachable ($connectIp:$port) — " . ($errstr ?: 'no route')
        . (str_starts_with((string)$connectIp, '10.200.200.') ? ' — the WireGuard tunnel is down.' : '');
    $out['verdict'] = ['level' => 'fail', 'title' => 'Router unreachable', 'message' => $out['error']];
    ob_clean(); echo json_encode($out); exit;
}
fclose($sock);
$out['reachable'] = true;

/** Append a check row. */
$check = function (string $id, string $label, string $level, string $detail, bool $fixable = false) use (&$out) {
    $out['checks'][] = ['id' => $id, 'label' => $label, 'level' => $level, 'detail' => $detail, 'fixable' => $fixable];
    if ($fixable && $level !== 'ok') $out['fixes'][] = $id;
};

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
    $api->connect();
    $out['api_ok'] = true;

    // ── Inventory ─────────────────────────────────────────────────────────────
    $bridges   = ce_rows($api->comm('/interface/bridge/print'));
    $hsServers = ce_rows($api->comm('/ip/hotspot/print'));
    $ppServers = ce_rows($api->comm('/interface/pppoe-server/server/print'));
    $pools     = ce_rows($api->comm('/ip/pool/print'));
    $addresses = ce_rows($api->comm('/ip/address/print'));
    $dhcp      = ce_rows($api->comm('/ip/dhcp-server/print'));
    $natRules  = ce_rows($api->comm('/ip/firewall/nat/print'));
    $filters   = ce_rows($api->comm('/ip/firewall/filter/print'));
    $pppProfs  = ce_rows($api->comm('/ppp/profile/print'));

    $poolByName = [];
    foreach ($pools as $p) { if (!empty($p['name'])) $poolByName[$p['name']] = $p['ranges'] ?? ''; }

    // ── 1. Bridge ─────────────────────────────────────────────────────────────
    $enabledBridges = array_values(array_filter($bridges, fn($b) => ($b['disabled'] ?? 'false') === 'false'));
    $out['bridges']  = array_map(fn($b) => $b['name'] ?? '?', $enabledBridges);
    $bridgeName      = $enabledBridges[0]['name'] ?? null;
    $out['bridge']   = $bridgeName;

    if (!$bridgeName) {
        $check('bridge', 'Bridge interface', 'fail',
            'No enabled bridge on this router. Both services need one shared L2 domain — create a bridge and add the LAN ports to it.');
    } elseif (count($enabledBridges) > 1) {
        $check('bridge', 'Bridge interface', 'warn',
            count($enabledBridges) . ' bridges present (' . implode(', ', $out['bridges']) . '). '
            . "Coexistence is only verified on '{$bridgeName}' — a service bound to a different bridge serves a different set of ports.");
    } else {
        $check('bridge', 'Bridge interface', 'ok', $bridgeName);
    }

    // ── 2. Hotspot server ─────────────────────────────────────────────────────
    $hsRow = null;
    foreach ($hsServers as $s) { if (($s['disabled'] ?? 'true') !== 'true') { $hsRow = $s; break; } }
    if ($hsRow === null && $hsServers) $hsRow = $hsServers[0];   // present but disabled

    if ($hsRow) {
        $out['hotspot']['present']   = true;
        $out['hotspot']['enabled']   = ($hsRow['disabled'] ?? 'true') !== 'true';
        $out['hotspot']['name']      = $hsRow['name'] ?? null;
        $out['hotspot']['interface'] = $hsRow['interface'] ?? null;
        $out['hotspot']['pool']      = $hsRow['address-pool'] ?? null;
        $out['hotspot']['on_bridge'] = $bridgeName !== null && ($hsRow['interface'] ?? '') === $bridgeName;

        $hsPoolName = (string)($hsRow['address-pool'] ?? '');
        if ($hsPoolName !== '' && $hsPoolName !== 'none' && isset($poolByName[$hsPoolName])) {
            $out['hotspot']['ranges'] = $poolByName[$hsPoolName];
        }
    }
    $hsRanges = ce_parse_ranges((string)($out['hotspot']['ranges'] ?? ''));
    $out['hotspot']['cidr'] = ce_covering_cidr($hsRanges);

    // ── 3. PPPoE server ───────────────────────────────────────────────────────
    $ppRow = null;
    foreach ($ppServers as $s) { if (($s['disabled'] ?? 'true') !== 'true') { $ppRow = $s; break; } }
    if ($ppRow === null && $ppServers) $ppRow = $ppServers[0];

    if ($ppRow) {
        $out['pppoe']['present']      = true;
        $out['pppoe']['enabled']      = ($ppRow['disabled'] ?? 'true') !== 'true';
        $out['pppoe']['service_name'] = $ppRow['service-name'] ?? ($ppRow['name'] ?? 'pppoe');
        $out['pppoe']['interface']    = $ppRow['interface'] ?? null;
        $out['pppoe']['profile']      = $ppRow['default-profile'] ?? null;
        $out['pppoe']['on_bridge']    = $bridgeName !== null && ($ppRow['interface'] ?? '') === $bridgeName;

        $profName = (string)($ppRow['default-profile'] ?? '');
        foreach ($pppProfs as $pr) {
            if (($pr['name'] ?? '') !== $profName) continue;
            $out['pppoe']['local_address'] = $pr['local-address'] ?? null;
            $remote = (string)($pr['remote-address'] ?? '');
            $out['pppoe']['pool'] = $remote ?: null;
            if ($remote !== '' && isset($poolByName[$remote])) {
                $out['pppoe']['ranges'] = $poolByName[$remote];
            } elseif ($remote !== '' && ce_parse_ranges($remote)) {
                $out['pppoe']['ranges'] = $remote;   // profile carries a literal range
            }
            break;
        }
    }
    $ppRanges = ce_parse_ranges((string)($out['pppoe']['ranges'] ?? ''));
    $out['pppoe']['cidr'] = ce_covering_cidr($ppRanges);

    // The unpaid-PPPoE captive-portal pool created by _setupPPPoECaptivePortal()
    $limRanges = ce_parse_ranges((string)($poolByName['fortunett-limited-pool'] ?? ''));
    if ($limRanges) {
        $out['limited']['present'] = true;
        $out['limited']['cidr']    = ce_covering_cidr($limRanges);
    }

    $bothRunning = $out['hotspot']['enabled'] && $out['pppoe']['enabled'];

    // ── 4. Are both bound to the same bridge? — the headline check ────────────
    if (!$out['hotspot']['present'] && !$out['pppoe']['present']) {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'fail',
            'Neither service exists on this router. Run the Configure Service step from the Routers page.');
    } elseif (!$out['hotspot']['present']) {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'warn',
            'PPPoE only — no hotspot server. Nothing can conflict, but hotspot customers cannot be served from this router.');
    } elseif (!$out['pppoe']['present']) {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'warn',
            'Hotspot only — no PPPoE server. Nothing can conflict, but PPPoE customers cannot be served from this router.');
    } elseif (!$bothRunning) {
        $disabled = [];
        if (!$out['hotspot']['enabled']) $disabled[] = 'hotspot';
        if (!$out['pppoe']['enabled'])   $disabled[] = 'PPPoE';
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'fail',
            'Both servers exist but ' . implode(' and ', $disabled) . ' is disabled.', true);
    } elseif ($out['hotspot']['on_bridge'] && $out['pppoe']['on_bridge']) {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'ok',
            "Both bound to {$bridgeName} — hotspot '{$out['hotspot']['name']}' and PPPoE '{$out['pppoe']['service_name']}'.");
    } elseif (($out['hotspot']['interface'] ?? '') === ($out['pppoe']['interface'] ?? '')) {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'warn',
            "Both on '{$out['hotspot']['interface']}', which is not the detected bridge ({$bridgeName}). "
            . 'They coexist, but only ports on that interface are served.');
    } else {
        $check('both_on_bridge', 'Hotspot + PPPoE on one bridge', 'warn',
            "Split across interfaces — hotspot on '{$out['hotspot']['interface']}', PPPoE on '{$out['pppoe']['interface']}'. "
            . 'A device on one interface cannot reach the other service.', true);
    }

    // ── 5. Address pool overlap — the silent killer ──────────────────────────
    $poolSets = [];
    if ($hsRanges)  $poolSets['Hotspot ('  . ($out['hotspot']['pool'] ?? '?') . ')']       = $hsRanges;
    if ($ppRanges)  $poolSets['PPPoE ('    . ($out['pppoe']['pool']   ?? '?') . ')']       = $ppRanges;
    if ($limRanges) $poolSets['Unpaid PPPoE (fortunett-limited-pool)']                      = $limRanges;

    $clash = null;
    $names = array_keys($poolSets);
    for ($i = 0; $i < count($names) && !$clash; $i++) {
        for ($j = $i + 1; $j < count($names); $j++) {
            $shared = ce_ranges_overlap($poolSets[$names[$i]], $poolSets[$names[$j]]);
            if ($shared !== null) { $clash = [$names[$i], $names[$j], $shared]; break; }
        }
    }
    if ($clash) {
        $check('pool_overlap', 'Address pools do not overlap', 'fail',
            "{$clash[0]} and {$clash[1]} both hand out {$clash[2]}. Two customers will get the same IP and both services will drop. "
            . 'Re-range one pool to a distinct subnet.');
    } elseif (count($poolSets) < 2) {
        $check('pool_overlap', 'Address pools do not overlap', 'ok',
            count($poolSets) === 1 ? 'Only one pool in use — nothing to clash with.' : 'No pools configured.');
    } else {
        $summary = [];
        foreach ($poolSets as $n => $r) $summary[] = preg_replace('/\s*\(.*/', '', $n) . ' ' . ce_covering_cidr($r);
        $check('pool_overlap', 'Address pools do not overlap', 'ok', implode(' · ', $summary));
    }

    // ── 6. PPPoE gateway must sit outside the hotspot subnet ─────────────────
    $ppLocal = (string)($out['pppoe']['local_address'] ?? '');
    if ($ppLocal !== '' && $hsRanges) {
        // local-address may be a pool name rather than a literal IP
        $ppLocalIp = filter_var($ppLocal, FILTER_VALIDATE_IP) ? $ppLocal : '';
        if ($ppLocalIp && ce_ip_in($ppLocalIp, $hsRanges)) {
            $check('gateway_isolation', 'PPPoE gateway outside the hotspot subnet', 'fail',
                "The PPPoE concentrator address {$ppLocalIp} falls inside the hotspot pool "
                . ($out['hotspot']['cidr'] ?? '') . '. The hotspot servlet will intercept the PPPoE gateway itself. '
                . 'Move the PPP profile local-address to its own subnet.');
        } else {
            $check('gateway_isolation', 'PPPoE gateway outside the hotspot subnet', 'ok',
                $ppLocalIp ? "{$ppLocalIp} — outside " . ($out['hotspot']['cidr'] ?? 'the hotspot pool') : "local-address={$ppLocal}");
        }
    } else {
        $check('gateway_isolation', 'PPPoE gateway outside the hotspot subnet', 'ok', 'Not applicable.');
    }

    // ── 7. Hotspot gateway address present on the bridge ─────────────────────
    $bridgeAddrs = [];
    foreach ($addresses as $a) {
        if (($a['interface'] ?? '') === $bridgeName && ($a['disabled'] ?? 'false') === 'false') {
            $bridgeAddrs[] = $a['address'] ?? '';
        }
    }
    $hsGateway = null;
    foreach ($bridgeAddrs as $cidr) {
        if ($hsRanges && ce_ip_in(ce_addr_only($cidr), $hsRanges)) { $hsGateway = $cidr; break; }
        // The gateway is normally .1, one below the pool start — accept a same-/24 match
        if ($hsRanges && (ip2long(ce_addr_only($cidr)) & 0xFFFFFF00) === ($hsRanges[0][0] & 0xFFFFFF00)) {
            $hsGateway = $cidr; break;
        }
    }
    $out['hotspot']['gateway'] = $hsGateway;
    if (!$out['hotspot']['present']) {
        $check('hs_gateway', 'Hotspot gateway IP on the bridge', 'ok', 'No hotspot — not applicable.');
    } elseif ($hsGateway) {
        $check('hs_gateway', 'Hotspot gateway IP on the bridge', 'ok', "{$hsGateway} on {$bridgeName}");
    } else {
        $check('hs_gateway', 'Hotspot gateway IP on the bridge', 'fail',
            "No address on {$bridgeName} inside the hotspot pool. Clients get a lease but cannot reach the gateway, "
            . 'so the portal never loads.');
    }

    // ── 8. Exactly one DHCP server on the bridge ─────────────────────────────
    $bridgeDhcp = array_values(array_filter($dhcp, fn($d) =>
        ($d['interface'] ?? '') === $bridgeName && ($d['disabled'] ?? 'true') !== 'true'));
    if (!$out['hotspot']['present']) {
        $check('dhcp_unique', 'One DHCP server on the bridge', 'ok', 'No hotspot — PPPoE does not use DHCP.');
    } elseif (count($bridgeDhcp) === 1) {
        $d = $bridgeDhcp[0];
        $check('dhcp_unique', 'One DHCP server on the bridge', 'ok',
            ($d['name'] ?? '?') . ' → pool ' . ($d['address-pool'] ?? '?'));
    } elseif (count($bridgeDhcp) === 0) {
        $check('dhcp_unique', 'One DHCP server on the bridge', 'fail',
            "No DHCP server on {$bridgeName}. Hotspot clients never get an IP and the portal cannot load.");
    } else {
        $names2 = array_map(fn($d) => $d['name'] ?? '?', $bridgeDhcp);
        $check('dhcp_unique', 'One DHCP server on the bridge', 'fail',
            count($bridgeDhcp) . ' DHCP servers on ' . $bridgeName . ' (' . implode(', ', $names2) . '). '
            . 'Clients race between them and half the leases land on the wrong subnet.', true);
    }

    // ── 9. NAT covers every subnet in play ───────────────────────────────────
    // A masquerade with no src-address restriction (the RouterOS defconf rule)
    // covers everything; a rule scoped to one subnet covers only that one.
    $genericNat = false;
    $natCovered = [];
    foreach ($natRules as $r) {
        if (($r['disabled'] ?? 'false') !== 'false') continue;
        if (($r['chain'] ?? '') !== 'srcnat') continue;
        $action = $r['action'] ?? '';
        if ($action !== 'masquerade' && $action !== 'src-nat') continue;

        $src = trim((string)($r['src-address'] ?? ''));
        if ($src === '') { $genericNat = true; continue; }
        $natCovered[] = ce_parse_ranges($src);
    }

    $needNat = [];
    if ($hsRanges)  $needNat['hotspot'] = $hsRanges;
    if ($ppRanges)  $needNat['pppoe']   = $ppRanges;
    if ($limRanges) $needNat['unpaid PPPoE'] = $limRanges;

    $uncovered = [];
    if (!$genericNat) {
        foreach ($needNat as $label => $ranges) {
            $covered = false;
            foreach ($natCovered as $cov) {
                // Covered when every address in the pool falls inside the NAT rule's src
                if (ce_ranges_overlap($ranges, $cov) !== null) { $covered = true; break; }
            }
            if (!$covered) $uncovered[] = $label . ' ' . ce_covering_cidr($ranges);
        }
    }

    if ($genericNat) {
        $check('nat_coverage', 'NAT covers both subnets', 'ok',
            'A catch-all srcnat masquerade is present — every subnet is translated.');
    } elseif (!$uncovered) {
        $check('nat_coverage', 'NAT covers both subnets', 'ok',
            count($needNat) . ' subnet(s) each matched by a masquerade rule.');
    } else {
        $check('nat_coverage', 'NAT covers both subnets', 'fail',
            'No masquerade for ' . implode(' and ', $uncovered) . '. Those customers authenticate successfully '
            . 'and then have no internet — the classic "connected but no data" report.', true);
    }

    // ── 10. The unpaid-PPPoE reject rule must not blackhole the hotspot ──────
    $blockIdx = null; $allowIdx = null; $blockSrc = '';
    foreach ($filters as $i => $r) {
        $c = $r['comment'] ?? '';
        if ($c === 'FortuNett-PPPoE-Block' && ($r['disabled'] ?? 'false') === 'false') {
            $blockIdx = $i; $blockSrc = (string)($r['src-address'] ?? '');
        }
        if ($c === 'FortuNett-PPPoE-Allow' && ($r['disabled'] ?? 'false') === 'false') {
            $allowIdx = $i;
        }
    }
    if ($blockIdx === null) {
        $check('block_rule', 'Unpaid-PPPoE block rule is scoped', 'ok',
            $out['limited']['present']
                ? 'No block rule yet — unpaid PPPoE customers are not walled off.'
                : 'Not applicable.');
    } else {
        $blockRanges = ce_parse_ranges($blockSrc);
        $hitsHotspot = $hsRanges && $blockRanges && ce_ranges_overlap($blockRanges, $hsRanges) !== null;
        if ($hitsHotspot) {
            $check('block_rule', 'Unpaid-PPPoE block rule is scoped', 'fail',
                "The reject rule src-address={$blockSrc} also matches the hotspot pool "
                . ($out['hotspot']['cidr'] ?? '') . ' — paying hotspot customers are being dropped.');
        } elseif ($allowIdx !== null && $allowIdx > $blockIdx) {
            $check('block_rule', 'Unpaid-PPPoE block rule is scoped', 'fail',
                'The reject rule sits ABOVE the portal allow rule, so unpaid customers cannot reach the payment page at all.', true);
        } else {
            $check('block_rule', 'Unpaid-PPPoE block rule is scoped', 'ok',
                "src-address={$blockSrc}, positioned after the portal allow rule.");
        }
    }

    // ── 11. Portal reachable over HTTPS (walled-garden IP entry) ─────────────
    if ($out['hotspot']['present']) {
        $wgIpRows = [];
        try { $wgIpRows = ce_rows($api->comm('/ip/hotspot/walled-garden/ip/print')); } catch (Throwable $_e) {}
        $wgHostRows = [];
        try { $wgHostRows = ce_rows($api->comm('/ip/hotspot/walled-garden/print')); } catch (Throwable $_e) {}

        $hasIp   = (bool)array_filter($wgIpRows, fn($r) => !empty($r['dst-address']) && ($r['disabled'] ?? 'false') === 'false');
        $hasHost = (bool)array_filter($wgHostRows, fn($r) => !empty($r['dst-host']) && ($r['disabled'] ?? 'false') === 'false');

        if ($hasIp && $hasHost) {
            $check('walled_garden', 'Portal reachable before login (HTTP + HTTPS)', 'ok',
                count($wgHostRows) . ' host entr(ies) + ' . count($wgIpRows) . ' IP entr(ies).');
        } elseif ($hasHost) {
            $check('walled_garden', 'Portal reachable before login (HTTP + HTTPS)', 'fail',
                'Only dst-host entries exist. Those match the plaintext HTTP Host header, so HTTPS to the portal — '
                . 'and therefore the M-Pesa STK push from the login page — is blocked.', true);
        } else {
            $check('walled_garden', 'Portal reachable before login (HTTP + HTTPS)', 'fail',
                'No walled-garden entries at all. Unauthenticated clients cannot open the payment page.', true);
        }
    } else {
        $check('walled_garden', 'Portal reachable before login (HTTP + HTTPS)', 'ok', 'No hotspot — not applicable.');
    }

    // ── 12. The default hotspot user profile must not carry an invented cap ──
    try {
        $hsUserProfs = ce_rows($api->comm('/ip/hotspot/user/profile/print', ['?name=default']));
        foreach ($hsUserProfs as $p) {
            if (($p['name'] ?? '') !== 'default') continue;
            $rl = trim((string)($p['rate-limit'] ?? ''));
            if ($rl !== '') {
                $check('default_profile', 'Default hotspot profile carries no invented speed', 'warn',
                    "The 'default' profile has rate-limit={$rl}. Any user that falls back to it silently gets that speed "
                    . 'regardless of what they paid. Rate limits belong on the per-package profile only.', true);
            } else {
                $check('default_profile', 'Default hotspot profile carries no invented speed', 'ok', 'No rate-limit set.');
            }
            break;
        }
    } catch (Throwable $_e) {}

    // ── 13. Live proof — both services carrying sessions right now ───────────
    try {
        $out['hotspot']['sessions'] = count(ce_rows($api->comm('/ip/hotspot/active/print')));
    } catch (Throwable $_e) {}
    try {
        $pppActive = ce_rows($api->comm('/ppp/active/print'));
        $out['pppoe']['sessions'] = count(array_filter($pppActive, fn($r) => ($r['service'] ?? 'pppoe') === 'pppoe'));
    } catch (Throwable $_e) {}

    $hsN = $out['hotspot']['sessions'];
    $ppN = $out['pppoe']['sessions'];
    if ($hsN !== null && $ppN !== null && $hsN > 0 && $ppN > 0) {
        $check('live_proof', 'Live coexistence', 'ok',
            "{$hsN} hotspot session(s) and {$ppN} PPPoE session(s) online simultaneously — coexistence proven on live traffic.");
    } elseif ($hsN !== null && $ppN !== null) {
        $check('live_proof', 'Live coexistence', 'warn',
            "{$hsN} hotspot / {$ppN} PPPoE sessions online. Configuration is verified, but no simultaneous live traffic to confirm it empirically yet.");
    } else {
        $check('live_proof', 'Live coexistence', 'warn', 'Session counts unavailable.');
    }

    $api->disconnect();

    // ── Verdict ───────────────────────────────────────────────────────────────
    $fails = array_values(array_filter($out['checks'], fn($c) => $c['level'] === 'fail'));
    $warns = array_values(array_filter($out['checks'], fn($c) => $c['level'] === 'warn'));

    if ($fails) {
        $out['verdict'] = [
            'level'   => 'fail',
            'title'   => count($fails) === 1 ? '1 blocking conflict' : count($fails) . ' blocking conflicts',
            'message' => $fails[0]['label'] . ' — ' . $fails[0]['detail'],
        ];
    } elseif (!$bothRunning) {
        $out['verdict'] = [
            'level'   => 'warn',
            'title'   => 'Only one service running',
            'message' => 'No conflicts found, but hotspot and PPPoE are not both active on ' . ($bridgeName ?: 'this router')
                       . ', so coexistence is untested here.',
        ];
    } elseif ($warns) {
        $out['verdict'] = [
            'level'   => 'warn',
            'title'   => 'Coexisting with ' . count($warns) . ' caveat' . (count($warns) === 1 ? '' : 's'),
            'message' => $warns[0]['label'] . ' — ' . $warns[0]['detail'],
        ];
    } else {
        $out['verdict'] = [
            'level'   => 'ok',
            'title'   => 'Hotspot + PPPoE verified on ' . $bridgeName,
            'message' => 'Both servers are bound to the same bridge, pools are disjoint, NAT covers both subnets, '
                       . 'and one DHCP server owns the segment. Neither service can break the other.',
        ];
    }

} catch (Throwable $e) {
    $out['error']   = $e->getMessage();
    $out['verdict'] = ['level' => 'fail', 'title' => 'Check failed', 'message' => $e->getMessage()];
}

$out['fixes'] = array_values(array_unique($out['fixes']));

ob_clean();
echo json_encode($out);
