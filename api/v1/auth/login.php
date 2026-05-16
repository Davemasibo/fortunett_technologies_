<?php
/**
 * POST /api/v1/auth/login.php
 *
 * ISP admin and super-admin login for mobile apps.
 * Returns a short-lived JWT access token (15 min) and a 30-day refresh token.
 *
 * Request body (JSON):
 *   { "email": "...", "password": "...", "device_name": "Admin App Android" }
 *
 * Response:
 *   { "access_token", "refresh_token", "token_type", "expires_in", "user": {...} }
 *
 * The Kotlin app stores refresh_token securely (EncryptedSharedPreferences / Keystore)
 * and calls /api/v1/auth/refresh.php when access_token expires.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/jwt.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$identifier = trim($body['email'] ?? $body['username'] ?? '');
$password   = $body['password'] ?? '';
$deviceName = trim(substr($body['device_name'] ?? 'Unknown device', 0, 150));

if (!$identifier || !$password) {
    http_response_code(422);
    echo json_encode(['error' => 'Email/username and password are required']);
    exit;
}

$secret = get_env_var('JWT_SECRET', '');
if (!$secret) {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT_SECRET not set in .env']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit;
    }

    if (isset($user['email_verified']) && !(bool)$user['email_verified']) {
        http_response_code(403);
        echo json_encode(['error' => 'Email not verified. Check your inbox.', 'code' => 'EMAIL_UNVERIFIED']);
        exit;
    }

    $tenantId     = !empty($user['tenant_id']) ? (int)$user['tenant_id'] : null;
    $isSuperAdmin = !empty($user['is_super_admin']);

    // Load tenant details for the response
    $tenantName      = null;
    $tenantSubdomain = null;
    if ($tenantId) {
        $ts = $pdo->prepare("SELECT name, subdomain FROM tenants WHERE id = ? LIMIT 1");
        $ts->execute([$tenantId]);
        $tr = $ts->fetch(PDO::FETCH_ASSOC);
        $tenantName      = $tr['name']      ?? null;
        $tenantSubdomain = $tr['subdomain'] ?? null;
    }

    $now = time();

    // ── Access token — short-lived JWT, no DB storage ─────────────────────────
    $accessToken = jwt_encode([
        'uid'  => (int)$user['id'],
        'tid'  => $tenantId,
        'role' => $user['role'] ?? 'admin',
        'sa'   => $isSuperAdmin,
        'iat'  => $now,
        'exp'  => $now + 900,   // 15 minutes
    ], $secret);

    // ── Refresh token — opaque random, stored as SHA-256 hash ────────────────
    $refreshRaw  = bin2hex(random_bytes(32));   // 64-char hex
    $refreshHash = hash('sha256', $refreshRaw);
    $refreshExp  = date('Y-m-d H:i:s', $now + 86400 * 30);  // 30 days

    // Purge expired tokens for this user before inserting (keep DB clean)
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ? AND expires_at < NOW()")
        ->execute([$user['id']]);

    $pdo->prepare("
        INSERT INTO api_tokens (user_id, tenant_id, role, token_hash, device_name, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $user['id'], $tenantId, $user['role'] ?? 'admin',
        $refreshHash, $deviceName, $refreshExp,
    ]);

    echo json_encode([
        'access_token'  => $accessToken,
        'refresh_token' => $refreshRaw,
        'token_type'    => 'Bearer',
        'expires_in'    => 900,
        'user'          => [
            'id'               => (int)$user['id'],
            'name'             => $user['full_name'] ?? $user['name'] ?? $user['username'],
            'email'            => $user['email'],
            'username'         => $user['username'],
            'role'             => $user['role'] ?? 'admin',
            'tenant_id'        => $tenantId,
            'tenant_name'      => $tenantName,
            'tenant_subdomain' => $tenantSubdomain,
            'is_super_admin'   => $isSuperAdmin,
        ],
    ]);

} catch (Throwable $e) {
    error_log('API login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Login failed. Please try again.']);
}
