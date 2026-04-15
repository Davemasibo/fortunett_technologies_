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
        $profileName   = 'profile_' . $username;

        // ── Connect to router ─────────────────────────────────────────────────
        $api = new MikrotikAPI(
            $router['ip_address'],
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
 */
function _provisionPPPoE(MikrotikAPI $api, string $username, string $password, string $profileName, string $rateLimit, string $comment): void
{
    // Upsert PPPoE profile
    $profiles = $api->comm('/ppp/profile/print', ['?name=' . $profileName]);
    $hasProfile = false;
    foreach ($profiles as $p) {
        if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $hasProfile = true; break; }
    }
    if (!$hasProfile) {
        $api->comm('/ppp/profile/add', [
            '=name='           . $profileName,
            '=local-address=10.0.0.1',
            '=remote-address=pool1',
            '=rate-limit='     . $rateLimit,
        ]);
    }

    // Upsert PPPoE secret
    $secrets = $api->comm('/ppp/secret/print', ['?name=' . $username]);
    $secretId = null;
    foreach ($secrets as $s) {
        if (isset($s['!re']) && ($s['name'] ?? '') === $username) { $secretId = $s['.id']; break; }
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
 * @param string $sharedUsers  RouterOS shared-users value: '1' (no sharing) or 'unlimited'
 */
function _provisionHotspot(MikrotikAPI $api, string $username, string $password, string $profileName, string $rateLimit, string $comment, string $sharedUsers = 'unlimited'): void
{
    // ── Upsert hotspot profile ────────────────────────────────────────────────
    $profiles = $api->comm('/ip/hotspot/user/profile/print', ['?name=' . $profileName]);
    $hasProfile = false;
    foreach ($profiles as $p) {
        if (isset($p['!re']) && ($p['name'] ?? '') === $profileName) { $hasProfile = true; break; }
    }
    if (!$hasProfile) {
        $api->comm('/ip/hotspot/user/profile/add', [
            '=name='          . $profileName,
            '=rate-limit='    . $rateLimit,
            '=shared-users='  . $sharedUsers,
        ]);
    } else {
        // Profile already exists — update shared-users in case setting changed
        foreach ($profiles as $p) {
            if (isset($p['!re']) && ($p['name'] ?? '') === $profileName && isset($p['.id'])) {
                $api->comm('/ip/hotspot/user/profile/set', [
                    '=.id='          . $p['.id'],
                    '=rate-limit='   . $rateLimit,
                    '=shared-users=' . $sharedUsers,
                ]);
                break;
            }
        }
    }

    // ── Upsert hotspot user ───────────────────────────────────────────────────
    $userId = null;
    $users  = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
    foreach ($users as $u) {
        if (isset($u['!re']) && ($u['name'] ?? '') === $username) { $userId = $u['.id']; break; }
    }

    if ($userId !== null) {
        $api->comm('/ip/hotspot/user/set', [
            '=.id='      . $userId,
            '=password=' . $password,
            '=profile='  . $profileName,
        ]);
        // Re-enable in case it was disabled by expiry/suspension
        $api->comm('/ip/hotspot/user/enable', ['=.id=' . $userId]);
    } else {
        $api->comm('/ip/hotspot/user/add', [
            '=name='     . $username,
            '=password=' . $password,
            '=profile='  . $profileName,
            '=comment='  . $comment,
            '=server=all',
        ]);
        // Fetch the .id of the newly created user
        $newUsers = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
        foreach ($newUsers as $u) {
            if (isset($u['!re']) && ($u['name'] ?? '') === $username) { $userId = $u['.id']; break; }
        }
    }

    // ── MAC Auth + Immediate Reconnect ────────────────────────────────────────
    // If the customer is currently in an active hotspot session (they were
    // previously logged in and their session is still alive), grab their MAC,
    // set it on the user record to enable future MAC-bypass authentication,
    // then kick the session so their device reconnects and is auto-authenticated.
    //
    // If they are NOT in an active session (e.g. blocked at captive-portal wall),
    // look for them in the unauthenticated host table by trying to match the
    // comment we just set. When the device next reconnects, MAC auth will fire.
    try {
        $activeSessions = $api->comm('/ip/hotspot/active/print');
        $reconnected    = false;

        foreach ($activeSessions as $session) {
            if (!isset($session['!re'])) continue;
            if (($session['user'] ?? '') !== $username) continue;

            $mac       = $session['mac-address'] ?? null;
            $sessionId = $session['.id']         ?? null;

            // Set MAC auth on the user record so the device is recognised next time
            if ($mac && $userId) {
                $api->comm('/ip/hotspot/user/set', [
                    '=.id='         . $userId,
                    '=mac-address=' . $mac,
                ]);
            }

            // Kick the current session → device reconnects → MAC auth grants access
            if ($sessionId) {
                $api->comm('/ip/hotspot/active/remove', ['=.id=' . $sessionId]);
                $reconnected = true;
            }
            break;
        }

        // If not in an active session, scan the host table for an unauthenticated
        // device.  We can't reliably identify the user without MAC at this point,
        // but we can remove all hosts whose MAC matches the user's stored mac-address
        // (if it was previously set).
        if (!$reconnected) {
            $updatedUser = null;
            if ($userId) {
                $uu = $api->comm('/ip/hotspot/user/print', ['?name=' . $username]);
                foreach ($uu as $u) {
                    if (isset($u['!re']) && ($u['name'] ?? '') === $username) { $updatedUser = $u; break; }
                }
            }
            $knownMac = $updatedUser['mac-address'] ?? null;
            if ($knownMac) {
                $hosts = $api->comm('/ip/hotspot/host/print');
                foreach ($hosts as $h) {
                    if (!isset($h['!re'])) continue;
                    if (($h['mac-address'] ?? '') === $knownMac && isset($h['.id'])) {
                        // Remove the host entry → device is forced to re-auth via MAC
                        $api->comm('/ip/hotspot/host/remove', ['=.id=' . $h['.id']]);
                        break;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('_provisionHotspot MAC reconnect: ' . $e->getMessage());
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
        $api = new MikrotikAPI(
            $router['ip_address'],
            $router['username'],
            $router['password'],
            (int)($router['api_port'] ?? 8728)
        );

        if (!$api->isReachable(4)) {
            error_log('_uploadHotspotLoginPage: router not reachable: ' . $router['ip_address']);
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
