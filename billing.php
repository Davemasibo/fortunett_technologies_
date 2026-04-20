<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get tenant context
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// --- Fetch Tenant & Admin User Details ---
$tenantStmt = $pdo->prepare("
    SELECT t.*, u.username AS admin_username, u.email AS admin_email
    FROM tenants t
    LEFT JOIN users u ON t.admin_user_id = u.id
    WHERE t.id = ? LIMIT 1
");
$tenantStmt->execute([$tenant_id]);
$tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$orgName     = $tenant['company_name'] ?? ($_SESSION['business_name'] ?? 'Your Company');
$adminEmail  = $tenant['admin_email']  ?? ($_SESSION['user_email'] ?? '');
$adminPhone  = ''; // phone not stored in users table
$orgSlug     = strtoupper(preg_replace('/[^a-z0-9]/i', '', $orgName));

// --- Billing Period ---
$currentMonthStart = date('Y-m-01');
$currentMonthEnd   = date('Y-m-t');

// --- PPPoE User Count ---
try {
    $pppoeStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND subscription_plan LIKE '%pppoe%'");
    $pppoeStmt->execute([$tenant_id]);
    $pppoeCount = (int)$pppoeStmt->fetchColumn();

    // If no 'pppoe' label, just count all clients (fallback)
    if ($pppoeCount === 0) {
        $allStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ?");
        $allStmt->execute([$tenant_id]);
        $pppoeCount = (int)$allStmt->fetchColumn();
    }
} catch (Exception $e) { $pppoeCount = 0; }

// --- Revenue ---
try {
    $revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id = ? AND payment_date BETWEEN ? AND ?");
    $revStmt->execute([$tenant_id, $currentMonthStart . ' 00:00:00', $currentMonthEnd . ' 23:59:59']);
    $currentRevenue = (float)$revStmt->fetchColumn();
} catch (Exception $e) { $currentRevenue = 0; }

// --- Billing Calculation ---
// PPPoE monthly fee: Ksh 25 per user
$pppoeRate    = 25.00;
$pppoeSubtotal = $pppoeCount * $pppoeRate;

// Hotspot service fee: 3% of total monthly revenue
$hotspotRate  = 0.03;
$hotspotFee   = $currentRevenue * $hotspotRate;

$serviceSubtotal = $pppoeSubtotal + $hotspotFee;
$totalDue        = $serviceSubtotal;

// Invoice number
$invoiceDate   = date('Ymd');
$invoiceNumber = 'INV-' . $orgSlug . '/' . $invoiceDate;
$dueDate       = date('d M Y', strtotime('+14 days'));
$issueDate     = date('d M Y');

// --- Upsert into tenant_bills ---
$checkBill = $pdo->prepare("SELECT id FROM tenant_bills WHERE tenant_id = ? AND billing_period = ?");
$checkBill->execute([$tenant_id, $currentMonthStart]);
$billId = $checkBill->fetchColumn();

try {
    if ($billId) {
        $upd = $pdo->prepare("UPDATE tenant_bills SET total_collections = ?, base_fee = ?, commission_amount = ? WHERE id = ? AND status = 'pending'");
        $upd->execute([$currentRevenue, $pppoeSubtotal, $hotspotFee, $billId]);
    } else {
        $ins = $pdo->prepare("INSERT INTO tenant_bills (tenant_id, billing_period, total_collections, base_fee, commission_rate, commission_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $ins->execute([$tenant_id, $currentMonthStart, $currentRevenue, $pppoeSubtotal, 3, $hotspotFee]);
    }
} catch (Exception $e) {}

// --- Fetch All Bills ---
$billsStmt = $pdo->prepare("SELECT * FROM tenant_bills WHERE tenant_id = ? ORDER BY billing_period DESC");
$billsStmt->execute([$tenant_id]);
$bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

// Current pending bill
$currentBill = null;
foreach ($bills as $b) {
    if ($b['billing_period'] === $currentMonthStart) {
        $currentBill = $b;
        break;
    }
}

// M-Pesa config — prefer tenant's own gateway, fall back to platform
$mpesaConfig = ['shortcode' => '', 'env' => 'sandbox', 'using_own' => false];
try {
    $gwStmt = $pdo->prepare(
        "SELECT credentials FROM payment_gateways
         WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
         ORDER BY is_default DESC LIMIT 1"
    );
    $gwStmt->execute([$tenant_id]);
    $tenantGw = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if ($tenantGw) {
        $gwCreds = json_decode($tenantGw['credentials'], true) ?? [];
        if (!empty($gwCreds['shortcode']) && !empty($gwCreds['consumer_key'])) {
            $mpesaConfig = [
                'shortcode'  => $gwCreds['shortcode'],
                'env'        => $gwCreds['environment'] ?? 'sandbox',
                'using_own'  => true,
            ];
        }
    }
    if (!$mpesaConfig['using_own']) {
        require_once __DIR__ . '/config/mpesa.php';
        $mpesaConfig = [
            'shortcode' => defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : '',
            'env'       => defined('MPESA_ENV') ? MPESA_ENV : 'sandbox',
            'using_own' => false,
        ];
    }
} catch (Exception $e) {}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
/* ===================== BILLING PAGE STYLES ===================== */
.billing-wrapper { background: #141414 !important; }
.billing-container { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }

.billing-header h2 { font-size: 28px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px 0; }
.billing-header p  { font-size: 14px; color: #9a9a95; margin: 0 0 24px 0; }

/* Current bill card */
.bill-summary-card { background: #222221; border-radius: 12px; border: 1px solid rgba(255,255,255,.06); padding: 24px 28px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
.bill-icon-wrap { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, var(--primary-dark, #1E3A8A), var(--primary-color, #3B82F6)); display: flex; align-items: center; justify-content: center; color: white; font-size: 22px; flex-shrink: 0; }
.bill-info h3 { font-size: 18px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px 0; }
.bill-info p  { color: #9a9a95; font-size: 14px; margin: 0; }
.bill-amount-wrap { text-align: right; }
.bill-amount      { font-size: 32px; font-weight: 800; color: #fff; }
.bill-amount span { font-size: 16px; font-weight: 500; color: #9a9a95; }
.view-invoice-btn { padding: 12px 24px; background: var(--primary-color, #3B6EA5); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s; white-space: nowrap; }
.view-invoice-btn:hover { opacity: 0.88; }

/* History table card */
.history-card { background: #222221; border-radius: 12px; border: 1px solid rgba(255,255,255,.06); overflow: hidden; box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
.history-card-header { padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,.07); font-size: 15px; font-weight: 700; color: #e2e2e0; }

/* Dark history table (matching clients.php style) */
.billing-history-table { width: 100%; min-width: 700px; border-collapse: collapse; }
.billing-history-table thead { background: rgba(255,255,255,.04); border-bottom: 1px solid rgba(255,255,255,.07); }
.billing-history-table th { padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 600; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .05em; }
.billing-history-table th.text-end { text-align: right; }
.billing-history-table th.text-center { text-align: center; }
.billing-history-table td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.04); font-size: 14px; color: #d4d4d2; }
.billing-history-table td.text-end { text-align: right; }
.billing-history-table td.text-center { text-align: center; }
.billing-history-table tbody tr:hover { background: rgba(255,255,255,.03); }
.billing-history-table tbody tr:last-child td { border-bottom: none; }

/* ===================== INVOICE MODAL ===================== */
.inv-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    z-index: 1050;
    align-items: center; justify-content: center;
    padding: 20px;
}
.inv-box {
    background: #1e1e1d; width: 100%; max-width: 720px;
    border-radius: 16px; overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,0.35);
    display: flex; flex-direction: column;
    max-height: 92vh;
    animation: modalSlideIn .22s cubic-bezier(.34,1.26,.64,1);
}
@keyframes modalSlideIn {
    from { opacity:0; transform: scale(.95) translateY(16px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}
.inv-modal-header {
    padding: 14px 20px;
    background: linear-gradient(135deg, var(--primary-dark,#2C5282) 0%, var(--primary-color,#3B6EA5) 100%);
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.inv-modal-header-left { display: flex; align-items: center; gap: 10px; }
.inv-modal-header-icon { width: 32px; height: 32px; background: rgba(255,255,255,.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; }
.inv-modal-header-title { font-size: 14px; font-weight: 700; color: #fff; }
.inv-modal-header-sub   { font-size: 11px; color: rgba(255,255,255,.7); margin-top: 1px; }
.inv-close {
    width: 30px; height: 30px; background: rgba(255,255,255,.15);
    border: none; border-radius: 8px; cursor: pointer;
    color: rgba(255,255,255,.9); font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.inv-close:hover { background: rgba(255,255,255,.25); }

/* Invoice paper section */
.inv-paper { padding: 32px 36px; overflow-y: auto; flex: 1; background: #1e1e1d; }
.inv-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; }
.inv-brand h2 { font-size: 22px; font-weight: 800; color: #e2e2e0; margin: 0 0 6px 0; }
.inv-brand-details { font-size: 12.5px; color: #9a9a95; line-height: 1.7; }
.inv-label { font-size: 11px; font-weight: 700; color: #9a9a95; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
.inv-number-block { text-align: right; }
.inv-number-block .inv-num { font-size: 18px; font-weight: 800; color: #e2e2e0; }
.inv-number-block .inv-dates { font-size: 12.5px; color: #9a9a95; line-height: 1.8; }

.inv-divider        { border: none; border-top: 1px solid rgba(255,255,255,.08); margin: 0 0 24px 0; }
.inv-divider-accent { border: none; border-top: 2px solid var(--primary-color,#3B6EA5); margin: 0 0 24px 0; }

/* Bill To & Status */
.inv-bill-status { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; }
.inv-bill-to-label   { font-size: 11px; font-weight: 700; color: var(--primary-light,#5b8fc9); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
.inv-bill-to-name    { font-size: 16px; font-weight: 700; color: #e2e2e0; margin-bottom: 2px; }
.inv-bill-to-details { font-size: 13px; color: #9a9a95; line-height: 1.6; }
.inv-status-badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.inv-status-badge.pending { background: rgba(245,158,11,.15); color: #fcd34d; border: 1px solid rgba(245,158,11,.25); }
.inv-status-badge.paid    { background: rgba(16,185,129,.15);  color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }

/* Line Items */
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.inv-table thead tr { background: rgba(255,255,255,.05); }
.inv-table th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 700; color: #9a9a95; text-transform: uppercase; letter-spacing: 0.5px; }
.inv-table th:not(:first-child) { text-align: right; }
.inv-table td { padding: 14px; border-bottom: 1px solid rgba(255,255,255,.06); font-size: 14px; color: #e2e2e0; }
.inv-table td:not(:first-child) { text-align: right; }
.inv-table .desc-main { font-weight: 600; color: #fff; }
.inv-table .desc-sub  { font-size: 12px; color: #9a9a95; margin-top: 2px; }
.inv-table tr.subtotal-row td { border-bottom: none; padding-top: 10px; padding-bottom: 4px; color: #9a9a95; font-size: 13px; }
.inv-table tr.total-row td { font-size: 18px; font-weight: 800; color: #e2e2e0; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.15); }

/* Pay Now Bar — sticky at modal bottom */
.inv-pay-bar { background: linear-gradient(135deg, var(--primary-dark, #1E3A8A), var(--primary-color, #3B82F6)); padding: 18px 32px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-shrink: 0; }
.inv-pay-bar-text { color: rgba(255,255,255,0.8); font-size: 13px; }
.inv-pay-bar-amount { color: white; font-size: 20px; font-weight: 800; }
.inv-pay-bar-methods { display: flex; gap: 8px; align-items: center; }
.inv-pay-method-icon { font-size: 11px; background: rgba(255,255,255,0.15); color: white; padding: 4px 8px; border-radius: 4px; }
.inv-pay-now-btn { background: rgba(255,255,255,.92); color: #1E3A5F; border: none; padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 800; cursor: pointer; white-space: nowrap; }
.inv-pay-now-btn:hover { background: #fff; }

/* ===================== PAYSTACK CHECKOUT MODAL ===================== */
.pst-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1100; align-items: center; justify-content: center; }
.pst-box { background: #222221; width: 100%; max-width: 420px; border-radius: 12px; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.6); border: 1px solid rgba(255,255,255,.07); }
.pst-header { background: rgba(255,255,255,.04); padding: 18px 24px; border-bottom: 1px solid rgba(255,255,255,.07); }
.pst-brand { font-size: 11px; font-weight: 700; color: #00C3F7; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 4px; }
.pst-tagline { font-size: 13px; color: #9a9a95; }
.pst-tagline strong { color: #e2e2e0; font-weight: 700; }
.pst-options { padding: 8px 0; }
.pst-option { display: flex; align-items: center; gap: 14px; padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,.05); cursor: pointer; transition: background 0.15s; }
.pst-option:hover { background: rgba(255,255,255,.05); }
.pst-option-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.pst-option-icon.mpesa  { background: rgba(16,185,129,.15); color: #6ee7b7; }
.pst-option-icon.airtel { background: rgba(239,68,68,.15);  color: #fca5a5; }
.pst-option-icon.card   { background: rgba(59,130,246,.15); color: #93c5fd; }
.pst-option-text { font-size: 15px; font-weight: 600; color: #e2e2e0; flex: 1; }
.pst-option-arrow { font-size: 12px; color: #9a9a95; }
.pst-footer { padding: 14px 24px; text-align: center; font-size: 11px; color: #9a9a95; border-top: 1px solid rgba(255,255,255,.05); }
.pst-footer i { color: #9a9a95; margin-right: 4px; }
.pst-cancel-btn { background: none; border: 1px solid rgba(255,255,255,.1); border-radius: 8px; padding: 8px 18px; color: #9a9a95; font-size: 13px; cursor: pointer; }
.pst-cancel-btn:hover { background: rgba(255,255,255,.07); color: #e2e2e0; }

/* ===================== MPESA STEP MODAL ===================== */
.mpesa-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
    z-index: 1200; align-items: center; justify-content: center;
}
.mpesa-box {
    background: #222221; width: 100%; max-width: 400px;
    border-radius: 16px; overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,0.4);
    animation: modalSlideIn .2s ease;
}
.mpesa-topbar { background: rgba(255,255,255,.04); padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; justify-content: space-between; align-items: flex-start; }
.mpesa-topbar-left { display: flex; align-items: center; gap: 10px; }
.mpesa-topbar-logo { font-size: 18px; font-weight: 800; color: #00C3F7; }
.mpesa-topbar-type { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #9a9a95; }
.mpesa-topbar-right { text-align: right; font-size: 12px; color: #9a9a95; }
.mpesa-topbar-right strong { display: block; font-size: 14px; color: #e2e2e0; font-weight: 700; }
.mpesa-body { padding: 28px 24px; text-align: center; background: #222221; }
.mpesa-logo-badge { font-size: 24px; font-weight: 900; letter-spacing: -1px; margin-bottom: 18px; }
.mpesa-logo-badge span.m { color: #e2162d; }
.mpesa-logo-badge span.p { color: #138a36; }
.mpesa-logo-badge span.dot { color: #138a36; font-size: 14px; vertical-align: super; }
.mpesa-body h4 { font-size: 14px; color: #9a9a95; margin: 0 0 18px 0; font-weight: 400; line-height: 1.5; }

/* ── Phone input ─── */
.mpesa-phone-wrap { margin-bottom: 6px; }
.mpesa-phone-group {
    display: flex; align-items: stretch; border: 2px solid rgba(255,255,255,.1);
    border-radius: 12px; overflow: hidden; transition: border-color .2s; background: #1a1a19;
}
.mpesa-phone-group:focus-within { border-color: #138a36; box-shadow: 0 0 0 3px rgba(19,138,54,.18); }
.mpesa-phone-group.invalid { border-color: #DC2626; box-shadow: 0 0 0 3px rgba(220,38,38,.15); }
.mpesa-phone-group.valid   { border-color: #138a36; box-shadow: 0 0 0 3px rgba(19,138,54,.18); }
.mpesa-phone-prefix {
    background: rgba(255,255,255,.06); padding: 12px 12px 12px 14px;
    font-size: 16px; font-weight: 700; color: #e2e2e0;
    display: flex; align-items: center; gap: 6px;
    border-right: 1px solid rgba(255,255,255,.08); white-space: nowrap;
}
.mpesa-phone-prefix .flag { font-size: 18px; }
#mpesaPhone {
    flex: 1; padding: 12px 14px; border: none; outline: none;
    font-size: 20px; font-weight: 700; color: #e2e2e0;
    letter-spacing: 1.5px; background: transparent;
    min-width: 0;
}
#mpesaPhone::placeholder { font-weight: 400; letter-spacing: 0; color: rgba(255,255,255,.25); font-size: 16px; }
.mpesa-phone-status {
    width: 40px; display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.mpesa-phone-hint { font-size: 11.5px; color: #9a9a95; margin-bottom: 16px; text-align: left; padding: 0 2px; min-height: 16px; }
.mpesa-phone-hint.err { color: #fca5a5; }
.mpesa-phone-hint.ok  { color: #6ee7b7; }

.mpesa-pay-btn {
    width: 100%; padding: 15px; background: #138a36; color: white;
    border: none; border-radius: 10px; font-size: 16px; font-weight: 800;
    cursor: pointer; margin-bottom: 14px; transition: background .15s, transform .1s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.mpesa-pay-btn:hover:not(:disabled) { background: #0f7a2e; transform: translateY(-1px); }
.mpesa-pay-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
.mpesa-alt { font-size: 13px; color: #9a9a95; margin-bottom: 6px; }
.mpesa-alt a { color: #138a36; font-weight: 600; cursor: pointer; text-decoration: none; }
.mpesa-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.06); display: flex; gap: 10px; justify-content: center; }

/* ── STK Waiting State ─── */
.stk-waiting { text-align: center; padding: 20px 0; }
.stk-phone-ring { font-size: 52px; display: inline-block; animation: phoneRing .9s ease-in-out infinite alternate; }
@keyframes phoneRing { from { transform: rotate(-12deg) scale(1); } to { transform: rotate(12deg) scale(1.05); } }
.stk-progress { width: 100%; height: 4px; background: rgba(255,255,255,.1); border-radius: 2px; margin: 18px 0; overflow: hidden; }
.stk-progress-bar { height: 100%; background: #138a36; border-radius: 2px; animation: stkLoad 2.5s ease-in-out infinite; }
@keyframes stkLoad { 0%{width:10%;margin-left:0} 50%{width:60%;margin-left:20%} 100%{width:10%;margin-left:80%} }
.stk-cb-note {
    background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.25); border-radius: 8px;
    padding: 10px 14px; font-size: 12px; color: #6ee7b7; text-align: left;
    margin-top: 14px; line-height: 1.6;
}
.stk-cb-note.warn { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.3); color: #fcd34d; }
</style>

<div class="main-content-wrapper billing-wrapper">
<div class="billing-container">

    <?php if (!empty($_GET['suspended'])): ?>
    <div style="margin-bottom:20px;padding:16px 20px;border-radius:10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);display:flex;align-items:flex-start;gap:12px;">
        <i class="fas fa-ban" style="color:#EF4444;font-size:18px;margin-top:1px;flex-shrink:0;"></i>
        <div>
            <div style="font-weight:700;color:#DC2626;margin-bottom:3px;">Account Suspended</div>
            <div style="font-size:13px;color:#6B7280;">Your platform subscription has been suspended due to an overdue invoice. Please settle your outstanding balance below to restore full access.</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="billing-header">
        <h2>Billing &amp; Invoices</h2>
        <p>View your subscription invoices and pay your FortuNett Technologies platform fee.</p>
    </div>

    <div class="bill-summary-card">
        <div class="bill-icon-wrap"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="bill-info" style="flex:1;">
            <h3>Current Month – <?php echo date('F Y'); ?></h3>
            <p>Invoice: <strong><?php echo htmlspecialchars($invoiceNumber); ?></strong> &nbsp;|&nbsp; Due: <strong><?php echo $dueDate; ?></strong></p>
        </div>
        <div class="bill-amount-wrap">
            <div class="bill-amount"><span>KES </span><?php echo number_format($totalDue, 2); ?></div>
            <div style="font-size:12px; color:#9CA3AF; margin-top:4px;">Total Due</div>
        </div>
        <?php
        // Always use live-computed amounts; only take id and status from DB record
        $viewBill = [
            'id'                => $currentBill['id'] ?? 0,
            'billing_period'    => $currentMonthStart,
            'total_collections' => $currentRevenue,
            'base_fee'          => $pppoeSubtotal,
            'commission_rate'   => $currentBill['commission_rate'] ?? 3,
            'commission_amount' => $hotspotFee,
            'status'            => $currentBill['status'] ?? 'pending',
        ];
        ?>
        <button class="view-invoice-btn" onclick='openInvoiceModal(<?php echo json_encode($viewBill); ?>)'>
            <i class="fas fa-eye"></i> View Invoice
        </button>
    </div>

    <!-- Invoice History -->
    <div class="history-card">
        <div class="history-card-header">Invoice History</div>
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
            <table class="billing-history-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th class="text-end">Revenue Collected</th>
                        <th class="text-end">PPPoE Fee</th>
                        <th class="text-end">Hotspot Commission</th>
                        <th class="text-end">Total Due</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bills as $bill):
                        $bTotal = $bill['base_fee'] + $bill['commission_amount'];
                        $isPaid = $bill['status'] === 'paid';
                    ?>
                    <tr>
                        <td style="font-weight:600; color:#e2e2e0;"><?php echo date('F Y', strtotime($bill['billing_period'])); ?></td>
                        <td class="text-end">KES <?php echo number_format($bill['total_collections'], 2); ?></td>
                        <td class="text-end">KES <?php echo number_format($bill['base_fee'], 2); ?></td>
                        <td class="text-end">KES <?php echo number_format($bill['commission_amount'], 2); ?></td>
                        <td class="text-end" style="font-weight:700; color:#e2e2e0;">KES <?php echo number_format($bTotal, 2); ?></td>
                        <td class="text-center">
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;
                                background:<?php echo $isPaid ? 'rgba(16,185,129,.15)' : 'rgba(245,158,11,.15)'; ?>;
                                color:<?php echo $isPaid ? '#6ee7b7' : '#fcd34d'; ?>;
                                border:1px solid <?php echo $isPaid ? 'rgba(16,185,129,.25)' : 'rgba(245,158,11,.25)'; ?>;">
                                <?php if ($isPaid): ?><span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span><?php endif; ?>
                                <?php echo ucfirst($bill['status']); ?>
                            </span>
                        </td>
                        <td class="text-end" style="padding-right:20px;">
                            <button style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#e2e2e0;padding:5px 14px;border-radius:6px;font-size:13px;cursor:pointer;transition:background .15s;"
                                onmouseover="this.style.background='rgba(255,255,255,.12)'"
                                onmouseout="this.style.background='rgba(255,255,255,.07)'"
                                onclick='openInvoiceModal(<?php echo json_encode($bill); ?>)'>View</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<!-- ========================= INVOICE MODAL ========================= -->
<div class="inv-overlay" id="invoiceModal" onclick="closeIfBg(event, 'invoiceModal')">
<div class="inv-box">
    <!-- Modal Header -->
    <div class="inv-modal-header">
        <div class="inv-modal-header-left">
            <div class="inv-modal-header-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="inv-modal-header-title">Platform Invoice</div>
                <div class="inv-modal-header-sub" id="invHeaderSub">FortuNett Technologies</div>
            </div>
        </div>
        <button class="inv-close" onclick="closeModal('invoiceModal')" title="Close">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Invoice Paper (scrollable) -->
    <div class="inv-paper">
        <!-- TOP: FortuNett (left) | INVOICE # (right) -->
        <div class="inv-top">
            <div class="inv-brand">
                <h2>FortuNett Technologies Ltd.</h2>
                <div class="inv-brand-details">
                    fortunettech1@gmail.com<br>
                    +254 700 000 000<br>
                    Upper Hill, Nairobi, Kenya<br>
                    <em style="font-size:11px; color:#9CA3AF;">Your ISP Management Platform</em>
                </div>
            </div>
            <div class="inv-number-block">
                <div class="inv-label">Invoice</div>
                <div class="inv-num" id="invNumber"><?php echo htmlspecialchars($invoiceNumber); ?></div>
                <div class="inv-dates">
                    <strong>Date:</strong> <span id="invDate"><?php echo $issueDate; ?></span><br>
                    <strong>Due:</strong> <span id="invDue"><?php echo $dueDate; ?></span>
                </div>
            </div>
        </div>

        <hr class="inv-divider-accent">

        <!-- BILL TO & STATUS -->
        <div class="inv-bill-status">
            <div class="inv-bill-to">
                <div class="inv-bill-to-label">Bill To</div>
                <div class="inv-bill-to-name" id="invBillName"><?php echo htmlspecialchars($orgName); ?></div>
                <div class="inv-bill-to-details">
                    <?php echo htmlspecialchars($adminEmail); ?><br>
                    <?php if ($adminPhone): ?><?php echo htmlspecialchars($adminPhone); ?><?php endif; ?>
                </div>
            </div>
            <div>
                <span class="inv-status-badge pending" id="invStatusBadge">PENDING</span>
            </div>
        </div>

        <!-- LINE ITEMS TABLE -->
        <table class="inv-table" id="invLineItems">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Qty</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="desc-main">Platform Fee</div>
                        <div class="desc-sub">Monthly base fee for ISP Management Platform</div>
                    </td>
                    <td>—</td>
                    <td>1</td>
                    <td id="invBaseFee">KES <?php echo number_format($pppoeSubtotal, 2); ?></td>
                </tr>
                <tr>
                    <td>
                        <div class="desc-main">Transaction Fee</div>
                        <div class="desc-sub">Commission on collections</div>
                    </td>
                    <td id="invCommRate">3%</td>
                    <td>1</td>
                    <td id="invCommAmt">KES <?php echo number_format($hotspotFee, 2); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="subtotal-row">
                    <td colspan="3" style="text-align:right;">Service Subtotal</td>
                    <td id="invServiceSubtotal">KES <?php echo number_format($totalDue, 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" style="text-align:right; font-weight:800;">Total Due</td>
                    <td id="invTotalDue">KES <?php echo number_format($totalDue, 2); ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Footer note -->
        <div style="text-align:center; font-size:11px; color:#9CA3AF; margin-top:8px;">
            Thank you for your business! For billing inquiries, contact fortunettech1@gmail.com<br>
            Access your account at <a href="#" style="color:#3B6EA5;">fortunetttech.site</a> &nbsp;|&nbsp; © <?php echo date('Y'); ?> FortuNett Technologies Ltd. All rights reserved.
        </div>
    </div>

    <!-- PAY NOW BAR -->
    <div class="inv-pay-bar" id="invPayBar">
        <div>
            <div class="inv-pay-bar-text">Payment methods accepted</div>
            <div class="inv-pay-bar-methods">
                <span class="inv-pay-method-icon">M-Pesa</span>
                <span class="inv-pay-method-icon">Airtel</span>
                <span class="inv-pay-method-icon">Card</span>
            </div>
        </div>
        <div>
            <div class="inv-pay-bar-text">Amount due</div>
            <div class="inv-pay-bar-amount" id="invPayBarAmt">KES <?php echo number_format($totalDue, 2); ?></div>
        </div>
        <button class="inv-pay-now-btn" id="invPayNowBtn" onclick="openPaystackModal()">
            Pay Now <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</div>
</div>

<!-- ========================= PAYSTACK CHECKOUT MODAL ========================= -->
<div class="pst-overlay" id="paystackModal" onclick="closeIfBg(event, 'paystackModal')">
<div class="pst-box">
    <div class="pst-header">
        <div class="pst-brand">Paystack Checkout</div>
        <div class="pst-tagline">Use one of the payment methods below to pay <strong id="pstAmount">KES 0</strong> to FortuNett Technologies</div>
    </div>
    <div class="pst-options">
        <div class="pst-option" onclick="openMpesaStep('stk')">
            <div class="pst-option-icon mpesa"><i class="fas fa-mobile-alt"></i></div>
            <div class="pst-option-text">Pay with M-PESA</div>
            <div class="pst-option-arrow"><i class="fas fa-chevron-right"></i></div>
        </div>
        <div class="pst-option" onclick="openMpesaStep('till')">
            <div class="pst-option-icon mpesa"><i class="fas fa-store"></i></div>
            <div class="pst-option-text">Pay with M-PESA Till</div>
            <div class="pst-option-arrow"><i class="fas fa-chevron-right"></i></div>
        </div>
        <div class="pst-option" onclick="openMpesaStep('airtel')">
            <div class="pst-option-icon airtel"><i class="fas fa-sim-card"></i></div>
            <div class="pst-option-text">Pay with Airtel Money</div>
            <div class="pst-option-arrow"><i class="fas fa-chevron-right"></i></div>
        </div>
        <div class="pst-option" onclick="openMpesaStep('card')">
            <div class="pst-option-icon card"><i class="fas fa-credit-card"></i></div>
            <div class="pst-option-text">Pay with Card</div>
            <div class="pst-option-arrow"><i class="fas fa-chevron-right"></i></div>
        </div>
    </div>
    <div class="pst-footer">
        <button class="pst-cancel-btn" onclick="closeModal('paystackModal')">&times; Cancel Payment</button>
        <br><br>
        <i class="fas fa-lock"></i> Secured by <strong>paystack</strong>
    </div>
</div>
</div>

<!-- ========================= M-PESA STK MODAL ========================= -->
<div class="mpesa-overlay" id="mpesaModal" onclick="closeIfBg(event, 'mpesaModal')">
<div class="mpesa-box">
    <!-- Top bar: Logo + email + amount -->
    <div class="mpesa-topbar">
        <div class="mpesa-topbar-left">
            <div>
                <div class="mpesa-topbar-logo">paystack</div>
                <div class="mpesa-topbar-type" id="mpesaMethodLabel">Pay with M-PESA</div>
            </div>
        </div>
        <div class="mpesa-topbar-right">
            <span><?php echo htmlspecialchars($adminEmail ?: 'fortunettech1@gmail.com'); ?></span>
            <strong id="mpesaTopAmt">Pay KES 0</strong>
        </div>
    </div>

    <!-- Body -->
    <div class="mpesa-body" id="mpesaInputSection">
        <!-- M-Pesa Logo -->
        <div class="mpesa-logo-badge">
            <span class="m">M</span><span class="dot">-</span><span class="p">PESA</span>
        </div>

        <h4>Enter your M-Pesa number to receive<br>the payment prompt on your phone</h4>

        <!-- Smart phone input -->
        <div class="mpesa-phone-wrap">
            <div class="mpesa-phone-group" id="mpesaPhoneGroup">
                <div class="mpesa-phone-prefix">
                    <span class="flag">🇰🇪</span> +254
                </div>
                <input type="tel" id="mpesaPhone" maxlength="9"
                       placeholder="7XX XXX XXX"
                       oninput="onPhoneInput(this)"
                       onkeypress="return event.charCode>=48&&event.charCode<=57">
                <div class="mpesa-phone-status" id="mpesaPhoneStatus"></div>
            </div>
            <div class="mpesa-phone-hint" id="mpesaPhoneHint">e.g. 0712 345 678 or 0700 000 000</div>
        </div>

        <button class="mpesa-pay-btn" id="mpesaPayBtn" onclick="submitMpesaPayment()" disabled>
            <i class="fas fa-lock" style="font-size:14px;opacity:.8;"></i>
            Pay KES <span id="mpesaPayBtnAmt">0</span>
        </button>

        <div class="mpesa-alt">No STK Push? <a onclick="switchToPaybill()">Pay via Paybill manually</a></div>
    </div>

    <!-- Waiting for PIN Section (hidden initially) -->
    <div class="mpesa-body stk-waiting" id="mpesaWaitSection" style="display:none;">
        <div class="stk-phone-ring">📱</div>
        <h4 style="font-size:17px; font-weight:700; color:#111827; margin:14px 0 8px;">Check your phone!</h4>
        <p style="color:#6B7280; font-size:13px; line-height:1.6;">
            An M-Pesa prompt has been sent to<br>
            <strong id="mpesaPhoneDisplay" style="color:#111827; font-size:15px;"></strong><br>
            Enter your <strong>M-Pesa PIN</strong> to complete payment.
        </p>
        <div class="stk-progress"><div class="stk-progress-bar"></div></div>
        <div id="stkCallbackNote" class="stk-cb-note">
            <strong>✅ Payment will auto-confirm</strong> once you enter your PIN —
            your callback URL is configured and Safaricom will notify the system automatically.
        </div>
        <p style="font-size:12px; color:#9CA3AF; margin-top:12px;">
            Didn't get the prompt?
            <a href="#" onclick="resetToPhoneInput()" style="color:#138a36;font-weight:600;">Try again</a>
            &nbsp;·&nbsp;
            <a href="payments.php" style="color:#6B7280;">Verify manually in Payments</a>
        </p>
    </div>

    <!-- Footer buttons -->
    <div class="mpesa-footer" id="mpesaFooter">
        <button class="pst-cancel-btn" onclick="closeModal('mpesaModal'); document.getElementById('paystackModal').style.display='flex';">
            ⇐ Change Payment Method
        </button>
        <button class="pst-cancel-btn" onclick="closeModal('mpesaModal')">
            &times; Cancel Payment
        </button>
    </div>
</div>
</div>

<style>
@keyframes loadbar {
    0%   { width: 20px; opacity: 0.4; }
    50%  { width: 100px; opacity: 1; }
    100% { width: 20px; opacity: 0.4; }
}
</style>

<script>
let currentInvoiceAmount = <?php echo $totalDue; ?>;
let currentBillId        = <?php echo (int)($viewBill['id'] ?? 0); ?>;
let currentMpesaMethod   = 'stk';
const isSandbox          = <?php echo MPESA_ENV === 'sandbox' ? 'true' : 'false'; ?>;

// ════════════════════════════════════════
// Invoice Modal
// ════════════════════════════════════════
function openInvoiceModal(bill) {
    const base  = parseFloat(bill.base_fee || 0);
    const comm  = parseFloat(bill.commission_amount || 0);
    const total = base + comm;
    const rate  = parseFloat(bill.commission_rate || 3);

    document.getElementById('invBaseFee').textContent         = 'KES ' + fmt(base);
    document.getElementById('invCommRate').textContent        = rate + '%';
    document.getElementById('invCommAmt').textContent         = 'KES ' + fmt(comm);
    document.getElementById('invServiceSubtotal').textContent = 'KES ' + fmt(total);
    document.getElementById('invTotalDue').textContent        = 'KES ' + fmt(total);
    document.getElementById('invPayBarAmt').textContent       = 'KES ' + fmt(total);
    currentInvoiceAmount = total;
    currentBillId        = parseInt(bill.id || 0);

    // Period + due date + invoice number
    if (bill.billing_period) {
        const d = new Date(bill.billing_period + 'T00:00:00');
        document.getElementById('invDate').textContent = d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
        const due = new Date(d); due.setDate(due.getDate() + 14);
        document.getElementById('invDue').textContent = due.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});

        // Reconstruct invoice number from billing period (format: INV-ORGSLUG/YYYYMMDD)
        const y   = d.getFullYear();
        const mo  = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        document.getElementById('invNumber').textContent = 'INV-<?php echo htmlspecialchars($orgSlug); ?>/' + y + mo + day;

        // Header subtitle
        const sub = document.getElementById('invHeaderSub');
        if (sub) sub.textContent = d.toLocaleDateString('en-GB', {month:'long', year:'numeric'});
    }

    const badge  = document.getElementById('invStatusBadge');
    const isPaid = bill.status === 'paid';
    badge.textContent = isPaid ? '✅ PAID' : '⏳ PENDING';
    badge.className   = 'inv-status-badge ' + (isPaid ? 'paid' : 'pending');

    document.getElementById('invPayBar').style.display = isPaid ? 'none' : 'flex';
    document.getElementById('invoiceModal').style.display = 'flex';
}

// ════════════════════════════════════════
// Checkout flow
// ════════════════════════════════════════
function openPaystackModal() {
    closeModal('invoiceModal');
    document.getElementById('pstAmount').textContent = 'KES ' + fmt(currentInvoiceAmount);
    document.getElementById('paystackModal').style.display = 'flex';
}

function openMpesaStep(method) {
    currentMpesaMethod = method;
    closeModal('paystackModal');

    const labels = { stk:'Pay with M-PESA', till:'Pay with M-PESA Till', airtel:'Pay with Airtel Money', card:'Pay with Card' };
    document.getElementById('mpesaMethodLabel').textContent  = labels[method] || 'Pay with M-PESA';
    document.getElementById('mpesaTopAmt').textContent       = 'Pay KES ' + fmt(currentInvoiceAmount);
    document.getElementById('mpesaPayBtnAmt').textContent    = fmt(currentInvoiceAmount);

    resetToPhoneInput();
    document.getElementById('mpesaModal').style.display = 'flex';
}

function resetToPhoneInput() {
    document.getElementById('mpesaInputSection').style.display = 'block';
    document.getElementById('mpesaWaitSection').style.display  = 'none';
    document.getElementById('mpesaFooter').style.display       = 'flex';
    // Re-enable button, re-validate phone
    const ph = document.getElementById('mpesaPhone');
    if (ph) onPhoneInput(ph);
}

function switchToPaybill() {
    closeModal('mpesaModal');
    const usingOwn  = <?php echo $mpesaConfig['using_own'] ? 'true' : 'false'; ?>;
    const shortcode = <?php echo json_encode($mpesaConfig['shortcode']); ?>;
    const scLabel   = shortcode || (usingOwn ? 'Your M-Pesa Shortcode' : 'FortuNett Paybill');
    const note      = usingOwn
        ? '<div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:12px;"><i class="fas fa-check-circle" style="margin-right:6px;"></i>Using <strong>your own M-Pesa shortcode</strong></div>'
        : '<div style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#93c5fd;border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:12px;"><i class="fas fa-info-circle" style="margin-right:6px;"></i>Using FortuNett platform paybill — configure your own in <a href="settings.php#payments" style="color:#93c5fd;font-weight:600;">Settings → Payments</a></div>';
    document.getElementById('mpesaInputSection').innerHTML = `
        <div class="mpesa-logo-badge"><span class="m">M</span><span class="dot">-</span><span class="p">PESA</span></div>
        <h4 style="font-weight:700;color:#111827;">Pay via M-PESA Paybill</h4>
        ${note}
        <div style="background:#F0FDF4;border-radius:10px;padding:16px;margin-bottom:16px;text-align:left;font-size:13px;line-height:1.9;">
            <strong>Steps:</strong><br>
            1. Open <b>M-PESA</b> on your phone<br>
            2. Select <b>Lipa na M-PESA → Pay Bill</b><br>
            3. Business No: <b>${scLabel}</b><br>
            4. Account No: <b><?php echo htmlspecialchars($orgName); ?></b><br>
            5. Amount: <b>KES ${fmt(currentInvoiceAmount)}</b><br>
            6. Enter your M-PESA PIN and confirm
        </div>
        <p style="font-size:12px;color:#6B7280;">Once paid, record the reference code in the
            <a href="payments.php" style="color:#138a36;font-weight:600;">Payments page</a>.</p>
    `;
    document.getElementById('mpesaModal').style.display = 'flex';
}

// ════════════════════════════════════════
// Phone input: live formatting & validation
// ════════════════════════════════════════
function onPhoneInput(el) {
    // Strip non-digits
    let raw = el.value.replace(/\D/g, '');
    // Max 9 digits after the +254 prefix (7XXXXXXXX)
    if (raw.length > 9) raw = raw.slice(0, 9);
    el.value = raw;

    const group  = document.getElementById('mpesaPhoneGroup');
    const hint   = document.getElementById('mpesaPhoneHint');
    const status = document.getElementById('mpesaPhoneStatus');
    const btn    = document.getElementById('mpesaPayBtn');

    if (raw.length === 0) {
        group.className  = 'mpesa-phone-group';
        hint.textContent = 'e.g. 0712 345 678 or 0700 000 000';
        hint.className   = 'mpesa-phone-hint';
        status.textContent = '';
        if (btn) btn.disabled = true;
        return;
    }

    // Must start with 7 (Safaricom) or some valid prefix
    const valid = raw.length === 9 && /^[17]/.test(raw);

    if (raw.length < 9) {
        group.className  = 'mpesa-phone-group';
        hint.textContent = `${9 - raw.length} more digits needed`;
        hint.className   = 'mpesa-phone-hint';
        status.textContent = '';
        if (btn) btn.disabled = true;
    } else if (!valid) {
        group.className  = 'mpesa-phone-group invalid';
        hint.textContent = 'Number should start with 07XX... (Safaricom) or 01XX...';
        hint.className   = 'mpesa-phone-hint err';
        status.innerHTML = '<span style="color:#DC2626">✕</span>';
        if (btn) btn.disabled = true;
    } else {
        group.className  = 'mpesa-phone-group valid';
        hint.textContent = 'Sending prompt to: +254 ' + raw.slice(0,3) + ' ' + raw.slice(3,6) + ' ' + raw.slice(6);
        hint.className   = 'mpesa-phone-hint ok';
        status.innerHTML = '<span style="color:#059669">✓</span>';
        if (btn) btn.disabled = false;
    }
}

// ════════════════════════════════════════
// Submit STK Push
// ════════════════════════════════════════
function submitMpesaPayment() {
    const rawDigits = document.getElementById('mpesaPhone')?.value?.replace(/\D/g,'') || '';
    if (rawDigits.length !== 9) {
        showBillingToast('Please enter a valid 9-digit number after +254', 'error');
        return;
    }

    // Always send as 254XXXXXXXXX (full international format)
    const phone = '254' + rawDigits;

    const btn = document.getElementById('mpesaPayBtn');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending prompt…';
    btn.disabled  = true;

    const fd = new FormData();
    fd.append('phone',       phone);
    fd.append('amount',      currentInvoiceAmount);
    fd.append('bill_id',     currentBillId);
    fd.append('account_ref', 'INV-<?php echo htmlspecialchars($orgSlug); ?>');

    fetch('api/mpesa/stk_push.php', { method:'POST', body:fd })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
            btn.innerHTML = origHtml;
            btn.disabled  = false;

            if (data.success) {
                if (data.sandbox) {
                    // Sandbox — show inline warning, do NOT show "check phone"
                    showSandboxWarning();
                } else {
                    showStkWaiting(phone, data);
                }
            } else {
                showBillingToast((data.message || 'M-Pesa request failed'), 'error');
            }
        })
        .catch(() => {
            btn.innerHTML = origHtml;
            btn.disabled  = false;
            showBillingToast('Connection error. Please try again.', 'error');
        });
}

function showStkWaiting(phone, data) {
    document.getElementById('mpesaInputSection').style.display = 'none';
    document.getElementById('mpesaWaitSection').style.display  = 'block';
    document.getElementById('mpesaFooter').style.display       = 'none';

    // Format display number
    const digits  = (phone || '').replace(/\D/g,'').replace(/^254/, '');
    const display = '+254 ' + digits.slice(0,3) + ' ' + digits.slice(3,6) + ' ' + digits.slice(6);
    document.getElementById('mpesaPhoneDisplay').textContent = display;

    // Callback note
    const note = document.getElementById('stkCallbackNote');
    const env  = data.environment || 'production';
    const sc   = data.shortcode   || '';
    const phoneSent = data.phone_sent || phone;

    if (data.using_platform) {
        note.className = 'stk-cb-note';
        note.innerHTML = '<strong>✅ Request accepted by Safaricom</strong> via FortuNett shared paybill' + (sc ? ' (' + sc + ')' : '') + '.'
            + '<br><small style="opacity:.8;">If no M-Pesa prompt appeared on the phone within 30 seconds, the platform shortcode may not be enrolled for Lipa Na M-Pesa Online (STK Push). Contact your FortuNett admin to verify the platform Daraja configuration.</small>';
    } else {
        note.className = 'stk-cb-note';
        note.innerHTML = '✅ STK prompt sent to <strong>' + phoneSent + '</strong>'
            + (sc ? ' via shortcode <strong>' + sc + '</strong>' : '')
            + '. Enter your M-Pesa PIN on the phone.'
            + '<br><small style="opacity:.8;">If no prompt appeared after 30 seconds: check that the shortcode is enrolled for Lipa Na M-Pesa Online in your Daraja portal, and that the passkey matches the production environment.'
            + ' <a href="settings.php#payments" style="color:inherit;font-weight:600;">Check gateway settings →</a></small>';
    }
}

function showSandboxWarning() {
    const section = document.getElementById('mpesaInputSection');
    section.innerHTML = `
        <div style="text-align:center;padding:10px 0;">
            <div style="font-size:44px;margin-bottom:16px;">🧪</div>
            <h4 style="font-size:16px;font-weight:700;color:#92400E;margin-bottom:12px;">Sandbox Mode Active</h4>
            <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:14px 16px;text-align:left;font-size:13px;color:#92400E;line-height:1.7;margin-bottom:16px;">
                <strong>Your credentials are currently in Sandbox.</strong> Safaricom accepted the request
                but <strong>no real phone prompt was sent</strong> — sandbox does not ring real phones.<br><br>
                <strong>To go live:</strong><br>
                1. Go to <a href="settings.php#payments" style="color:#92400E;font-weight:700;">Settings → Payments</a><br>
                2. Edit your M-Pesa API gateway<br>
                3. Change <strong>Environment → Production</strong><br>
                4. Enter your live Consumer Key, Secret &amp; Passkey from Safaricom Daraja
            </div>
            <button class="mpesa-pay-btn" style="background:#92400E;" onclick="resetToPhoneInput()">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
        </div>`;
}

// ════════════════════════════════════════
// Helpers
// ════════════════════════════════════════
function showBillingToast(msg, type, dur) {
    if (typeof showToast === 'function') { showToast(msg, type || 'info', dur || 5000); return; }
    // Fallback inline alert banner
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;background:'+(type==='error'?'#DC2626':'#D97706')+';color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;max-width:360px;box-shadow:0 8px 24px rgba(0,0,0,.2);';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), dur || 5000);
}

function fmt(n) {
    return parseFloat(n).toLocaleString('en-KE', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function closeModal(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }
function closeIfBg(e, id) { if (e.target === document.getElementById(id)) closeModal(id); }
</script>

<?php include 'includes/footer.php'; ?>
