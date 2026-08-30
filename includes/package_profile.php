<?php
/**
 * Package → RouterOS profile name and rate-limit.
 *
 * Speed caps live on the package profile and nowhere else, so the name of that
 * profile is load-bearing: get it wrong and the customer runs uncapped.
 *
 * Why this file exists
 * --------------------
 * `packages.mikrotik_profile` was blank for every package on every tenant. Both
 * writers used `??`, which only fires on a MISSING key:
 *
 *     $mikrotik_profile = $_POST['mikrotik_profile'] ?? <derived>;   // create.php
 *     $mikrotik_profile = $_POST['mikrotik_profile'] ?? '';          // update.php
 *
 * The package form always submits the field, so the value was the empty string,
 * `??` never fired, and '' was written to the column. create.php then went on
 * to create a profile literally named '' on every router.
 *
 * The blank column was survivable in one path and only one: autoProvisionClient()
 * carries its own fallback and generates `pkg{id}-{slug}`. Everywhere else read
 * the column as `$package['mikrotik_profile'] ?? 'default'` — and `'' ?? 'default'`
 * is '', not 'default', so those paths pushed an empty profile name at RouterOS,
 * which resolves to the built-in `default` profile. That profile carries NO
 * rate-limit, so the cap was silently gone: a 5M plan delivered line speed.
 *
 * One rule, one place. The generated name must stay byte-identical to the one
 * autoProvisionClient() has been creating on live routers, or every client
 * silently moves to a second, empty profile on the next provision.
 */

/**
 * Canonical RouterOS profile name for a package.
 *
 * Uses the operator's explicit `mikrotik_profile` when they set one, otherwise
 * derives `pkg{id}-{slug}`. Never returns '' and never returns 'default'.
 *
 * @param array $package  Package row; needs at least 'id' and 'name'.
 */
function packageProfileName(array $package): string
{
    $explicit = trim((string)($package['mikrotik_profile'] ?? ''));

    // 'default' is rejected as deliberately as blank: RouterOS's default profile
    // has no rate-limit, so honouring it would disable the cap.
    if ($explicit !== '' && strcasecmp($explicit, 'default') !== 0) {
        return $explicit;
    }

    $slug = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower((string)($package['name'] ?? '')));

    // The package id keeps it unique — two packages can sanitise to the same slug.
    return 'pkg' . (int)($package['id'] ?? 0) . ($slug !== '' ? '-' . substr($slug, 0, 20) : '');
}

/**
 * RouterOS rate-limit string for a package: `rx/tx` from the ROUTER's view,
 * i.e. {upload}M/{download}M.
 *
 * Returns '' when the package has no download speed — uncapped, deliberately.
 * A missing speed used to default to 10 Mbps, silently over-delivering on every
 * package whose speed column was NULL.
 */
function packageRateLimit(array $package): string
{
    $download = (int)($package['download_speed'] ?? 0);
    $upload   = (int)($package['upload_speed'] ?? 0);

    if ($download <= 0) return '';
    if ($upload <= 0)   $upload = $download;   // mirror rather than leave upload open

    return "{$upload}M/{$download}M";
}

/**
 * Resolve the profile name AND persist it, so the column stops being empty and
 * the admin UI can show the operator which profile their package really uses.
 *
 * Best-effort: a deployment without the column still gets a usable name back.
 */
function ensurePackageProfileName(PDO $pdo, array $package): string
{
    $name     = packageProfileName($package);
    $existing = trim((string)($package['mikrotik_profile'] ?? ''));

    if ($existing === $name) return $name;

    try {
        $st = $pdo->prepare("UPDATE packages SET mikrotik_profile = ? WHERE id = ?");
        $st->execute([$name, (int)($package['id'] ?? 0)]);
    } catch (Throwable $e) {
        error_log('ensurePackageProfileName(' . ($package['id'] ?? '?') . '): ' . $e->getMessage());
    }

    return $name;
}

/**
 * Create the profile on a router if missing, and enforce its rate-limit if not.
 *
 * Called on package create/update so an operator changing a package's speed sees
 * the router change with it. Previously api/packages/update.php wrote the new
 * speed to the database and touched no router at all, so the profile kept the
 * old cap forever.
 *
 * @param MikrotikAPI $api       Connected API instance.
 * @param string $connectionType 'hotspot' | 'pppoe'
 * @return bool  TRUE when the profile now carries $rateLimit.
 */
function syncPackageProfileToRouter($api, string $connectionType, string $profileName, string $rateLimit): bool
{
    if ($profileName === '' || strcasecmp($profileName, 'default') === 0) {
        error_log("syncPackageProfileToRouter: refusing to touch profile '$profileName'");
        return false;
    }

    $base = ($connectionType === 'hotspot') ? '/ip/hotspot/user/profile' : '/ppp/profile';

    try {
        $existing  = $api->comm($base . '/print', ['?name=' . $profileName]);
        $profileId = null;
        foreach ((array)$existing as $p) {
            if (($p['name'] ?? '') === $profileName) { $profileId = $p['.id'] ?? null; break; }
        }

        if ($profileId !== null) {
            $api->comm($base . '/set', ['=.id=' . $profileId, '=rate-limit=' . $rateLimit]);
        } else {
            $api->comm($base . '/add', ['=name=' . $profileName, '=rate-limit=' . $rateLimit]);
        }

        // Read back: a profile that silently keeps an old or empty rate-limit is
        // exactly how customers end up on line speed.
        if ($rateLimit !== '') {
            $verify = $api->comm($base . '/print', ['?name=' . $profileName]);
            foreach ((array)$verify as $v) {
                if (($v['name'] ?? '') !== $profileName) continue;
                $actual = trim($v['rate-limit'] ?? '');
                if ($actual !== $rateLimit) {
                    error_log("syncPackageProfileToRouter: profile '$profileName' rate-limit is '$actual', expected '$rateLimit'");
                    return false;
                }
                return true;
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log("syncPackageProfileToRouter('$profileName'): " . $e->getMessage());
        return false;
    }
}
