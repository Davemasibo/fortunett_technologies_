<?php
/**
 * Separate timestamps for router WAN check-ins, WireGuard handshakes, and
 * successful RouterOS API logins. The older last_seen field is retained for
 * billing and compatibility; it is not a reliable health signal.
 */

function ensureRouterHealthColumns(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    $columns = [
        'last_wan_heartbeat_at' => "DATETIME NULL DEFAULT NULL COMMENT 'Last authenticated WAN check-in from the router'",
        'last_wg_handshake_at' => "DATETIME NULL DEFAULT NULL COMMENT 'Most recent WireGuard handshake observed on the VPS'",
        'last_api_success_at' => "DATETIME NULL DEFAULT NULL COMMENT 'Last successful RouterOS API login'",
    ];

    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mikrotik_routers'");
        $st->execute();
        $existing = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($columns as $name => $definition) {
            if (!isset($existing[$name])) {
                $pdo->exec("ALTER TABLE mikrotik_routers ADD COLUMN `$name` $definition");
            }
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('[router_health] Could not ensure router health columns: ' . $e->getMessage());
        // Concurrent requests may both discover a missing column; if the other
        // request completed the ALTER first, treat the resulting duplicate as
        // success after verifying the full set.
        try {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mikrotik_routers'");
            $st->execute();
            $existing = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
            $ready = count(array_diff_key($columns, $existing)) === 0;
        } catch (Throwable $_checkError) {
            $ready = false;
        }
    }
    return $ready;
}

function routerHealthAge(?string $timestamp): ?int
{
    if (!$timestamp) return null;
    $time = strtotime($timestamp);
    return $time === false ? null : max(0, time() - $time);
}

