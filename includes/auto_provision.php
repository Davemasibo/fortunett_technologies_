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
        $rateLimit     = "{$downloadSpeed}M/{$uploadSpeed}M";
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

        if ($connType === 'pppoe') {
            _provisionPPPoE($api, $username, $password, $profileName, $rateLimit, $client['full_name'] ?? '');
        } else {
            _provisionHotspot($api, $username, $password, $profileName, $rateLimit, $client['full_name'] ?? '', $sharedUsers);
        }

        $api->disconnect();

        // ── Upload hotspot login page (hotspot only) ──────────────────────────
        if ($connType === 'hotspot') {
            _uploadHotspotLoginPage($pdo, $router, $tenantId);
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
    // Ensure the package profile exists (skip if it's 'default' — that always exists)
    if ($profileName !== 'default') {
        $profiles = $api->comm('/ppp/profile/print', ['?name=' . $profileName]);
        $hasProfile = false;
        foreach ($profiles as $p) {
            if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $hasProfile = true; break; }
        }
        if (!$hasProfile) {
            $api->comm('/ppp/profile/add', [
                '=name='        . $profileName,
                '=local-address=10.0.0.1',
                '=remote-address=pppoe-pool',
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
function _provisionHotspot(MikrotikAPI $api, string $username, string $password, string $profileName, string $rateLimit, string $comment, string $sharedUsers = 'unlimited'): void
{
    // ── Ensure package profile exists ─────────────────────────────────────────
    // We create it if missing (e.g. router was offline when the package was saved),
    // but we do NOT recreate it per-user — one profile serves all users on that package.
    if ($profileName !== 'default') {
        $profiles = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profileName]);
        $hasProfile = false;
        foreach ($profiles as $p) {
            if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $hasProfile = true; break; }
        }
        if (!$hasProfile) {
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
            '=server=all',
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
 * Upload a tenant-branded hotspot login page to the router.
 *
 * Strategy: use the RouterOS API to instruct the router to pull the branded
 * login page from this server via /tool/fetch.  This is more reliable than
 * FTP because:
 *   • The server is already publicly reachable (it hosts the customer portal).
 *   • The RouterOS API connection is already proven to work (provisioning used it).
 *   • FTP requires a separate service to be enabled on the router.
 *
 * The branded page is served by /hotspot/login_serve.php?token={provisioning_token}.
 *
 * Silent on failure — provisioning must not be blocked by upload issues.
 */
function _uploadHotspotLoginPage(PDO $pdo, array $router, int $tenantId): void
{
    try {
        // Get the tenant's provisioning token (used to authenticate the serve endpoint)
        $stmt = $pdo->prepare("SELECT provisioning_token FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $provToken = $stmt->fetchColumn();

        if (!$provToken) {
            error_log('_uploadHotspotLoginPage: no provisioning token for tenant ' . $tenantId);
            return;
        }

        // Build the URL that the router will fetch.
        // Derive server base from HTTP_HOST if available, otherwise use the provisioning
        // callback URL pattern (MPESA_CALLBACK_URL is set from config/mpesa.php).
        if (!empty($_SERVER['HTTP_HOST'])) {
            $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            $baseUrl  = $proto . '://' . $_SERVER['HTTP_HOST'] . $basePath;
        } elseif (defined('MPESA_CALLBACK_URL')) {
            // e.g. https://demo.fortunetttech.site/api/mpesa/callback.php → strip 3 segments
            $baseUrl = dirname(dirname(dirname(MPESA_CALLBACK_URL)));
        } else {
            error_log('_uploadHotspotLoginPage: cannot determine server URL');
            return;
        }

        $serveUrl = $baseUrl . '/hotspot/login_serve.php?token=' . urlencode($provToken);
        $mode     = (str_starts_with($serveUrl, 'https://')) ? 'https' : 'http';

        // Connect to router via API and issue /tool/fetch
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $api = new MikrotikAPI(
            $connectIp,
            $router['username'],
            $router['password'],
            (int)($router['api_port'] ?? 8728)
        );

        if (!$api->isReachable(4)) {
            error_log('_uploadHotspotLoginPage: router not reachable: ' . $connectIp);
            return;
        }

        $api->connect();

        // RouterOS /tool/fetch: router pulls file from server and saves to flash
        $result = $api->comm('/tool/fetch', [
            '=url='      . $serveUrl,
            '=dst-path=hotspot/login.html',
            '=mode='     . $mode,
        ]);

        $api->disconnect();

        // Log any trap (error) from the router
        foreach ($result as $r) {
            if (isset($r['!trap'])) {
                error_log('_uploadHotspotLoginPage: router fetch error: ' . ($r['message'] ?? 'unknown'));
            }
        }

    } catch (Throwable $e) {
        error_log('_uploadHotspotLoginPage: ' . $e->getMessage());
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
