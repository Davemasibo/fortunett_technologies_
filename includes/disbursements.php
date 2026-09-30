<?php
// Manual confirmations of money already transferred, never a money-transfer API.
function ensureDisbursementBalance(PDO $pdo): void
{
    require_once __DIR__ . '/schema_guard.php';
    if (!ensureColumn($pdo, 'payments', 'disbursed_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0')) {
        throw new RuntimeException('Could not prepare disbursement balances.');
    }
}

function ensureDisbursementSchema(PDO $pdo): void
{
    require_once __DIR__ . '/schema_guard.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_disbursements (
        id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL,
        reference VARCHAR(100) NOT NULL, gross_amount DECIMAL(12,2) NOT NULL,
        cash_amount DECIMAL(12,2) NOT NULL, fees_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        platform_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        disbursed_at DATETIME NOT NULL, recorded_by INT NOT NULL,
        notes VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY tenant_reference (tenant_id, reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_disbursement_items (
        disbursement_id INT NOT NULL, payment_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL, PRIMARY KEY(disbursement_id, payment_id),
        KEY payment_id (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade earlier installations without discarding already recorded payouts.
    if (!ensureColumn($pdo, 'payments', 'disbursed_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0')
        || !ensureColumn($pdo, 'tenant_disbursements', 'platform_cost', 'DECIMAL(12,2) NOT NULL DEFAULT 0')) {
        throw new RuntimeException('Could not prepare disbursement balances.');
    }
    $primary = $pdo->query("SHOW INDEX FROM tenant_disbursement_items WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($primary) === 1 && $primary[0]['Column_name'] === 'payment_id') {
        $pdo->exec('ALTER TABLE tenant_disbursement_items DROP PRIMARY KEY, ADD PRIMARY KEY(disbursement_id, payment_id), ADD KEY payment_id(payment_id)');
    }
}

/** Nairobi calendar dates are accepted without requiring a transfer's exact second. */
function disbursementDate(string $value, string $label = 'Transfer date'): string
{
    $value = str_replace('T', ' ', trim($value));
    $zone = new DateTimeZone('Africa/Nairobi');
    $now = new DateTimeImmutable('now', $zone);
    $dateOnly = strlen($value) === 10;
    if (strlen($value) === 16) $value .= ':00';
    $format = $dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
    if (!$date || $date->format($format) !== $value) {
        throw new InvalidArgumentException($label . ': choose a valid calendar date.');
    }
    if ($dateOnly) {
        if ($date->format('Y-m-d') > $now->format('Y-m-d')) throw new InvalidArgumentException($label . ' cannot be in the future.');
        $date = $date->setTime(23, 59, 59);
        if ($date > $now) $date = $now;
    } elseif ($date > $now) {
        throw new InvalidArgumentException($label . ' cannot be in the future (Nairobi time).');
    }
    return $date->format('Y-m-d H:i:s');
}

function disbursementMoney(string $value): int
{
    $value = trim($value);
    if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $value)) $value = str_replace(',', '', $value);
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
    }
    return (int)round((float)$value * 100);
}

function disbursementPayments(PDO $pdo, int $tenantId, string $cutoff, bool $lock = false): array
{
    $st = $pdo->prepare("SELECT id, amount AS original_amount, amount - disbursed_amount AS amount FROM payments
        WHERE tenant_id = ? AND collection_type = 'platform' AND status = 'completed'
          AND released_at IS NULL AND amount > disbursed_amount AND payment_date <= ? AND created_at <= ?
        ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$tenantId, $cutoff, $cutoff]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function recordDisbursement(PDO $pdo, int $tenantId, int $actorId, array $input): int
{
    $reference = trim($input['reference'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $cutoff = disbursementDate((string)($input['cutoff'] ?? ''), 'Collections through');
    $paidAt = disbursementDate((string)($input['disbursed_at'] ?? ''), 'Transfer date');
    if (substr($cutoff, 0, 10) > substr($paidAt, 0, 10)) throw new InvalidArgumentException('Collections through must be on or before the transfer date.');
    if ($tenantId <= 0 || $actorId <= 0 || $reference === '' || strlen($reference) > 100 || strlen($notes) > 255) {
        throw new InvalidArgumentException('A tenant and transfer reference are required (reference: 100 characters; notes: 255).');
    }
    $cash = disbursementMoney((string)($input['cash_amount'] ?? ''));
    $fees = disbursementMoney(trim((string)($input['fees_amount'] ?? '')) ?: '0');
    $platformCost = disbursementMoney(trim((string)($input['platform_cost'] ?? '')) ?: '0');
    $expected = disbursementMoney((string)($input['expected_gross'] ?? ''));
    if ($cash <= 0 || ($fees > 0 && $notes === '')) {
        throw new InvalidArgumentException('Enter the cash sent and explain any fees withheld in the notes.');
    }
    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $tenant->execute([$tenantId]);
        if (!$tenant->fetchColumn()) throw new InvalidArgumentException('Tenant not found.');
        $dup = $pdo->prepare('SELECT id FROM tenant_disbursements WHERE tenant_id = ? AND reference = ?');
        $dup->execute([$tenantId, $reference]);
        if ($dup->fetchColumn()) throw new InvalidArgumentException('This transfer reference is already recorded.');

        // Lock queue rows before payments, as the B2C settlement path does.
        $queue = $pdo->prepare('SELECT id, payment_id, status FROM isp_payout_queue WHERE tenant_id = ? FOR UPDATE');
        $queue->execute([$tenantId]);
        $queued = $queue->fetchAll(PDO::FETCH_ASSOC);
        $rows = disbursementPayments($pdo, $tenantId, $cutoff, true);
        $gross = array_sum(array_map(fn($r) => disbursementMoney((string)$r['amount']), $rows));
        if (!$rows || $gross !== $expected || count($rows) !== (int)($input['expected_count'] ?? 0)) {
            throw new InvalidArgumentException('Collections changed or have already been disbursed. Refresh the preview.');
        }
        $settledAmount = $cash + $fees;
        if ($settledAmount > $gross) {
            throw new InvalidArgumentException('Cash sent plus tenant fees is KES ' . number_format($settledAmount / 100, 2)
                . ', but only KES ' . number_format($gross / 100, 2) . ' is available. A charge paid by you belongs in Platform transfer cost.');
        }
        // Allocate the actual payout oldest-first. An unpaid remainder stays owed.
        $allocations = [];
        $remaining = $settledAmount;
        foreach ($rows as $row) {
            if ($remaining === 0) break;
            $available = disbursementMoney((string)$row['amount']);
            $allocated = min($available, $remaining);
            $allocations[] = ['id' => $row['id'], 'amount' => $allocated, 'full' => $allocated === $available];
            $remaining -= $allocated;
        }
        $ids = array_column($allocations, 'id');
        foreach ($queued as $q) {
            if (in_array($q['payment_id'], $ids) && !in_array($q['status'], ['pending', 'cancelled'], true)) {
                throw new InvalidArgumentException('A selected payment has an automatic payout in progress or already paid. Reconcile it first.');
            }
        }
        $pdo->prepare('INSERT INTO tenant_disbursements
            (tenant_id, reference, gross_amount, cash_amount, fees_amount, platform_cost, disbursed_at, recorded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $tenantId, $reference, $settledAmount / 100, $cash / 100, $fees / 100, $platformCost / 100, $paidAt, $actorId, $notes,
            ]);
        $id = (int)$pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO tenant_disbursement_items (disbursement_id, payment_id, amount) VALUES (?, ?, ?)');
        $release = $pdo->prepare('UPDATE payments SET disbursed_amount = disbursed_amount + ?, released_at = ?, release_note = ? WHERE id = ? AND tenant_id = ?');
        // A partially settled queue row is held out of B2C to prevent sending
        // its original full amount. The remainder can be recorded manually.
        $settle = $pdo->prepare("UPDATE isp_payout_queue SET status = ?, processed_at = ?, notes = ?
            WHERE payment_id = ? AND tenant_id = ? AND status IN ('pending','cancelled')");
        foreach ($allocations as $row) {
            $item->execute([$id, $row['id'], $row['amount'] / 100]);
            $note = 'Manual disbursement #' . $id . ': ' . $reference;
            $release->execute([$row['amount'] / 100, $row['full'] ? $paidAt : null, $note, $row['id'], $tenantId]);
            $settle->execute([$row['full'] ? 'paid' : 'cancelled', $row['full'] ? $paidAt : null,
                $note . ($row['full'] ? '' : ' - remaining balance requires manual disbursement'), $row['id'], $tenantId]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
