<?php
/** Evaluate live RouterOS evidence without treating unreadable data as healthy. */
function hotspotDiagnosticSnapshot(array $data, string $portalHost, array $portalIps): array
{
    $checks = [];
    $add = function ($id, $label, $level, $detail, $action = null) use (&$checks) {
        $checks[] = compact('id', 'label', 'level', 'detail', 'action');
    };
    $enabled = fn($r) => !in_array($r['disabled'] ?? 'false', ['true', 'yes'], true);
    $servers = array_values(array_filter($data['servers'] ?? [], $enabled));
    $server = $servers[0] ?? null;
    if (count($servers) > 1) $add('multiple_servers', 'Multiple hotspot servers', 'warn', 'Primary checks cover ' . ($server['name'] ?? 'the first server') . '. Review every additional hotspot server on Routers.');
    if (!array_key_exists('servers', $data)) {
        $add('server', 'Hotspot server', 'warn', 'Could not read hotspot servers.', 'Check the router API permissions.');
    } elseif (!$server) {
        $add('server', 'Hotspot server', 'fail', 'No enabled hotspot server.', 'Open Routers and configure hotspot on the customer LAN interface.');
    } else {
        $invalid = ($server['invalid'] ?? 'false') === 'true';
        $add('server', 'Hotspot server', $invalid ? 'fail' : 'ok', ($server['name'] ?? 'Hotspot') . ' on ' . ($server['interface'] ?? '?') . ($invalid ? ' is invalid.' : ' is enabled and valid.'), $invalid ? 'Check the gateway address, interface and address pool below.' : null);
        $interface = $server['interface'] ?? '';
        $profile = null;
        foreach ($data['profiles'] ?? [] as $p) if (($p['name'] ?? '') === ($server['profile'] ?? '')) $profile = $p;
        $gateway = $profile['hotspot-address'] ?? '';
        $address = null;
        foreach ($data['addresses'] ?? [] as $a) if (($a['interface'] ?? '') === $interface && $enabled($a) && ($a['invalid'] ?? 'false') !== 'true' && explode('/', $a['address'] ?? '')[0] === $gateway) $address = $a;
        $add('gateway', 'Hotspot gateway', !isset($data['addresses'], $data['profiles']) ? 'warn' : ($address ? 'ok' : 'fail'), $address ? $address['address'] . ' on ' . $interface : 'Profile gateway ' . ($gateway ?: 'unknown') . ' has no matching address on ' . $interface . '.', $address ? null : 'Add the profile gateway to this LAN interface after checking subnet conflicts.');
        $dhcp = array_values(array_filter($data['dhcp'] ?? [], fn($d) => ($d['interface'] ?? '') === $interface && $enabled($d)));
        $d = $dhcp[0] ?? [];
        $dhcpOk = count($dhcp) === 1 && ($d['invalid'] ?? 'false') !== 'true' && ($d['address-pool'] ?? '') === ($server['address-pool'] ?? '');
        $add('dhcp', 'Customer IP addresses', !isset($data['dhcp']) ? 'warn' : ($dhcpOk ? 'ok' : 'fail'), $dhcpOk ? $d['name'] . ' uses ' . $d['address-pool'] : 'Expected one valid DHCP server using ' . ($server['address-pool'] ?? 'the hotspot pool') . ' on ' . $interface . '.', $dhcpOk ? null : 'Repair the gateway first, then check DHCP pool and network gateway.');
        $networkOk = false;
        foreach ($data['networks'] ?? [] as $n) if (($n['gateway'] ?? '') === $gateway && $gateway !== '') $networkOk = true;
        $add('network', 'DHCP gateway settings', !isset($data['networks']) ? 'warn' : ($networkOk ? 'ok' : 'fail'), $networkOk ? 'DHCP advertises gateway ' . $gateway . '.' : 'No DHCP network advertises the hotspot gateway.', $networkOk ? null : 'Configure the hotspot subnet gateway and DNS in DHCP Networks.');
        $dir = trim(($profile['html-directory-override'] ?? '') ?: ($profile['html-directory'] ?? ''), '/');
        $paths = $dir === '' ? [] : [$dir . '/login.html'];
        if ($dir !== '' && !str_starts_with($dir, 'flash/')) $paths[] = 'flash/' . $dir . '/login.html';
        $login = null;
        foreach ($data['files'] ?? [] as $f) if (in_array($f['name'] ?? '', $paths, true) && (int)($f['size'] ?? 0) > 0) $login = $f;
        $add('login', 'Login page used by this server', !isset($data['files'], $data['profiles']) ? 'warn' : ($login ? 'ok' : 'fail'), $login ? $login['name'] . ' (' . (int)$login['size'] . ' bytes). File presence does not verify its branding.' : 'No nonempty login.html in the active profile directory ' . ($dir ?: '(unknown)') . '.', $login ? null : 'Use Re-deploy Login Page, then run this check again.');
        $redirect=false;
        foreach ($data['files'] ?? [] as $file) {
            foreach ($paths as $path) {
                $base=substr($path,0,-strlen('login.html'));
                if (in_array($file['name'] ?? '',[$base.'rlogin.html',$base.'redirect.html'],true) && (int)($file['size'] ?? 0)>0) $redirect=true;
            }
        }
        $add('redirect', 'Automatic login redirect files', !isset($data['files'], $data['profiles']) ? 'warn' : ($redirect ? 'ok' : 'fail'), $redirect ? 'The active directory has a redirect servlet. Verify the sign-in prompt on a phone.' : 'The active directory has neither rlogin.html nor redirect.html. A login.html file alone does not complete captive detection.', $redirect ? null : 'Re-deploy Login Page to restore the supporting hotspot servlet files.');
    }
    $dns = $data['dns'][0] ?? [];
    $dnsOk = ($dns['allow-remote-requests'] ?? '') === 'true';
    $add('dns', 'Router DNS for hotspot clients', !isset($data['dns']) ? 'warn' : ($dnsOk ? 'ok' : 'fail'), $dnsOk ? 'Router accepts client DNS queries.' : 'Router DNS requests are disabled or unreadable.', $dnsOk ? null : 'Enable DNS requests for the hotspot LAN and check upstream resolvers.');
    $hostOk = false;
    foreach ($data['garden'] ?? [] as $g) {
        if (!$enabled($g) || ($g['action'] ?? 'allow') !== 'allow') continue;
        if (isset($g['server']) && !in_array($g['server'], ['', 'all', $server['name'] ?? ''], true)) continue;
        if (in_array($g['dst-host'] ?? '', [$portalHost, '*.' . substr($portalHost, strpos($portalHost, '.') + 1)], true)) $hostOk = true;
    }
    $covered = [];
    foreach ($portalIps as $ip) foreach ($data['garden_ip'] ?? [] as $g) {
        if (!$enabled($g) || ($g['invalid'] ?? 'false') === 'true' || ($g['action'] ?? '') !== 'accept') continue;
        if (isset($g['server']) && !in_array($g['server'], ['', 'all', $server['name'] ?? ''], true)) continue;
        if (!empty($g['src-address']) || !empty($g['src-address-list']) || !empty($g['dst-address-list'])) continue;
        if (!empty($g['protocol']) && $g['protocol'] !== 'tcp') continue;
        if (!empty($g['dst-port']) && !in_array('443', explode(',', $g['dst-port']), true)) continue;
        if (in_array($g['dst-address'] ?? '', [$ip, $ip . '/32'], true)) $covered[$ip] = true;
    }
    $missing = array_values(array_diff($portalIps, array_keys($covered)));
    $portalOk = $hostOk && count($portalIps) > 0 && !$missing;
    $known = isset($data['garden'], $data['garden_ip']) && count($portalIps) > 0;
    $add('portal', 'Payment portal before login', !$known ? 'warn' : ($portalOk ? 'ok' : 'fail'), $portalOk ? 'Hostname and current IPv4 HTTPS destinations allowed. Verify on a connected phone.' : 'Portal: ' . $portalHost . '. ' . (!$hostOk ? 'Hostname allow rule missing. ' : '') . ($missing ? 'HTTPS allow rules missing for ' . implode(', ', $missing) . '.' : 'DNS or rule coverage could not be verified.'), $portalOk ? null : 'Repair portal allow rules for the current DNS destinations, then test from Wi-Fi.');
    $fail = count(array_filter($checks, fn($c) => $c['level'] === 'fail'));
    $warn = count(array_filter($checks, fn($c) => $c['level'] === 'warn'));
    return ['checks' => $checks, 'verdict' => ['level' => $fail ? 'fail' : ($warn ? 'warn' : 'ok'), 'title' => $fail ? "$fail hotspot check" . ($fail === 1 ? ' needs attention' : 's need attention') : ($warn ? 'Some checks could not be verified' : 'Hotspot configuration checks passed'), 'message' => $fail ? 'Fix the failed checks in order, then recheck. Payment history is shown separately.' : 'A phone on the hotspot must still verify the portal, package purchase, login and Internet access.']];
}
