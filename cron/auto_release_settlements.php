<?php
/**
 * Cron: Auto-release platform-collected payments to tenants
 * Schedule: 0 3 * * * php /var/www/html/cron/auto_release_settlements.php
 *
 * Releases all platform payments that are:
 *   - status = 'completed'
 *   - collection_type = 'platform'
 *   - released_at IS NULL
 *   - payment_date older than RELEASE_THRESHOLD_HOURS (default 48h)
 *
 * "Release" here means marking the payment as settled — money was already
 * collected by the platform paybill. The super admin then sends the net
 * amount (after platform fees) to the tenant manually or via B2C.
 */

define('RELEASE_THRESHOLD_HOURS', 48);

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/EmailHelper.php';

// Ensure columns exist
try { $pdo->exec("ALTER TABLE payments ADD COLUMN released_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE payments ADD COLUMN release_note VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

$cutoff  = date('Y-m-d H:i:s', strtotime('-' . RELEASE_THRESHOLD_HOURS . ' hours'));
$now     = date('Y-m-d H:i:s');
$logFile = __DIR__ . '/../logs/auto_release.log';

if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);

function log_msg(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

log_msg("Auto-release cron started. Threshold: " . RELEASE_THRESHOLD_HOURS . "h (cutoff: $cutoff)");

try {
    // Fetch tenants with unreleased payments older than threshold
    $stmt = $pdo->prepare("
        SELECT
            p.tenant_id,
            t.company_name,
            t.subdomain,
            u.email   AS admin_email,
            COUNT(*)  AS payment_count,
            COALESCE(SUM(p.amount), 0) AS total_amount
        FROM payments p
        JOIN tenants t ON t.id = p.tenant_id
        LEFT JOIN users u ON u.id = t.admin_user_id
        WHERE p.collection_type = 'platform'
          AND p.status = 'completed'
          AND p.released_at IS NULL
          AND p.payment_date <= ?
        GROUP BY p.tenant_id, t.company_name, t.subdomain, u.email
    ");
    $stmt->execute([$cutoff]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tenants)) {
        log_msg("No payments eligible for release.");
        exit(0);
    }

    foreach ($tenants as $row) {
        $tid    = (int)$row['tenant_id'];
        $tName  = $row['company_name'];
        $amount = (float)$row['total_amount'];
        $count  = (int)$row['payment_count'];

        // Mark payments as released
        $upd = $pdo->prepare("
            UPDATE payments
            SET released_at = ?, release_note = 'Auto-released by cron'
            WHERE tenant_id = ?
              AND collection_type = 'platform'
              AND status = 'completed'
              AND released_at IS NULL
              AND payment_date <= ?
        ");
        $upd->execute([$now, $tid, $cutoff]);
        $released = $upd->rowCount();

        log_msg("Released $released payment(s) (KES " . number_format($amount, 2) . ") for tenant $tid ($tName)");

        // Email the tenant admin notification
        if (!empty($row['admin_email'])) {
            try {
                $subject = "Settlement Released — KES " . number_format($amount, 2);
                $body = "Hello,\n\n"
                    . "$count payment(s) collected via the FortuNett platform paybill have been marked as released:\n\n"
                    . "  Amount: KES " . number_format($amount, 2) . "\n"
                    . "  Payments: $count\n"
                    . "  Released at: $now\n\n"
                    . "You can view the details in your Billing page under 'Platform Collections'.\n\n"
                    . "The net amount (after platform fees) will be remitted to your registered M-Pesa account within 1 business day.\n\n"
                    . "FortuNett Technologies";
                EmailHelper::send($row['admin_email'], $subject, nl2br(htmlspecialchars($body)), $pdo, $tid);
            } catch (Throwable $emailErr) {
                log_msg("Email to {$row['admin_email']} failed: " . $emailErr->getMessage());
            }
        }
    }

    $totalReleased = array_sum(array_column($tenants, 'payment_count'));
    log_msg("Done. Total: $totalReleased payment(s) released across " . count($tenants) . " tenant(s).");
    exit(0);

} catch (Throwable $e) {
    log_msg("FATAL: " . $e->getMessage());
    exit(1);
}
