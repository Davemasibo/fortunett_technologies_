<?php
/**
 * Pre-Expiry SMS Reminder — FortuNett Technologies
 *
 * Runs daily (or twice daily). Sends SMS to clients whose subscription expires
 * in exactly 3 days or 1 day, prompting them to renew before disconnection.
 *
 * Uses expiry_reminder_3d_sent / expiry_reminder_1d_sent flags on the clients
 * table to prevent duplicate sends. These flags are reset to 0 by
 * payment_pipeline.php when a client renews.
 *
 * Cron schedule (daily at 07:00):
 *   0 7 * * * php /var/www/html/cron/expiry_reminders.php >> /var/log/fortunett_reminders.log 2>&1
 *
 * NOTE: Run sql/migrations/2026-06-07-expiry-reminders.sql once before enabling.
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/SMSHelper.php';

// Silently add columns if this runs before the migration
try {
    $pdo->exec("ALTER TABLE clients ADD COLUMN IF NOT EXISTS expiry_reminder_3d_sent TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE clients ADD COLUMN IF NOT EXISTS expiry_reminder_1d_sent TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $_) {}

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Expiry Reminders: " . date('Y-m-d') . " ===");

$sent   = 0;
$errors = 0;

// ── Helper: fetch M-Pesa shortcode for a tenant ───────────────────────────────
$windows = [
    ['days' => 3, 'flag_col' => 'expiry_reminder_3d_sent', 'label' => '3-day'],
    ['days' => 1, 'flag_col' => 'expiry_reminder_1d_sent', 'label' => '1-day'],
];

foreach ($windows as $window) {
    $daysAhead = $window['days'];
    $flagCol   = $window['flag_col'];
    $label     = $window['label'];

    // Find active clients expiring within the window who haven't been notified yet.
    // Expiry window: from midnight of target day to end of that day.
    $targetDate   = date('Y-m-d', strtotime("+{$daysAhead} days"));
    $windowStart  = $targetDate . ' 00:00:00';
    $windowEnd    = $targetDate . ' 23:59:59';

    $stmt = $pdo->prepare("
        SELECT c.id, c.full_name, c.phone, c.account_number, c.tenant_id, c.expiry_date,
               p.name AS pkg_name, p.price AS pkg_price,
               t.company_name AS tenant_name, t.subdomain
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        LEFT JOIN tenants t  ON t.id = c.tenant_id
        WHERE c.status = 'active'
          AND c.phone IS NOT NULL AND c.phone != ''
          AND c.expiry_date BETWEEN ? AND ?
          AND c.{$flagCol} = 0
        ORDER BY c.tenant_id, c.id
    ");
    $stmt->execute([$windowStart, $windowEnd]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log("{$label} window ({$targetDate}): " . count($clients) . " client(s) to notify.");

    foreach ($clients as $client) {
        $tenantId  = (int)$client['tenant_id'];
        $clientId  = (int)$client['id'];
        $firstName = explode(' ', trim($client['full_name']))[0];
        try {
            $sms = new SMSHelper($pdo, $tenantId);
            if (!$sms->hasConfig()) {
                $log("  [{$tenantId}] #{$clientId} — SMS skipped (not configured)");
                // Still mark as sent to avoid repeated log noise
                $pdo->prepare("UPDATE clients SET {$flagCol} = 1 WHERE id = ? AND tenant_id = ?")->execute([$clientId, $tenantId]);
                continue;
            }

            $msg = $sms->renderPlaceholders(smsDefaultTemplates()['account_expiring'][1], $client);
            $result = $sms->send($client['phone'], $msg, $clientId);

            // Mark reminder sent regardless of delivery status (avoid spam on transient errors)
            $pdo->prepare("UPDATE clients SET {$flagCol} = 1 WHERE id = ? AND tenant_id = ?")->execute([$clientId, $tenantId]);

            if ($result['success']) {
                $sent++;
                $log("  [{$tenantId}] #{$clientId} {$firstName} — {$label} SMS sent ({$client['phone']})");
            } else {
                $errors++;
                $log("  [{$tenantId}] #{$clientId} {$firstName} — {$label} SMS failed: " . ($result['message'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            $errors++;
            $log("  [{$tenantId}] #{$clientId} — exception: " . $e->getMessage());
            error_log("expiry_reminders.php client {$clientId}: " . $e->getMessage());
        }
    }
}

$log("=== Done: {$sent} sent, {$errors} errors ===");
