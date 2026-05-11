<?php
/**
 * tools/reassign_profiles.php
 *
 * Updates every active PPPoE client's secret on the router to use the correct
 * package profile, then kicks their active session so new speeds apply immediately.
 *
 * Usage:
 *   php tools/reassign_profiles.php            # dry-run (shows what would change)
 *   php tools/reassign_profiles.php --apply    # makes the changes
 *
 * Run from the project root on the server.
 */

$_SERVER['HTTP_HOST'] = 'ecolandattic.fortunetttech.site';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

$dryRun = !in_array('--apply', $argv ?? []);
if ($dryRun) {
    echo "[DRY RUN] Pass --apply to make actual changes.\n\n";
}

// Fetch all active PPPoE clients joined to their tenant's first active router.
// clients table has no router_id — router is resolved via tenant_id, same as auto_provision.php.
$clients = $pdo->query("
    SELECT
        c.id,
        c.full_name,
        c.mikrotik_username,
        c.tenant_id,
        p.mikrotik_profile,
        p.name        AS package_name,
        p.upload_speed,
        p.download_speed,
        r.id          AS router_id,
        r.name        AS router_name,
        r.ip_address,
        r.vpn_ip,
        r.username    AS r_user,
        r.password    AS r_pass,
        r.api_port
    FROM clients c
    JOIN packages p ON p.id = c.package_id
    JOIN mikrotik_routers r ON r.tenant_id = c.tenant_id
        AND r.id = (
            SELECT id FROM mikrotik_routers
            WHERE tenant_id = c.tenant_id AND status IN ('active','online')
            ORDER BY id ASC LIMIT 1
        )
    WHERE c.status = 'active'
      AND c.connection_type = 'pppoe'
      AND c.mikrotik_username IS NOT NULL
      AND c.mikrotik_username != ''
    ORDER BY r.id, c.id
")->fetchAll(PDO::FETCH_ASSOC);

if (!$clients) {
    echo "No active PPPoE clients found.\n";
    exit;
}

echo "Found " . count($clients) . " active PPPoE clients.\n\n";

// Group by router to minimise reconnects
$byRouter = [];
foreach ($clients as $c) {
    $byRouter[$c['router_id'] ?? $c['tenant_id']][] = $c;
}

foreach ($byRouter as $routerId => $rows) {
    $r = $rows[0];
    $ip = !empty($r['vpn_ip']) ? $r['vpn_ip'] : $r['ip_address'];
    echo "=== Router: {$r['router_name']} ({$ip}) ===\n";

    $api = null;
    try {
        $api = new MikrotikAPI($ip, $r['r_user'], $r['r_pass'], (int)($r['api_port'] ?? 8728));
        if (!$api->isReachable(4)) {
            echo "  SKIP: router not reachable\n\n";
            continue;
        }
        $api->connect();
    } catch (Throwable $e) {
        echo "  CONNECT FAILED: {$e->getMessage()}\n\n";
        continue;
    }

    // Fetch all PPPoE secrets on this router once (cheaper than per-user lookup)
    $allSecrets = [];
    try {
        $resp = $api->comm('/ppp/secret/print');
        foreach ($resp as $s) {
            if (!isset($s['!re'])) continue;
            $allSecrets[strtolower($s['name'] ?? '')] = $s;
        }
    } catch (Throwable $e) {
        echo "  Could not fetch secrets: {$e->getMessage()}\n\n";
        $api->disconnect();
        continue;
    }

    foreach ($rows as $c) {
        $username    = strtolower($c['mikrotik_username']);
        $wantProfile = $c['mikrotik_profile']
            ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($c['package_name']));
        $upload   = max(1, (int)$c['upload_speed']);
        $download = max(1, (int)$c['download_speed']);
        $wantRate = "{$upload}M/{$download}M";

        if (!isset($allSecrets[$username])) {
            echo "  [{$c['full_name']}] NOT FOUND on router (username={$c['mikrotik_username']})\n";
            continue;
        }

        $secret      = $allSecrets[$username];
        $curProfile  = $secret['profile'] ?? '';
        $secretId    = $secret['.id'];

        $profileOk = ($curProfile === $wantProfile);
        echo "  [{$c['full_name']}] {$c['mikrotik_username']} :: "
            . "profile={$curProfile} → {$wantProfile} "
            . ($profileOk ? "(no change)" : "(UPDATE)") . "\n";

        if ($profileOk) continue;

        if (!$dryRun) {
            try {
                $api->comm('/ppp/secret/set', [
                    '=.id='     . $secretId,
                    '=profile=' . $wantProfile,
                ]);
                // Kick live session so new profile applies immediately
                $api->kickPPPoESession($c['mikrotik_username']);
                echo "    -> updated + session kicked\n";
            } catch (Throwable $e) {
                echo "    -> ERROR: {$e->getMessage()}\n";
            }
        }
    }

    try { $api->disconnect(); } catch (Throwable $_e) {}
    echo "\n";
}

echo "Done.\n";
