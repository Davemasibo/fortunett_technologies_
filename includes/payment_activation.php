<?php
require_once __DIR__ . '/validity.php';

/** Atomically extend once per payment and retain a durable provisioning retry. */
function activatePaidSubscription(PDO $pdo, int $clientId, int $tenantId, string $key, string $receipt, ?array $package): array
{
    if ($key === '') throw new InvalidArgumentException('Payment reference is required');
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_activations (
        tenant_id INT NOT NULL,
        activation_key VARCHAR(191) NOT NULL,
        client_id INT NOT NULL,
        expiry_date DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (tenant_id, activation_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->beginTransaction();
    try {
        // Serializes renewals for the same customer, including different receipts.
        $st = $pdo->prepare('SELECT expiry_date, status FROM clients WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $st->execute([$clientId, $tenantId]);
        $client = $st->fetch(PDO::FETCH_ASSOC);
        if (!$client) throw new RuntimeException('Payment customer not found');

        $st = $pdo->prepare('SELECT client_id, expiry_date FROM payment_activations WHERE tenant_id = ? AND activation_key IN (?, ?)');
        $st->execute([$tenantId, $key, $receipt]);
        $previous = $st->fetch(PDO::FETCH_ASSOC);
        if ($previous) {
            if ((int)$previous['client_id'] !== $clientId) throw new RuntimeException('Payment reference belongs to another customer');
            // STK query initially knows only checkout ID. Link the final receipt
            // too, so a later receipt-based confirmation cannot grant time twice.
            $pdo->prepare('INSERT IGNORE INTO payment_activations (tenant_id, activation_key, client_id, expiry_date) VALUES (?, ?, ?, ?)')
                ->execute([$tenantId, $receipt, $clientId, $previous['expiry_date']]);
            $pdo->commit();
            return ['expiry_date' => $previous['expiry_date'], 'already_applied' => true];
        }

        $expiry = $package ? packageExtendExpiry($client['status'] === 'active' ? $client['expiry_date'] : null, $package['validity_value'] ?? null, $package['validity_unit'] ?? null) : $client['expiry_date'];
        if ($package) {
            $pdo->prepare("UPDATE clients SET status = 'active', expiry_date = ?, package_id = ?,
                expiry_reminder_3d_sent = 0, expiry_reminder_1d_sent = 0
                WHERE id = ? AND tenant_id = ?")
                ->execute([$expiry, $package['id'], $clientId, $tenantId]);
        }
        // A missing package requires repair, not an unlimited active subscription.
        $pdo->prepare('INSERT INTO payment_activations (tenant_id, activation_key, client_id, expiry_date) VALUES (?, ?, ?, ?)')
            ->execute([$tenantId, $key, $clientId, $expiry]);
        if ($receipt !== $key) {
            $pdo->prepare('INSERT INTO payment_activations (tenant_id, activation_key, client_id, expiry_date) VALUES (?, ?, ?, ?)')
                ->execute([$tenantId, $receipt, $clientId, $expiry]);
        }
        $pdo->prepare("INSERT INTO pending_provisions (tenant_id, client_id, package_id, receipt, fail_reason, next_retry_at)
            VALUES (?, ?, ?, ?, 'Payment activation awaiting provisioning', NOW() + INTERVAL 1 MINUTE)
            ON DUPLICATE KEY UPDATE package_id = VALUES(package_id), receipt = VALUES(receipt),
                attempts = 1, fail_reason = VALUES(fail_reason), next_retry_at = VALUES(next_retry_at)")
            ->execute([$tenantId, $clientId, $package['id'] ?? null, $receipt]);
        $pdo->commit();
        return ['expiry_date' => $expiry, 'already_applied' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
