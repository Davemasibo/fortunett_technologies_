<?php
/**
 * Unmatched paybill payments.
 *
 * A C2B confirmation is final — the money is already in the ISP's account and
 * Safaricom will not replay it. When the BillRefNumber resolves to nobody, the
 * handlers used to write one line to logs/tenant_c2b.log and return 0. The
 * customer had paid, nothing happened, and the only trace was a file nobody
 * reads.
 *
 * There is no Daraja API that lists past C2B transactions, so a poller cannot
 * recover these the way cron/stk_poll.php recovers a missing STK callback. The
 * backstop has to be a durable queue plus a human decision, which is what this
 * provides: every unroutable payment is captured with its full payload, shown
 * in the admin UI, and can be assigned to a customer in one click — at which
 * point it runs the exact same pipeline a matched payment would have.
 *
 * Self-installing: no migration required before the first payment arrives.
 */

function _unmatched_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS unmatched_payments (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id      INT           DEFAULT NULL,
                transaction_id VARCHAR(50)   NOT NULL,
                amount         DECIMAL(10,2) NOT NULL,
                phone          VARCHAR(20)   DEFAULT NULL,
                account_ref    VARCHAR(60)   DEFAULT NULL,
                payer_name     VARCHAR(120)  DEFAULT NULL,
                source         VARCHAR(30)   NOT NULL DEFAULT 'c2b_tenant',
                reason         VARCHAR(120)  NOT NULL DEFAULT 'unmatched',
                raw_payload    TEXT          DEFAULT NULL,
                status         ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
                resolved_client_id INT       DEFAULT NULL,
                resolved_by    INT           DEFAULT NULL,
                resolved_at    TIMESTAMP     NULL DEFAULT NULL,
                created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_transaction (transaction_id),
                INDEX idx_tenant (tenant_id, status),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('_unmatched_ensure_table: ' . $e->getMessage());
    }
}

/**
 * Capture a payment that arrived but could not be credited to anyone.
 *
 * @param string $reason 'unmatched' (no client) | 'unrouted' (no tenant) | 'ambiguous'
 */
function record_unmatched_payment(
    PDO     $pdo,
    ?int    $tenantId,
    string  $transactionId,
    float   $amount,
    string  $phone,
    string  $accountRef,
    string  $reason      = 'unmatched',
    string  $source      = 'c2b_tenant',
    ?string $rawPayload  = null,
    string  $payerName   = ''
): void {
    _unmatched_ensure_table($pdo);
    try {
        $pdo->prepare("
            INSERT INTO unmatched_payments
                (tenant_id, transaction_id, amount, phone, account_ref, payer_name, source, reason, raw_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), raw_payload = VALUES(raw_payload)
        ")->execute([
            $tenantId, $transactionId, $amount, $phone, $accountRef,
            $payerName ?: null, $source, $reason, $rawPayload,
        ]);
    } catch (Throwable $e) {
        error_log("record_unmatched_payment($transactionId): " . $e->getMessage());
    }
}

/** Count open unmatched payments for a tenant. */
function count_unmatched_payments(PDO $pdo, int $tenantId): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM unmatched_payments WHERE tenant_id = ? AND status = 'open'");
        $st->execute([$tenantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // table not created yet — no traffic has been unmatched
    }
}

/**
 * Credit an unmatched payment to a client, running the full payment pipeline
 * exactly as a matched C2B confirmation would have. Idempotent: a row already
 * marked resolved is left alone.
 *
 * @return array{success:bool, message:string}
 */
function resolve_unmatched_payment(PDO $pdo, int $rowId, int $clientId, int $tenantId, ?int $userId = null): array
{
    require_once __DIR__ . '/payment_pipeline.php';
    _unmatched_ensure_table($pdo);

    $st = $pdo->prepare("SELECT * FROM unmatched_payments WHERE id = ? AND tenant_id = ? LIMIT 1");
    $st->execute([$rowId, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row)                       return ['success' => false, 'message' => 'Payment not found'];
    if ($row['status'] !== 'open')   return ['success' => false, 'message' => 'Already ' . $row['status']];

    $cSt = $pdo->prepare("SELECT id, package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $cSt->execute([$clientId, $tenantId]);
    $client = $cSt->fetch(PDO::FETCH_ASSOC);
    if (!$client) return ['success' => false, 'message' => 'Client not found for this tenant'];

    // Whose bank the money is in was already decided when the payment arrived —
    // by which handler captured it. Hard-coding 'direct' here credited the ISP
    // for money FortuNett's own till was holding: the row read as settled and no
    // payout was ever queued for it, so a manually matched platform payment was
    // simply never disbursed.
    $platformCollected = in_array($row['source'] ?? '', ['c2b_platform', 'platform'], true);
    $collectionType    = $platformCollected ? 'platform' : 'direct';

    try {
        // Guard against a race with a late confirmation retry crediting it first
        $dup = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
        $dup->execute([$row['transaction_id']]);
        if (!$dup->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date, collection_type, notes)
                VALUES (?, ?, ?, 'mpesa_paybill', ?, 'completed', NOW(), ?, ?)
            ")->execute([
                $clientId, $tenantId, (float)$row['amount'], $row['transaction_id'], $collectionType,
                'Manually matched paybill payment — original ref: ' . ($row['account_ref'] ?: 'none'),
            ]);
        }

        process_payment_success(
            $pdo,
            $clientId,
            $tenantId,
            (float)$row['amount'],
            (string)$row['transaction_id'],
            'mpesa_paybill',
            !empty($client['package_id']) ? (int)$client['package_id'] : null,
            $platformCollected
        );

        $pdo->prepare("
            UPDATE unmatched_payments
            SET status = 'resolved', resolved_client_id = ?, resolved_by = ?, resolved_at = NOW()
            WHERE id = ?
        ")->execute([$clientId, $userId, $rowId]);

        return ['success' => true, 'message' => 'Payment credited and the customer has been provisioned.'];
    } catch (Throwable $e) {
        error_log("resolve_unmatched_payment($rowId): " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
