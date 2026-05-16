<?php
/**
 * POST /api/v1/auth/logout.php
 *
 * Revoke a refresh token, preventing it from issuing new access tokens.
 * The Kotlin app calls this on user sign-out.
 *
 * Request body (JSON):
 *   { "refresh_token": "<64-char hex>" }
 *   Omitting refresh_token is accepted — treated as a no-op (already logged out).
 *
 * Always returns 200 — idempotent.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$refreshRaw = trim($body['refresh_token'] ?? '');

if ($refreshRaw) {
    try {
        $hash = hash('sha256', $refreshRaw);
        $pdo->prepare("DELETE FROM api_tokens WHERE token_hash = ?")->execute([$hash]);
    } catch (Throwable $e) {
        error_log('API logout error: ' . $e->getMessage());
    }
}

echo json_encode(['success' => true]);
