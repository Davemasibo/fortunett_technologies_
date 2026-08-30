<?php
/**
 * ISP Payouts — moving the platform's float back to the ISPs it belongs to.
 *
 * What already existed, and why it was not automation
 * --------------------------------------------------
 * `isp_payout_queue` recorded what was owed and `released_at` recorded that
 * somebody had decided to settle it — but nothing moved money. Both
 * `cron/auto_release_settlements.php` and `api/super_admin/release_payments.php`
 * only stamp `released_at`, and the email they send says the amount "will be
 * remitted within 1 business day", by hand. So a payout marked released and a
 * payout actually paid were the same state in the database, which is a bad
 * state to be in with real money: nothing could tell you what you still owed.
 *
 * This file adds the missing half. The states are now distinct:
 *
 *   pending    — owed, nothing attempted
 *   processing — a B2C request has been ACCEPTED by Safaricom; outcome unknown
 *   paid       — Safaricom's result callback confirmed the money landed
 *   cancelled  — withdrawn (a mis-tagged payment, a duplicate)
 *
 * `released_at` on the payment keeps its old meaning — "settled, stop showing
 * it as our liability" — and is now stamped by the result callback rather than
 * by a cron guessing.
 *
 * Safety posture: sending money is off unless three separate things are true —
 * the platform switch, the tenant's own opt-in, and a verified destination
 * number. See disbursementPreflight().
 */

require_once __DIR__ . '/db_master.php';

/**
 * Create the payout tables/columns if a deployment is missing the migration.
 * Same self-installing pattern the rest of the codebase uses.
 */
function ensurePayoutTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tenant_payout_settings (
            tenant_id     INT           NOT NULL,
            payout_phone  VARCHAR(20)   DEFAULT NULL,
            payout_name   VARCHAR(120)  DEFAULT NULL,
            auto_payout   TINYINT(1)    NOT NULL DEFAULT 0,
            min_payout    DECIMAL(10,2) NOT NULL DEFAULT 100.00,
            max_daily     DECIMAL(10,2) NOT NULL DEFAULT 50000.00,
            verified_at   DATETIME      DEFAULT NULL,
            verified_by   INT           DEFAULT NULL,
            notes         TEXT          DEFAULT NULL,
            updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // One row per B2C request. The batch is the unit of idempotency: it is
    // written BEFORE the API call, so a crash mid-send leaves evidence that a
    // request may be in flight instead of a queue that looks safe to re-send.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS isp_payout_batches (
            id                        INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id                 INT           NOT NULL,
            originator_conversation_id VARCHAR(64)  NOT NULL,
            conversation_id           VARCHAR(64)   DEFAULT NULL,
            transaction_id            VARCHAR(64)   DEFAULT NULL,
            payout_phone              VARCHAR(20)   NOT NULL,
            gross_amount              DECIMAL(10,2) NOT NULL,
            commission_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_amount                DECIMAL(10,2) NOT NULL,
            sent_amount               DECIMAL(10,2) NOT NULL,
            queue_ids                 TEXT          NOT NULL,
            status                    ENUM('sending','accepted','paid','failed','unknown') NOT NULL DEFAULT 'sending',
            result_code               VARCHAR(10)   DEFAULT NULL,
            result_desc               VARCHAR(255)  DEFAULT NULL,
            raw_result                TEXT          DEFAULT NULL,
            created_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at              DATETIME      DEFAULT NULL,
            UNIQUE KEY uq_originator (originator_conversation_id),
            INDEX idx_tenant (tenant_id),
            INDEX idx_status (status),
            INDEX idx_conversation (conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        "ALTER TABLE isp_payout_queue ADD COLUMN batch_id INT DEFAULT NULL",
        "ALTER TABLE isp_payout_queue ADD COLUMN attempts INT NOT NULL DEFAULT 0",
        "ALTER TABLE isp_payout_queue ADD COLUMN last_error VARCHAR(255) DEFAULT NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* already present */ }
    }
}

/** Read a platform_settings key. */
function payoutSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $st = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Everything that must be true before a single shilling can leave.
 *
 * Three independent gates, on purpose. A bug or a bad config in any one layer
 * should not be enough to start paying money out:
 *
 *   1. platform_settings.payouts_enabled = '1'  — the global switch, off by default
 *   2. platform_mpesa_config has B2C initiator credentials
 *   3. the tenant has auto_payout = 1 AND a verified payout number
 *
 * @return array{ok:bool,reasons:array,config:array}
 */
function disbursementPreflight(PDO $pdo): array
{
    ensurePayoutTables($pdo);

    $reasons = [];
    $config  = [];

    if (payoutSetting($pdo, 'payouts_enabled', '0') !== '1') {
        $reasons[] = "the platform payout switch is OFF (platform_settings.payouts_enabled != '1')";
    }

    try {
        $row = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    if (!$row) {
        $reasons[] = 'platform_mpesa_config has no row — the platform M-Pesa account is not set up';
    } else {
        $config = $row;
        foreach (['consumer_key', 'consumer_secret'] as $f) {
            if (empty($row[$f])) $reasons[] = "platform_mpesa_config.$f is empty";
        }
        if (empty($row['initiator_name']))      $reasons[] = 'platform_mpesa_config.initiator_name is empty';
        if (empty($row['security_credential'])) $reasons[] = 'platform_mpesa_config.security_credential is empty';
    }

    // The result callback is the only thing that can confirm a payout landed,
    // and Safaricom will not call a URL it cannot resolve. Without an absolute
    // public base URL every payout would be sent and then hang in 'accepted'
    // forever, which reads as money in flight that never arrives.
    $base = payoutSetting($pdo, 'public_base_url', '');
    if ($base === '') {
        $reasons[] = 'platform_settings.public_base_url is not set — set it with '
                   . "tools/payout_config.php --base-url=https://your-domain";
    } elseif (!preg_match('~^https?://[^/\s]+~i', $base)) {
        $reasons[] = "platform_settings.public_base_url ('$base') is not an absolute http(s) URL";
    } elseif (stripos($base, 'https://') !== 0) {
        $reasons[] = "platform_settings.public_base_url ('$base') must be https — Safaricom will not post to plain http";
    }

    // A sandbox payout is harmless but silently pays nobody, so name it rather
    // than letting a "successful" run be mistaken for money actually sent.
    $env = strtolower((string)($config['environment'] ?? 'sandbox'));
    if ($env !== 'production' && $env !== 'live') {
        $reasons[] = "platform M-Pesa is in '$env' — B2C will be accepted but no real money moves";
    }

    return ['ok' => empty($reasons), 'reasons' => $reasons, 'config' => $config];
}

/**
 * Per-tenant gate. Separate from the platform one so a misconfigured tenant
 * never blocks the rest, and so the reason is reportable per tenant.
 *
 * @return array{ok:bool,reason:string,settings:array}
 */
function tenantPayoutGate(PDO $pdo, int $tenantId): array
{
    ensurePayoutTables($pdo);

    $st = $pdo->prepare("SELECT * FROM tenant_payout_settings WHERE tenant_id = ? LIMIT 1");
    $st->execute([$tenantId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);

    if (!$s) {
        return ['ok' => false, 'reason' => 'no payout settings — run tools/payout_config.php', 'settings' => []];
    }
    if ((int)$s['auto_payout'] !== 1) {
        return ['ok' => false, 'reason' => 'auto payout is off for this tenant', 'settings' => $s];
    }
    if (empty($s['payout_phone'])) {
        return ['ok' => false, 'reason' => 'no payout phone number on file', 'settings' => $s];
    }
    // Verification is a human confirming the number belongs to this ISP. Paying
    // an unverified number is how money reaches the wrong person permanently —
    // M-Pesa has no chargeback.
    if (empty($s['verified_at'])) {
        return ['ok' => false, 'reason' => 'payout number has not been verified', 'settings' => $s];
    }

    return ['ok' => true, 'reason' => '', 'settings' => $s];
}

/** Total already sent (or in flight) to this tenant today — enforces max_daily. */
function payoutSentToday(PDO $pdo, int $tenantId): float
{
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(sent_amount), 0)
            FROM isp_payout_batches
            WHERE tenant_id = ?
              AND DATE(created_at) = CURDATE()
              AND status IN ('sending','accepted','paid','unknown')
        ");
        $st->execute([$tenantId]);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) {
        // Unknown means we cannot prove we are under the cap, so report the cap
        // as already spent rather than risk exceeding it.
        error_log('payoutSentToday(' . $tenantId . '): ' . $e->getMessage());
        return PHP_FLOAT_MAX;
    }
}

/**
 * Mark every payment behind a batch as settled.
 *
 * Called only from the B2C result callback, on a confirmed success. This is the
 * moment `released_at` becomes true rather than aspirational.
 */
function settlePayoutBatch(PDO $pdo, array $batch, string $note): int
{
    $ids = array_filter(array_map('intval', explode(',', (string)$batch['queue_ids'])));
    if (!$ids) return 0;

    $in = implode(',', array_fill(0, count($ids), '?'));

    $pdo->prepare("
        UPDATE isp_payout_queue
        SET status = 'paid', processed_at = NOW(),
            notes = CONCAT(COALESCE(notes,''), ' | ', ?)
        WHERE id IN ($in)
    ")->execute(array_merge([$note], $ids));

    // Stamp the payments themselves so the ISP's own pages stop showing the
    // money as awaiting disbursement.
    $st = $pdo->prepare("
        UPDATE payments p
        JOIN isp_payout_queue q ON q.payment_id = p.id
        SET p.released_at = NOW(), p.release_note = ?
        WHERE q.id IN ($in) AND p.released_at IS NULL
    ");
    $st->execute(array_merge([substr($note, 0, 255)], $ids));

    return $st->rowCount();
}
