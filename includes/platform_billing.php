<?php
/**
 * Platform billing — tenants paying FortuNett.
 *
 * ── The problem this file exists to fix ──────────────────────────────────────
 * There were two disconnected billing systems:
 *
 *   tenant_bills       written and displayed by billing.php (the tenant's own
 *                      page), settled by callback.php after an STK push
 *   platform_invoices  written by cron/monthly_billing.php, displayed by
 *                      super_admin/billing.php, and the ONLY thing
 *                      cron/check_suspensions.php looks at
 *
 * So a tenant could pay by STK, watch their bill go green, and still be
 * suspended that night — because the invoice the suspension engine cares about
 * was never touched. The super admin could not see the payment either, because
 * they were looking at the other table.
 *
 * platform_invoices is now the single source of truth. Everything that settles
 * money goes through applyPlatformPayment() below, whether it arrived by STK
 * push, by paybill C2B, or was marked paid by hand.
 *
 * Every entry point below calls ensurePlatformBillingSchema() first, which
 * repairs the 2026-07-26 platform-collections migration in place if it was
 * never applied. It is called inside the functions rather than at include time
 * because some callers require this file from inside a function body, where the
 * global $pdo is not in scope - passing null to a PDO-typed parameter would
 * turn a missing migration into a TypeError, which is worse than the bug it
 * fixes. The guard is static, so calling it from all three costs one query.
 */

require_once __DIR__ . '/schema_guard.php';

/**
 * A tenant's permanent paybill reference, e.g. "FN5".
 * Short on purpose — it gets read out over the phone and typed at a till.
 */
function platformBillingCode(PDO $pdo, int $tenantId): string
{
    ensurePlatformBillingSchema($pdo);

    try {
        $st = $pdo->prepare("SELECT platform_billing_code FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$tenantId]);
        $code = trim((string)$st->fetchColumn());
        if ($code !== '') {
            return strtoupper($code);
        }
        // Column missing or not back-filled — derive and persist it
        $code = 'FN' . $tenantId;
        try {
            $pdo->prepare("UPDATE tenants SET platform_billing_code = ? WHERE id = ?")->execute([$code, $tenantId]);
        } catch (Throwable $_e) {}
        return $code;
    } catch (Throwable $_e) {
        return 'FN' . $tenantId;
    }
}

/**
 * Does this BillRefNumber belong to platform billing rather than an end
 * customer? Returns the tenant id, or null to let normal client matching run.
 *
 * Accepts the short code ("FN5") or a full invoice number ("INV-2026-07-5"),
 * because operators paste invoice numbers out of habit.
 */
function resolvePlatformBillingRef(PDO $pdo, string $ref): ?int
{
    $ref = strtoupper(trim($ref));
    if ($ref === '') {
        return null;
    }

    try {
        $st = $pdo->prepare("SELECT id FROM tenants WHERE UPPER(platform_billing_code) = ? LIMIT 1");
        $st->execute([$ref]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
    } catch (Throwable $_e) { /* column may not exist yet */ }

    // Full invoice number
    try {
        $st = $pdo->prepare("SELECT tenant_id FROM platform_invoices WHERE UPPER(invoice_number) = ? LIMIT 1");
        $st->execute([$ref]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
    } catch (Throwable $_e) {}

    return null;
}

/**
 * Record money received from a tenant and apply it to their invoices.
 *
 * Oldest invoice first, part-payments allowed, surplus left as credit against
 * the next invoice generated. Idempotent on $receipt so a replayed M-Pesa
 * callback cannot double-credit.
 *
 * @return array{ok:bool,message:string,payment_id:?int,allocated:float,reactivated:bool}
 */
function applyPlatformPayment(
    PDO    $pdo,
    int    $tenantId,
    float  $amount,
    string $receipt,
    string $phone   = '',
    string $billRef = '',
    string $source  = 'c2b',
    string $raw     = ''
): array {
    ensurePlatformBillingSchema($pdo);

    $out = ['ok' => false, 'message' => '', 'payment_id' => null, 'allocated' => 0.0, 'reactivated' => false];

    if ($amount <= 0) {
        $out['message'] = 'Amount must be positive';
        return $out;
    }

    // ── Idempotency ───────────────────────────────────────────────────────────
    if ($receipt !== '') {
        try {
            $dup = $pdo->prepare("SELECT id FROM platform_payments WHERE mpesa_receipt = ? LIMIT 1");
            $dup->execute([$receipt]);
            if ($existing = $dup->fetchColumn()) {
                $out['ok']         = true;
                $out['payment_id'] = (int)$existing;
                $out['message']    = 'Already recorded';
                return $out;
            }
        } catch (Throwable $_e) { /* table may not exist yet */ }
    }

    try {
        $ins = $pdo->prepare("
            INSERT INTO platform_payments
                (tenant_id, amount, allocated, phone, mpesa_receipt, bill_ref, source, raw_callback, paid_at)
            VALUES (?, ?, 0, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $tenantId, $amount, $phone ?: null,
            $receipt ?: null, $billRef ?: null, $source, $raw ?: null,
        ]);
        $paymentId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $out['message'] = 'Could not record payment: ' . $e->getMessage();
        error_log('applyPlatformPayment insert: ' . $e->getMessage());
        return $out;
    }

    $out['payment_id'] = $paymentId;

    // ── Allocate oldest-first ────────────────────────────────────────────────
    $remaining = $amount;
    try {
        $open = $pdo->prepare("
            SELECT id, total_due, COALESCE(amount_paid,0) AS amount_paid
            FROM platform_invoices
            WHERE tenant_id = ? AND status <> 'paid'
            ORDER BY billing_period ASC, id ASC
        ");
        $open->execute([$tenantId]);

        $allocSt = $pdo->prepare("INSERT INTO platform_payment_allocations (payment_id, invoice_id, amount) VALUES (?, ?, ?)");
        $updSt   = $pdo->prepare("
            UPDATE platform_invoices
            SET amount_paid = ?,
                status      = ?,
                paid_at     = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END,
                payment_method = ?,
                transaction_ref = COALESCE(NULLIF(transaction_ref,''), ?)
            WHERE id = ?
        ");

        foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            if ($remaining <= 0.004) break;

            $balance = round((float)$inv['total_due'] - (float)$inv['amount_paid'], 2);
            if ($balance <= 0) continue;

            $apply    = min($remaining, $balance);
            $newPaid  = round((float)$inv['amount_paid'] + $apply, 2);
            $isSettled = $newPaid + 0.004 >= (float)$inv['total_due'];
            $status   = $isSettled ? 'paid' : 'pending';

            $updSt->execute([
                $newPaid, $status, $status,
                $source === 'stk' ? 'mpesa_stk' : ($source === 'manual' ? 'manual' : 'mpesa_paybill'),
                $receipt ?: null,
                $inv['id'],
            ]);
            $allocSt->execute([$paymentId, $inv['id'], $apply]);

            $remaining = round($remaining - $apply, 2);
        }

        $allocated = round($amount - $remaining, 2);
        $pdo->prepare("UPDATE platform_payments SET allocated = ? WHERE id = ?")->execute([$allocated, $paymentId]);
        $out['allocated'] = $allocated;

    } catch (Throwable $e) {
        $out['message'] = 'Recorded but allocation failed: ' . $e->getMessage();
        error_log('applyPlatformPayment allocate: ' . $e->getMessage());
        return $out;
    }

    // ── Reactivate if nothing is outstanding any more ────────────────────────
    try {
        $owe = $pdo->prepare("SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
        $owe->execute([$tenantId]);
        if ((int)$owe->fetchColumn() === 0) {
            $st = $pdo->prepare("SELECT status FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$tenantId]);
            if (in_array($st->fetchColumn(), ['suspended', 'expired'], true)) {
                $pdo->prepare("
                    UPDATE tenants SET status = 'active', suspended_at = NULL, suspended_reason = NULL WHERE id = ?
                ")->execute([$tenantId]);
                $out['reactivated'] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('applyPlatformPayment reactivate: ' . $e->getMessage());
    }

    $out['ok'] = true;
    $credit    = round($amount - $out['allocated'], 2);
    $out['message'] = 'KSH ' . number_format($out['allocated'], 2) . ' applied'
                    . ($credit > 0 ? ', KSH ' . number_format($credit, 2) . ' left as credit' : '')
                    . ($out['reactivated'] ? ' — tenant reactivated' : '');
    return $out;
}

/**
 * Make sure the current month's invoice exists in platform_invoices.
 *
 * The tenant billing page used to compute this into tenant_bills on page view,
 * which is why the tenant and the super admin saw different numbers. Both now
 * read the same row. Mirrors cron/monthly_billing.php's calculation so an
 * invoice looks identical whether the cron or a page view created it.
 */
function ensureCurrentPlatformInvoice(PDO $pdo, int $tenantId): ?array
{
    // billing.php SELECTs amount_paid immediately after calling this, outside
    // any try/catch. Healing the schema here is what stops that query throwing
    // a 1054 and blanking every tenant's billing page with a 500.
    ensurePlatformBillingSchema($pdo);

    $periodStart = date('Y-m-01');

    try {
        $find = $pdo->prepare("SELECT * FROM platform_invoices WHERE tenant_id = ? AND billing_period = ? LIMIT 1");
        $find->execute([$tenantId, $periodStart]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        // Never recalculate a settled or part-paid invoice — the amount owed is
        // fixed once money has been applied to it.
        if ($existing && ($existing['status'] === 'paid' || (float)($existing['amount_paid'] ?? 0) > 0)) {
            return $existing;
        }

        // Plan rates
        $rate = $pdo->prepare("
            SELECT COALESCE(p.pppoe_fee_per_user, 25) AS pppoe_fee,
                   COALESCE(p.hotspot_commission_rate, 0.03) AS hotspot_rate,
                   p.id AS plan_id
            FROM tenants t
            LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
            WHERE t.id = ? LIMIT 1
        ");
        $rate->execute([$tenantId]);
        $r = $rate->fetch(PDO::FETCH_ASSOC) ?: ['pppoe_fee' => 25, 'hotspot_rate' => 0.03, 'plan_id' => null];

        // Active PPPoE users
        $pc = $pdo->prepare("
            SELECT COUNT(*) FROM clients
            WHERE tenant_id = ? AND status = 'active' AND connection_type = 'pppoe'
        ");
        $pc->execute([$tenantId]);
        $pppoeCount = (int)$pc->fetchColumn();

        // Hotspot money collected this period
        $hc = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            JOIN clients c ON c.id = p.client_id
            WHERE p.tenant_id = ? AND p.status = 'completed'
              AND c.connection_type = 'hotspot'
              AND p.payment_date >= ?
        ");
        $hc->execute([$tenantId, $periodStart]);
        $hotspotCollections = (float)$hc->fetchColumn();

        // pppoe_subtotal, hotspot_commission and total_due are STORED GENERATED
        // columns — the database derives them from the inputs below. Writing to
        // them explicitly is rejected (or silently ignored, which is worse: the
        // invoice then shows a figure nobody intended). Only the inputs are set.
        $invoiceNumber = 'INV-' . date('Y-m') . '-' . $tenantId;
        $dueDate       = date('Y-m-d', strtotime($periodStart . ' +14 days'));

        if ($existing) {
            $pdo->prepare("
                UPDATE platform_invoices
                SET pppoe_user_count = ?, pppoe_fee_per_user = ?,
                    hotspot_collections = ?, hotspot_commission_rate = ?,
                    plan_id = ?
                WHERE id = ? AND status <> 'paid'
            ")->execute([
                $pppoeCount, $r['pppoe_fee'],
                $hotspotCollections, $r['hotspot_rate'],
                $r['plan_id'], $existing['id'],
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO platform_invoices
                    (invoice_number, tenant_id, billing_period, plan_id,
                     pppoe_user_count, pppoe_fee_per_user,
                     hotspot_collections, hotspot_commission_rate,
                     base_fee, status, due_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?)
            ")->execute([
                $invoiceNumber, $tenantId, $periodStart, $r['plan_id'],
                $pppoeCount, $r['pppoe_fee'],
                $hotspotCollections, $r['hotspot_rate'],
                $dueDate,
            ]);
        }

        $find->execute([$tenantId, $periodStart]);
        return $find->fetch(PDO::FETCH_ASSOC) ?: null;

    } catch (Throwable $e) {
        error_log("ensureCurrentPlatformInvoice($tenantId): " . $e->getMessage());
        return null;
    }
}
