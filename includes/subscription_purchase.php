<?php
require_once __DIR__ . '/validity.php';

/** Calculate in cents so decimal prices do not lose a paid period. */
function subscriptionPurchase(array $package, float $amount, float $balance = 0): array
{
    $price = (float)($package['price'] ?? 0);
    if (!is_finite($amount) || $amount < 0 || !is_finite($price) || $price <= 0 || !is_finite($balance)) {
        throw new InvalidArgumentException('A valid payment and positive package price are required.');
    }
    $priceCents = (int)round($price * 100);
    if ($priceCents < 1) throw new InvalidArgumentException('Package price must be at least one cent.');
    $available = (int)round($amount * 100) + (int)round($balance * 100);
    $periods = max(0, intdiv($available, $priceCents));
    // Hotspot buys one timed session; excess money remains account credit.
    if (strtolower($package['conn_type'] ?? $package['connection_type'] ?? $package['type'] ?? '') === 'hotspot') {
        $periods = min(1, $periods);
    }
    $value = filter_var($package['validity_value'] ?? null, FILTER_VALIDATE_INT);
    $unit = packageValidityUnit($package['validity_unit'] ?? null, true);
    if (!$value || $value < 1 || $periods > intdiv(PHP_INT_MAX, $value)) {
        throw new InvalidArgumentException('Package duration is invalid.');
    }
    return ['periods' => $periods, 'validity_value' => $value * $periods,
        'validity_unit' => $unit, 'balance' => ($available - $periods * $priceCents) / 100];
}

function ensureSubscriptionGrants(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_subscription_grants (
        tenant_id INT NOT NULL, activation_key VARCHAR(191) NOT NULL,
        client_id INT NOT NULL, receipt VARCHAR(191) NOT NULL, package_id INT NOT NULL,
        package_price DECIMAL(12,2) NOT NULL, periods INT NOT NULL,
        validity_value INT NOT NULL, validity_unit VARCHAR(20) NOT NULL,
        previous_expiry DATETIME NULL, expiry_date DATETIME NULL, previous_status VARCHAR(20) NOT NULL,
        previous_balance DECIMAL(12,2) NOT NULL, balance_after DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(tenant_id, activation_key), KEY receipt(tenant_id, receipt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
