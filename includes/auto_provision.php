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

        $downloadSpeed = max(1, (int)($package['download_speed'] ?? 10));
        $uploadSpeed   = max(1, (int)($package['upload_speed']   ?? 5));
        // RouterOS rate-limit format: rx-rate/tx-rate (router perspective)
        // rx-rate = client upload speed, tx-rate = client download speed
        $rateLimit     = "{$uploadSpeed}M/{$downloadSpeed}M";
        // Use the package-level profile (one shared profile per package, not per user).
        $profileName   = $package['mikrotik_profile']
            ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($package['name']));
        if (empty($profileName)) $profileName = 'default';

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

        // ── Provision ─────────────────────────────────────────────────────────
        // hotspot_shared_users: 0 = unlimited, 1 = one active session per user
        $sharedUsers = ((int)($router['hotspot_shared_users'] ?? 0) === 1) ? '1' : 'unlimited';

        $hotspotServer = !empty($package['hotspot_server']) ? $package['hotspot_server'] : 'all';

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
            '=.id='      . $secretId,
            '=password=' . $password,
            '=profile='  . $profileName,
            '=service=pppoe',
        ]);
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
    if ($profileName !== 'default') {
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
        $api->comm('/ip/hotspot/user/set', [
            '=.id='         . $userId,
            '=password='    . $password,
            '=profile='     . $profileName,
            '=mac-address=',
        ]);
        // Re-enable in case it was disabled by expiry/suspension
        $api->comm('/ip/hotspot/user/enable', ['=.id=' . $userId]);
        // Kick any live session so the device is redirected to the captive portal
        $api->kickHotspotSession($username);
    } else {
        $isNewUser = true;
        $addResp = $api->comm('/ip/hotspot/user/add', [
            '=name='     . $username,
            '=password=' . $password,
            '=profile='  . $profileName,
            '=comment='  . $comment,
            '=server='   . $hotspotServer,
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

    } catch (Throwable $e) {
        error_log('_uploadHotspotLoginPage: ' . $e->getMessage());
        throw $e;   // re-throw so deploy_hotspot_login.php can report it
    }
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
