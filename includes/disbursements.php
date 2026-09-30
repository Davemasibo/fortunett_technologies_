<?php
// Manual confirmations of money already transferred, never a money-transfer API.
function ensureDisbursementSchema(PDO $pdo): void
{
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
        amount DECIMAL(12,2) NOT NULL, PRIMARY KEY(payment_id),
        KEY disbursement_id (disbursement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function disbursementMoney(string $value): int
{
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
    }
    return (int)round((float)$value * 100);
}

function disbursementPayments(PDO $pdo, int $tenantId, string $cutoff, bool $lock = false): array
{
    $st = $pdo->prepare("SELECT id, amount FROM payments
        WHERE tenant_id = ? AND collection_type = 'platform' AND status = 'completed'
          AND released_at IS NULL AND payment_date <= ? AND created_at <= ?
        ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$tenantId, $cutoff, $cutoff]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function recordDisbursement(PDO $pdo, int $tenantId, int $actorId, array $input): int
{
    $reference = trim($input['reference'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $cutoff = $input['cutoff'] ?? '';
    $paidAt = $input['disbursed_at'] ?? '';
    foreach ([$cutoff, $paidAt] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date || $date > date('Y-m-d H:i:s')) {
            throw new InvalidArgumentException('Use a valid date and time that is not in the future.');
        }
    }
    if ($cutoff > $paidAt) throw new InvalidArgumentException('The collection cutoff cannot be after the transfer date.');
    if ($tenantId <= 0 || $actorId <= 0 || $reference === '' || strlen($reference) > 100 || strlen($notes) > 255) {
        throw new InvalidArgumentException('A tenant and transfer reference are required (reference: 100 characters; notes: 255).');
    }
    $cash = disbursementMoney((string)($input['cash_amount'] ?? ''));
    $fees = disbursementMoney((string)($input['fees_amount'] ?? '0'));
    $platformCost = disbursementMoney((string)($input['platform_cost'] ?? '0'));
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
        if ($cash + $fees !== $gross) {
            throw new InvalidArgumentException('Cash sent plus fees withheld must equal the previewed collections. Choose an earlier cutoff for a smaller batch.');
        }
        $ids = array_column($rows, 'id');
        foreach ($queued as $q) {
            if (in_array($q['payment_id'], $ids) && !in_array($q['status'], ['pending', 'cancelled'], true)) {
                throw new InvalidArgumentException('A selected payment has an automatic payout in progress or already paid. Reconcile it first.');
            }
        }
        $pdo->prepare('INSERT INTO tenant_disbursements
            (tenant_id, reference, gross_amount, cash_amount, fees_amount, platform_cost, disbursed_at, recorded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $tenantId, $reference, $gross / 100, $cash / 100, $fees / 100, $platformCost / 100, $paidAt, $actorId, $notes,
            ]);
        $id = (int)$pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO tenant_disbursement_items VALUES (?, ?, ?)');
        $release = $pdo->prepare('UPDATE payments SET released_at = ?, release_note = ? WHERE id = ? AND tenant_id = ?');
        $settle = $pdo->prepare("UPDATE isp_payout_queue SET status = 'paid', processed_at = ?, notes = ?
            WHERE payment_id = ? AND tenant_id = ? AND status IN ('pending','cancelled')");
        foreach ($rows as $row) {
            $item->execute([$id, $row['id'], $row['amount']]);
            $note = 'Manual disbursement #' . $id . ': ' . $reference;
            $release->execute([$paidAt, $note, $row['id'], $tenantId]);
            $settle->execute([$paidAt, $note, $row['id'], $tenantId]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
