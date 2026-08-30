<?php
/**
 * Fill in packages.mikrotik_profile, and optionally push each package's speed
 * cap to the routers.
 *
 *   php tools/backfill_package_profiles.php                 # dry run
 *   php tools/backfill_package_profiles.php --apply         # write the column
 *   php tools/backfill_package_profiles.php --apply --push  # ...and sync routers
 *   php tools/backfill_package_profiles.php --tenant=7 --apply --push
 *
 * Why this exists
 * ---------------
 * `packages.mikrotik_profile` was empty on every package on every tenant:
 * api/packages/create.php and api/packages/update.php both used `??` against a
 * form field the package modal always submits, so the empty string sailed past
 * the fallback and was written to the column.
 *
 * The blank column is not cosmetic. Speed limits live on the package profile and
 * nowhere else, so a caller that resolved the profile name to '' or to 'default'
 * put the customer on RouterOS's built-in default profile — which carries no
 * rate-limit at all. autoProvisionClient() had its own `pkg{id}-{slug}` fallback
 * and was safe; api/customers/update.php and api/mikrotik/sync_users.php did not
 * and were not.
 *
 * --push is the part that actually restores enforcement on routers that were
 * provisioned through one of those paths: it creates the profile if missing and
 * sets its rate-limit to {upload}M/{download}M.
 *
 * Packages with no download_speed are reported and SKIPPED on push rather than
 * given an invented cap — never guess a speed the operator did not sell.
 *
 * Idempotent.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/package_profile.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

$apply = in_array('--apply', $argv, true);
$push  = in_array('--push', $argv, true);

$onlyTenant = 0;
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0) $onlyTenant = (int)substr($a, 9);
}

echo $apply ? "=== APPLYING ===\n\n" : "=== DRY RUN (add --apply to commit) ===\n\n";

$sql = "SELECT id, tenant_id, name, mikrotik_profile, download_speed, upload_speed,
               COALESCE(NULLIF(connection_type,''), NULLIF(type,''), 'pppoe') AS conn_type
        FROM packages";
$params = [];
if ($onlyTenant) { $sql .= " WHERE tenant_id = ?"; $params[] = $onlyTenant; }
$sql .= " ORDER BY tenant_id, id";

$st = $pdo->prepare($sql);
$st->execute($params);
$packages = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$packages) exit("No packages found.\n");

$fixed    = 0;
$uncapped = [];
$byTenant = [];

printf("%-6s %-8s %-26s %-24s %-12s %s\n", 'PKG', 'TENANT', 'NAME', 'PROFILE', 'RATE-LIMIT', 'ACTION');
echo str_repeat('-', 100) . "\n";

foreach ($packages as $pkg) {
    $stored = trim((string)($pkg['mikrotik_profile'] ?? ''));
    $name   = packageProfileName($pkg);
    $rate   = packageRateLimit($pkg);
    $needs  = ($stored !== $name);

    if ($rate === '') $uncapped[] = $pkg;

    printf("%-6s %-8s %-26s %-24s %-12s %s\n",
        $pkg['id'], $pkg['tenant_id'], substr($pkg['name'], 0, 26), $name,
        $rate !== '' ? $rate : 'UNCAPPED',
        $needs ? ($stored === '' ? 'fill blank column' : "replace '$stored'") : 'ok');

    if ($needs) {
        $fixed++;
        if ($apply) {
            $pdo->prepare("UPDATE packages SET mikrotik_profile = ? WHERE id = ?")
                ->execute([$name, (int)$pkg['id']]);
        }
    }

    $pkg['_profile'] = $name;
    $pkg['_rate']    = $rate;
    $byTenant[(int)$pkg['tenant_id']][] = $pkg;
}

echo "\n$fixed package(s) " . ($apply ? 'updated' : 'would be updated') . ".\n";

if ($uncapped) {
    echo "\n" . count($uncapped) . " package(s) have NO download_speed and are therefore uncapped.\n";
    echo "These are NOT given an invented limit — set a speed on the package and re-run:\n";
    foreach ($uncapped as $u) {
        printf("  package %-5s tenant %-4s %s\n", $u['id'], $u['tenant_id'], $u['name']);
    }
}

if (!$push) {
    echo "\nRouters were not touched. Add --push to create/repair the profiles on them.\n";
    if (!$apply) echo "Nothing was written. Re-run with --apply to commit.\n";
    exit;
}

if (!$apply) {
    exit("\n--push requires --apply.\n");
}

echo "\n=== Pushing profiles to routers ===\n";

foreach ($byTenant as $tenantId => $pkgs) {
    $rSt = $pdo->prepare("SELECT id, ip_address, vpn_ip, username, password, api_port
                          FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
    $rSt->execute([$tenantId]);
    $routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

    if (!$routers) {
        echo "  tenant $tenantId: no active routers\n";
        continue;
    }

    foreach ($routers as $router) {
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        echo "  tenant $tenantId router {$router['ip_address']} ($connectIp): ";

        try {
            $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $router['api_port']);
            if (!$api->connect()) { echo "UNREACHABLE\n"; continue; }
        } catch (Throwable $e) {
            echo "UNREACHABLE (" . $e->getMessage() . ")\n";
            continue;
        }

        $ok = 0; $skip = 0; $bad = 0;
        foreach ($pkgs as $pkg) {
            // Never push an empty rate-limit over a profile: that is the same as
            // removing the cap, and a package with no speed set is a
            // configuration problem to fix, not one to propagate to routers.
            if ($pkg['_rate'] === '') { $skip++; continue; }
            if (syncPackageProfileToRouter($api, $pkg['conn_type'] === 'hotspot' ? 'hotspot' : 'pppoe',
                                           $pkg['_profile'], $pkg['_rate'])) {
                $ok++;
            } else {
                $bad++;
            }
        }

        try { $api->disconnect(); } catch (Throwable $e) {}
        echo "$ok profile(s) set" . ($skip ? ", $skip skipped (no speed)" : '') . ($bad ? ", $bad FAILED" : '') . "\n";
    }
}

echo "\nDone. RouterOS applies a profile's rate-limit to NEW sessions only, so\n";
echo "customers already online keep their old speed until they reconnect.\n";
