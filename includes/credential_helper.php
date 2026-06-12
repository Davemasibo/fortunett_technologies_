<?php
/**
 * Gateway Credential Helpers
 *
 * Shared encryption/decryption for payment_gateways.credentials column.
 * Key lives at config/encryption.key (AES-256-CBC, base64-encoded 32-byte key).
 * PaymentGatewayManager uses the same key file — both paths are compatible.
 */

function _gateway_encryption_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $keyFile = dirname(__DIR__) . '/config/encryption.key';
    if (file_exists($keyFile)) {
        $key = trim(file_get_contents($keyFile));
        return $key;
    }

    // Generate and persist a new key (first-run only)
    $key = base64_encode(random_bytes(32));
    $dir = dirname($keyFile);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    file_put_contents($keyFile, $key);
    @chmod($keyFile, 0600);
    return $key;
}

/**
 * Decrypt gateway credentials.
 *
 * Handles three storage formats transparently:
 *   1. Plain JSON (credentials saved before encryption was introduced)
 *   2. AES-256-CBC base64 blob (credentials saved via PaymentGatewayManager)
 *
 * @return array  Decoded credential array, or [] on failure.
 */
function decrypt_gateway_credentials(string $data): array {
    if ($data === '') return [];

    // Fast-path: plain JSON (old records or paybill_no_api which has no secrets)
    $plain = json_decode($data, true);
    if ($plain !== null) return $plain;

    // Encrypted path
    try {
        $raw  = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 17) return [];
        $iv        = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $decrypted = openssl_decrypt(
            $encrypted, 'AES-256-CBC',
            base64_decode(_gateway_encryption_key()),
            0, $iv
        );
        return ($decrypted !== false) ? (json_decode($decrypted, true) ?? []) : [];
    } catch (Throwable $e) {
        error_log("decrypt_gateway_credentials: " . $e->getMessage());
        return [];
    }
}

/**
 * Encrypt credential array for storage.
 *
 * @return string  Base64-encoded AES-256-CBC blob.
 */
function encrypt_gateway_credentials(array $credentials): string {
    $json = json_encode($credentials);
    $iv   = random_bytes(16);
    $enc  = openssl_encrypt($json, 'AES-256-CBC', base64_decode(_gateway_encryption_key()), 0, $iv);
    return base64_encode($iv . $enc);
}
