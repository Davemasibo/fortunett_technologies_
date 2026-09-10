<?php
require_once __DIR__ . '/validity.php';

function preparePaymentTerms(PDO $pdo, int $packageId, int $tenantId): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_purchase_terms (
        checkout_id VARCHAR(191) PRIMARY KEY, tenant_id INT NOT NULL, client_id INT NOT NULL,
        package_id INT NOT NULL, validity_value INT NOT NULL, validity_unit VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare('SELECT id, price, validity_value, validity_unit FROM packages WHERE id = ? AND tenant_id = ?');
    $st->execute([$packageId, $tenantId]);
    $terms = $st->fetch(PDO::FETCH_ASSOC);
    if (!$terms) throw new RuntimeException('Purchased package not found');
    // Reject invalid terms before sending an STK prompt.
    packageExpiryFrom($terms['validity_value'], $terms['validity_unit']);
    return $terms;
}

function recordPaymentTerms(PDO $pdo, string $checkout, int $clientId, int $tenantId, array $terms): void
{
    if ($checkout === '') throw new InvalidArgumentException('Checkout ID is required');
    $pdo->prepare('INSERT INTO payment_purchase_terms (checkout_id, tenant_id, client_id, package_id, validity_value, validity_unit) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$checkout, $tenantId, $clientId, $terms['id'], $terms['validity_value'], packageValidityUnit($terms['validity_unit'], true)]);
}

function loadPaymentTerms(PDO $pdo, string $checkout, int $clientId, int $tenantId): ?array
{
    try {
        $st = $pdo->prepare('SELECT package_id, validity_value, validity_unit FROM payment_purchase_terms WHERE checkout_id = ? AND client_id = ? AND tenant_id = ?');
        $st->execute([$checkout, $clientId, $tenantId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        // Historical payments predate snapshots; other database errors must not
        // silently change the purchased duration.
        if (($e->errorInfo[1] ?? null) === 1146) return null;
        throw $e;
    }
}
