<?php
/** Attribute only unambiguous bridge MAC entries; never guess an AP behind a switch. */
function hotspotLocationSnapshot(array $interfaces, array $hosts, array $sessions): array {
    $locations = [];
    foreach ($interfaces as $row) {
        if (empty($row['name'])) continue;
        $locations[$row['name']] = ['interface'=>$row['name'], 'location'=>$row['comment'] ?? '',
            'running'=>in_array($row['running'] ?? '', ['true','yes'], true),
            'rx_bytes'=>isset($row['rx-byte']) ? (int)$row['rx-byte'] : null,
            'tx_bytes'=>isset($row['tx-byte']) ? (int)$row['tx-byte'] : null,
            'sessions'=>0, 'users'=>[], 'session_upload_bytes'=>0, 'session_download_bytes'=>0];
    }
    $macs = [];
    foreach ($hosts as $host) {
        if (empty($host['mac-address']) || empty($host['on-interface']) || ($host['local'] ?? '') === 'true') continue;
        $macs[strtoupper($host['mac-address'])][$host['on-interface']] = true;
    }
    $unmapped = 0;
    foreach ($sessions as $session) {
        if (empty($session['user'])) continue;
        $ports = array_keys($macs[strtoupper($session['mac-address'] ?? '')] ?? []);
        if (count($ports) !== 1 || !isset($locations[$ports[0]])) { $unmapped++; continue; }
        $row = &$locations[$ports[0]];
        $row['sessions']++;
        $row['users'][$session['user']] = true;
        $row['session_upload_bytes'] += (int)($session['bytes-in'] ?? 0);
        $row['session_download_bytes'] += (int)($session['bytes-out'] ?? 0);
        unset($row);
    }
    foreach ($locations as &$row) { $row['customers'] = count($row['users']); unset($row['users']); }
    unset($row);
    return ['locations'=>array_values($locations), 'unmapped_sessions'=>$unmapped];
}
