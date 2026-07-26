<?php
/**
 * Repairs the status ENUMs the payment path writes to.
 *
 * Fixes: "Payment could not be initiated: SQLSTATE[01000]: Warning: 1265
 *         Data truncated for column 'status' at row 1"
 *
 * Run from CLI:      php tools/repair_status_enums.php
 * Or in a browser:   /tools/repair_status_enums.php   (localhost only)
 *
 * Idempotent — safe to re-run. Delete afterwards if you prefer.
 */

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Access denied. Run from localhost or the CLI.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/schema_guard.php';

$targets = [
    ['clients',             'status',         ['active', 'inactive', 'suspended', 'pending', 'expired', 'grace']],
    ['payments',            'status',         ['pending', 'completed', 'failed', 'cancelled', 'refunded']],
    ['payments',            'payment_method', ['mpesa', 'cash', 'bank_transfer', 'card', 'mpesa_paybill', 'mpesa_stk', 'mpesa_c2b', 'voucher', 'manual']],
    ['mpesa_transactions',  'status',         ['pending', 'completed', 'failed', 'cancelled', 'timeout']],
    ['payment_auto_logins', 'status',         ['pending', 'used', 'expired']],
];

$columns = [
    ['mpesa_transactions', 'tenant_id',            'INT DEFAULT NULL'],
    ['mpesa_transactions', 'mpesa_receipt_number', 'VARCHAR(32) DEFAULT NULL'],
    ['mpesa_transactions', 'raw_callback',         'TEXT DEFAULT NULL'],
];

echo "sql_mode: " . $pdo->query('SELECT @@sql_mode')->fetchColumn() . "\n\n";

foreach ($targets as [$table, $column, $required]) {
    $before = _colType($pdo, $table, $column);
    if ($before === null) {
        printf("  --  %-20s %s\n", "$table.$column", 'not present — skipped');
        continue;
    }

    ensureEnumMembers($pdo, $table, $column, $required);
    $after = _colType($pdo, $table, $column);

    if ($before === $after) {
        printf("  --  %-20s already OK\n", "$table.$column");
    } else {
        printf("  OK  %-20s widened\n      was: %s\n      now: %s\n", "$table.$column", $before, $after);
    }
}

echo "\nMissing columns the payment handlers write:\n";
foreach ($columns as [$table, $column, $definition]) {
    $before = _colType($pdo, $table, $column);
    if ($before !== null) {
        printf("  --  %-38s already present\n", "$table.$column");
        continue;
    }
    ensureColumn($pdo, $table, $column, $definition);
    printf("  %s  %-38s %s\n",
        _colType($pdo, $table, $column) !== null ? 'OK' : '!!',
        "$table.$column",
        _colType($pdo, $table, $column) !== null ? 'added' : 'COULD NOT ADD');
}

echo "\nDone.\n";

function _colType(PDO $pdo, string $table, string $column): ?string
{
    $st = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
    ");
    $st->execute([$table, $column]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
