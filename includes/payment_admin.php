<?php
/**
 * Correcting and removing a HAND-ENTERED payment.
 *
 * A payment is not one row. `process_payment_success()` writes a payments row,
 * a client_invoices row, two-to-six ledger_entries, a platform_commissions row
 * for hotspot, an isp_payout_queue row when the platform collected, and it
 * extends the customer's expiry. Editing the amount in `payments` alone leaves
 * the invoice, the ledger and the commission stating a different number, and
 * every settlement figure is then computed from whichever one the query
 * happened to read. So the money trail is corrected or removed as a unit here,
 * in one place, used by both endpoints.
 *
 * Three rules that must not be relaxed:
 *
 *  1. **Only a hand-entered payment may be edited or deleted.** A row that came
 *     from Safaricom is evidence of money that actually moved; if it is wrong,
 *     the fix is a reversing entry, not a delete. manuallyRecordedSql() is the
 *     one definition of "hand-entered" and it is what gates every operation in
 *     this file.
 *  2. **Never touch a payout that has left, or is leaving.** M-Pesa B2C has no
 *     chargeback. `processing` means Safaricom accepted the request and the
 *     result callback has not landed; `paid` means it has. Either way the money
 *     is gone and the record of why must stay.
 *  3. **Revoking access is opt-in and is refused when it cannot be exact.** The
 *     expiry is rolled back by subtracting the same period that granted it,
 *     which is only correct while no later payment has extended it.
 */

require_once __DIR__ . '/payment_routing.php';
require_once __DIR__ . '/validity.php';

/** Ledger pairs the pipeline writes, keyed by (entry_type, account). */
const PAYMENT_LEDGER_SHAPE = [
    'base'       => [['debit', 'cash'], ['credit', 'revenue']],
    'commission' => [['debit', 'revenue'], ['credit', 'commission']],
    'payout'     => [['debit', 'isp_payable'], ['credit', 'payout_queued']],
];

/**
 * Mirrors the invoice number process_payment_success() derives from a receipt.
 * Kept identical on purpose: if the two ever drift, editing a reference orphans
 * the invoice instead of renaming it.
 */
function paymentInvoiceNumber(string $receipt, int $tenantId): string
{
    return 'INV-' . substr(strtoupper(preg_replace('/[^A-Z0-9]/i', '', $receipt)), 0, 20) . '-' . $tenantId;
}

/**
 * Is this reference already on another payment for this tenant?
 *
 * Checks both places a reference lives — `payments.transaction_id` and the
 * paired `mpesa_transactions.checkout_request_id` — because a half-written
 * record (the pipeline threw between the two inserts) still burns the code, and
 * letting a second payment reuse it would make the two indistinguishable to
 * every idempotency check downstream.
 *
 * @return array|null The conflicting payment's details, or null when free.
 */
function paymentReferenceConflict(PDO $pdo, int $tenantId, string $ref, ?int $excludePaymentId = null): ?array
{
    $ref = trim($ref);
    if ($ref === '') return null;

    $exclude = $excludePaymentId ? ' AND p.id <> ' . (int)$excludePaymentId : '';

    try {
        $st = $pdo->prepare("
            SELECT p.id, p.amount, p.payment_date, p.status, p.payment_method,
                   c.full_name, c.phone
            FROM payments p
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE p.tenant_id = ? AND p.transaction_id = ?{$exclude}
            LIMIT 1
        ");
        $st->execute([$tenantId, $ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['where'] = 'payments';
            return $row;
        }
    } catch (Throwable $e) {
        error_log('[payment_admin] reference check (payments): ' . $e->getMessage());
    }

    // An mpesa_transactions row with no payments row means a previous attempt
    // died midway. The code is still spoken for.
    try {
        $st = $pdo->prepare("
            SELECT m.amount, m.created_at AS payment_date, m.status, m.result_desc,
                   c.full_name, c.phone
            FROM mpesa_transactions m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.tenant_id = ? AND m.checkout_request_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM payments p
                  WHERE p.tenant_id = m.tenant_id AND p.transaction_id = m.checkout_request_id
              )
            LIMIT 1
        ");
        $st->execute([$tenantId, $ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['id']    = null;
            $row['where'] = 'mpesa_transactions';
            return $row;
        }
    } catch (Throwable $e) {
        error_log('[payment_admin] reference check (mpesa_transactions): ' . $e->getMessage());
    }

    return null;
}

/** A one-line description of a conflicting payment, for an error message. */
function paymentConflictSummary(array $conflict): string
{
    $who  = trim((string)($conflict['full_name'] ?? '')) ?: 'an unnamed customer';
    $when = !empty($conflict['payment_date'])
          ? date('d M Y H:i', strtotime($conflict['payment_date']))
          : 'an unknown date';
    $amt  = isset($conflict['amount']) ? 'KES ' . number_format((float)$conflict['amount'], 2) : 'an unknown amount';

    if (($conflict['where'] ?? '') === 'mpesa_transactions') {
        return "already used by an incomplete transaction for {$who} ({$amt}, {$when}). "
             . 'That entry never finished writing, so the code is still taken.';
    }

    return "already recorded against {$who} — {$amt} on {$when}.";
}

/**
 * Load a payment this tenant is allowed to edit or delete.
 *
 * @throws RuntimeException when it is not theirs, or not hand-entered.
 */
function loadManualPayment(PDO $pdo, int $tenantId, int $paymentId): array
{
    $st = $pdo->prepare("
        SELECT p.*, c.full_name, c.phone, c.package_id, c.expiry_date, c.connection_type,
               " . manuallyRecordedSql('p') . " AS is_manual
        FROM payments p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.id = ? AND p.tenant_id = ?
        LIMIT 1
    ");
    $st->execute([$paymentId, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('That payment does not exist in your account.');
    }
    if (!(int)$row['is_manual']) {
        throw new RuntimeException(
            'This payment came from M-Pesa, not from a hand-entered record, so it cannot be edited or deleted. '
            . 'It is the record of money that actually moved. If it is wrong, record a correcting entry instead.'
        );
    }

    $row['payout'] = paymentPayoutRow($pdo, $tenantId, $paymentId);
    return $row;
}

/** The queued ISP payout for a payment, if one was ever created. */
function paymentPayoutRow(PDO $pdo, int $tenantId, int $paymentId): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM isp_payout_queue WHERE payment_id = ? AND tenant_id = ? LIMIT 1");
        $st->execute([$paymentId, $tenantId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null; // table may predate the payout migration
    }
}

/**
 * Refuse when the money has already been sent to the ISP, or is in flight.
 *
 * @throws RuntimeException
 */
function assertPayoutNotInFlight(array $payment): void
{
    $payout = $payment['payout'] ?? null;
    $status = strtolower((string)($payout['status'] ?? ''));

    if (in_array($status, ['processing', 'paid'], true)) {
        throw new RuntimeException(
            'A disbursement for this payment is ' . ($status === 'paid' ? 'already paid' : 'in flight')
            . '. M-Pesa B2C cannot be reversed, so the record of why the money left has to stay. '
            . 'Ask FortuNett support to reconcile it instead.'
        );
    }

    if (!empty($payment['released_at'])) {
        throw new RuntimeException(
            'This payment has already been released for settlement on '
            . date('d M Y', strtotime($payment['released_at']))
            . '. It can no longer be edited or deleted from here.'
        );
    }
}

/** The tenant's hotspot commission rate — same lookup the pipeline uses. */
function paymentCommissionRate(PDO $pdo, int $tenantId): float
{
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(p.hotspot_commission_rate, 0.03) AS rate
            FROM tenants t
            LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
            WHERE t.id = ? LIMIT 1
        ");
        $st->execute([$tenantId]);
        return (float)($st->fetchColumn() ?: 0.03);
    } catch (Throwable $e) {
        return 0.03;
    }
}

/**
 * Correct a hand-entered payment, carrying the change through the money trail.
 *
 * Accepts any of: amount, reference, payment_date, method, notes, status.
 * Returns a list of what actually changed, so the UI can say so rather than
 * claiming a save that touched nothing.
 */
function updateManualPayment(PDO $pdo, int $tenantId, int $paymentId, array $in): array
{
    $payment = loadManualPayment($pdo, $tenantId, $paymentId);
    assertPayoutNotInFlight($payment);

    $oldRef    = (string)$payment['transaction_id'];
    $oldAmount = (float)$payment['amount'];

    $newRef    = array_key_exists('reference', $in)  ? strtoupper(trim((string)$in['reference'])) : $oldRef;
    $newAmount = array_key_exists('amount', $in)     ? round((float)$in['amount'], 2)             : $oldAmount;
    $newMethod = array_key_exists('method', $in)     ? paymentNormalizeMethod((string)$in['method']) : (string)$payment['payment_method'];
    $newNotes  = array_key_exists('notes', $in)      ? trim((string)$in['notes'])                 : (string)($payment['notes'] ?? '');
    $newStatus = array_key_exists('status', $in)     ? strtolower(trim((string)$in['status']))    : (string)$payment['status'];

    if (!in_array($newStatus, ['completed', 'pending', 'failed'], true)) {
        $newStatus = (string)$payment['status'];
    }

    $newDate = (string)$payment['payment_date'];
    if (!empty($in['payment_date'])) {
        $ts = strtotime(str_replace('T', ' ', (string)$in['payment_date']));
        if ($ts) $newDate = date('Y-m-d H:i:s', $ts);
    }

    if ($newRef === '')   throw new RuntimeException('A reference code is required.');
    if ($newAmount <= 0)  throw new RuntimeException('Amount must be greater than zero.');

    // The duplicate rule applies to an edit exactly as it does to a new record —
    // otherwise the check is trivially bypassed by recording under one code and
    // editing it to another.
    if ($newRef !== $oldRef) {
        $conflict = paymentReferenceConflict($pdo, $tenantId, $newRef, $paymentId);
        if ($conflict) {
            throw new RuntimeException('Reference ' . $newRef . ' is ' . paymentConflictSummary($conflict));
        }
    }

    $changed = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE payments
               SET amount = ?, transaction_id = ?, payment_method = ?, payment_date = ?,
                   status = ?, notes = ?
             WHERE id = ? AND tenant_id = ?
        ")->execute([$newAmount, $newRef, $newMethod, $newDate, $newStatus, $newNotes, $paymentId, $tenantId]);

        if ($newAmount !== $oldAmount) $changed[] = 'amount';
        if ($newRef !== $oldRef)       $changed[] = 'reference';
        if ($newDate !== (string)$payment['payment_date']) $changed[] = 'date';
        if ($newMethod !== (string)$payment['payment_method']) $changed[] = 'method';
        if ($newStatus !== (string)$payment['status']) $changed[] = 'status';

        // ── The paired mpesa_transactions row ─────────────────────────────────
        // checkout_request_id is what manuallyRecordedSql() joins on, so if it
        // is not renamed with the payment the row stops being recognised as
        // hand-entered and can never be edited again.
        $pdo->prepare("
            UPDATE mpesa_transactions
               SET checkout_request_id = ?, amount = ?, created_at = ?,
                   status = ?, updated_at = NOW()
             WHERE tenant_id = ? AND checkout_request_id = ?
        ")->execute([
            $newRef, $newAmount, $newDate,
            $newStatus === 'completed' ? 'completed' : 'pending',
            $tenantId, $oldRef,
        ]);

        paymentRewriteTrail($pdo, $tenantId, $paymentId, $payment, $oldRef, $newRef, $oldAmount, $newAmount);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // A pending entry being confirmed is the moment the customer should come
    // back online — the same thing recording a verified payment does. Outside
    // the transaction: provisioning talks to a router and must never hold a
    // database lock.
    $activation = null;
    if ($newStatus === 'completed' && $payment['status'] !== 'completed') {
        $activation = paymentRunPipeline($pdo, $tenantId, (int)$payment['client_id'], $newAmount, $newRef, $newMethod);
        $changed[]  = 'activation';
    }

    return [
        'changed'    => $changed,
        'reference'  => $newRef,
        'amount'     => $newAmount,
        'activation' => $activation,
    ];
}

/**
 * Carry a reference or amount change through invoice, ledger, commission and
 * payout so every table states the same number.
 */
function paymentRewriteTrail(
    PDO $pdo, int $tenantId, int $paymentId, array $payment,
    string $oldRef, string $newRef, float $oldAmount, float $newAmount
): void {
    $refChanged    = $newRef !== $oldRef;
    $amountChanged = abs($newAmount - $oldAmount) > 0.001;
    if (!$refChanged && !$amountChanged) return;

    // ── Invoice ───────────────────────────────────────────────────────────────
    try {
        $pdo->prepare("
            UPDATE client_invoices
               SET invoice_number = ?, amount = ?
             WHERE payment_id = ? AND tenant_id = ?
        ")->execute([paymentInvoiceNumber($newRef, $tenantId), $newAmount, $paymentId, $tenantId]);
    } catch (Throwable $e) {
        error_log('[payment_admin] invoice rewrite: ' . $e->getMessage());
    }

    // ── Commission, and the ledger amounts that depend on it ─────────────────
    $oldCommission = 0.0;
    $newCommission = 0.0;
    try {
        $cSt = $pdo->prepare("SELECT commission_amount FROM platform_commissions WHERE payment_id = ? AND tenant_id = ? LIMIT 1");
        $cSt->execute([$paymentId, $tenantId]);
        $hasCommission = $cSt->fetch(PDO::FETCH_ASSOC);

        if ($hasCommission) {
            $oldCommission = (float)$hasCommission['commission_amount'];
            $rate          = paymentCommissionRate($pdo, $tenantId);
            $newCommission = round($newAmount * $rate, 2);

            $pdo->prepare("
                UPDATE platform_commissions
                   SET gross_amount = ?, commission_rate = ?, commission_amount = ?,
                       net_amount = ?, receipt = ?
                 WHERE payment_id = ? AND tenant_id = ?
            ")->execute([
                $newAmount, $rate, $newCommission, round($newAmount - $newCommission, 2),
                $newRef, $paymentId, $tenantId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[payment_admin] commission rewrite: ' . $e->getMessage());
    }

    // ── Payout queue (pending only — assertPayoutNotInFlight ran already) ─────
    $newNet = round($newAmount - $newCommission, 2);
    try {
        $pdo->prepare("
            UPDATE isp_payout_queue
               SET gross_amount = ?, commission_amount = ?, net_amount = ?, receipt = ?
             WHERE payment_id = ? AND tenant_id = ? AND status = 'pending'
        ")->execute([$newAmount, $newCommission, $newNet, $newRef, $paymentId, $tenantId]);
    } catch (Throwable $e) {
        error_log('[payment_admin] payout rewrite: ' . $e->getMessage());
    }

    // ── Ledger ────────────────────────────────────────────────────────────────
    // Keyed on (entry_type, account) because the pipeline writes 'revenue'
    // twice — credited for the sale and debited for the commission — and a
    // blanket update on payment_id alone would set both to the same figure.
    $amounts = [
        'base'       => $newAmount,
        'commission' => $newCommission,
        'payout'     => $newNet,
    ];
    foreach (PAYMENT_LEDGER_SHAPE as $pair => $legs) {
        foreach ($legs as [$entryType, $account]) {
            try {
                $pdo->prepare("
                    UPDATE ledger_entries
                       SET amount = ?, reference = ?
                     WHERE payment_id = ? AND tenant_id = ? AND entry_type = ? AND account = ?
                ")->execute([$amounts[$pair], $newRef, $paymentId, $tenantId, $entryType, $account]);
            } catch (Throwable $e) {
                error_log('[payment_admin] ledger rewrite ' . $entryType . '/' . $account . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * Delete a hand-entered payment and everything it produced.
 *
 * A mistaken manual entry is not a transaction that happened and was reversed —
 * it is a transaction that never existed, so it is removed rather than left in
 * the ledger as a voided artifact that every report then has to exclude. The
 * guards above are what make that safe: a real M-Pesa payment can never reach
 * this function, and neither can one whose payout has moved.
 */
function deleteManualPayment(PDO $pdo, int $tenantId, int $paymentId, bool $revokeAccess = false): array
{
    $payment = loadManualPayment($pdo, $tenantId, $paymentId);
    assertPayoutNotInFlight($payment);

    $ref      = (string)$payment['transaction_id'];
    $clientId = (int)$payment['client_id'];
    $removed  = [];

    // Worked out before the delete, while the payment row is still there to
    // compare dates against.
    $revocation = $revokeAccess
        ? paymentPlanRevocation($pdo, $tenantId, $payment)
        : ['applied' => false, 'reason' => 'not requested'];

    $pdo->beginTransaction();
    try {
        $del = function (string $sql, array $args, string $label) use ($pdo, &$removed) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($args);
                if ($st->rowCount() > 0) $removed[$label] = $st->rowCount();
            } catch (Throwable $e) {
                // A table the deployment never created is not a reason to
                // abandon the delete — the payments row is what matters.
                error_log('[payment_admin] delete ' . $label . ': ' . $e->getMessage());
            }
        };

        $del("DELETE FROM isp_payout_queue WHERE payment_id = ? AND tenant_id = ? AND status IN ('pending','cancelled')",
             [$paymentId, $tenantId], 'payout_queue');
        $del("DELETE FROM platform_commissions WHERE payment_id = ? AND tenant_id = ?",
             [$paymentId, $tenantId], 'commission');
        $del("DELETE FROM ledger_entries WHERE payment_id = ? AND tenant_id = ?",
             [$paymentId, $tenantId], 'ledger_entries');
        $del("DELETE FROM client_invoices WHERE payment_id = ? AND tenant_id = ?",
             [$paymentId, $tenantId], 'invoice');
        // Scoped to the hand-entered marker so a genuine Safaricom row that
        // happens to share the reference is never removed.
        $del("DELETE FROM mpesa_transactions
              WHERE tenant_id = ? AND checkout_request_id = ?
                AND (merchant_request_id LIKE 'MANUAL-%' OR result_desc LIKE 'Manual:%')",
             [$tenantId, $ref], 'mpesa_transaction');

        $st = $pdo->prepare("DELETE FROM payments WHERE id = ? AND tenant_id = ?");
        $st->execute([$paymentId, $tenantId]);
        if ($st->rowCount() < 1) {
            throw new RuntimeException('The payment was already removed by someone else.');
        }
        $removed['payment'] = 1;

        if ($revocation['applied']) {
            $pdo->prepare("UPDATE clients SET expiry_date = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$revocation['new_expiry'], $clientId, $tenantId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // sms_logs is deliberately left alone. It records that a customer was
    // actually texted, which remains true whatever happens to the payment, and
    // deleting it would make the message look like it was never sent.

    return [
        'reference'  => $ref,
        'amount'     => (float)$payment['amount'],
        'client'     => (string)($payment['full_name'] ?? ''),
        'removed'    => $removed,
        'revocation' => $revocation,
    ];
}

/**
 * Work out whether the expiry this payment granted can be taken back exactly.
 *
 * Refused whenever another completed payment landed at or after this one: the
 * expiry then reflects time the customer did pay for, and subtracting a period
 * from it would cut off someone who is paid up.
 */
function paymentPlanRevocation(PDO $pdo, int $tenantId, array $payment): array
{
    $clientId = (int)$payment['client_id'];

    if ((string)$payment['status'] !== 'completed') {
        return ['applied' => false, 'reason' => 'the payment was never confirmed, so it granted no access'];
    }
    if (empty($payment['expiry_date'])) {
        return ['applied' => false, 'reason' => 'the customer has no expiry date to roll back'];
    }

    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM payments
            WHERE client_id = ? AND tenant_id = ? AND status = 'completed'
              AND id <> ? AND payment_date >= ?
        ");
        $st->execute([$clientId, $tenantId, (int)$payment['id'], $payment['payment_date']]);
        if ((int)$st->fetchColumn() > 0) {
            return [
                'applied' => false,
                'reason'  => 'a later payment has extended this customer since, so the expiry no longer '
                           . 'belongs only to this entry. Adjust it by hand from the customer page if you need to.',
            ];
        }
    } catch (Throwable $e) {
        return ['applied' => false, 'reason' => 'could not confirm later payments, so the expiry was left alone'];
    }

    $pkg = null;
    if (!empty($payment['package_id'])) {
        try {
            $pSt = $pdo->prepare("SELECT name, validity_value, validity_unit FROM packages WHERE id = ? AND tenant_id = ?");
            $pSt->execute([(int)$payment['package_id'], $tenantId]);
            $pkg = $pSt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $pkg = null;
        }
    }
    if (!$pkg) {
        return ['applied' => false, 'reason' => 'the customer has no package, so there is no period to subtract'];
    }

    $newExpiry = packageShortenExpiry($payment['expiry_date'], $pkg['validity_value'] ?? 30, $pkg['validity_unit'] ?? 'days');
    if (!$newExpiry) {
        return ['applied' => false, 'reason' => 'the expiry date could not be read'];
    }

    return [
        'applied'    => true,
        'new_expiry' => $newExpiry,
        'period'     => packageValidityLabel($pkg['validity_value'] ?? 30, $pkg['validity_unit'] ?? 'days'),
        'package'    => (string)$pkg['name'],
        // check_expiry.php runs every 15 minutes and moves an elapsed account
        // through grace to disabled. Doing that here would duplicate the
        // grace-period logic in a second place and get it subtly different.
        'now_past'   => strtotime($newExpiry) < time(),
    ];
}

/** Map whatever the form sent to a value the payments ENUM accepts. */
function paymentNormalizeMethod(string $raw): string
{
    $map = [
        'm-pesa' => 'mpesa', 'mpesa' => 'mpesa', 'safaricom' => 'mpesa',
        'mpesa_stk' => 'mpesa_stk', 'stk' => 'mpesa_stk',
        'cash' => 'cash',
        'bank_transfer' => 'bank_transfer', 'bank transfer' => 'bank_transfer', 'bank' => 'bank_transfer',
        'card' => 'card', 'credit card' => 'card', 'debit card' => 'card',
        'cheque' => 'bank_transfer', 'check' => 'bank_transfer',
    ];
    return $map[strtolower(trim($raw))] ?? 'cash';
}

/** Run the activation pipeline for a payment that has just been confirmed. */
function paymentRunPipeline(PDO $pdo, int $tenantId, int $clientId, float $amount, string $ref, string $method): array
{
    require_once __DIR__ . '/payment_pipeline.php';
    try {
        $pkgSt = $pdo->prepare("SELECT package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
        $pkgSt->execute([$clientId, $tenantId]);
        $packageId = $pkgSt->fetchColumn();

        $res = process_payment_success(
            $pdo, $clientId, $tenantId, $amount, $ref,
            $method === 'mpesa' ? 'mpesa' : $method,
            $packageId ? (int)$packageId : null,
            false
        );
        return ['attempted' => true, 'expiry_date' => $res['expiry_date'] ?? null, 'steps' => $res['steps'] ?? []];
    } catch (Throwable $e) {
        error_log("[payment_admin] pipeline [$ref]: " . $e->getMessage());
        return ['attempted' => true, 'error' => $e->getMessage()];
    }
}
