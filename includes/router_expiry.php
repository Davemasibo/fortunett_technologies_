<?php
/** Router-local paid deadlines. Shared profiles never store a customer's expiry. */
function routerCheckedCommand($api, string $path, array $params = []): array
{
    $rows = $api->comm($path, $params);
    foreach ((array)$rows as $row) {
        if (isset($row['!trap']) || isset($row['!fatal'])) {
            throw new RuntimeException($path . ': ' . ($row['message'] ?? 'Router rejected command'));
        }
    }
    return (array)$rows;
}

function routerScriptString(string $value): string
{
    return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\r" => '\\r', "\n" => '\\n']) . '"';
}

/** Supports both RouterOS 6 month-name dates and RouterOS 7 ISO dates. */
function routerPaidDeadline(array $clock, string $expiry, ?int $now = null): array
{
    $remaining = strtotime($expiry) - ($now ?? time());
    if ($remaining <= 0) throw new RuntimeException('Purchased access has expired');
    $date = (string)($clock['date'] ?? '');
    $format = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? 'Y-m-d' : 'M/d/Y';
    $local = DateTimeImmutable::createFromFormat('!' . $format . ' H:i:s', $date . ' ' . ($clock['time'] ?? ''), new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$local || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new RuntimeException('Cannot read router clock; access remains disabled');
    }
    // The router clock was sampled BEFORE this remaining-time calculation.
    // Network delay therefore shortens the grant; it never adds purchased time.
    $deadline = $local->modify('+' . $remaining . ' seconds');
    return ['date' => strtolower($deadline->format($format)), 'time' => $deadline->format('H:i:s'),
        'key' => $deadline->format('YmdHis'), 'start' => $local->format('YmdHis'), 'remaining' => $remaining];
}

/** A sortable local clock value, using only scripting operations available in v6. */
function routerClockKeyScript(): string
{
    return <<<'ROS'
:local d [/system clock get date];
:local t [/system clock get time];
:local daykey "";
:if ([:pick $d 4 5] = "-") do={
    :set daykey ([:pick $d 0 4] . [:pick $d 5 7] . [:pick $d 8 10]);
} else={
    :local months {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"};
    :for i from=0 to=11 do={
        :if (($months->$i) = [:pick $d 0 3]) do={
            :local m ($i + 1);
            :local mm [:tostr $m];
            :if ($m < 10) do={ :set mm ("0" . $mm); };
            :set daykey ([:pick $d 7 11] . $mm . [:pick $d 4 6]);
        };
    };
};
:local clockkey [:tonum ($daykey . [:pick $t 0 2] . [:pick $t 3 5] . [:pick $t 6 8])];
ROS;
}

/** Reject a reconnect after a missed deadline (including a reboot at expiry). */
function routerExpiryLoginScript(string $service): string
{
    $base = $service === 'hotspot' ? '/ip hotspot user' : '/ppp secret';
    $active = $service === 'hotspot' ? '/ip hotspot active' : '/ppp active';
    $field = $service === 'hotspot' ? 'user' : 'name';
    $script = ':local u $user; :local ids [' . $base . ' find where name=$u]; ';
    $script .= ':local allowed false; :foreach id in=$ids do={ :local c [' . $base . ' get $id comment]; ';
    $script .= ':if (([:pick $c 0 6] = "FNEXP:") && ([:pick $c 20 21] = "|")) do={ ';
    $script .= routerClockKeyScript();
    $script .= ':local deadline [:tonum [:pick $c 6 20]]; :local issued [:tonum [:pick $c 29 43]]; ';
    $script .= ':if (([:len $daykey] = 8) && ([:typeof $clockkey] = "num") && ([:typeof $deadline] = "num") && ([:typeof $issued] = "num") && ($clockkey >= $issued) && ($clockkey < $deadline)) do={ :set allowed true; }; }; }; ';
    $script .= ':if (!$allowed) do={ ' . $base . ' disable $ids; ' . $active . ' remove [' . $active . ' find where ' . $field . '=$u]; }';
    // Runtime parsing/clock errors must also remove the authenticated session.
    return ':local u $user; :do { ' . $script . ' } on-error={ '
        . $base . ' disable [' . $base . ' find where name=$u]; '
        . $active . ' remove [' . $active . ' find where ' . $field . '=$u]; };';
}

function routerUptimeSeconds(string $value): int
{
    if ($value === '' || $value === '0') return 0;
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(\d+):(\d{2}):(\d{2})$/', $value, $m)) {
        return (int)$m[1] * 604800 + (int)$m[2] * 86400 + (int)$m[3] * 3600 + (int)$m[4] * 60 + (int)$m[5];
    }
    if (!preg_match('/^(?:\d+[wdhms])+$/', $value)) throw new RuntimeException('Unrecognised router uptime');
    preg_match_all('/(\d+)([wdhms])/', $value, $parts, PREG_SET_ORDER);
    $seconds = 0;
    foreach ($parts as $p) $seconds += (int)$p[1] * ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1][$p[2]];
    return $seconds;
}

/** Keep the account disabled until its independently enforced deadline is verified. */
function provisionRouterPaidUser($api, string $service, string $username, string $password,
    string $profile, string $comment, string $expiry, string $server = 'all'): void
{
    $base = $service === 'hotspot' ? '/ip/hotspot/user' : '/ppp/secret';
    $active = $service === 'hotspot' ? '/ip hotspot active' : '/ppp active';
    $scriptBase = str_replace('/', ' ', ltrim($base, '/'));
    $scriptBase = '/' . $scriptBase;
    $activeField = $service === 'hotspot' ? 'user' : 'name';
    $name = 'fn-exp-' . $service . '-' . substr(hash('sha256', $username), 0, 24);
    $id = null;
    foreach (routerCheckedCommand($api, $base . '/print', ['?name=' . $username]) as $row) {
        if (($row['name'] ?? '') === $username) $id = $row['.id'];
    }
    if ($id !== null) {
        routerCheckedCommand($api, $base . '/set', ['=.id=' . $id, '=disabled=yes']);
        if ($service === 'hotspot') $api->kickHotspotSession($username);
        else $api->kickPPPoESession($username);
    }
    try {
        $params = ['=password=' . $password, '=profile=' . $profile, '=disabled=yes'];
        if ($service === 'hotspot') { $params[] = '=server=' . $server; $params[] = '=mac-address=00:00:00:00:00:00'; }
        else $params[] = '=service=pppoe';
        if ($id === null) {
            routerCheckedCommand($api, $base . '/add', array_merge(['=name=' . $username], $params));
        } else {
            routerCheckedCommand($api, $base . '/set', array_merge(['=.id=' . $id], $params));
        }
        $user = null;
        foreach (routerCheckedCommand($api, $base . '/print', ['?name=' . $username]) as $row) {
            if (($row['name'] ?? '') === $username) { $user = $row; $id = $row['.id']; }
        }
        if (!$user || $id === null) throw new RuntimeException('Router user was not created');
        if (array_key_exists('rate-limit', $user) && $user['rate-limit'] !== '') {
            routerCheckedCommand($api, $base . '/set', ['=.id=' . $id, '=rate-limit=']);
        }
        $clock = null;
        foreach (routerCheckedCommand($api, '/system/clock/print') as $row) {
            if (isset($row['date'], $row['time'])) $clock = $row;
        }
        if (!$clock) throw new RuntimeException('Router clock unavailable');
        $deadline = routerPaidDeadline($clock, $expiry);
        $tag = 'FNEXP:' . $deadline['key'] . '|';
        $params = ['=.id=' . $id, '=comment=' . $tag . 'FNSTART:' . $deadline['start'] . '|' . $comment];
        if ($service === 'hotspot') {
            // Never reset counters on login/retry. Only the remaining paid wall
            // time can be added to the already-consumed uptime counter.
            $params[] = '=limit-uptime=' . (routerUptimeSeconds($user['uptime'] ?? '0s') + $deadline['remaining']) . 's';
        }
        routerCheckedCommand($api, $base . '/set', $params);

        $u = routerScriptString($username);
        $event = ':local u ' . $u . '; :foreach id in=[' . $scriptBase . ' find where name=$u] do={ ';
        // An old scheduled event must never disable a newly renewed subscription.
        $event .= ':if ([:pick [' . $scriptBase . ' get $id comment] 0 21] = ' . routerScriptString($tag) . ') do={ ';
        $event .= $scriptBase . ' disable $id; ' . $active . ' remove [' . $active . ' find where ' . $activeField . '=$u]; ';
        if ($service === 'hotspot') $event .= '/ip hotspot cookie remove [/ip hotspot cookie find where user=$u]; ';
        $event .= '}; };';
        $scheduleId = null;
        foreach (routerCheckedCommand($api, '/system/scheduler/print', ['?name=' . $name]) as $row) {
            if (($row['name'] ?? '') === $name) $scheduleId = $row['.id'];
        }
        $schedule = ['=start-date=' . $deadline['date'], '=start-time=' . $deadline['time'],
            '=interval=0s', '=on-event=' . $event, '=policy=read,write,test', '=disabled=no'];
        routerCheckedCommand($api, '/system/scheduler/' . ($scheduleId === null ? 'add' : 'set'),
            array_merge([$scheduleId === null ? '=name=' . $name : '=.id=' . $scheduleId], $schedule));
        $verified = false;
        foreach (routerCheckedCommand($api, '/system/scheduler/print', ['?name=' . $name]) as $row) {
            if (($row['name'] ?? '') === $name && ($row['disabled'] ?? 'true') === 'false'
                && ($row['start-date'] ?? '') === $deadline['date'] && ($row['start-time'] ?? '') === $deadline['time']
                && ($row['on-event'] ?? '') === $event) $verified = true;
        }
        $userVerified = false;
        foreach (routerCheckedCommand($api, $base . '/print', ['?name=' . $username]) as $row) {
            if (($row['name'] ?? '') !== $username) continue;
            $userVerified = ($row['profile'] ?? '') === $profile
                && str_starts_with($row['comment'] ?? '', $tag)
                && ($row['disabled'] ?? '') === 'true';
            if ($service === 'hotspot') {
                $userVerified = $userVerified && routerUptimeSeconds($row['limit-uptime'] ?? '0s')
                    === routerUptimeSeconds($user['uptime'] ?? '0s') + $deadline['remaining'];
            }
        }
        if (!$verified || !$userVerified || strtotime($expiry) <= time()) throw new RuntimeException('Paid deadline could not be verified before expiry');
        routerCheckedCommand($api, $base . '/set', ['=.id=' . $id, '=disabled=no']);
    } catch (Throwable $e) {
        if ($id !== null) {
            try { routerCheckedCommand($api, $base . '/set', ['=.id=' . $id, '=disabled=yes']); } catch (Throwable $_) {}
        }
        throw $e;
    }
}
