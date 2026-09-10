<?php
/** Offline contract tests; no live router/database is contacted. */
require_once __DIR__ . '/../includes/package_profile.php';

function deadlineCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function deadlineRejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable $e) { deadlineCheck(true, $message); return; }
    throw new RuntimeException($message);
}
class PaidRouterDouble
{
    public array $data = [];
    public array $calls = [];
    public ?string $fail = null;
    public bool $loseSchedule = false;
    public bool $loseQuota = false;
    public function comm($path, $params = []): array {
        $this->calls[] = [$path, $params];
        if ($this->fail === $path) return [['!trap' => true, 'message' => 'simulated router rejection']];
        if ($path === '/system/clock/print') return [['!re' => true, 'date' => gmdate('Y-m-d'), 'time' => gmdate('H:i:s')]];
        $base = substr($path, 0, strrpos($path, '/'));
        $op = substr($path, strrpos($path, '/') + 1);
        $fields = [];
        $filter = null;
        foreach ($params as $p) {
            if (str_starts_with($p, '?name=')) $filter = substr($p, 6);
            elseif (str_starts_with($p, '=')) {
                [$key, $value] = explode('=', substr($p, 1), 2);
                $fields[$key] = $value === 'yes' ? 'true' : ($value === 'no' ? 'false' : $value);
            }
        }
        if ($op === 'print') {
            if ($this->loseSchedule && $base === '/system/scheduler') return [];
            $rows = array_values(array_filter($this->data[$base] ?? [], fn($row) => $filter === null || ($row['name'] ?? null) === $filter));
            if ($this->loseQuota) foreach ($rows as &$row) unset($row['limit-uptime']);
            return $rows;
        }
        if ($op === 'add') {
            $id = '*' . (count($this->data[$base] ?? []) + 1);
            $this->data[$base][$id] = array_merge(['!re' => true, '.id' => $id, 'uptime' => '0s'], $fields);
        } elseif ($op === 'set') {
            $id = $fields['.id'];
            $this->data[$base][$id] = array_merge($this->data[$base][$id], $fields);
        } else throw new RuntimeException('Unexpected test command ' . $path);
        return [['!done' => true]];
    }
    public function kickHotspotSession($username): bool { $this->calls[] = ['kick-hotspot', [$username]]; return true; }
    public function kickPPPoESession($username): bool { $this->calls[] = ['kick-pppoe', [$username]]; return true; }
}

$terms = ['id' => 8, 'name' => 'Half Hour', 'download_speed' => 10, 'upload_speed' => 3,
    'validity_value' => 30, 'validity_unit' => 'minutes', 'device_limit' => 2];
$profile = packageProfileSettings($terms, 'hotspot');
deadlineCheck($profile['rate-limit'] === '3M/10M', 'Profile uses purchased upload/download speeds');
deadlineCheck($profile['session-timeout'] === '1800s', '30-minute profile is capped at 1800 seconds');
deadlineCheck($profile['shared-users'] === '2', 'Package device limit overrides router-wide unlimited sharing');
deadlineCheck($profile['add-mac-cookie'] === 'no', 'Profile does not create persistent MAC cookies');
deadlineCheck(str_contains($profile['on-login'], '$clockkey < $deadline') && str_contains($profile['on-login'], '$clockkey >= $issued'), 'Reconnect guard checks deadline and clock rollback');
deadlineCheck(isset(packageProfileSettings($terms, 'pppoe')['on-up']), 'PPPoE profiles also carry an expiry guard');
deadlineRejects(fn() => packageExpiryFrom(30, 'minutess'), 'Unknown duration units cannot silently become days');
deadlineRejects(fn() => packageExpiryFrom(0, 'hours'), 'Zero-duration packages cannot receive fallback time');
deadlineRejects(fn() => packageExpiryFrom(1.5, 'hours'), 'Fractional durations are not silently rounded');
deadlineRejects(fn() => packageProfileSettings(array_merge($terms, ['download_speed' => 0]), 'hotspot'), 'Paid profiles cannot silently become uncapped');
deadlineRejects(fn() => packageProfileSettings(array_merge($terms, ['device_limit' => 0]), 'hotspot'), 'Invalid device count is rejected');
deadlineCheck(packageProfileName(array_merge($terms, ['mikrotik_profile' => 'default'])) !== 'default', 'Built-in default profile is never used for a package');
deadlineCheck(packageProfileName(array_merge($terms, ['mikrotik_profile' => 'walled-garden'])) !== 'walled-garden', 'Captive profile is never overwritten by a paid package');
$now = strtotime('2026-09-10 21:50:00 UTC');
$expiry = date('Y-m-d H:i:s', $now + 1800);
$iso = routerPaidDeadline(['date' => '2026-09-10', 'time' => '23:50:00'], $expiry, $now);
deadlineCheck($iso['date'] === '2026-09-11' && $iso['time'] === '00:20:00', 'Router deadline crosses midnight in router local time');
$legacy = routerPaidDeadline(['date' => 'dec/31/2026', 'time' => '23:50:00'], $expiry, $now);
deadlineCheck($legacy['date'] === 'jan/01/2027' && $legacy['time'] === '00:20:00', 'RouterOS 6 date format crosses year boundary');
deadlineRejects(fn() => routerPaidDeadline(['date' => 'garbage', 'time' => '00:00:00'], $expiry, $now), 'Unreadable router clock fails closed');
deadlineRejects(fn() => routerPaidDeadline(['date' => '2026-09-10', 'time' => '00:00:00'], $expiry, $now + 1800), 'Exact expiry never becomes an unlimited zero timeout');
deadlineCheck(routerUptimeSeconds('1w2d03:04:05') === 788645 && routerUptimeSeconds('1h30m') === 5400, 'Consumed uptime parses both RouterOS duration formats');
deadlineCheck(routerScriptString('a";$b\\c') === '"a\\";\\$b\\\\c"', 'Router script strings escape quotes, dollars and backslashes');

$router = new PaidRouterDouble();
deadlineCheck(syncPackageProfileToRouter($router, 'hotspot', 'pkg8-half-hour', '3M/10M', $terms), 'Package profile is installed and read back');
$router->fail = '/ip/hotspot/user/profile/set';
deadlineCheck(!syncPackageProfileToRouter($router, 'hotspot', 'pkg8-half-hour', '3M/10M', $terms), 'Profile write rejection is not reported as success');
$router->fail = null;
$paidUntil = date('Y-m-d H:i:s', time() + 1800);
provisionRouterPaidUser($router, 'hotspot', 'alice', 'test', 'pkg8-half-hour', 'Alice', $paidUntil);
$user = $router->data['/ip/hotspot/user']['*1'];
$schedule = $router->data['/system/scheduler']['*1'];
deadlineCheck($user['disabled'] === 'false' && str_starts_with($user['comment'], 'FNEXP:'), 'User enabled only with installed paid deadline');
deadlineCheck(routerUptimeSeconds($user['limit-uptime']) <= 1800, 'First grant never exceeds purchased time');
deadlineCheck(str_contains($schedule['on-event'], '/ip hotspot cookie remove') && str_contains($schedule['on-event'], 'FNEXP:'), 'Expiry removes cookies and checks renewal identity');
$router->data['/ip/hotspot/user']['*1']['uptime'] = '10m';
provisionRouterPaidUser($router, 'hotspot', 'alice', 'test', 'pkg8-half-hour', 'Alice', date('Y-m-d H:i:s', time() + 1200));
$user = $router->data['/ip/hotspot/user']['*1'];
deadlineCheck(routerUptimeSeconds($user['limit-uptime']) <= 1800 && $user['uptime'] === '10m', 'Retry retains consumed uptime and only grants remaining time');
deadlineCheck(count(array_filter($router->data['/system/scheduler'], fn($row) => str_starts_with($row['name'], 'fn-exp-'))) === 1, 'Renewal replaces the same customer schedule');
$router->fail = '/system/scheduler/set';
deadlineRejects(fn() => provisionRouterPaidUser($router, 'hotspot', 'alice', 'test', 'pkg8-half-hour', '', $paidUntil), 'Scheduler rejection fails provisioning');
deadlineCheck($router->data['/ip/hotspot/user']['*1']['disabled'] === 'true', 'Failed deadline installation leaves account disabled');
$router = new PaidRouterDouble();
$router->loseSchedule = true;
deadlineRejects(fn() => provisionRouterPaidUser($router, 'pppoe', 'bob', 'test', 'pkg8-half-hour', '', $paidUntil), 'Missing scheduler readback fails PPPoE provisioning');
deadlineCheck($router->data['/ppp/secret']['*1']['disabled'] === 'true', 'PPPoE stays disabled when expiry cannot be verified');
$router = new PaidRouterDouble();
$router->loseQuota = true;
deadlineRejects(fn() => provisionRouterPaidUser($router, 'hotspot', 'alice', 'test', 'pkg8-half-hour', '', $paidUntil), 'Missing uptime-limit readback fails provisioning');
deadlineCheck($router->data['/ip/hotspot/user']['*1']['disabled'] === 'true', 'User stays disabled when the router does not retain its time cap');
echo "All package/deadline regression checks passed.\n";
