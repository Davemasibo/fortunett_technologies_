<?php
/**
 * Monthly Billing Engine — FortuNett Technologies
 *
 * Calculates each tenant's monthly platform fee:
 *   - KSH 25 per active PPPoE user (rate from plan)
 *   - 3% commission on hotspot collections (rate from plan)
 *   - Fixed base fee (from plan, default 0)
 *
 * Creates a platform_invoice row for each tenant and
 * sends an invoice notification email to the admin.
 *
 * Run via cron on the 1st of each month, e.g.:
 *   0 6 1 * * php /var/www/html/cron/monthly_billing.php >> /var/log/fortunett_billing.log 2>&1
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__)); // Set working dir to project root

require_once __DIR__ . '/../includes/db_master.php';

// ── Configuration ─────────────────────────────────────────────────────────────
$billingPeriod = date('Y-m-01');          // First day of current month
$prevPeriod    = date('Y-m-01', strtotime('-1 month')); // Prior month for revenue calc
$dueDate       = date('Y-m-d', strtotime($billingPeriod . ' +15 days'));
$graceDays     = 5;                        // Days after due before marked overdue

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Monthly Billing Run: $billingPeriod ===");

// ── Fetch active/trial tenants with their plan rates ─────────────────────────
$tenants = $pdo->query("
    SELECT
        t.id,
        t.company_name,
        t.subdomain,
        t.status,
        COALESCE(p.pppoe_fee_per_user, 25.00)     AS pppoe_fee,
        COALESCE(p.hotspot_commission_rate, 0.03)  AS commission_rate,
        COALESCE(p.base_monthly_fee, 0.00)         AS base_fee,
        p.id                                        AS plan_id,
        u.email                                     AS admin_email,
        u.username                                  AS admin_username
    FROM tenants t
    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
    LEFT JOIN users u ON u.id = t.admin_user_id
    WHERE t.status IN ('active', 'trial')
")->fetchAll(PDO::FETCH_ASSOC);

$log("Found " . count($tenants) . " active/trial tenants.");

$generated = 0;
$skipped   = 0;
$errors    = 0;

foreach ($tenants as $tenant) {
    $tenantId = (int)$tenant['id'];

    try {
        // Skip if invoice already exists for this period
        $existsStmt = $pdo->prepare("SELECT id FROM platform_invoices WHERE tenant_id = ? AND billing_period = ?");
        $existsStmt->execute([$tenantId, $billingPeriod]);
        if ($existsStmt->fetchColumn()) {
            $log("SKIP tenant #{$tenantId} ({$tenant['company_name']}) — invoice already exists for $billingPeriod");
            $skipped++;
            continue;
        }

        // ── Count active PPPoE users ──────────────────────────────────────────
        $pppoeStmt = $pdo->prepare("
            SELECT COUNT(*) FROM clients
            WHERE tenant_id = ?
              AND connection_type = 'pppoe'
              AND status = 'active'
        ");
        $pppoeStmt->execute([$tenantId]);
        $pppoeCount = (int)$pppoeStmt->fetchColumn();

        // ── Hotspot collections for the billing period ────────────────────────
        $hotspotStmt = $pdo->prepare("
            SELECT COALESCE(SUM(pay.amount), 0)
            FROM payments pay
            JOIN clients c ON c.id = pay.client_id
            WHERE pay.tenant_id = ?
              AND c.connection_type = 'hotspot'
              AND pay.status = 'completed'
              AND pay.payment_date >= ?
              AND pay.payment_date <= LAST_DAY(?)
        ");
        $hotspotStmt->execute([$tenantId, $billingPeriod, $billingPeriod]);
        $hotspotCollections = (float)$hotspotStmt->fetchColumn();

        // ── Derived amounts ───────────────────────────────────────────────────
        $pppoeSubtotal       = round($pppoeCount * $tenant['pppoe_fee'], 2);
        $hotspotCommission   = round($hotspotCollections * $tenant['commission_rate'], 2);
        $totalDue            = $pppoeSubtotal + $hotspotCommission + $tenant['base_fee'];

        // ── Invoice number: INV-YYYY-MM-TENANTID (padded) ────────────────────
        $invoiceNumber = sprintf('INV-%s-%04d', date('Y-m', strtotime($billingPeriod)), $tenantId);

        // ── Insert invoice ────────────────────────────────────────────────────
        $insertStmt = $pdo->prepare("
            INSERT INTO platform_invoices
                (invoice_number, tenant_id, billing_period, plan_id,
                 pppoe_user_count, pppoe_fee_per_user,
                 hotspot_collections, hotspot_commission_rate,
                 base_fee, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $insertStmt->execute([
            $invoiceNumber,
            $tenantId,
            $billingPeriod,
            $tenant['plan_id'],
            $pppoeCount,
            $tenant['pppoe_fee'],
            $hotspotCollections,
            $tenant['commission_rate'],
            $tenant['base_fee'],
            $dueDate,
        ]);

        $generated++;
        $log("GENERATED $invoiceNumber — {$tenant['company_name']} | PPPoE: $pppoeCount users | Hotspot: KSH " . number_format($hotspotCollections,2) . " | Total: KSH " . number_format($totalDue,2));

        // ── Send invoice notification email ───────────────────────────────────
        if (!empty($tenant['admin_email'])) {
            sendInvoiceEmail($pdo, $tenant, $invoiceNumber, $billingPeriod, $dueDate, $pppoeCount, $tenant['pppoe_fee'], $hotspotCollections, $tenant['commission_rate'], $tenant['base_fee'], $totalDue, $log);
        }

    } catch (Throwable $e) {
        $errors++;
        $log("ERROR tenant #{$tenantId}: " . $e->getMessage());
        error_log("monthly_billing.php tenant $tenantId: " . $e->getMessage());
    }
}

$log("=== Done: $generated generated, $skipped skipped, $errors errors ===");

// ─────────────────────────────────────────────────────────────────────────────

function sendInvoiceEmail(PDO $pdo, array $tenant, string $invoiceNumber, string $billingPeriod, string $dueDate, int $pppoeCount, float $pppoeRate, float $hotspotCollections, float $commissionRate, float $baseFee, float $totalDue, callable $log): void
{
    $tenantUrl   = "https://{$tenant['subdomain']}.fortunetttech.site";
    $billingPage = $tenantUrl . '/billing.php';
    $periodLabel = date('F Y', strtotime($billingPeriod));
    $dueDateFmt  = date('d M Y', strtotime($dueDate));

    $subject = "Invoice $invoiceNumber — Platform Fee for $periodLabel";

    $pppoeSubtotal     = round($pppoeCount * $pppoeRate, 2);
    $hotspotCommission = round($hotspotCollections * $commissionRate, 2);
    $commissionPct     = round($commissionRate * 100, 2);

    $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#0f3460,#16213e);padding:32px;text-align:center;color:#fff;">
    <h1 style="margin:0;font-size:22px;">FortuNett Technologies</h1>
    <p style="margin:8px 0 0;opacity:.8;font-size:14px;">Platform Invoice — $periodLabel</p>
  </div>
  <div style="padding:32px;">
    <p style="color:#374151;">Dear <strong>{$tenant['admin_username']}</strong>,</p>
    <p style="color:#374151;">Your monthly platform invoice has been generated. Please review and pay by <strong>$dueDateFmt</strong> to avoid service interruption.</p>

    <div style="background:#f8fafc;border-radius:10px;padding:20px;margin:24px 0;">
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#374151;">
        <span>Invoice Number</span><strong>{$invoiceNumber}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#374151;">
        <span>PPPoE Users ({$pppoeCount} × KSH {$pppoeRate})</span><span>KSH {$pppoeSubtotal}</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#374151;">
        <span>Hotspot Commission ({$commissionPct}% × KSH {$hotspotCollections})</span><span>KSH {$hotspotCommission}</span>
      </div>
HTML;

    if ($baseFee > 0) {
        $body .= <<<HTML
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e2e8f0;font-size:14px;color:#374151;">
        <span>Base Platform Fee</span><span>KSH {$baseFee}</span>
      </div>
HTML;
    }

    $body .= <<<HTML
      <div style="display:flex;justify-content:space-between;padding:12px 0 4px;font-size:16px;font-weight:700;color:#0f3460;">
        <span>Total Due</span><span>KSH {$totalDue}</span>
      </div>
      <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Due by: {$dueDateFmt}</div>
    </div>

    <p style="text-align:center;">
      <a href="{$billingPage}" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#0f3460,#16213e);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px;">Pay Now via M-Pesa</a>
    </p>

    <p style="font-size:13px;color:#6b7280;margin-top:24px;">Pay via M-Pesa Paybill <strong>400200</strong>, Account: <strong>{$invoiceNumber}</strong>.<br>
    If you have questions, contact <a href="mailto:support@fortunetttech.site">support@fortunetttech.site</a>.</p>
  </div>
  <div style="background:#f8fafc;padding:16px 32px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    FortuNett Technologies — Multi-Tenant ISP Management Platform<br>
    <a href="{$tenantUrl}" style="color:#0f3460;">{$tenantUrl}</a>
  </div>
</div>
</body></html>
HTML;

    // Use PHPMailer if available, otherwise php mail()
    $sent = false;
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            // Use system SMTP from .env / config
            $mail->isSMTP();
            $mail->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('MAIL_USERNAME') ?: '';
            $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
            $mail->setFrom(getenv('MAIL_FROM_ADDRESS') ?: 'billing@fortunetttech.site', 'FortuNett Billing');
            $mail->addAddress($tenant['admin_email']);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = "Invoice $invoiceNumber — KSH $totalDue due $dueDateFmt. Pay at $billingPage";
            $mail->send();
            $sent = true;
        } catch (Throwable $e) {
            error_log("monthly_billing email error (PHPMailer) tenant {$tenant['id']}: " . $e->getMessage());
        }
    }

    if (!$sent) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: FortuNett Billing <billing@fortunetttech.site>\r\n";
        @mail($tenant['admin_email'], $subject, $body, $headers);
    }

    // Log email to outbox (tenant_id 0 = system for platform billing emails)
    try {
        $pdo->prepare("
            INSERT INTO email_outbox (tenant_id, recipient_email, subject, message_body, status)
            VALUES (?, ?, ?, ?, 'sent')
        ")->execute([$tenant['id'], $tenant['admin_email'], $subject, substr($body, 0, 2000)]);
    } catch (Throwable $e) {
        // Non-fatal
    }

    $log("EMAIL sent to {$tenant['admin_email']} for invoice $invoiceNumber");
}
