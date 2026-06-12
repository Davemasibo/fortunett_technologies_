<?php
/**
 * Provisioning Retry — FortuNett Technologies
 *
 * Runs every 5 minutes. Picks up pending_provisions rows whose next_retry_at
 * has elapsed and retries autoProvisionClient(). Backs off exponentially:
 *   attempt 1 → 5 min, 2 → 15 min, 3 → 30 min, 4 → 60 min, 5+ → 120 min.
 * After 10 failed attempts the row is left in place but not retried further;
 * a human must investigate.
 *
 * Cron schedule (every 5 minutes):
 *   *\/5 * * * * php /var/www/html/cron/retry_provisions.php >> /var/log/fortunett_provisions.log 2>&1
 *
 * NOTE: Run sql/migrations/2026-06-07-pending-provisions.sql before enabling.
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auto_provision.php';

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Provision Retry Run ===");

// Silently create table on first run (safe no-op if migration already ran)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_provisions (
            id            INT           AUTO_INCREMENT PRIMARY KEY,
            tenant_id     INT           NOT NULL,
            client_id     INT           NOT NULL,
            package_id    INT           DEFAULT NULL,
            receipt       VARCHAR(50)   DEFAULT NULL,
            attempts      INT           NOT NULL DEFAULT 1,
            fail_reason   VARCHAR(500)  DEFAULT NULL,
            next_retry_at DATETIME      NOT NULL DEFAULT (NOW() + INTERVAL 5 MINUTE),
            created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_client_tenant (client_id, tenant_id),
            INDEX idx_retry (next_retry_at, attempts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $_) {}

// Max 10 attempts — beyond that requires human intervention
$due = $pdo->query("
    SELECT pp.*, c.status AS client_status
    FROM pending_provisions pp
    LEFT JOIN clients c ON c.id = pp.client_id
    WHERE pp.next_retry_at <= NOW()
      AND pp.attempts <= 10
    ORDER BY pp.next_retry_at ASC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

$log("Found " . count($due) . " provision(s) due for retry.");

foreach ($due as $row) {
    $clientId = (int)$row['client_id'];
    $tenantId = (int)$row['tenant_id'];
    $attempt  = (int)$row['attempts'];

    // Skip if client is inactive (payment reversed, package changed, etc.)
    if ($row['client_status'] === 'inactive') {
        $pdo->prepare("DELETE FROM pending_provisions WHERE id = ?")->execute([$row['id']]);
        $log("  DROP #{$row['id']} client {$clientId} — client is inactive");
        continue;
    }

    $log("  Retry #{$row['id']} client {$clientId} tenant {$tenantId} (attempt {$attempt})");

    try {
        $result = autoProvisionClient($pdo, $clientId, $tenantId);

        if ($result['success']) {
            $pdo->prepare("DELETE FROM pending_provisions WHERE id = ?")->execute([$row['id']]);
            $log("  SUCCESS #{$row['id']} client {$clientId} — provisioned (attempt {$attempt})");
        } else {
            // Exponential backoff: 5, 15, 30, 60, 120, 120, ... minutes
            $backoffMinutes = min(120, 5 * (int)pow(2, min($attempt - 1, 4)));
            $nextRetry      = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));

            $pdo->prepare("
                UPDATE pending_provisions
                SET attempts      = attempts + 1,
                    fail_reason   = ?,
                    next_retry_at = ?
                WHERE id = ?
            ")->execute([$result['message'] ?? 'unknown', $nextRetry, $row['id']]);

            $log("  FAIL #{$row['id']} client {$clientId} — {$result['message']} — next retry in {$backoffMinutes}min");
        }
    } catch (Throwable $e) {
        $backoffMinutes = min(120, 5 * (int)pow(2, min($attempt - 1, 4)));
        $nextRetry      = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));
        $pdo->prepare("
            UPDATE pending_provisions SET attempts = attempts + 1, fail_reason = ?, next_retry_at = ? WHERE id = ?
        ")->execute([$e->getMessage(), $nextRetry, $row['id']]);
        $log("  EXCEPTION #{$row['id']} client {$clientId}: " . $e->getMessage());
        error_log("retry_provisions.php id {$row['id']}: " . $e->getMessage());
    }
}

// Report rows that have hit the attempt limit
$stuck = $pdo->query("SELECT COUNT(*) FROM pending_provisions WHERE attempts > 10")->fetchColumn();
if ($stuck > 0) {
    $log("WARNING: {$stuck} provision(s) exceeded 10 attempts — manual investigation required.");
}

$log("=== Done ===");
