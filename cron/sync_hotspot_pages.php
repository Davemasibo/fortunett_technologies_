<?php
/**
 * Server-side hotspot portal sweep.
 *
 * Belt-and-braces companion to the router-side scheduler: walks every active
 * router the server *can* reach and makes sure it (a) holds the current login
 * page and (b) has the self-update script installed. Routers behind CGNAT are
 * skipped here — the scheduler covers those.
 *
 * Cron (hourly, offset from the top of the hour to spread load):
 *   30 * * * * php /var/www/html/cron/sync_hotspot_pages.php >> /var/log/fortunett_portal_sync.log 2>&1
 *
 * Flags:
 *   --tenant=12   only this tenant
 *   --router=7    only this router
 *   --force       re-upload even when the router already reports the current build
 *   --check       report only: what build each router holds vs what is published.
 *                 Installs nothing and triggers nothing, so it is safe to run
 *                 against a live fleet just to answer "did they pull it yet?".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auto_provision.php';
require_once __DIR__ . '/../includes/hotspot_sync.php';
require_once __DIR__ . '/../hotspot/render_login.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';

// Not stamped on --check: the heartbeat is how super_admin/diagnostics.php
// knows the SCHEDULED sweep is alive, and a hand-run report must not make a
// missing crontab line look healthy.
if (!isset(getopt('', ['check'])['check'])) {
    cron_heartbeat($pdo, 'sync_hotspot_pages');
}

$opts       = getopt('', ['tenant::', 'router::', 'force', 'check']);
$onlyTenant = isset($opts['tenant']) ? (int)$opts['tenant'] : 0;
$onlyRouter = isset($opts['router']) ? (int)$opts['router'] : 0;
$force      = isset($opts['force']);
$checkOnly  = isset($opts['check']);

function out(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

$sql    = "SELECT r.*, t.id AS t_id FROM mikrotik_routers r
           JOIN tenants t ON t.id = r.tenant_id
           WHERE r.status IN ('active','online')";
$params = [];
if ($onlyTenant) { $sql .= " AND r.tenant_id = ?"; $params[] = $onlyTenant; }
if ($onlyRouter) { $sql .= " AND r.id = ?";        $params[] = $onlyRouter; }
$sql .= " ORDER BY r.tenant_id, r.id";

$st = $pdo->prepare($sql);
$st->execute($params);
$routers = $st->fetchAll(PDO::FETCH_ASSOC);

out(($checkOnly ? 'Checking ' : 'Sweeping ') . count($routers) . ' router(s)'
    . ($checkOnly ? ' — report only, nothing will be changed' : ''));

// One fingerprint per tenant — rendering the page is the expensive part
$versionCache = [];
$stats = ['ok' => 0, 'skipped' => 0, 'unreachable' => 0, 'failed' => 0];

foreach ($routers as $router) {
    $tenantId = (int)$router['tenant_id'];
    $label    = ($router['name'] ?: $router['ip_address']) . " (tenant $tenantId, router {$router['id']})";

    $urls = hotspotPortalUrls($pdo, $tenantId);
    if (!$urls) {
        out("  SKIP  $label — tenant has no provisioning token");
        $stats['skipped']++;
        continue;
    }

    // Current published fingerprint for this tenant
    if (!array_key_exists($tenantId, $versionCache)) {
        try {
            $tSt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
            $tSt->execute([$tenantId]);
            $tenantRow = $tSt->fetch(PDO::FETCH_ASSOC);
            $versionCache[$tenantId] = $tenantRow
                ? substr(sha1(renderHotspotLoginPage($pdo, $tenantRow)), 0, 12)
                : null;
        } catch (Throwable $e) {
            $versionCache[$tenantId] = null;
            out("  WARN  tenant $tenantId — could not render page: " . $e->getMessage());
        }
    }
    $currentVersion = $versionCache[$tenantId];

    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $apiPort   = (int)($router['api_port'] ?? 8728);

    $sock = @fsockopen($connectIp, $apiPort, $errno, $errstr, 5);
    if (!$sock) {
        // Expected for CGNAT routers — their own scheduler handles the update.
        out("  ---   $label — unreachable at $connectIp:$apiPort, leaving it to the router scheduler");
        $stats['unreachable']++;
        continue;
    }
    fclose($sock);

    try {
        $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $apiPort);
        $api->connect();

        // Always (re)install the scheduler — cheap, and it upgrades routers that
        // hold an older version of the script body. Skipped on --check, which
        // must not change a single thing on the router.
        $sched = $checkOnly
            ? ['installed' => true, 'message' => '']
            : installHotspotSyncScheduler($api, $urls['page'], $urls['version']);

        // Does the router already hold this build?
        $onRouter = null;
        try {
            foreach ($api->comm('/file/print', ['?name=fortunett-portal.ver']) as $f) {
                if (isset($f['contents'])) { $onRouter = trim($f['contents']); break; }
            }
        } catch (Throwable $_e) {}

        if ($checkOnly) {
            // A router with no sync script will NEVER catch up on its own. That
            // is the failure worth naming: it looks identical to "up to date"
            // in every other view, and it is what happens to routers
            // provisioned before the self-update scheduler existed.
            $hasScript = false;
            try {
                foreach ($api->comm('/system/script/print') as $sc) {
                    if (($sc['name'] ?? '') === HOTSPOT_SYNC_NAME) { $hasScript = true; break; }
                }
            } catch (Throwable $_e) {}
            try { $api->disconnect(); } catch (Throwable $_e) {}

            if ($currentVersion !== null && $onRouter === $currentVersion) {
                out("  CURRENT  $label — holds $onRouter");
                $stats['ok']++;
            } else {
                out("  STALE    $label — holds " . ($onRouter ?: 'no build marker')
                    . ', published is ' . ($currentVersion ?: '?'));
                $stats['failed']++;
            }
            if (!$hasScript) {
                out("           no {" . HOTSPOT_SYNC_NAME . "} script on this router — it will never "
                    . 'self-update. Run this sweep without --check, or use Sync Portal on mikrotik.php.');
            }
            continue;
        }

        if (!$force && $currentVersion !== null && $onRouter === $currentVersion) {
            out("  OK    $label — already on $currentVersion" . ($sched['installed'] ? '' : ' (scheduler: ' . $sched['message'] . ')'));
            $stats['ok']++;
            try { $api->disconnect(); } catch (Throwable $_e) {}
            continue;
        }

        // Out of date (or unknown) — trigger the router's own sync script so the
        // download path is identical to the scheduled run.
        $scriptId = null;
        foreach ($api->comm('/system/script/print') as $s) {
            if (($s['name'] ?? '') === HOTSPOT_SYNC_NAME) { $scriptId = $s['.id'] ?? null; break; }
        }
        if (!$scriptId) {
            throw new RuntimeException('sync script missing after install');
        }
        $api->comm('/system/script/run', ['=.id=' . $scriptId]);
        try { $api->disconnect(); } catch (Throwable $_e) {}

        out("  PUSH  $label — sync triggered (was: " . ($onRouter ?: 'unknown') . ", now: " . ($currentVersion ?: '?') . ')');
        $stats['ok']++;

    } catch (Throwable $e) {
        out("  FAIL  $label — " . $e->getMessage());
        $stats['failed']++;

        // --check promises to change nothing, and that promise has to hold on
        // the error path too: the recovery below UPLOADS a page.
        if ($checkOnly) {
            continue;
        }

        // Fall back to the direct upload path, which also re-fixes profiles and
        // the walled garden if the router drifted.
        try {
            _uploadHotspotLoginPage($pdo, $router, $tenantId);
            out("        recovered via direct upload");
            $stats['failed']--;
            $stats['ok']++;
        } catch (Throwable $e2) {
            out("        direct upload also failed: " . $e2->getMessage());
        }
    }
}

out($checkOnly
    ? sprintf('Done — %d current, %d stale, %d unreachable, %d skipped',
        $stats['ok'], $stats['failed'], $stats['unreachable'], $stats['skipped'])
    : sprintf('Done — %d synced, %d unreachable (self-updating), %d skipped, %d failed',
        $stats['ok'], $stats['unreachable'], $stats['skipped'], $stats['failed'])
);
