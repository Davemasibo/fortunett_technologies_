<?php
/**
 * Self-healing ENUM guards.
 *
 * Production MySQL runs with STRICT_TRANS_TABLES, so writing an enum value that
 * isn't a member is a hard error:
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1
 *
 * The hotspot captive portal writes clients.status='pending' before firing the
 * STK push. On any deployment where sql/migrations/2026-07-24-clients-status-enum.sql
 * was never applied, that INSERT throws and the customer sees
 * "Payment could not be initiated: ... Data truncated for column 'status'".
 *
 * These guards read information_schema once per request and widen the column in
 * place when a value the code actually writes is missing. A missed migration
 * degrades to a single ALTER instead of a customer-facing payment failure.
 */

/**
 * Ensure an ENUM column accepts every value in $required, widening it if not.
 * Preserves the existing member order, nullability and default.
 */
function ensureEnumMembers(PDO $pdo, string $table, string $column, array $required): bool
{
    static $checked = [];
    $key = $table . '.' . $column;
    if (isset($checked[$key])) {
        return $checked[$key];
    }
    $checked[$key] = false;

    try {
        $st = $pdo->prepare("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table, $column]);
        $col = $st->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            return false;   // table or column absent on this deployment
        }

        $type = $col['COLUMN_TYPE'];
        if (stripos($type, 'enum(') !== 0) {
            // Already a VARCHAR/TEXT — nothing to widen, any value fits.
            $checked[$key] = true;
            return true;
        }

        // enum('a','b','c') → ['a','b','c']
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $members = array_map(static fn($v) => str_replace("''", "'", $v), $m[1]);

        $missing = array_values(array_diff($required, $members));
        if (!$missing) {
            $checked[$key] = true;
            return true;
        }

        $all  = array_merge($members, $missing);
        $list = implode(',', array_map(static fn($v) => $pdo->quote($v), $all));

        $sql = sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` ENUM(%s)', $table, $column, $list);
        $sql .= ($col['IS_NULLABLE'] === 'NO') ? ' NOT NULL' : ' NULL';
        $sql .= _enumDefaultClause($pdo, $col['COLUMN_DEFAULT'], $col['IS_NULLABLE'] !== 'NO');

        $pdo->exec($sql);
        error_log("[schema_guard] widened $key with: " . implode(',', $missing));
        $checked[$key] = true;
        return true;

    } catch (Throwable $e) {
        // ALTER may be denied (read-only replica, restricted grants). Log and let
        // the caller proceed — the original INSERT error is still surfaced.
        error_log("[schema_guard] $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Reproduce a column's existing DEFAULT for the rewritten definition.
 *
 * MariaDB and MySQL disagree here: MariaDB's information_schema.COLUMN_DEFAULT
 * returns the value *already quoted* ("'inactive'") and the bare word NULL for a
 * null default, while MySQL returns the raw value ("inactive"). Quoting a
 * MariaDB value again yields DEFAULT '\'inactive\'', which the server rejects
 * with 1067 "Invalid default value".
 */
function _enumDefaultClause(PDO $pdo, ?string $default, bool $nullable): string
{
    if ($default === null || strcasecmp($default, 'NULL') === 0) {
        return $nullable ? ' DEFAULT NULL' : '';
    }
    // MariaDB style — already a quoted literal, pass it straight through
    if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
        return ' DEFAULT ' . $default;
    }
    return ' DEFAULT ' . $pdo->quote($default);
}

/**
 * Ensure a column exists, adding it if not. Returns true when the column is
 * present afterwards.
 *
 * A missing column is a hard 1054 error on every MySQL/MariaDB configuration —
 * unlike an out-of-range enum it does not depend on strict mode. In the C2B
 * confirmation handler the mpesa_transactions INSERT sits *before*
 * process_payment_success(), so one absent column aborted the whole handler and
 * the customer's account was never activated even though the money arrived.
 */
function ensureColumn(PDO $pdo, string $table, string $column, string $definition): bool
{
    static $checked = [];
    $key = $table . '.' . $column;
    if (isset($checked[$key])) {
        return $checked[$key];
    }
    $checked[$key] = false;

    try {
        $st = $pdo->prepare("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table, $column]);
        if ($st->fetchColumn()) {
            $checked[$key] = true;
            return true;
        }

        // Does the table itself exist? Adding a column to nothing is not an error
        // worth logging on deployments that never created the table.
        $tSt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $tSt->execute([$table]);
        if (!$tSt->fetchColumn()) {
            return false;
        }

        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        error_log("[schema_guard] added $key");
        $checked[$key] = true;
        return true;

    } catch (Throwable $e) {
        error_log("[schema_guard] $key: " . $e->getMessage());
        return false;
    }
}

/**
 * Give tenants.trial_ends_at / subscription_ends_at hour precision.
 *
 * Both shipped as DATE, so the shortest grace a super admin could hand out was a
 * whole day. Widening them to DATETIME lets access be extended by an hour while
 * an operator sorts out a payment.
 *
 * The conversion has one trap: a DATE of '2026-08-24' meant "valid through the
 * end of the 24th" (the comparisons are all `< today`), but MySQL widens it to
 * '2026-08-24 00:00:00' — which under a time-aware comparison expires the tenant
 * a full day early. Every value sitting exactly on midnight is therefore pushed
 * to 23:59:59 of the same day. That normalisation runs once ever, flagged in
 * platform_settings, so a tenant genuinely extended to midnight later on is not
 * silently given another day.
 */
function ensureTenantExpiryPrecision(PDO $pdo): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $done = false;

    $columns = ['trial_ends_at', 'subscription_ends_at'];

    try {
        $st = $pdo->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME IN (?, ?)
        ");
        $st->execute($columns);
        $found = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$found) {
            return false;   // table absent on this deployment
        }

        foreach ($found as $col) {
            if (strcasecmp($col['DATA_TYPE'], 'date') !== 0) {
                continue;   // already datetime/timestamp
            }
            $null = $col['IS_NULLABLE'] === 'NO' ? 'NOT NULL' : 'NULL DEFAULT NULL';
            $pdo->exec(sprintf(
                'ALTER TABLE `tenants` MODIFY COLUMN `%s` DATETIME %s',
                $col['COLUMN_NAME'],
                $null
            ));
            error_log('[schema_guard] widened tenants.' . $col['COLUMN_NAME'] . ' to DATETIME');
        }

        $done = true;

        // One-shot: lift midnight values to end-of-day so nobody loses a day.
        // Kept in its own try so a missing platform_settings table cannot make
        // the (already applied) column conversion look like a failure. Re-running
        // the UPDATEs is harmless anyway — 23:59:59 no longer matches.
        try {
            $flag = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
            $flag->execute(['tenant_expiry_precision_migrated']);
            if ($flag->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('[schema_guard] tenant expiry flag unreadable: ' . $e->getMessage());
        }

        $pdo->exec("
            UPDATE tenants
            SET trial_ends_at = DATE_ADD(trial_ends_at, INTERVAL 86399 SECOND)
            WHERE trial_ends_at IS NOT NULL AND TIME(trial_ends_at) = '00:00:00'
        ");
        $pdo->exec("
            UPDATE tenants
            SET subscription_ends_at = DATE_ADD(subscription_ends_at, INTERVAL 86399 SECOND)
            WHERE subscription_ends_at IS NOT NULL AND TIME(subscription_ends_at) = '00:00:00'
        ");
        error_log('[schema_guard] normalised tenant expiry midnights to end-of-day');

        try {
            $pdo->prepare("
                INSERT INTO platform_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute(['tenant_expiry_precision_migrated', date('c')]);
        } catch (Throwable $e) {
            error_log('[schema_guard] tenant expiry flag unwritable: ' . $e->getMessage());
        }

        return true;

    } catch (Throwable $e) {
        error_log('[schema_guard] tenant expiry precision: ' . $e->getMessage());
        return false;
    }
}

/**
 * Every status enum the payment/provisioning path writes to.
 * Keep in sync with the values actually used in code, not with the schema file.
 */
function ensurePaymentStatusEnums(PDO $pdo): void
{
    // clients.status — 'pending' is set by the hotspot buy flow before payment
    // confirms; 'expired'/'grace' are set by cron/check_expiry.php.
    ensureEnumMembers($pdo, 'clients', 'status',
        ['active', 'inactive', 'suspended', 'pending', 'expired', 'grace']);

    ensureEnumMembers($pdo, 'payments', 'status',
        ['pending', 'completed', 'failed', 'cancelled', 'refunded']);

    ensureEnumMembers($pdo, 'mpesa_transactions', 'status',
        ['pending', 'completed', 'failed', 'cancelled', 'timeout']);

    ensureEnumMembers($pdo, 'payment_auto_logins', 'status',
        ['pending', 'used', 'expired']);

    // payment_method is written as 'mpesa_paybill' by both C2B confirmation
    // handlers. The original enum only had the four manual methods, so every
    // paybill payment either threw (strict mode) or stored an empty string.
    ensureEnumMembers($pdo, 'payments', 'payment_method',
        ['mpesa', 'cash', 'bank_transfer', 'card', 'mpesa_paybill', 'mpesa_stk', 'mpesa_c2b', 'voucher', 'manual']);

    // Columns the payment handlers write but which predate the current schema.
    // tenant_id in particular is also SELECTed by hotspot_payment_status.php, so
    // without it the captive portal polls forever and never sees 'completed'.
    ensureColumn($pdo, 'mpesa_transactions', 'tenant_id',            'INT DEFAULT NULL');
    ensureColumn($pdo, 'mpesa_transactions', 'mpesa_receipt_number', 'VARCHAR(32) DEFAULT NULL');
    ensureColumn($pdo, 'mpesa_transactions', 'raw_callback',         'TEXT DEFAULT NULL');
}

/**
 * Install everything sql/migrations/2026-07-26-platform-collections.sql adds.
 *
 * That migration was never run on at least one production deployment, and the
 * symptom was as bad as it gets for a page: billing.php SELECTs `amount_paid`
 * at line 112, outside any try/catch, so a missing column threw
 *
 *   PDOException: SQLSTATE[42S22]: Unknown column 'amount_paid' in 'field list'
 *
 * and EVERY tenant's billing page returned a blank 500. It was invisible to
 * `curl` because the fatal is past redirectIfNotLoggedIn() — an unauthenticated
 * request gets a clean 302 to login.php and never reaches the query. The page
 * looked healthy from outside and was broken for every logged-in user.
 *
 * Nothing else installs `platform_payments` / `platform_payment_allocations`
 * either — they existed only in the .sql file — so applyPlatformPayment() would
 * have been the next thing to fail.
 *
 * Called from includes/platform_billing.php, which every consumer of
 * platform_invoices already loads, so all of them heal together.
 */
function ensurePlatformBillingSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // Part-payment support: a tenant paying 500 against a 1,200 invoice must
    // reduce the balance rather than be rejected or over-credited.
    if (ensureColumn($pdo, 'platform_invoices', 'amount_paid',
                     'DECIMAL(12,2) NOT NULL DEFAULT 0')) {
        try {
            // An invoice already marked paid is fully paid by definition.
            $pdo->exec("UPDATE platform_invoices SET amount_paid = total_due
                        WHERE status = 'paid' AND amount_paid = 0");
        } catch (Throwable $e) {
            error_log('[schema_guard] platform_invoices.amount_paid backfill: ' . $e->getMessage());
        }
    }

    // The tenant's permanent paybill reference. platformBillingCode() already
    // degrades to a derived 'FN<id>' without it, so this is not fatal - but
    // without the column that derived value cannot be persisted and the unique
    // index that stops two tenants sharing a code never exists.
    if (ensureColumn($pdo, 'tenants', 'platform_billing_code', 'VARCHAR(16) DEFAULT NULL')) {
        try {
            $pdo->exec("UPDATE tenants SET platform_billing_code = CONCAT('FN', id)
                        WHERE platform_billing_code IS NULL OR platform_billing_code = ''");
        } catch (Throwable $e) {
            error_log('[schema_guard] tenants.platform_billing_code backfill: ' . $e->getMessage());
        }
        try {
            $idx = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                                    AND INDEX_NAME = 'uniq_platform_billing_code' LIMIT 1");
            $idx->execute();
            if (!$idx->fetchColumn()) {
                $pdo->exec("ALTER TABLE tenants ADD UNIQUE KEY uniq_platform_billing_code (platform_billing_code)");
            }
        } catch (Throwable $e) {
            error_log('[schema_guard] tenants.uniq_platform_billing_code: ' . $e->getMessage());
        }
    }

    // Money tenants send US, and which invoice each shilling settled.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS platform_payments (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id      INT NOT NULL,
                amount         DECIMAL(12,2) NOT NULL,
                allocated      DECIMAL(12,2) NOT NULL DEFAULT 0,
                phone          VARCHAR(20)  DEFAULT NULL,
                mpesa_receipt  VARCHAR(32)  DEFAULT NULL,
                bill_ref       VARCHAR(64)  DEFAULT NULL,
                source         VARCHAR(16)  NOT NULL DEFAULT 'c2b',
                raw_callback   TEXT         DEFAULT NULL,
                paid_at        DATETIME     NOT NULL,
                created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_platform_receipt (mpesa_receipt),
                INDEX idx_pp_tenant (tenant_id),
                INDEX idx_pp_paid   (paid_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS platform_payment_allocations (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                payment_id  INT NOT NULL,
                invoice_id  INT NOT NULL,
                amount      DECIMAL(12,2) NOT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ppa_payment (payment_id),
                INDEX idx_ppa_invoice (invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[schema_guard] platform_payments tables: ' . $e->getMessage());
    }
}

/**
 * Create the two tables the SMS path writes to, if a deployment is missing them.
 *
 * `sms_logs` is the worse of the two omissions: it is written by
 * process_payment_success() and read by api/dashboard/stats.php, and it is
 * defined in NO schema file in this repository. Both call sites wrap their
 * query in a try/catch, so on every deployment the effect was silent — the
 * payment-confirmation SMS had no dedupe key (Safaricom retries a callback, so
 * a customer could be texted twice for one payment), and the admin dashboard
 * counted zero messages sent no matter how many went out. An operator looking
 * for evidence that SMS was working found none, which reads exactly like
 * "the system is not sending SMS".
 *
 * `sms_outbox` exists in the master schema and in a migration, so it is only
 * missing where that migration never ran — but SMSHelper::logMessage() writes
 * to it AFTER the message has already left, so an absent table turned a
 * successful send into an exception at every call site.
 */
function ensureSmsTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_outbox (
                id                INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id         INT NOT NULL,
                client_id         INT DEFAULT NULL,
                recipient_phone   VARCHAR(20) NOT NULL,
                message           TEXT NOT NULL,
                status            VARCHAR(20) DEFAULT 'sent',
                provider_response TEXT DEFAULT NULL,
                sent_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_outbox_tenant (tenant_id, sent_at),
                INDEX idx_sms_outbox_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[schema_guard] sms_outbox: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_logs (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id  INT NOT NULL,
                client_id  INT DEFAULT NULL,
                phone      VARCHAR(20) NOT NULL,
                message    TEXT NOT NULL,
                status     VARCHAR(20) NOT NULL DEFAULT 'sent',
                reference  VARCHAR(64) DEFAULT NULL,
                sent_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_logs_tenant (tenant_id, sent_at),
                INDEX idx_sms_logs_ref (tenant_id, client_id, reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('[schema_guard] sms_logs: ' . $e->getMessage());
    }

    // Older deployments have sms_outbox without provider_response, and the
    // INSERT names that column explicitly — a missing one is a hard 1054 on
    // every send.
    ensureColumn($pdo, 'sms_outbox', 'provider_response', 'TEXT DEFAULT NULL');
    ensureColumn($pdo, 'sms_logs',   'reference',         'VARCHAR(64) DEFAULT NULL');
}
