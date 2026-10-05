<?php
/**
 * Monthly Billing Engine — FortuNett Technologies
 *
 * Calculates each tenant's monthly platform fee:
 *   - KSH 25 per active PPPoE user (rate from plan)
 *   - 3% commission on hotspot collections (rate from plan)
 *   - Monthly fee per configured router (waived during trial)
 *
 * Creates a platform_invoice row for each tenant and
 * sends an invoice notification email to the admin.
 *
 * Run via cron on the 1st of each month, e.g.:
 *   0 6 1 * * php /var/www/html/cron/monthly_billing.php >> /var/log/fortunett_billing.log 2>&1
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__)); // Set working dir to project root

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__.'/../includes/email_helper.php';
require_once __DIR__ . '/../includes/platform_billing.php';

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
        $exists = $pdo->prepare('SELECT id FROM platform_invoices WHERE tenant_id=? AND billing_period=?');
        $exists->execute([$tenantId, $billingPeriod]);
        $alreadyExists = (bool)$exists->fetchColumn();
        $invoice = ensureCurrentPlatformInvoice($pdo, $tenantId);
        if (!$invoice || (float)$invoice['total_due'] <= 0) {
            $log("SKIP tenant #{$tenantId} - no billable collections");
            $skipped++;
            continue;
        }
        // Page views and cron share one calculation and one invoice.
        // Only notify for invoices created by this run, not every cron replay.
        if ($alreadyExists) {
            $skipped++;
            continue;
        }
        $invoiceNumber = $invoice['invoice_number'];
        $dueDate = $invoice['due_date'];
        $pppoeCount = (int)$invoice['pppoe_user_count'];
        $hotspotCollections = (float)$invoice['hotspot_collections'];
        $tenant['base_fee'] = (float)$invoice['base_fee'];
        $tenant['router_count']=(int)($invoice['router_count']??0);
        $tenant['router_fee_per_router']=(float)($invoice['router_fee_per_router']??0);
        $tenant['pppoe_fee'] = (float)$invoice['pppoe_fee_per_user'];
        $tenant['commission_rate'] = (float)$invoice['hotspot_commission_rate'];
        $totalDue = (float)$invoice['total_due'];

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

    $rows=['Invoice number'=>$invoiceNumber,'Billing period'=>$periodLabel,'PPPoE users'=>$pppoeCount.' x KSH '.number_format($pppoeRate,2).' = KSH '.number_format($pppoeSubtotal,2),'Hotspot commission'=>$commissionPct.'% of KSH '.number_format($hotspotCollections,2).' = KSH '.number_format($hotspotCommission,2)];
    if ($baseFee>0) $rows['Monthly router fee']=($tenant['router_count'] ?? 0).' routers x KSH '.number_format($tenant['router_fee_per_router'] ?? 0,2).' = KSH '.number_format($baseFee,2);
    $rows['Total due']='KSH '.number_format($totalDue,2); $rows['Due date']=$dueDateFmt;
    $body=fortunettEmail('Your monthly invoice is ready',
        '<p>Hello <strong>'.fortunettEmailEscape($tenant['admin_username']).'</strong>,</p><p>Review your platform invoice and pay by the due date to keep your workspace active.</p>'
        .fortunettEmailSummary($rows)
        .'<p style="font-size:13px;color:#64748b;">Pay via M-Pesa Paybill <strong>400200</strong>, account <strong>'.fortunettEmailEscape($invoiceNumber).'</strong>.</p>',
        ['category'=>'Billing','preheader'=>'Invoice '.$invoiceNumber.': KSH '.number_format($totalDue,2).' due '.$dueDateFmt.'.','action_label'=>'View invoice & pay','action_url'=>$billingPage]);
    $result=sendEmail($tenant['admin_email'],$subject,$body);
    $sent=$result===true;
    try {
        $pdo->prepare("INSERT INTO email_outbox (tenant_id,recipient_email,subject,message_body,status) VALUES (?,?,?,?,?)")
            ->execute([$tenant['id'],$tenant['admin_email'],$subject,$body,$sent ? 'sent' : 'failed']);
    } catch (Throwable $e) { error_log('Unable to record invoice email status: '.get_class($e)); }
    $log('EMAIL '.($sent ? 'accepted by SMTP' : 'failed').' for tenant #'.$tenant['id'].' invoice '.$invoiceNumber);
}
