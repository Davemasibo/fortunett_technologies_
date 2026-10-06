<?php
require_once __DIR__ . '/validity.php';
require_once __DIR__ . '/subscription_purchase.php';

/** Atomically extend once per payment and retain a durable provisioning retry. */
function activatePaidSubscription(PDO $pdo, int $clientId, int $tenantId, string $key, string $receipt, ?array $package, ?float $amount = null): array
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

    if ($amount !== null) ensureSubscriptionGrants($pdo);
    $pdo->beginTransaction();
    try {
        // Serializes renewals for the same customer, including different receipts.
        $st = $pdo->prepare('SELECT expiry_date, status, account_balance, connection_type FROM clients WHERE id = ? AND tenant_id = ? FOR UPDATE');
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
            $grant = [];
            if ($amount !== null) {
                $st = $pdo->prepare('SELECT periods, validity_value, validity_unit, balance_after AS balance FROM payment_subscription_grants WHERE tenant_id = ? AND client_id = ? AND (activation_key IN (?, ?) OR receipt IN (?, ?)) LIMIT 1');
                $st->execute([$tenantId, $clientId, $key, $receipt, $key, $receipt]);
                $grant = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $pdo->commit();
            return array_merge(['expiry_date' => $previous['expiry_date'], 'already_applied' => true], $grant);
        }

        $hotspot = ($client['connection_type'] ?? '') === 'hotspot';
        if ($package && $hotspot) $package['conn_type'] = 'hotspot';
        $purchase = $package && $amount !== null
            ? subscriptionPurchase($package, $amount, (float)($client['account_balance'] ?? 0))
            : ['periods' => 1, 'validity_value' => $package['validity_value'] ?? null, 'validity_unit' => $package['validity_unit'] ?? null];
        $expiry = $package && $purchase['periods'] > 0 ? packageExtendExpiry(!$hotspot && $client['status'] === 'active' ? $client['expiry_date'] : null, $purchase['validity_value'], $purchase['validity_unit']) : $client['expiry_date'];
        if ($package && $purchase['periods'] > 0) {
            $pdo->prepare("UPDATE clients SET status = 'active', expiry_date = ?, package_id = ?,
                expiry_reminder_3d_sent = 0, expiry_reminder_1d_sent = 0
                WHERE id = ? AND tenant_id = ?")
                ->execute([$expiry, $package['id'], $clientId, $tenantId]);
        }
        if ($package && $amount !== null) {
            $pdo->prepare('UPDATE clients SET account_balance = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$purchase['balance'], $clientId, $tenantId]);
            $pdo->prepare('INSERT INTO payment_subscription_grants
                (tenant_id, activation_key, client_id, receipt, package_id, package_price, periods,
                 validity_value, validity_unit, previous_expiry, expiry_date, previous_status, previous_balance, balance_after)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $tenantId, $key, $clientId, $receipt, $package['id'], $package['price'], $purchase['periods'],
                    $purchase['validity_value'], $purchase['validity_unit'], $client['expiry_date'], $expiry, $client['status'],
                    $client['account_balance'] ?? 0, $purchase['balance'],
                ]);
        }
        // A missing package requires repair, not an unlimited active subscription.
        if ($package) {
            $pdo->prepare('INSERT INTO payment_activations (tenant_id, activation_key, client_id, expiry_date) VALUES (?, ?, ?, ?)')
                ->execute([$tenantId, $key, $clientId, $expiry]);
            if ($receipt !== $key) {
                $pdo->prepare('INSERT INTO payment_activations (tenant_id, activation_key, client_id, expiry_date) VALUES (?, ?, ?, ?)')
                    ->execute([$tenantId, $receipt, $clientId, $expiry]);
            }
        }
        if (!$package || $purchase['periods'] > 0) $pdo->prepare("INSERT INTO pending_provisions (tenant_id, client_id, package_id, receipt, fail_reason, next_retry_at)
            VALUES (?, ?, ?, ?, 'Payment activation awaiting provisioning', NOW() + INTERVAL 1 MINUTE)
            ON DUPLICATE KEY UPDATE package_id = VALUES(package_id), receipt = VALUES(receipt),
                attempts = 1, fail_reason = VALUES(fail_reason), next_retry_at = VALUES(next_retry_at)")
            ->execute([$tenantId, $clientId, $package['id'] ?? null, $receipt]);
        $pdo->commit();
        return array_merge(['expiry_date' => $expiry, 'already_applied' => false], $purchase);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
