<?php
/**
 * Cron heartbeats.
 *
 * Half of "the automation didn't run" turns out to be a cron line that was never
 * added to crontab — cron/stk_poll.php in particular was missing from the
 * documented schedule for a long time, and nothing in the UI could tell you.
 * Every scheduled script stamps its name and the time here on each run, so the
 * diagnostics page can say "STK reconciliation last ran 41 seconds ago" or
 * "never run — add it to crontab" instead of leaving you guessing.
 *
 * Stored in platform_settings (already present on every deployment) rather than
 * a new table, so this needs no migration.
 */

/** Record that a scheduled script just ran. Never throws. */
function cron_heartbeat(PDO $pdo, string $name, ?string $note = null): void
{
    $payload = json_encode([
        'at'   => date('c'),
        'ts'   => time(),
        'note' => $note,
        'pid'  => function_exists('getmypid') ? getmypid() : null,
    ]);
    try {
        $pdo->prepare("
            INSERT INTO platform_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute(['cron_last_run_' . $name, $payload]);
    } catch (Throwable $e) {
        error_log("cron_heartbeat($name): " . $e->getMessage());
    }
}

/**
 * Read a heartbeat back.
 * @return array{ran:bool, ts:?int, age:?int, at:?string, note:?string}
 */
function cron_last_run(PDO $pdo, string $name): array
{
    $none = ['ran' => false, 'ts' => null, 'age' => null, 'at' => null, 'note' => null];
    try {
        $st = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $st->execute(['cron_last_run_' . $name]);
        $raw = $st->fetchColumn();
        if (!$raw) return $none;
        $d = json_decode((string)$raw, true);
        if (!is_array($d) || empty($d['ts'])) return $none;
        return [
            'ran'  => true,
            'ts'   => (int)$d['ts'],
            'age'  => max(0, time() - (int)$d['ts']),
            'at'   => $d['at']   ?? null,
            'note' => $d['note'] ?? null,
        ];
    } catch (Throwable $e) {
        return $none;
    }
}

/** "41s ago" / "6m 02s ago" / "3h 11m ago" / "2d ago" */
function cron_age_human(?int $secs): string
{
    if ($secs === null) return 'never';
    if ($secs < 60)     return $secs . 's ago';
    if ($secs < 3600)   return intdiv($secs, 60) . 'm ' . str_pad((string)($secs % 60), 2, '0', STR_PAD_LEFT) . 's ago';
    if ($secs < 86400)  return intdiv($secs, 3600) . 'h ' . intdiv($secs % 3600, 60) . 'm ago';
    return intdiv($secs, 86400) . 'd ago';
}
