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
