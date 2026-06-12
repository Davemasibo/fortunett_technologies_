<?php
/**
 * Suspension Checker — FortuNett Technologies
 *
 * Runs daily. Performs three actions:
 *   1. Marks overdue: invoices past their due_date + grace period
 *   2. Sends warning emails at 3 days before due
 *   3. Suspends tenants whose invoices are overdue beyond the grace period
 *   4. Re-activates tenants whose overdue invoices are now paid
 *
 * Cron schedule (daily at 08:00):
 *   0 8 * * * php /var/www/html/cron/check_suspensions.php >> /var/log/fortunett_suspensions.log 2>&1
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_master.php';

$graceDays    = 7;    // Days after due_date before suspension
$warningDays  = 3;    // Days before due_date to send warning
$today        = date('Y-m-d');

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Suspension Check: $today ===");

// ── 0. Convert expired trial tenants to suspended ─────────────────────────────
$expiredTrials = $pdo->query("
    SELECT t.id AS tenant_id, t.company_name, t.subdomain, u.email AS admin_email
    FROM tenants t
    LEFT JOIN users u ON u.id = t.admin_user_id
    WHERE t.status = 'trial'
      AND t.trial_ends_at < CURDATE()
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($expiredTrials as $t) {
    try {
        $pdo->prepare("
            UPDATE tenants
            SET status = 'suspended',
                suspended_at = NOW(),
                suspended_reason = 'Trial period ended — payment required'
            WHERE id = ?
        ")->execute([$t['tenant_id']]);
        $log("TRIAL EXPIRED tenant #{$t['tenant_id']} ({$t['company_name']}) — suspended");

        // Generate a pending invoice for the current month if none exists
        $period = date('Y-m-01');
        $checkInv = $pdo->prepare("SELECT id FROM platform_invoices WHERE tenant_id = ? AND billing_period = ?");
        $checkInv->execute([$t['tenant_id'], $period]);
        if (!$checkInv->fetchColumn()) {
            $invNum  = sprintf('INV-%s-%04d', date('Y-m'), $t['tenant_id']);
            $dueDate = date('Y-m-d', strtotime('+15 days'));
            $pdo->prepare("
                INSERT INTO platform_invoices
                    (invoice_number, tenant_id, billing_period,
                     pppoe_user_count, pppoe_fee_per_user,
                     hotspot_collections, hotspot_commission_rate,
                     base_fee, due_date, status)
                VALUES (?, ?, ?, 0, 25.00, 0, 0.03, 0, ?, 'pending')
            ")->execute([$invNum, $t['tenant_id'], $period, $dueDate]);
            $log("INVOICE $invNum generated for trial-expired tenant #{$t['tenant_id']}");
        }

        if (!empty($t['admin_email'])) {
            $tenantUrl = "https://{$t['subdomain']}.fortunetttech.site";
            sendSystemEmail($t['admin_email'], "Your FortuNett free trial has ended", buildTrialExpiredEmail($t, $tenantUrl));
            $log("TRIAL EXPIRED email sent to {$t['admin_email']}");
        }
    } catch (Throwable $e) {
        $log("ERROR trial-expiry tenant #{$t['tenant_id']}: " . $e->getMessage());
    }
}

// ── 1. Mark invoices as overdue ───────────────────────────────────────────────
$overdueStmt = $pdo->prepare("
    UPDATE platform_invoices
    SET status = 'overdue'
    WHERE status = 'pending'
      AND due_date < DATE_SUB(?, INTERVAL ? DAY)
");
$overdueStmt->execute([$today, $graceDays]);
$markedOverdue = $overdueStmt->rowCount();
$log("Marked $markedOverdue invoice(s) as overdue.");

// ── 2. Send warning emails (3 days before due) ────────────────────────────────
$warningDate = date('Y-m-d', strtotime("+$warningDays days"));
$warnStmt = $pdo->prepare("
    SELECT pi.*, t.company_name, t.subdomain, u.email AS admin_email, u.username AS admin_username
    FROM platform_invoices pi
    JOIN tenants t ON t.id = pi.tenant_id
    LEFT JOIN users u ON u.id = t.admin_user_id
    WHERE pi.status = 'pending'
      AND pi.due_date = ?
      AND u.email IS NOT NULL
");
$warnStmt->execute([$warningDate]);
$warnings = $warnStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($warnings as $inv) {
    $subject  = "Payment Reminder: Invoice {$inv['invoice_number']} Due in $warningDays Days";
    $tenantUrl = "https://{$inv['subdomain']}.fortunetttech.site";
    $body = buildWarningEmail($inv, $warningDays, $tenantUrl);
    sendSystemEmail($inv['admin_email'], $subject, $body);
    $log("WARNING email sent to {$inv['admin_email']} for {$inv['invoice_number']} (due {$inv['due_date']})");
}

// ── 3. Suspend tenants with overdue invoices ──────────────────────────────────
$suspendCandidates = $pdo->query("
    SELECT DISTINCT pi.tenant_id, t.company_name, t.subdomain, t.status,
           u.email AS admin_email
    FROM platform_invoices pi
    JOIN tenants t ON t.id = pi.tenant_id
    LEFT JOIN users u ON u.id = t.admin_user_id
    WHERE pi.status = 'overdue'
      AND t.status NOT IN ('suspended','expired')
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($suspendCandidates as $t) {
    try {
        $pdo->prepare("
            UPDATE tenants
            SET status = 'suspended',
                suspended_at = NOW(),
                suspended_reason = 'Overdue platform invoice — auto-suspended'
            WHERE id = ?
        ")->execute([$t['tenant_id']]);

        $log("SUSPENDED tenant #{$t['tenant_id']} ({$t['company_name']}) — overdue invoice");

        // Send suspension notice
        if (!empty($t['admin_email'])) {
            $subject = "URGENT: Your FortuNett account has been suspended";
            $tenantUrl = "https://{$t['subdomain']}.fortunetttech.site";
            $body = buildSuspensionEmail($t, $tenantUrl);
            sendSystemEmail($t['admin_email'], $subject, $body);
            $log("SUSPENSION email sent to {$t['admin_email']}");
        }
    } catch (Throwable $e) {
        error_log("Suspension error tenant {$t['tenant_id']}: " . $e->getMessage());
        $log("ERROR suspending tenant #{$t['tenant_id']}: " . $e->getMessage());
    }
}

// ── 4. Re-activate tenants whose overdue invoices are all paid ────────────────
$reactivateCandidates = $pdo->query("
    SELECT t.id, t.company_name, u.email AS admin_email
    FROM tenants t
    LEFT JOIN users u ON u.id = t.admin_user_id
    WHERE t.status = 'suspended'
      AND (t.suspended_reason LIKE '%auto-suspended%' OR t.suspended_reason LIKE '%Trial period ended%')
      AND NOT EXISTS (
          SELECT 1 FROM platform_invoices pi
          WHERE pi.tenant_id = t.id AND pi.status IN ('pending','overdue')
      )
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($reactivateCandidates as $t) {
    $pdo->prepare("
        UPDATE tenants SET status = 'active', suspended_at = NULL, suspended_reason = NULL WHERE id = ?
    ")->execute([$t['id']]);
    $log("REACTIVATED tenant #{$t['id']} ({$t['company_name']}) — all invoices paid");

    if (!empty($t['admin_email'])) {
        $subject = "Your FortuNett account has been reactivated";
        $body = buildReactivationEmail($t);
        sendSystemEmail($t['admin_email'], $subject, $body);
    }
}

$log("=== Done ===");

// ─── Email builders ───────────────────────────────────────────────────────────

function buildTrialExpiredEmail(array $t, string $tenantUrl): string {
    return <<<HTML
<div style="font-family:sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;">
  <div style="background:#7c3aed;padding:24px;color:#fff;text-align:center;">
    <h2 style="margin:0;">Free Trial Ended</h2>
    <p style="margin:6px 0 0;opacity:.85;">Subscribe to continue using FortuNett</p>
  </div>
  <div style="padding:28px;">
    <p>Dear <strong>{$t['company_name']}</strong> administrator,</p>
    <p>Your 14-day free trial has ended. To continue managing your ISP and serving your customers, please pay your first platform invoice.</p>
    <p>Your account will be reactivated <strong>automatically</strong> within minutes of payment confirmation.</p>
    <p style="text-align:center;margin:24px 0;"><a href="{$tenantUrl}/billing.php" style="background:#7c3aed;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">View Invoice &amp; Pay</a></p>
    <p style="font-size:12px;color:#6b7280;">Pay via M-Pesa Paybill <strong>400200</strong>. For assistance, contact <a href="mailto:support@fortunetttech.site">support@fortunetttech.site</a></p>
  </div>
</div>
HTML;
}

function buildWarningEmail(array $inv, int $warningDays, string $tenantUrl): string {
    $due = date('d M Y', strtotime($inv['due_date']));
    $amt = number_format($inv['total_due'], 2);
    return <<<HTML
<div style="font-family:sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;">
  <div style="background:#d97706;padding:24px;color:#fff;text-align:center;">
    <h2 style="margin:0;">Payment Reminder</h2>
    <p style="margin:6px 0 0;opacity:.85;">Invoice {$inv['invoice_number']} is due in {$warningDays} days</p>
  </div>
  <div style="padding:28px;">
    <p>Dear <strong>{$inv['admin_username']}</strong>,</p>
    <p>Your platform invoice <strong>{$inv['invoice_number']}</strong> of <strong>KSH {$amt}</strong> is due on <strong>{$due}</strong>.</p>
    <p>Please pay promptly to avoid account suspension.</p>
    <p style="text-align:center;margin:24px 0;"><a href="{$tenantUrl}/billing.php" style="background:#d97706;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Pay Now</a></p>
    <p style="font-size:12px;color:#6b7280;">Pay via M-Pesa Paybill <strong>400200</strong>, Account: <strong>{$inv['invoice_number']}</strong></p>
  </div>
</div>
HTML;
}

function buildSuspensionEmail(array $t, string $tenantUrl): string {
    return <<<HTML
<div style="font-family:sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;">
  <div style="background:#dc2626;padding:24px;color:#fff;text-align:center;">
    <h2 style="margin:0;">Account Suspended</h2>
    <p style="margin:6px 0 0;opacity:.85;">Overdue platform invoice</p>
  </div>
  <div style="padding:28px;">
    <p>Dear <strong>{$t['company_name']}</strong> administrator,</p>
    <p>Your FortuNett platform account has been <strong>suspended</strong> due to an unpaid overdue invoice. Your ISP dashboard is currently inaccessible to your team.</p>
    <p>To restore access immediately, please pay the outstanding invoice via M-Pesa Paybill <strong>400200</strong>. Your account will be reactivated automatically within minutes of payment confirmation.</p>
    <p style="text-align:center;margin:24px 0;"><a href="{$tenantUrl}/billing.php" style="background:#dc2626;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Pay & Restore Access</a></p>
    <p style="font-size:12px;color:#6b7280;">For urgent assistance, contact <a href="mailto:support@fortunetttech.site">support@fortunetttech.site</a></p>
  </div>
</div>
HTML;
}

function buildReactivationEmail(array $t): string {
    return <<<HTML
<div style="font-family:sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;">
  <div style="background:#16a34a;padding:24px;color:#fff;text-align:center;">
    <h2 style="margin:0;">Account Reactivated</h2>
  </div>
  <div style="padding:28px;">
    <p>Dear <strong>{$t['company_name']}</strong> administrator,</p>
    <p>Great news! Your payment has been confirmed and your FortuNett platform account is now <strong>active</strong> again.</p>
    <p>Your team and customers can resume normal operations immediately.</p>
    <p style="font-size:12px;color:#6b7280;">Thank you for your continued partnership with FortuNett Technologies.</p>
  </div>
</div>
HTML;
}

function sendSystemEmail(string $to, string $subject, string $body): void {
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('MAIL_USERNAME') ?: '';
            $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
            $mail->setFrom(getenv('MAIL_FROM_ADDRESS') ?: 'billing@fortunetttech.site', 'FortuNett Technologies');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return;
        } catch (Throwable $e) {
            error_log("check_suspensions email error: " . $e->getMessage());
        }
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FortuNett Technologies <billing@fortunetttech.site>\r\n";
    @mail($to, $subject, $body, $headers);
}
