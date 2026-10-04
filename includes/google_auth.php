<?php
require_once __DIR__ . '/env.php';

function googleClientId(): string { return trim((string)get_env_var('GOOGLE_CLIENT_ID', '')); }
function googleAuthReady(): bool {
    if (!preg_match('/^[a-zA-Z0-9._-]+\.apps\.googleusercontent\.com$/D', googleClientId())
        || !is_file(__DIR__ . '/../vendor/autoload.php')) return false;
    require_once __DIR__ . '/../vendor/autoload.php';
    return class_exists(Google\Auth\OAuth2::class) && class_exists(Firebase\JWT\JWK::class);
}
function ensureGoogleIdentitySchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS google_identities (
        google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id INT NOT NULL, email VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY google_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function googlePending(string $key): ?array {
    $value = $_SESSION[$key] ?? null;
    if (!is_array($value) || ($value['expires'] ?? 0) < time()) { unset($_SESSION[$key]); return null; }
    return $value;
}
function googleValidateClaims(array $claims, string $nonce): array {
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com','https://accounts.google.com'], true)
        || !isset($claims['exp']) || (int)$claims['exp'] <= time()
        || empty($claims['sub']) || strlen((string)$claims['sub']) > 255
        || !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)
        || ($claims['email_verified'] ?? false) !== true
        || !isset($claims['nonce']) || !hash_equals($nonce, (string)$claims['nonce'])) {
        throw new InvalidArgumentException('Google could not verify this sign-in. Please try again.');
    }
    $email = strtolower($claims['email']);
    return ['sub'=>(string)$claims['sub'], 'email'=>$email,
        'authoritative'=>str_ends_with($email, '@gmail.com') || !empty($claims['hd']),
        'expires'=>time()+600];
}
function googleVerifyCredential(string $credential, string $nonce): array {
    require_once __DIR__ . '/../vendor/autoload.php';
    // The origin's IPv6 egress is rejected by Google; use its working IPv4 route.
    $http = new GuzzleHttp\Client(['timeout'=>10, 'connect_timeout'=>5, 'force_ip_resolve'=>'v4']);
    $response = $http->get('https://www.googleapis.com/oauth2/v3/certs');
    $certs = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    return googleVerifyWithKeys($credential, $nonce, googleClientId(), $certs);
}
function googleVerifyWithKeys(string $credential, string $nonce, string $clientId, array $certs): array {
    $keys = Firebase\JWT\JWK::parseKeySet($certs, 'RS256');
    $verifier = new Google\Auth\OAuth2(['audience'=>$clientId]);
    $verifier->setIdToken($credential);
    try { $claims = (array)$verifier->verifyIdToken($keys); }
    catch (Throwable $e) { throw new InvalidArgumentException('Google could not verify this sign-in. Please try again.'); }
    return googleValidateClaims($claims, $nonce);
}
function googleLinkIdentity(PDO $pdo, int $userId, array $identity): void {
    // Unique keys ensure concurrent requests cannot attach an identity twice.
    $st = $pdo->prepare('SELECT user_id FROM google_identities WHERE google_sub = ?');
    $st->execute([$identity['sub']]);
    $existing = $st->fetchColumn();
    if ($existing !== false) {
        if ((int)$existing !== $userId) throw new InvalidArgumentException('This Google account is already linked to another account.');
        return;
    }
    $pdo->prepare('INSERT INTO google_identities(google_sub,user_id,email) VALUES(?,?,?)')
        ->execute([$identity['sub'],$userId,$identity['email']]);
}
function completeGooglePasswordLink(PDO $pdo, array $user): void {
    $identity = googlePending('google_link');
    if (!$identity) return;
    if (strtolower($user['email']) !== $identity['email']) {
        unset($_SESSION['google_link']);
        return;
    }
    ensureGoogleIdentitySchema($pdo);
    googleLinkIdentity($pdo, (int)$user['id'], $identity);
    unset($_SESSION['google_link']);
}
function googleRequestTenant(PDO $pdo): ?int {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if (in_array($host,['www.fortunetttech.site','fortunetttech.site','localhost','127.0.0.1'],true)) return null;
    if (!preg_match('/^([a-z0-9-]+)\.fortunetttech\.site$/D', $host, $match)) throw new InvalidArgumentException('Unsupported workspace address.');
    $st=$pdo->prepare('SELECT id FROM tenants WHERE subdomain=?'); $st->execute([$match[1]]);
    $id=$st->fetchColumn();
    if (!$id) throw new InvalidArgumentException('Workspace not found.');
    return (int)$id;
}
function googleTenantLoginUrl(PDO $pdo, int $tenantId): string {
    $st = $pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');
    $st->execute([$tenantId]); $slug = (string)$st->fetchColumn();
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) || in_array($slug,['www','admin','api'],true)) {
        throw new InvalidArgumentException('Your workspace address is unavailable. Contact support.');
    }
    return 'https://' . $slug . '.fortunetttech.site/login.php?signin=1';
}
function googleResolveIdentity(PDO $pdo, array $identity, ?int $tenantId): array {
    $st=$pdo->prepare('SELECT u.* FROM google_identities g JOIN users u ON u.id=g.user_id WHERE g.google_sub=?');
    $st->execute([$identity['sub']]); $user=$st->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        if ($tenantId === null && empty($user['is_super_admin'])) return ['action'=>'workspace','url'=>googleTenantLoginUrl($pdo,(int)$user['tenant_id'])];
        if ($tenantId !== null && (int)$user['tenant_id'] !== $tenantId) throw new InvalidArgumentException('This Google account belongs to a different workspace.');
        if (empty($user['email_verified'])) throw new InvalidArgumentException('Verify your account email before signing in.');
        return ['action'=>'login','user'=>$user];
    }
    $st=$pdo->prepare('SELECT id, tenant_id, is_super_admin FROM users WHERE email=?'); $st->execute([$identity['email']]);
    $existing=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($tenantId === null && count($existing) === 1 && empty($existing[0]['is_super_admin'])) return ['action'=>'workspace','url'=>googleTenantLoginUrl($pdo,(int)$existing[0]['tenant_id'])];
        if ($tenantId === null && count($existing) > 1) throw new InvalidArgumentException('Open your assigned workspace address to sign in.');
        if ($tenantId !== null && !in_array($tenantId, array_map('intval',array_column($existing,'tenant_id')), true)) throw new InvalidArgumentException('This account belongs to a different workspace.');
        return ['action'=>'link'];
    }
    if ($tenantId !== null) throw new InvalidArgumentException('Create a new workspace from www.fortunetttech.site/signup.php.');
    $st=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='signup_enabled' LIMIT 1");
    if ($st->fetchColumn() === '0') throw new InvalidArgumentException('New registrations are currently closed.');
    return ['action'=>'signup'];
}
