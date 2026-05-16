<?php
/**
 * Minimal HS256 JWT — no Composer dependency.
 *
 * Access tokens carry: uid, tid (tenant), role, sa (super_admin), iat, exp
 * Tokens are stateless — validation requires only the secret, no DB hit.
 * Refresh tokens are opaque random strings stored in the api_tokens table.
 */

function jwt_encode(array $payload, string $secret): string {
    $header  = _b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $claims  = _b64u(json_encode($payload));
    $sig     = _b64u(hash_hmac('sha256', "$header.$claims", $secret, true));
    return "$header.$claims.$sig";
}

/**
 * Decode and verify a JWT. Returns the payload array or null on failure.
 * Returns null if the signature is wrong, the token is malformed, or 'exp' has passed.
 */
function jwt_decode(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $claims, $sig] = $parts;

    $expected = _b64u(hash_hmac('sha256', "$header.$claims", $secret, true));
    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(_b64d($claims), true);
    if (!is_array($data)) return null;
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function _b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _b64d(string $data): string {
    $pad = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
}
