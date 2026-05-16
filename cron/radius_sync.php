<?php
/**
 * Cron: Full RADIUS sync — runs nightly to catch any drift.
 * Real-time enable/disable is handled by auto_provision.php and check_expiry.php.
 * This is the safety net that reconciles everything.
 *
 * Schedule: 0 2 * * * php /var/www/html/cron/radius_sync.php >> /var/log/fortunett_radius_sync.log 2>&1
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/radius_client.php';

if (!radius_is_available($pdo)) {
    echo date('Y-m-d H:i:s') . " RADIUS tables not found — skipping sync. Run the FreeRADIUS migration first.\n";
    exit(0);
}

$start   = microtime(true);
$synced  = 0;
$blocked = 0;
$skipped = 0;

// Only sync PPPoE clients — hotspot uses MikroTik-native auth
$stmt = $pdo->query("
    SELECT c.id, c.mikrotik_username, c.mikrotik_password,
           c.status, c.expiry_date, c.full_name, c.tenant_id,
           p.id AS pkg_id, p.upload_speed, p.download_speed, p.name AS pkg_name
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    WHERE c.connection_type = 'pppoe'
      AND c.mikrotik_username IS NOT NULL
      AND c.mikrotik_username != ''
");

foreach ($stmt as $c) {
    $username = $c['mikrotik_username'];
    $package  = ['id' => $c['pkg_id'], 'upload_speed' => $c['upload_speed'], 'download_speed' => $c['download_speed']];
    $isActive = $c['status'] === 'active'
        && !empty($c['expiry_date'])
        && strtotime($c['expiry_date']) > time();

    try {
        if ($isActive) {
            if ($package['id'] && radius_sync_client($pdo, $c, $package)) {
                $synced++;
            } else {
                $skipped++;
            }
        } else {
            // Ensure password record exists so the entry is in radcheck
            if (!empty($c['mikrotik_password'])) {
                $pdo->prepare("
                    INSERT INTO radcheck (username, attribute, op, value)
                    VALUES (?, 'Cleartext-Password', ':=', ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ")->execute([$username, $c['mikrotik_password']]);
            }
            radius_disable_client($pdo, $username);
            $blocked++;
        }
    } catch (Throwable $e) {
        error_log("radius_sync error for $username: " . $e->getMessage());
        $skipped++;
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo date('Y-m-d H:i:s') . " RADIUS sync: active=$synced blocked=$blocked skipped=$skipped ({$elapsed}s)\n";
