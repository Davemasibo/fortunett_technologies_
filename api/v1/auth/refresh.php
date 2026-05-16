<?php
/**
 * POST /api/v1/auth/refresh.php
 *
 * Exchange a valid refresh token for a new JWT access token.
 * The Kotlin app calls this when it gets a 401 with code TOKEN_EXPIRED.
 *
 * Request body (JSON):
 *   { "refresh_token": "<64-char hex>" }
 *
 * Response:
 *   { "access_token", "token_type", "expires_in" }
 *
 * The refresh token itself is NOT rotated — it stays valid for its 30-day window.
 * Revoke it explicitly via /api/v1/auth/logout.php.
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
$refreshRaw = trim($body['refresh_token'] ?? '');

if (!$refreshRaw) {
    http_response_code(422);
    echo json_encode(['error' => 'refresh_token required']);
    exit;
}

$secret = get_env_var('JWT_SECRET', '');
if (!$secret) {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration']);
    exit;
}

try {
    $hash = hash('sha256', $refreshRaw);

    $stmt = $pdo->prepare("
        SELECT t.user_id, t.tenant_id, t.role,
               u.is_super_admin
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh token expired or revoked. Please log in again.', 'code' => 'REFRESH_EXPIRED']);
        exit;
    }

    // Touch last_used so we can detect stale tokens in the future
    $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?")
        ->execute([$hash]);

    $now = time();
    $accessToken = jwt_encode([
        'uid'  => (int)$row['user_id'],
        'tid'  => $row['tenant_id'] ? (int)$row['tenant_id'] : null,
        'role' => $row['role'] ?? 'admin',
        'sa'   => !empty($row['is_super_admin']),
        'iat'  => $now,
        'exp'  => $now + 900,
    ], $secret);

    echo json_encode([
        'access_token' => $accessToken,
        'token_type'   => 'Bearer',
        'expires_in'   => 900,
    ]);

} catch (Throwable $e) {
    error_log('API refresh error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Token refresh failed']);
}
