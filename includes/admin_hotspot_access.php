<?php
/** Explicit owner grants keep the normal paid hotspot policy intact. */
function adminHotspotGrantExpiry(array $user, array $client, string $requested, ?DateTimeImmutable $now = null): string {
    if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true) && empty($user['is_super_admin'])) {
        throw new InvalidArgumentException('Only an administrator can grant owner access.');
    }
    if (empty($user['tenant_id']) || (int)$user['tenant_id'] !== (int)($client['tenant_id'] ?? 0)) {
        throw new InvalidArgumentException('Customer not found.');
    }
    if (($client['connection_type'] ?? '') !== 'hotspot') throw new InvalidArgumentException('Owner access is for hotspot accounts only.');
    $now ??= new DateTimeImmutable();
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $requested);
    if (!$expiry || $expiry->format('Y-m-d\TH:i') !== $requested || $expiry <= $now || $expiry > $now->modify('+10 years')) {
        throw new InvalidArgumentException('Choose a future expiry within 10 years.');
    }
    return $expiry->format('Y-m-d H:i:s');
}

function adminHotspotGrantSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_admin_grants (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, client_id INT NOT NULL,
        granted_by INT NOT NULL, old_expiry DATETIME NULL, expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX client_grants (tenant_id, client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
