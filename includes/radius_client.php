<?php
/**
 * FreeRADIUS MySQL integration layer.
 *
 * FreeRADIUS reads the rad* tables via rlm_sql. This file writes to those
 * tables so FreeRADIUS-controlled PPPoE sessions reflect FortuNett's billing state.
 *
 * Design: RADIUS operates alongside MikroTik API provisioning during migration.
 * Once all routers point to RADIUS, MikroTik PPPoE secrets become optional.
 *
 * Rate-limit attribute note (from project rules):
 *   Mikrotik-Rate-Limit = "upload/download" from the ROUTER's perspective
 *   = "{upload_speed}M/{download_speed}M"  (rx/tx from router = upload/download for client)
 */

/**
 * Check whether RADIUS tables are present in the DB.
 * Returns false gracefully if FreeRADIUS hasn't been installed yet.
 */
function radius_is_available(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo->query("SELECT 1 FROM radcheck LIMIT 1");
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Sync an active client to RADIUS.
 * Sets Cleartext-Password, removes any Reject flag, assigns speed group.
 * Call after payment confirmed or account activated.
 */
function radius_sync_client(PDO $pdo, array $client, array $package): bool {
    if (!radius_is_available($pdo)) return false;

    $username = $client['mikrotik_username'] ?? null;
    $password = $client['mikrotik_password'] ?? null;
    if (!$username || !$password) return false;

    try {
        // Upsert password
        $pdo->prepare("
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (?, 'Cleartext-Password', ':=', ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ")->execute([$username, $password]);

        // Clear any Reject that was set on expiry
        $pdo->prepare("
            DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'
        ")->execute([$username]);

        // Ensure the speed group exists
        radius_ensure_group($pdo, $package);

        // Assign user to group
        $pdo->prepare("
            INSERT INTO radusergroup (username, groupname, priority)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE groupname = VALUES(groupname)
        ")->execute([$username, radius_group_name($package)]);

        return true;
    } catch (Throwable $e) {
        error_log("radius_sync_client error for $username: " . $e->getMessage());
        return false;
    }
}

/**
 * Disable a user in RADIUS (expired or suspended).
 * Sets Auth-Type = Reject so the next PPPoE reconnect is denied.
 * The existing session continues until the router's idle-timeout or disconnect.
 */
function radius_disable_client(PDO $pdo, string $username): bool {
    if (!radius_is_available($pdo)) return false;
    try {
        $pdo->prepare("
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (?, 'Auth-Type', ':=', 'Reject')
            ON DUPLICATE KEY UPDATE value = 'Reject'
        ")->execute([$username]);
        return true;
    } catch (Throwable $e) {
        error_log("radius_disable_client error for $username: " . $e->getMessage());
        return false;
    }
}

/**
 * Re-enable a user in RADIUS after payment.
 * Removes the Reject flag — next PPPoE connect will succeed.
 */
function radius_enable_client(PDO $pdo, string $username): bool {
    if (!radius_is_available($pdo)) return false;
    try {
        $pdo->prepare("
            DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'
        ")->execute([$username]);
        return true;
    } catch (Throwable $e) {
        error_log("radius_enable_client error for $username: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove all RADIUS entries for a user (on client deletion).
 */
function radius_delete_client(PDO $pdo, string $username): void {
    if (!radius_is_available($pdo)) return;
    try {
        $pdo->prepare("DELETE FROM radcheck     WHERE username = ?")->execute([$username]);
        $pdo->prepare("DELETE FROM radreply     WHERE username = ?")->execute([$username]);
        $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
    } catch (Throwable $e) {
        error_log("radius_delete_client error for $username: " . $e->getMessage());
    }
}

/**
 * Get active PPPoE sessions from RADIUS accounting.
 * Returns rows from radacct where acctstoptime is NULL.
 */
function radius_get_active_sessions(PDO $pdo, string $username): array {
    if (!radius_is_available($pdo)) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT acctsessionid, nasipaddress, framedipaddress,
                   acctstarttime, acctsessiontime,
                   acctinputoctets  AS upload_bytes,
                   acctoutputoctets AS download_bytes,
                   callingstationid AS mac_address
            FROM radacct
            WHERE username = ? AND acctstoptime IS NULL
            ORDER BY acctstarttime DESC
        ");
        $stmt->execute([$username]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get data usage totals for a user in the current calendar month.
 * Returns ['upload_bytes', 'download_bytes', 'session_count', 'total_seconds'].
 */
function radius_get_monthly_usage(PDO $pdo, string $username): array {
    if (!radius_is_available($pdo)) {
        return ['upload_bytes' => 0, 'download_bytes' => 0, 'session_count' => 0, 'total_seconds' => 0];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(acctinputoctets),  0) AS upload_bytes,
                COALESCE(SUM(acctoutputoctets), 0) AS download_bytes,
                COUNT(*)                            AS session_count,
                COALESCE(SUM(acctsessiontime),  0) AS total_seconds
            FROM radacct
            WHERE username = ?
              AND acctstarttime >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['upload_bytes' => 0, 'download_bytes' => 0, 'session_count' => 0, 'total_seconds' => 0];
    } catch (Throwable $e) {
        return ['upload_bytes' => 0, 'download_bytes' => 0, 'session_count' => 0, 'total_seconds' => 0];
    }
}

/**
 * Ensure the RADIUS group for a package exists with the correct MikroTik rate-limit.
 * Group name: "pkg-{id}" e.g. "pkg-5"
 */
function radius_ensure_group(PDO $pdo, array $package): void {
    $groupName     = radius_group_name($package);
    $uploadMbps    = max(1, (int)($package['upload_speed']   ?? 1));
    $downloadMbps  = max(1, (int)($package['download_speed'] ?? 1));

    // upload/download from router perspective = rx/tx = client upload/download
    $rateLimit = "{$uploadMbps}M/{$downloadMbps}M";

    $attributes = [
        ['Mikrotik-Rate-Limit', '=', $rateLimit],
        ['Service-Type',        '=', 'Framed-User'],
        ['Framed-Protocol',     '=', 'PPP'],
    ];

    foreach ($attributes as [$attr, $op, $val]) {
        $pdo->prepare("
            INSERT INTO radgroupreply (groupname, attribute, op, value)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ")->execute([$groupName, $attr, $op, $val]);
    }
}

/**
 * Generate a consistent, short RADIUS group name from a package.
 * "pkg-5" is stable across package renames.
 */
function radius_group_name(array $package): string {
    return 'pkg-' . (int)($package['id'] ?? 0);
}
