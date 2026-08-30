<?php
/**
 * Auto-Provisioning Helper
 *
 * Called after a client is activated (free package or M-Pesa payment).
 * Finds the tenant's first active router, creates the PPPoE secret or hotspot
 * user, uploads the branded hotspot login page via FTP, and records the service.
 *
 * Returns an array: ['success' => bool, 'message' => string, ...]
 * Failures are logged but never bubble up to callers — activation already
 * succeeded; provisioning is best-effort.
 */

require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/radius_client.php';
require_once __DIR__ . '/package_profile.php';
require_once __DIR__ . '/hotspot_sync.php';

/**
 * Provision a newly activated client on their tenant's first active router.
 *
 * @param PDO $pdo
 * @param int $clientId
 * @param int $tenantId
 * @return array
 */
function autoProvisionClient(PDO $pdo, int $clientId, int $tenantId): array
{
    try {
        // ── Fetch client ──────────────────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$clientId, $tenantId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            return ['success' => false, 'message' => 'Client not found'];
        }

        // ── Fetch package ─────────────────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$client['package_id'], $tenantId]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$package) {
            return ['success' => false, 'message' => 'Package not found'];
        }

        // ── Find the tenant's first active router ─────────────────────────────
        $stmt = $pdo->prepare("
            SELECT * FROM mikrotik_routers
            WHERE tenant_id = ? AND status = 'active'
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) {
            return ['success' => false, 'message' => 'No active router found for tenant'];
        }

        // ── Determine service type ────────────────────────────────────────────
        $connType = $client['connection_type'] ?? ($package['type'] ?? 'hotspot');
        $connType = ($connType === 'pppoe') ? 'pppoe' : 'hotspot';

        // ── Resolve credentials ───────────────────────────────────────────────
        $username = $client['mikrotik_username']
            ?: ('user_' . substr(preg_replace('/\D/', '', $client['phone'] ?? ''), -8));
        $password = $client['mikrotik_password'] ?: bin2hex(random_bytes(4));

        // Speeds come from the package and nowhere else. The old code defaulted a
        // missing download_speed to 10 Mbps, which silently handed out 10 Mbps on
        // any package whose speed column was NULL — the customer got far more than
        // they paid for and nothing in the UI explained why.
        $downloadSpeed = (int)($package['download_speed'] ?? 0);
        $uploadSpeed   = (int)($package['upload_speed']   ?? 0);

        if ($downloadSpeed > 0 && $uploadSpeed <= 0) {
            // Only a download cap configured — mirror it so upload is capped too
            // rather than left wide open.
            $uploadSpeed = $downloadSpeed;
        }

        // RouterOS rate-limit format: rx-rate/tx-rate from the ROUTER's view.
        // rx = what the client uploads, tx = what the client downloads.
        $rateLimit = ($downloadSpeed > 0)
            ? "{$uploadSpeed}M/{$downloadSpeed}M"
            : '';   // empty = uncapped; only when the package genuinely has no speed

        if ($rateLimit === '') {
            error_log(sprintf(
                'autoProvisionClient(%d): package #%d "%s" has no download_speed — provisioning UNCAPPED. Set a speed on the package to enforce a limit.',
                $clientId, (int)$package['id'], $package['name'] ?? '?'
            ));
        }

        // One shared profile per package. It must never resolve to "default":
        // RouterOS's default profile carries no rate-limit, so falling back to it
        // silently disabled the speed cap entirely. packageProfileName() is the
        // single generator of this name - every other caller uses it too, so the
        // profile a client is put on is the same one the package page shows and
        // the same one api/packages/update.php pushes rate-limit changes to.
        $profileName = packageProfileName($package);

        // Persist the derived name so it stops being invisible: packages.php read
        // a blank column as "no profile" and the operator had no way to tell which
        // profile their customers were actually on.
        if (trim((string)($package['mikrotik_profile'] ?? '')) === '') {
            ensurePackageProfileName($pdo, $package);
        }

        // ── Connect to router — prefer VPN IP (WireGuard) over public IP ─────
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $api = new MikrotikAPI(
            $connectIp,
            $router['username'],
            $router['password']
        );

        if (!$api->isReachable(4)) {
            return ['success' => false, 'message' => 'Router not reachable: ' . $router['ip_address']];
        }

        $api->connect();

        // ── Pre-flight: detect bridge + running service (ADVISORY ONLY) ─────────
        // Never block provisioning here. Hotspots/PPPoE legitimately run on a plain
        // interface or VLAN with no bridge, so a missing bridge is not an error.
        // RouterOS itself returns a clear trap if the hotspot/PPPoE server is truly
        // missing (handled in _provisionHotspot/_provisionPPPoE). Hard-failing here
        // blocked working setups and broke hotspot connect + renewal.
        $bridgeCheck = _verifyServiceOnBridge($api, $connType);
        if (!$bridgeCheck['ok']) {
            error_log("autoProvisionClient($clientId): pre-flight warning — " . $bridgeCheck['message']);
        }

        // ── Provision ─────────────────────────────────────────────────────────
        // hotspot_shared_users: 0 = unlimited, 1 = one active session per user
        $sharedUsers = ((int)($router['hotspot_shared_users'] ?? 0) === 1) ? '1' : 'unlimited';

        // Use the hotspot server detected during bridge check if not overridden by package
        $hotspotServer = !empty($package['hotspot_server']) ? $package['hotspot_server'] : ($bridgeCheck['server_name'] ?? 'all');

        // Fetch server IP for captive portal setup
        $serverIp = '';
        try {
            $ipSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $serverIp = $ipSt ? ($ipSt->fetchColumn() ?: '') : '';
        } catch (Throwable $_e) {}

        if ($connType === 'pppoe') {
            // Ensure captive-portal infrastructure exists before provisioning the real profile
            if ($serverIp) {
                try { _setupPPPoECaptivePortal($api, $serverIp); } catch (Throwable $_e) {
                    error_log('PPPoE captive portal setup: ' . $_e->getMessage());
                }
            }
            _provisionPPPoE($api, $username, $password, $profileName, $rateLimit, $client['full_name'] ?? '');

            // Sync to RADIUS (best-effort — runs alongside MikroTik API provisioning)
            try {
                radius_sync_client($pdo, array_merge($client, ['mikrotik_username' => $username, 'mikrotik_password' => $password]), $package);
            } catch (Throwable $_e) {
                error_log('RADIUS sync on provision: ' . $_e->getMessage());
            }
        } else {
            _provisionHotspot($api, $username, $password, $profileName, $rateLimit, $client['full_name'] ?? '', $sharedUsers, $hotspotServer);
        }

        $api->disconnect();

        // ── Upload hotspot login page (hotspot only) ──────────────────────────
        // Non-fatal during provisioning — failure is reported by the deploy button separately.
        if ($connType === 'hotspot') {
            try { _uploadHotspotLoginPage($pdo, $router, $tenantId); } catch (Throwable $_e) {}
        }

        // ── Persist credentials & service record ──────────────────────────────
        $pdo->prepare("
            UPDATE clients SET mikrotik_username = ?, mikrotik_password = ?
            WHERE id = ? AND tenant_id = ?
        ")->execute([$username, $password, $clientId, $tenantId]);

        $pdo->prepare("
            INSERT INTO router_services
                (tenant_id, router_id, client_id, service_type, package_id, username, password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE
                password = VALUES(password),
                status   = VALUES(status),
                deployed_at = CURRENT_TIMESTAMP
        ")->execute([
            $tenantId,
            $router['id'],
            $clientId,
            $connType,
            $client['package_id'],
            $username,
            $password,
        ]);

        return [
            'success'  => true,
            'message'  => 'Provisioned successfully',
            'username' => $username,
            'password' => $password,
            'service'  => $connType,
            'profile'  => $profileName,
            'router'   => $router['name'] ?? $router['ip_address'],
        ];

    } catch (Throwable $e) {
        error_log("autoProvisionClient($clientId, $tenantId): " . $e->getMessage());
        return ['success' => false, 'message' => 'Provisioning error: ' . $e->getMessage()];
    }
}

// ── Private helpers ────────────────────────────────────────────────────────────

/**
 * Clear a per-record rate-limit that would override the package profile's cap.
 *
 * Sent as its own request, and only when the earlier /print actually returned a
 * non-empty rate-limit for this record. Two reasons:
 *
 *  - The property does not exist on /ppp/secret in every RouterOS build.
 *    Including it in the main set() made the whole call fail with
 *    "unknown parameter rate", which broke customer creation entirely.
 *  - Isolating it means a failure here cannot take the provisioning with it.
 *
 * @param MikrotikAPI $api     Connected API instance.
 * @param array       $printed Rows previously returned by the matching /print
 */
function _clearStaleRateLimit($api, string $path, array $printed, string $recordId): void
{
    $stale = false;
    foreach ($printed as $row) {
        if (($row['.id'] ?? null) !== $recordId) continue;
        $stale = array_key_exists('rate-limit', $row) && trim((string)$row['rate-limit']) !== '';
        break;
    }
    if (!$stale) {
        return;
    }

    try {
        $api->comm($path . '/set', ['=.id=' . $recordId, '=rate-limit=']);
        error_log("_clearStaleRateLimit: cleared per-user rate-limit on $path $recordId");
    } catch (Throwable $e) {
        // Non-fatal — the profile cap still applies to new sessions
        error_log("_clearStaleRateLimit($path): " . $e->getMessage());
    }
}

/**
 * Create or update a PPPoE secret and ensure it is enabled.
 * Uses the package-level profile (profileName). Creates the profile on the router
 * if it doesn't exist yet (e.g. router was offline when the package was saved).
 */
function _provisionPPPoE(MikrotikAPI $api, string $username, string $password, string $profileName, string $rateLimit, string $comment): void
{
    // Ensure the package profile exists with the correct rate-limit.
    // Skip 'default' — it always exists and shouldn't be modified.
    // IMPORTANT: even if the profile exists, update its rate-limit so speed caps are enforced.
    if ($profileName !== 'default') {
        $profiles = $api->comm('/ppp/profile/print', ['?name=' . $profileName]);
        $profileId = null;
        foreach ($profiles as $p) {
            if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $profileId = $p['.id'] ?? null; break; }
        }
        if ($profileId !== null) {
            // Profile exists — enforce rate-limit in case it changed or was never set
            $api->comm('/ppp/profile/set', [
                '=.id='        . $profileId,
                '=rate-limit=' . $rateLimit,
            ]);
        } else {
            // Profile missing on this router — create it with rate-limit only.
            // Do NOT reference pppoe-pool (it may not exist on new routers).
            $api->comm('/ppp/profile/add', [
                '=name='        . $profileName,
                '=rate-limit='  . $rateLimit,
            ]);
        }
    }

    // Upsert PPPoE secret — use case-insensitive match so "User1" ≡ "user1"
    $secrets = $api->comm('/ppp/secret/print', ['?name=' . $username]);
    $secretId = null;
    foreach ($secrets as $s) {
        if (isset($s['!re']) && strcasecmp($s['name'] ?? '', $username) === 0) { $secretId = $s['.id']; break; }
    }

    if ($secretId !== null) {
        $api->comm('/ppp/secret/set', [
            '=.id='       . $secretId,
            '=password='  . $password,
            '=profile='   . $profileName,
            '=service=pppoe',
        ]);
        // A rate-limit set on the secret itself overrides the profile's cap, so a
        // stale one must go. It is cleared in a SEPARATE call, and only when the
        // print output actually reported the property — not every RouterOS build
        // exposes rate-limit on /ppp/secret, and blindly sending it fails the
        // whole request with "unknown parameter", which breaks provisioning.
        _clearStaleRateLimit($api, '/ppp/secret', $secrets, $secretId);
        // Re-enable in case it was disabled
        $api->comm('/ppp/secret/enable', ['=.id=' . $secretId]);
        // Kick any live session so the CPE must re-auth with the new password.
        // Without this, a connected CPE holds an open session indefinitely despite
        // the password change, and reconnects immediately after a kick because the
        // old secret is still cached in the PPP daemon state.
        $api->kickPPPoESession($username);
    } else {
        $api->comm('/ppp/secret/add', [
            '=name='     . $username,
            '=password=' . $password,
            '=profile='  . $profileName,
            '=service=pppoe',
            '=comment='  . $comment,
        ]);
    }
}

/**
 * Create or update a hotspot user, enable it, set MAC auth, and reconnect
 * any active session so the customer gets internet access immediately.
 *
 * Uses the package-level profile (profileName) — one shared profile per package.
 * Creates the profile on the router if it doesn't exist yet.
 *
 * @param string $sharedUsers  RouterOS shared-users value: '1' (no sharing) or 'unlimited'
 */
function _provisionHotspot(MikrotikAPI $api, string $username, string $password, string $profileName, string $rateLimit, string $comment, string $sharedUsers = 'unlimited', string $hotspotServer = 'all'): void
{
    // ── Ensure package profile exists with correct rate-limit ─────────────────
    // One profile per package; create on first use, update rate-limit on every
    // provisioning call so speed caps are enforced even on new/re-added routers.
    // $profileName is guaranteed by the caller never to be "default" — RouterOS's
    // default profile has no rate-limit, so using it means no cap at all.
    $profiles = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profileName]);
    $profileId = null;
    foreach ($profiles as $p) {
        if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $profileId = $p['.id'] ?? null; break; }
    }
    if ($profileId !== null) {
        // Profile exists — enforce rate-limit in case it changed or was never set
        $api->comm('/ip/hotspot/user/profile/set', [
            '=.id='          . $profileId,
            '=rate-limit='   . $rateLimit,
            '=shared-users=' . $sharedUsers,
        ]);
    } else {
        $api->comm('/ip/hotspot/user/profile/add', [
            '=name='         . $profileName,
            '=rate-limit='   . $rateLimit,
            '=shared-users=' . $sharedUsers,
        ]);
    }

    // Read back and confirm the cap actually stuck. A profile that silently keeps
    // an old or empty rate-limit is exactly how customers end up on line speed.
    if ($rateLimit !== '') {
        try {
            $verify = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profileName]);
            foreach ($verify as $v) {
                if (!isset($v['!re']) || ($v['name'] ?? '') !== $profileName) continue;
                $actual = trim($v['rate-limit'] ?? '');
                if ($actual !== $rateLimit) {
                    error_log("_provisionHotspot: profile '$profileName' rate-limit is '$actual', expected '$rateLimit'");
                }
                break;
            }
        } catch (Throwable $_e) { /* verification only */ }
    }

    // ── Upsert hotspot user ───────────────────────────────────────────────────
    $userId    = null;
    $isNewUser = false;
    $users     = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
    foreach ($users as $u) {
        if (isset($u['!re']) && strcasecmp($u['name'] ?? '', $username) === 0) { $userId = $u['.id']; break; }
    }

    if ($userId !== null) {
        // Credential update — clear MAC so device must re-auth at the captive portal
        // with the new password rather than bypassing via MAC auth.
        //
        $api->comm('/ip/hotspot/user/set', [
            '=.id='         . $userId,
            '=password='    . $password,
            '=profile='     . $profileName,
            '=mac-address=',
        ]);
        // A rate-limit on the USER overrides the profile's cap — that is how a 5M
        // plan ended up delivering line speed. Cleared separately and only when
        // the record actually carries the property (see _clearStaleRateLimit).
        _clearStaleRateLimit($api, '/ip/hotspot/user', $users, $userId);
        // Re-enable in case it was disabled by expiry/suspension
        $api->comm('/ip/hotspot/user/enable', ['=.id=' . $userId]);
        // Kick any live session so the device is redirected to the captive portal
        $api->kickHotspotSession($username);
    } else {
        $isNewUser = true;
        // No rate-limit here: a fresh user has none, and the property is not
        // accepted on /add across all RouterOS builds. The profile governs.
        $addResp = $api->comm('/ip/hotspot/user/add', [
            '=name='       . $username,
            '=password='   . $password,
            '=profile='    . $profileName,
            '=comment='    . $comment,
            '=server='     . $hotspotServer,
        ]);
        // Check for RouterOS trap (error) — e.g. hotspot not configured on router
        foreach ($addResp as $r) {
            if (isset($r['!trap'])) {
                throw new \RuntimeException('Hotspot user add failed: ' . ($r['message'] ?? 'RouterOS error. Is hotspot configured on this router?'));
            }
        }
        // Fetch the .id of the newly created user
        $newUsers = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
        foreach ($newUsers as $u) {
            if (isset($u['!re']) && strcasecmp($u['name'] ?? '', $username) === 0) { $userId = $u['.id']; break; }
        }
    }

    // ── MAC Auth + Immediate Reconnect (new users only) ───────────────────────
    // For a first-time provisioning, capture the device's MAC from any active
    // session and bind it to the user record for seamless future reconnections,
    // then kick so the device re-authenticates via MAC bypass immediately.
    //
    // For credential updates this block is skipped — the MAC was already cleared
    // above and the session was kicked, so the device must go through the portal.
    if ($isNewUser) {
        try {
            $activeSessions = $api->comm('/ip/hotspot/active/print');
            $reconnected    = false;

            foreach ($activeSessions as $session) {
                if (!isset($session['!re'])) continue;
                if (strcasecmp($session['user'] ?? '', $username) !== 0) continue;

                $mac       = $session['mac-address'] ?? null;
                $sessionId = $session['.id']         ?? null;

                // Bind MAC so the device is recognised on next reconnect
                if ($mac && $userId) {
                    $api->comm('/ip/hotspot/user/set', [
                        '=.id='         . $userId,
                        '=mac-address=' . $mac,
                    ]);
                }

                // Kick → device reconnects → MAC auth grants immediate access
                if ($sessionId) {
                    $api->comm('/ip/hotspot/active/remove', ['=.id=' . $sessionId]);
                    $reconnected = true;
                }
                break;
            }

            // Not in an active session — remove any stale host entry so the device
            // is forced to re-authenticate (MAC auth fires on next connection).
            if (!$reconnected && $userId) {
                $uu = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
                foreach ($uu as $u) {
                    if (!isset($u['!re'])) continue;
                    if (strcasecmp($u['name'] ?? '', $username) !== 0) continue;
                    $knownMac = $u['mac-address'] ?? null;
                    if ($knownMac) {
                        $hosts = $api->comm('/ip/hotspot/host/print');
                        foreach ($hosts as $h) {
                            if (!isset($h['!re'])) continue;
                            if (($h['mac-address'] ?? '') === $knownMac && isset($h['.id'])) {
                                $api->comm('/ip/hotspot/host/remove', ['=.id=' . $h['.id']]);
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('_provisionHotspot MAC reconnect: ' . $e->getMessage());
        }
    }
}

/**
 * One-time idempotent setup of PPPoE captive portal infrastructure on a router.
 *
 * Creates:
 *  - fortunett-limited-pool  (10.88.0.2–10.88.0.253)  — IP pool for unactivated customers
 *  - fortunett-limited        PPP profile using that pool, DNS via router
 *  - FortuNett-PPPoE-Allow    filter rule: allow limited → portal server
 *  - FortuNett-PPPoE-Block    filter rule: reject all other traffic from limited subnet
 *  - FortuNett-PPPoE-Portal   NAT dstnat: redirect port 80 from limited subnet → portal server
 *  - /ip dns allow-remote-requests=yes  (so clients can resolve hostnames via router)
 *
 * All operations check for existence first — safe to call multiple times.
 * No hotspot package required — uses only core RouterOS (firewall + PPP).
 */
function _setupPPPoECaptivePortal(MikrotikAPI $api, string $serverIp): void
{
    $poolName    = 'fortunett-limited-pool';
    $poolRanges  = '10.88.0.2-10.88.0.253';
    $localAddr   = '10.88.0.1';  // PPP tunnel local end (router side)
    $limitedNet  = '10.88.0.0/24';
    $profileName = 'fortunett-limited';
    $natComment  = 'FortuNett-PPPoE-Portal';
    $allowComment = 'FortuNett-PPPoE-Allow';
    $blockComment = 'FortuNett-PPPoE-Block';

    // ── Enable DNS so limited clients can resolve hostnames ───────────────────
    try {
        $api->comm('/ip/dns/set', ['=allow-remote-requests=yes']);
    } catch (Throwable $_e) {}

    // ── 1. Address pool ───────────────────────────────────────────────────────
    $pools = $api->comm('/ip/pool/print');
    $poolExists = false;
    foreach ($pools as $p) {
        if (($p['name'] ?? '') === $poolName) { $poolExists = true; break; }
    }
    if (!$poolExists) {
        $api->comm('/ip/pool/add', [
            '=name='   . $poolName,
            '=ranges=' . $poolRanges,
            '=comment=FortuNett Captive Portal - Unactivated PPPoE',
        ]);
    }

    // ── 2. PPP profile ────────────────────────────────────────────────────────
    $profiles = $api->comm('/ppp/profile/print');
    $profileExists = false;
    foreach ($profiles as $p) {
        if (($p['name'] ?? '') === $profileName) { $profileExists = true; break; }
    }
    if (!$profileExists) {
        $api->comm('/ppp/profile/add', [
            '=name='           . $profileName,
            '=local-address='  . $localAddr,
            '=remote-address=' . $poolName,
            '=rate-limit=512k/512k',
            '=dns-server='     . $localAddr,   // router answers DNS queries
            '=session-timeout=24h',
            '=comment=FortuNett Unactivated - redirected to captive portal',
        ]);
    }

    // ── 3. NAT: redirect port 80 from limited subnet → captive portal ─────────
    $natRules = $api->comm('/ip/firewall/nat/print');
    $natExists = false;
    foreach ($natRules as $r) {
        if (($r['comment'] ?? '') === $natComment) { $natExists = true; break; }
    }
    if (!$natExists) {
        $api->comm('/ip/firewall/nat/add', [
            '=chain=dstnat',
            '=protocol=tcp',
            '=dst-port=80',
            '=src-address=' . $limitedNet,
            '=action=dst-nat',
            '=to-addresses=' . $serverIp,
            '=to-ports=80',
            '=comment=' . $natComment,
        ]);
    }

    // ── 3b. NAT: masquerade the limited subnet ────────────────────────────────
    // The dst-nat above rewrites the destination, but the packet still leaves the
    // router with a 10.88.0.x source. Without a matching srcnat the reply never
    // comes back, so an unactivated customer sees a blank page instead of the
    // payment portal and has no way to pay their way out of it.
    $natMasqComment = 'FortuNett-PPPoE-Limited-NAT';
    $masqExists = false;
    foreach ($natRules as $r) {
        if (($r['comment'] ?? '') === $natMasqComment) { $masqExists = true; break; }
    }
    if (!$masqExists) {
        try {
            $api->comm('/ip/firewall/nat/add', [
                '=chain=srcnat',
                '=src-address=' . $limitedNet,
                '=action=masquerade',
                '=comment=' . $natMasqComment,
            ]);
        } catch (Throwable $_e) {
            error_log('_setupPPPoECaptivePortal masquerade: ' . $_e->getMessage());
        }
    }

    // ── 4a. Filter: allow limited → portal server ─────────────────────────────
    $filterRules = $api->comm('/ip/firewall/filter/print');
    $allowExists = false;
    $blockExists = false;
    foreach ($filterRules as $r) {
        if (($r['comment'] ?? '') === $allowComment) { $allowExists = true; }
        if (($r['comment'] ?? '') === $blockComment)  { $blockExists = true; }
    }
    if (!$allowExists) {
        $api->comm('/ip/firewall/filter/add', [
            '=chain=forward',
            '=src-address=' . $limitedNet,
            '=dst-address=' . $serverIp . '/32',
            '=action=accept',
            '=comment=' . $allowComment,
        ]);
    }

    // ── 4b. Filter: block all other forward traffic from limited subnet ────────
    if (!$blockExists) {
        $api->comm('/ip/firewall/filter/add', [
            '=chain=forward',
            '=src-address=' . $limitedNet,
            '=action=reject',
            '=reject-with=tcp-reset',
            '=comment=' . $blockComment,
        ]);
    }
}

/**
 * Pre-provision a new PPPoE client with the limited captive-portal profile
 * BEFORE they have paid. The customer connects PPPoE, gets an IP from the
 * limited pool, and every HTTP request is redirected to the FortuNett renewal
 * page. After payment autoProvisionClient() moves them to their real profile.
 *
 * Called from api/clients.php when admin creates a new PPPoE client.
 *
 * @return array ['success'=>bool, 'username'=>string, 'password'=>string, ...]
 */
function preProvisionPPPoEClient(PDO $pdo, int $clientId, int $tenantId): array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$clientId, $tenantId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) return ['success' => false, 'message' => 'Client not found'];

        $stmt = $pdo->prepare("
            SELECT * FROM mikrotik_routers
            WHERE tenant_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) return ['success' => false, 'message' => 'No active router found'];

        $username = $client['mikrotik_username']
            ?: ('user_' . substr(preg_replace('/\D/', '', $client['phone'] ?? ''), -8));
        $password = $client['mikrotik_password'] ?: bin2hex(random_bytes(4));

        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $apiPort   = (int)($router['api_port'] ?? 8728);
        $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $apiPort);

        if (!$api->isReachable(4)) {
            return ['success' => false, 'message' => 'Router not reachable: ' . $connectIp];
        }
        $api->connect();

        // ── Pre-flight: advisory check only (never blocks — see autoProvisionClient) ──
        $bridgeCheck = _verifyServiceOnBridge($api, 'pppoe');
        if (!$bridgeCheck['ok']) {
            error_log("preProvisionPPPoEClient($clientId): pre-flight warning — " . $bridgeCheck['message']);
        }

        // Get server IP and ensure captive portal infrastructure exists
        $serverIp = '';
        try {
            $ipSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $serverIp = $ipSt ? ($ipSt->fetchColumn() ?: '') : '';
        } catch (Throwable $_e) {}

        if ($serverIp) {
            _setupPPPoECaptivePortal($api, $serverIp);
        }

        // Create or update PPPoE secret with limited profile
        $secrets  = $api->comm('/ppp/secret/print', ['?name=' . $username]);
        $secretId = null;
        foreach ($secrets as $s) {
            if (strcasecmp($s['name'] ?? '', $username) === 0) { $secretId = $s['.id']; break; }
        }

        if ($secretId !== null) {
            $api->comm('/ppp/secret/set', [
                '=.id='      . $secretId,
                '=password=' . $password,
                '=profile=fortunett-limited',
                '=service=pppoe',
            ]);
            $api->comm('/ppp/secret/enable', ['=.id=' . $secretId]);
        } else {
            $api->comm('/ppp/secret/add', [
                '=name='     . $username,
                '=password=' . $password,
                '=profile=fortunett-limited',
                '=service=pppoe',
                '=comment='  . ($client['full_name'] ?? ''),
            ]);
        }
        $api->disconnect();

        // Persist credentials so autoProvisionClient() can reuse them
        $pdo->prepare("UPDATE clients SET mikrotik_username = ?, mikrotik_password = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$username, $password, $clientId, $tenantId]);

        return [
            'success'  => true,
            'username' => $username,
            'password' => $password,
            'message'  => 'Pre-provisioned with limited profile. Customer connects PPPoE → gets captive portal.',
        ];
    } catch (Throwable $e) {
        error_log("preProvisionPPPoEClient($clientId): " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Configure the router to redirect to the external FortuNett captive portal
 * and ensure the server IP is in the hotspot walled garden.
 *
 * Strategy (in order):
 *   1. Read all hotspot profiles on the router.
 *   2. For each profile, set login-page → external customer/login.php URL,
 *      enable http-pap + http-cookie login methods, and set html-directory
 *      as a fallback in case login-page ever fails.
 *   3. Add the server's external IP (212.95.34.211) to /ip hotspot walled-garden-ip
 *      so unauthenticated devices can always reach the portal.
 *   4. Also fetch the branded flash page as a last-resort fallback.
 *
 * Silent on failure — provisioning must not be blocked by portal config issues.
 */
function _uploadHotspotLoginPage(PDO $pdo, array $router, int $tenantId): void
{
    try {
        // ── Resolve base URL for this tenant ──────────────────────────────────
        $stmt = $pdo->prepare("SELECT provisioning_token, subdomain FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenantRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $provToken = $tenantRow['provisioning_token'] ?? '';
        $subdomain = $tenantRow['subdomain'] ?? '';

        if (!$provToken) {
            error_log('_uploadHotspotLoginPage: no provisioning token for tenant ' . $tenantId);
            return;
        }

        // Prefer the HTTP_HOST from a live web request; fall back to the tenant
        // subdomain + platform domain when running from cron / CLI.
        if (!empty($_SERVER['HTTP_HOST'])) {
            $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $proto . '://' . $_SERVER['HTTP_HOST'];
        } else {
            // Resolve platform domain from settings
            $platformDomain = 'fortunetttech.site';
            try {
                $pdSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1");
                $pd = $pdSt ? $pdSt->fetchColumn() : null;
                if ($pd) $platformDomain = $pd;
            } catch (Throwable $_e) {}
            $baseUrl = 'https://' . ($subdomain ? $subdomain . '.' : '') . $platformDomain;
        }

        // ── Detect server external IP for walled garden ───────────────────────
        // Priority: platform_settings.server_external_ip → DNS resolve → skip
        $serverIp = '';
        try {
            $ipSt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='server_external_ip' LIMIT 1");
            $serverIp = $ipSt ? ($ipSt->fetchColumn() ?: '') : '';
        } catch (Throwable $_e) {}
        if (!$serverIp) {
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: '';
            if ($hostname) {
                $resolved = @gethostbyname($hostname);
                if ($resolved && $resolved !== $hostname && filter_var($resolved, FILTER_VALIDATE_IP)) {
                    $serverIp = $resolved;
                }
            }
        }

        // External login page URL — the comprehensive captive portal
        $externalLoginUrl = $baseUrl . '/customer/login.php';
        $serveUrl         = $baseUrl . '/hotspot/login_serve.php?token=' . rawurlencode($provToken);

        // ── Connect to router ─────────────────────────────────────────────────
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $apiPort   = (int)($router['api_port'] ?? 8728);
        $mkArgs    = [$connectIp, $router['username'], $router['password'], $apiPort];

        // Verify TCP is up before opening the real API connection
        $sock = @fsockopen($connectIp, $apiPort, $errno, $errstr, 5);
        if (!$sock) {
            error_log("_uploadHotspotLoginPage: router {$connectIp}:{$apiPort} unreachable");
            return;
        }
        fclose($sock);

        // ── Step 1: read hotspot profiles ─────────────────────────────────────
        $profiles = [];
        try {
            $mk1 = new MikrotikAPI(...$mkArgs);
            $mk1->connect();
            $profiles = $mk1->comm('/ip/hotspot/profile/print');
            try { $mk1->disconnect(); } catch (Throwable $_e) {}
        } catch (Throwable $_e) {}

        // ── Step 2: update every profile — html-directory + login methods ────────
        // NOTE: RouterOS 7 removed the 'login-page' profile parameter. The primary
        // redirect mechanism is now the login.html uploaded in Step 4.
        // 'login-page' is attempted separately and silently ignored on RouterOS 7.
        //
        // RouterOS 7 path quirk: setting html-directory=flash/hotspot causes RouterOS
        // to store and display it as flash/flash/hotspot (it prepends flash/ internally).
        // html-directory-override, when set, takes precedence over html-directory.
        // We collect the effective directory each profile resolves to so Step 4 can
        // write login.html to all of them.
        $effectiveDirs = ['flash/hotspot', 'flash/flash/hotspot']; // always cover both
        foreach ($profiles as $p) {
            if (empty($p['.id'])) continue;

            $curLoginBy = trim($p['login-by']       ?? '');
            $curDir     = trim($p['html-directory'] ?? '');
            // html-directory-override takes precedence when set
            $overrideDir = trim($p['html-directory-override'] ?? '');
            $effectiveDir = $overrideDir ?: $curDir;
            if ($effectiveDir && !in_array($effectiveDir, $effectiveDirs)) {
                $effectiveDirs[] = $effectiveDir;
            }

            $updates = ['=.id=' . $p['.id']];

            // RouterOS 7 prepends flash/ to whatever you set, so set just "hotspot"
            // (no flash/ prefix) and RouterOS stores it as flash/hotspot.
            // Accept flash/hotspot or flash/flash/hotspot as already-correct displays.
            if ($curDir !== 'flash/hotspot' && $curDir !== 'flash/flash/hotspot') {
                $updates[] = '=html-directory=hotspot';
            }

            // login-by: RouterOS 7 uses 'cookie' (was 'http-cookie' in RouterOS 6).
            // We check for both so existing 'http-cookie' entries are not re-added.
            $methodParts = $curLoginBy ? array_map('trim', explode(',', $curLoginBy)) : [];
            $needPap    = !in_array('http-pap', $methodParts);
            $needCookie = !in_array('cookie', $methodParts) && !in_array('http-cookie', $methodParts);
            if ($needPap || $needCookie) {
                if ($needPap)    $methodParts[] = 'http-pap';
                if ($needCookie) $methodParts[] = 'cookie';   // RouterOS 7 value
                $updates[] = '=login-by=' . implode(',', array_unique($methodParts));
            }

            if (count($updates) >= 2) {
                try {
                    $mk2 = new MikrotikAPI(...$mkArgs);
                    $mk2->connect();
                    $mk2->comm('/ip/hotspot/profile/set', $updates);
                    try { $mk2->disconnect(); } catch (Throwable $_e) {}
                } catch (Throwable $_e) {
                    error_log('_uploadHotspotLoginPage profile set: ' . $_e->getMessage());
                }
            }

            // RouterOS 6 only: set login-page on profile (silently fails on RouterOS 7).
            try {
                $curLoginPage = trim($p['login-page'] ?? '');
                if ($curLoginPage !== $externalLoginUrl) {
                    $mk2r = new MikrotikAPI(...$mkArgs);
                    $mk2r->connect();
                    $mk2r->comm('/ip/hotspot/profile/set', [
                        '=.id='        . $p['.id'],
                        '=login-page=' . $externalLoginUrl,
                    ]);
                    try { $mk2r->disconnect(); } catch (Throwable $_e) {}
                }
            } catch (Throwable $_e) { /* RouterOS 7 — expected, login.html handles redirect */ }
        }

        // ── Step 3: walled garden — allow portal server before authentication ───
        // RouterOS 7: uses /ip/hotspot/walled-garden/ (domain/host-based).
        // RouterOS 6: uses /ip/hotspot/walled-garden-ip/ (IP-based) as fallback.
        $portalHost = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        try {
            $mk3 = new MikrotikAPI(...$mkArgs);
            $mk3->connect();
            $wgList = $mk3->comm('/ip/hotspot/walled-garden/print');

            // Collect already-present hosts to avoid duplicates
            $existingHosts = [];
            foreach ($wgList as $wg) {
                $existingHosts[] = $wg['dst-host'] ?? '';
            }

            // Add portal hostname (e.g. demo.fortunetttech.site)
            if ($portalHost && !in_array($portalHost, $existingHosts)) {
                $mk3->comm('/ip/hotspot/walled-garden/add', [
                    '=dst-host=' . $portalHost,
                    '=comment=FortuNett Portal ' . $portalHost,
                ]);
            }
            // Also add server IP directly as a dst-host entry (RouterOS 7 accepts IPs here)
            if ($serverIp && !in_array($serverIp, $existingHosts)) {
                try {
                    $mk3->comm('/ip/hotspot/walled-garden/add', [
                        '=dst-host=' . $serverIp,
                        '=comment=FortuNett Portal IP',
                    ]);
                } catch (Throwable $_e) { /* non-fatal */ }
            }
            try { $mk3->disconnect(); } catch (Throwable $_e) {}
        } catch (Throwable $wgEx7) {
            // RouterOS 6 fallback: IP-based walled-garden-ip
            error_log('_uploadHotspotLoginPage walled-garden (v7): ' . $wgEx7->getMessage());
            if ($serverIp) {
                try {
                    $mk3b = new MikrotikAPI(...$mkArgs);
                    $mk3b->connect();
                    $wgList     = $mk3b->comm('/ip/hotspot/walled-garden-ip/print');
                    $ipWithCidr = $serverIp . '/32';
                    $wgExists   = false;
                    foreach ($wgList as $wg) {
                        $s = $wg['dst-address'] ?? '';
                        if ($s === $serverIp || $s === $ipWithCidr) { $wgExists = true; break; }
                    }
                    if (!$wgExists) {
                        $mk3b->comm('/ip/hotspot/walled-garden-ip/add', [
                            '=dst-address=' . $ipWithCidr,
                            '=action=accept',
                            '=comment=FortuNett Portal',
                        ]);
                    }
                    try { $mk3b->disconnect(); } catch (Throwable $_e) {}
                } catch (Throwable $wgEx6) {
                    error_log('_uploadHotspotLoginPage walled-garden-ip (v6): ' . $wgEx6->getMessage());
                }
            }
        }

        // ── Step 4: upload redirect login.html to every effective html-directory ──
        // Write to all directories collected in Step 2 (flash/hotspot AND
        // flash/flash/hotspot). RouterOS 7 sometimes stores the path differently
        // from what /file/print shows, so we cover both to be safe.
        //
        // IMPORTANT: Do NOT pass =mode= when $url already contains a scheme
        // (https:// or http://) — RouterOS returns "Conflicting modes" if both
        // the URL scheme and the mode= parameter are present simultaneously.
        $httpFallbackUrl = str_replace('https://', 'http://', $serveUrl);

        foreach (array_unique($effectiveDirs) as $htmlDir) {
            $dstPath  = rtrim($htmlDir, '/') . '/login.html';
            $fetched  = false;

            // Try 1: HTTPS (scheme in URL, no mode= param, cert check off)
            try {
                $mk4 = new MikrotikAPI(...$mkArgs);
                $mk4->connect();
                $fetchResult = $mk4->comm('/tool/fetch', [
                    '=url='              . $serveUrl,
                    '=dst-path='         . $dstPath,
                    '=check-certificate=no',
                ]);
                try { $mk4->disconnect(); } catch (Throwable $_e) {}

                $hasTrap = false;
                foreach ($fetchResult as $fr) {
                    if (isset($fr['!trap'])) {
                        $hasTrap = true;
                        error_log("_uploadHotspotLoginPage fetch trap HTTPS ($dstPath): " . ($fr['message'] ?? '?'));
                    }
                }
                if (!$hasTrap) $fetched = true;
            } catch (Throwable $_e) {
                error_log("_uploadHotspotLoginPage HTTPS upload ($dstPath): " . $_e->getMessage());
            }

            // Try 2: HTTP fallback (requires nginx to NOT redirect /hotspot/ on port 80)
            if (!$fetched) {
                try {
                    $mk4h = new MikrotikAPI(...$mkArgs);
                    $mk4h->connect();
                    $fetchResult = $mk4h->comm('/tool/fetch', [
                        '=url='      . $httpFallbackUrl,
                        '=dst-path=' . $dstPath,
                    ]);
                    try { $mk4h->disconnect(); } catch (Throwable $_e) {}

                    foreach ($fetchResult as $fr) {
                        if (isset($fr['!trap'])) {
                            error_log("_uploadHotspotLoginPage fetch trap HTTP ($dstPath): " . ($fr['message'] ?? '?'));
                        } else {
                            $fetched = true;
                        }
                    }
                } catch (Throwable $_e) {
                    error_log("_uploadHotspotLoginPage HTTP upload ($dstPath): " . $_e->getMessage());
                }
            }

            if (!$fetched) {
                error_log("_uploadHotspotLoginPage: both HTTPS and HTTP fetch failed for $dstPath");
            }
        }

        // ── Step 5: install the self-update scheduler ─────────────────────────
        // This is what makes future portal changes automatic. Once the router
        // holds this script it re-checks hourly on its own, so editing
        // login.html or a tenant's packages never requires touching a router
        // again — including routers behind CGNAT the server can't dial into.
        $urls = hotspotPortalUrls($pdo, $tenantId);
        if ($urls) {
            try {
                $mk5 = new MikrotikAPI(...$mkArgs);
                $mk5->connect();
                $res = installHotspotSyncScheduler($mk5, $urls['page'], $urls['version']);
                try { $mk5->disconnect(); } catch (Throwable $_e) {}
                if (!$res['installed']) {
                    error_log('_uploadHotspotLoginPage: sync scheduler not installed — ' . $res['message']);
                }
            } catch (Throwable $_e) {
                error_log('_uploadHotspotLoginPage scheduler: ' . $_e->getMessage());
            }
        }

    } catch (Throwable $e) {
        error_log('_uploadHotspotLoginPage: ' . $e->getMessage());
        throw $e;   // re-throw so deploy_hotspot_login.php can report it
    }
}

/**
 * Return the name of the first enabled bridge interface on the router, or null.
 */
function _detectBridgeInterface(MikrotikAPI $api): ?string
{
    try {
        $bridges = $api->comm('/interface/bridge/print');
        foreach ($bridges as $b) {
            if (!isset($b['!re'])) continue;
            if (($b['disabled'] ?? 'false') === 'false' && !empty($b['name'])) {
                return $b['name'];
            }
        }
    } catch (Throwable $_e) {
        error_log('_detectBridgeInterface: ' . $_e->getMessage());
    }
    return null;
}

/**
 * Verify the hotspot or PPPoE server is configured and running on a bridge.
 *
 * Rules:
 *  - If no bridge exists on the router: hard fail (routers should always have a bridge).
 *  - If the service server doesn't exist: hard fail with actionable message.
 *  - If the service server exists but is NOT on the bridge: warning log + soft pass
 *    (admin should reconfigure the server, but we don't block the customer).
 *
 * Returns ['ok' => bool, 'message' => string, 'bridge' => ?string, 'server_name' => ?string]
 */
function _verifyServiceOnBridge(MikrotikAPI $api, string $connType): array
{
    $bridge = _detectBridgeInterface($api);

    if ($bridge === null) {
        return [
            'ok'          => false,
            'message'     => 'No bridge interface found on this router. '
                           . 'Create a bridge (e.g. bridge1) and add your LAN port to it before provisioning.',
            'bridge'      => null,
            'server_name' => null,
        ];
    }

    if ($connType === 'hotspot') {
        // Fetch hotspot servers
        $servers = $api->comm('/ip/hotspot/print');
        $running = null;
        foreach ($servers as $s) {
            if (!isset($s['!re'])) continue;
            if (($s['disabled'] ?? 'true') !== 'true') {
                $running = $s;
                break;
            }
        }

        if ($running === null) {
            return [
                'ok'          => false,
                'message'     => "No active hotspot server found on this router. "
                               . "Add a hotspot server on interface {$bridge} (/ip hotspot add interface={$bridge} ...).",
                'bridge'      => $bridge,
                'server_name' => null,
            ];
        }

        $serverIface = $running['interface'] ?? '';
        $serverName  = $running['name']      ?? '';
        if ($serverIface !== $bridge) {
            error_log(
                "_verifyServiceOnBridge: hotspot server '{$serverName}' is on '{$serverIface}', "
                . "but bridge is '{$bridge}'. Move the hotspot server to the bridge for correct operation."
            );
        }

        return [
            'ok'          => true,
            'message'     => "Hotspot server '{$serverName}' on '{$serverIface}' (bridge: {$bridge})",
            'bridge'      => $bridge,
            'server_name' => $serverName ?: 'all',
        ];
    }

    // PPPoE — check /interface/pppoe-server/server
    $servers = $api->comm('/interface/pppoe-server/server/print');
    $running = null;
    foreach ($servers as $s) {
        if (!isset($s['!re'])) continue;
        if (($s['disabled'] ?? 'true') !== 'true') {
            $running = $s;
            break;
        }
    }

    if ($running === null) {
        return [
            'ok'          => false,
            'message'     => "No active PPPoE server found on this router. "
                           . "Add a PPPoE server on interface {$bridge} (/interface pppoe-server server add interface={$bridge} ...).",
            'bridge'      => $bridge,
            'server_name' => null,
        ];
    }

    $serverIface = $running['interface'] ?? '';
    $serverName  = $running['service-name'] ?? ($running['name'] ?? '');
    if ($serverIface !== $bridge) {
        error_log(
            "_verifyServiceOnBridge: PPPoE server '{$serverName}' is on '{$serverIface}', "
            . "but bridge is '{$bridge}'. Move the PPPoE server to the bridge for correct operation."
        );
    }

    return [
        'ok'          => true,
        'message'     => "PPPoE server '{$serverName}' on '{$serverIface}' (bridge: {$bridge})",
        'bridge'      => $bridge,
        'server_name' => $serverName,
    ];
}

/**
 * Darken a hex colour by $amount per channel.
 */
function _darkenHex(string $hex, int $amount): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = max(0, hexdec(substr($hex, 0, 2)) - $amount);
    $g = max(0, hexdec(substr($hex, 2, 2)) - $amount);
    $b = max(0, hexdec(substr($hex, 4, 2)) - $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
