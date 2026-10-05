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
require_once __DIR__.'/../includes/email_helper.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
require_once __DIR__ . '/../includes/schema_guard.php';
require_once __DIR__ . '/../includes/platform_billing.php';
ensurePlatformBillingSchema($pdo);
foreach ($pdo->query("SELECT id FROM tenants WHERE status='trial'")->fetchAll(PDO::FETCH_COLUMN) as $trialId) {
    repairUnpaidTrialInvoices($pdo, (int)$trialId);
}

cron_heartbeat($pdo, 'check_suspensions');

// trial_ends_at must be DATETIME before the NOW() comparison below is safe: on a
// DATE column '2026-08-24' reads as midnight, which would suspend a tenant whose
// trial still has the whole day to run.
ensureTenantExpiryPrecision($pdo);

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
      AND t.trial_ends_at < NOW()
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

function buildTrialExpiredEmail(array $t,string $tenantUrl): string {
    return fortunettEmail('Your trial has ended',
        '<p>Hello <strong>'.fortunettEmailEscape($t['company_name']).'</strong>,</p><p>Your free trial has ended. Open Billing to review your platform invoice and continue using your workspace.</p><p>Access is restored automatically after payment confirmation.</p><p style="font-size:13px;color:#64748b;">You can pay via M-Pesa Paybill <strong>400200</strong>.</p>',
        ['category'=>'Trial update','preheader'=>'Review your invoice to continue using your workspace.','action_label'=>'View invoice & pay','action_url'=>$tenantUrl.'/billing.php']);
}
function buildWarningEmail(array $inv,int $warningDays,string $tenantUrl): string {
    $due=date('d M Y',strtotime($inv['due_date']));
    return fortunettEmail('Your invoice is due soon',
        '<p>Hello <strong>'.fortunettEmailEscape($inv['admin_username']).'</strong>,</p><p>Please pay your invoice by the due date to avoid account suspension.</p>'
        .fortunettEmailSummary(['Invoice'=>$inv['invoice_number'],'Amount due'=>'KSH '.number_format($inv['total_due'],2),'Due date'=>$due,'Time remaining'=>$warningDays.' days'])
        .'<p style="font-size:13px;color:#64748b;">M-Pesa Paybill <strong>400200</strong>, account <strong>'.fortunettEmailEscape($inv['invoice_number']).'</strong>.</p>',
        ['category'=>'Payment reminder','preheader'=>'Invoice '.$inv['invoice_number'].' is due on '.$due.'.','action_label'=>'View invoice & pay','action_url'=>$tenantUrl.'/billing.php']);
}
function buildSuspensionEmail(array $t,string $tenantUrl): string {
    return fortunettEmail('Restore access to your workspace',
        '<p>Hello <strong>'.fortunettEmailEscape($t['company_name']).'</strong>,</p><p>Your platform account has been suspended because an invoice is overdue. Your team cannot currently access the ISP dashboard.</p><p>Review the outstanding invoice and pay to restore access. Reactivation follows automatically after payment confirmation.</p><p style="font-size:13px;color:#64748b;">M-Pesa Paybill <strong>400200</strong>.</p>',
        ['category'=>'Account status','preheader'=>'An overdue invoice needs your attention.','action_label'=>'Pay & restore access','action_url'=>$tenantUrl.'/billing.php']);
}
function buildReactivationEmail(array $t): string {
    return fortunettEmail('Your workspace is active again',
        '<p>Hello <strong>'.fortunettEmailEscape($t['company_name']).'</strong>,</p><p>Your payment has been confirmed and your FortuNett platform account is active again. Your team can sign in and resume managing your network.</p><p>Thank you for using FortuNett Technologies.</p>',
        ['category'=>'Payment confirmed','preheader'=>'Payment confirmed. Your team can access the workspace again.']);
}
function sendSystemEmail(string $to,string $subject,string $body): void {
    $result=sendEmail($to,$subject,$body);
    if ($result!==true) error_log('Account-status email was not accepted by SMTP. Check the configured provider.');
}
