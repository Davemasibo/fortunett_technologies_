<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

$tenantId = $customer['tenant_id'] ?? null;
if (!$tenantId) {
    $s = $pdo->prepare("SELECT tenant_id FROM users WHERE username = ?");
    $s->execute([$customer['username']]);
    $tenantId = $s->fetchColumn() ?: 1;
}

$selectedPackageId = $_GET['package_id'] ?? $customer['package_id'];
$package = null;
if ($selectedPackageId) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$selectedPackageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
}

$packagePrice   = $package ? (float)$package['price'] : 0;
$accountBalance = (float)($customer['account_balance'] ?? 0);
$amountToPay    = max(0, $packagePrice - $accountBalance);

$gatewaysStmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? AND is_active = 1 ORDER BY is_default DESC");
$gatewaysStmt->execute([$tenantId]);
$gateways = $gatewaysStmt->fetchAll(PDO::FETCH_ASSOC);

$mpesaGw = null;
foreach ($gateways as $g) {
    if ($g['gateway_type'] === 'mpesa_api') { $mpesaGw = $g; break; }
}

// Platform paybill — show to customer unless tenant has disabled it
$platformPaybill = null;
$showPlatformPaybill = true;
try {
    $tsCheck = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'show_platform_paybill' LIMIT 1");
    $tsCheck->execute([$tenantId]);
    $pref = $tsCheck->fetchColumn();
    if ($pref === '0') $showPlatformPaybill = false;
} catch (Exception $e) {}

if ($showPlatformPaybill) {
    try {
        $platQ = $pdo->query("SELECT shortcode, shortcode_type, consumer_key FROM platform_mpesa_config WHERE id = 1 LIMIT 1");
        $platRow = $platQ ? $platQ->fetch(PDO::FETCH_ASSOC) : null;
        if ($platRow && !empty($platRow['shortcode'])) {
            $platformPaybill = $platRow;
        }
    } catch (Exception $e) {}
}

// If tenant has no mpesa_api gateway but platform has STK credentials, allow STK via platform (gateway_id = 0)
$stkGwId = $mpesaGw ? $mpesaGw['id'] : ($platformPaybill && !empty($platformPaybill['consumer_key']) ? 0 : null);

$_base = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false
       || preg_match('/^\d+\.\d+\./', $_SERVER['HTTP_HOST'] ?? ''))
       ? '/fortunett_technologies_' : '';

include 'includes/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════
   PAYMENT PAGE — dark neumorphism, single-column
   ═══════════════════════════════════════════════════ */
:root {
    --green:    #10b981;
    --green-lt: #6ee7b7;
    --neu-inset: inset 3px 3px 8px rgba(0,0,0,.55), inset -2px -2px 5px rgba(255,255,255,.05);
}

.pay-page { padding: 28px 32px; max-width: 1200px; }
@media(max-width:640px){ .pay-page{ padding:16px 14px; } }

/* ── Side-by-side grid for the two main cards ──────── */
.pay-main-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    align-items: start;
    margin-bottom: 18px;
}
.pay-main-grid .pay-card { margin-bottom: 0; height: 100%; box-sizing: border-box; }
@media(max-width: 860px){ .pay-main-grid { grid-template-columns: 1fr; } }

.pay-page-title { font-size: 24px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px; }
.pay-page-sub   { font-size: 14px; color: rgba(255,255,255,.38); margin: 0 0 24px; }

/* ── Tabs ─────────────────────────────────────────── */
.pay-tabs {
    display: flex; gap: 4px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 50px; padding: 4px;
    margin-bottom: 24px; width: fit-content;
}
.pay-tab {
    padding: 9px 22px; border-radius: 40px; border: none;
    background: transparent; color: rgba(255,255,255,.42);
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all .2s; font-family: inherit;
    display: flex; align-items: center; gap: 7px;
}
.pay-tab.active {
    background: #222221; color: #e2e2e0;
    box-shadow: 4px 4px 10px rgba(0,0,0,.4), -2px -2px 6px rgba(255,255,255,.04);
}
.pay-panel { display: none; }
.pay-panel.active { display: block; }

/* ── Card ─────────────────────────────────────────── */
.pay-card {
    background: #222221;
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 16px;
    box-shadow: 10px 10px 24px rgba(0,0,0,.5), -5px -5px 14px rgba(255,255,255,.04);
    overflow: hidden;
    margin-bottom: 18px;
}
.pay-card-head {
    padding: 16px 22px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 14px; font-weight: 600; color: #e2e2e0;
    display: flex; align-items: center; gap: 8px;
}
.pay-card-head i { color: rgba(255,255,255,.35); font-size: 13px; }
.pay-card-body { padding: 20px 22px; }

/* ── Package row ──────────────────────────────────── */
.pkg-row {
    display: flex; align-items: center; gap: 14px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 12px; padding: 14px 16px; margin-bottom: 18px;
}
.pkg-icon {
    width: 44px; height: 44px;
    background: rgba(59,130,246,.15); color: #93c5fd;
    border-radius: 10px; display: flex; align-items: center;
    justify-content: center; font-size: 18px; flex-shrink: 0;
}
.pkg-name  { font-weight: 600; color: #e2e2e0; font-size: 14px; }
.pkg-speed { font-size: 12px; color: rgba(255,255,255,.38); margin-top: 2px; }
.pkg-price { margin-left: auto; font-size: 20px; font-weight: 700; color: var(--green-lt); white-space: nowrap; }

/* ── Summary rows ─────────────────────────────────── */
.sum-row {
    display: flex; justify-content: space-between;
    font-size: 13px; padding: 9px 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
    color: rgba(255,255,255,.48);
}
.sum-row:last-of-type { border-bottom: none; }
.sum-row span:last-child { color: #e2e2e0; font-weight: 500; }
.sum-row.credit span:last-child { color: var(--green-lt); }
.sum-total {
    display: flex; justify-content: space-between;
    font-size: 20px; font-weight: 800;
    padding: 14px 0 0; margin-top: 6px;
    border-top: 1px solid rgba(255,255,255,.1); color: #fff;
}

/* ── Primary buttons ──────────────────────────────── */
.pay-now-btn {
    width: 100%; padding: 16px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none; border-radius: 12px;
    color: #fff; font-size: 16px; font-weight: 700;
    cursor: pointer; transition: opacity .2s, transform .15s;
    display: flex; align-items: center; justify-content: center;
    gap: 10px; margin-top: 16px;
    box-shadow: 0 4px 20px rgba(16,185,129,.35);
    font-family: inherit;
}
.pay-now-btn:hover { opacity: .92; transform: translateY(-1px); }
.pay-now-btn:disabled { opacity: .5; cursor: default; transform: none; }

.activate-btn {
    width: 100%; padding: 14px; margin-top: 14px;
    background: linear-gradient(135deg, rgba(16,185,129,.2) 0%, rgba(16,185,129,.1) 100%);
    border: 1px solid rgba(16,185,129,.35); border-radius: 12px;
    color: var(--green-lt); font-size: 15px; font-weight: 700;
    cursor: pointer; transition: all .2s; font-family: inherit;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.activate-btn:hover { background: rgba(16,185,129,.28); transform: translateY(-1px); }

.balance-covered {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px; margin-top: 16px;
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.25);
    border-radius: 10px; font-size: 13px; color: var(--green-lt);
}
.balance-covered i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* ── Gateway accordion ────────────────────────────── */
.gw-list { display: flex; flex-direction: column; gap: 10px; }
.gw-item { border: 1px solid rgba(255,255,255,.07); border-radius: 12px; overflow: hidden; transition: border-color .2s; }
.gw-item.open { border-color: rgba(255,255,255,.16); }
.gw-trigger {
    width: 100%; background: rgba(255,255,255,.04); border: none;
    padding: 14px 18px; display: flex; align-items: center;
    gap: 14px; cursor: pointer; text-align: left; transition: background .18s;
    font-family: inherit;
}
.gw-trigger:hover { background: rgba(255,255,255,.07); }
.gw-icon {
    width: 38px; height: 38px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.gw-icon.mpesa { background: rgba(16,185,129,.15); color: var(--green-lt); }
.gw-icon.bank  { background: rgba(59,130,246,.15); color: #93c5fd; }
.gw-icon.other { background: rgba(99,102,241,.15); color: #a5b4fc; }
.gw-label { font-size: 14px; font-weight: 600; color: #e2e2e0; }
.gw-type  { font-size: 11px; color: rgba(255,255,255,.32); margin-top: 1px; }
.gw-chevron { margin-left: auto; color: rgba(255,255,255,.28); font-size: 12px; transition: transform .2s; }
.gw-item.open .gw-chevron { transform: rotate(180deg); }
.gw-body { display: none; padding: 18px; background: rgba(0,0,0,.2); border-top: 1px solid rgba(255,255,255,.06); }
.gw-item.open .gw-body { display: block; }

/* ── Paybill display ──────────────────────────────── */
.pb-row {
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px; padding: 12px 16px; margin-bottom: 10px;
}
.pb-row:last-child { margin-bottom: 0; }
.pb-field { font-size: 10px; font-weight: 600; color: rgba(255,255,255,.32); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.pb-val   { font-size: 22px; font-weight: 800; color: #e2e2e0; letter-spacing: 2px; }
.pb-row.highlight { background: rgba(99,102,241,.1); border-color: rgba(99,102,241,.25); }
.pb-row.highlight .pb-val { color: #a5b4fc; }
.pb-hint  { font-size: 11px; color: rgba(99,102,241,.7); margin-top: 2px; }
.copy-btn {
    padding: 6px 12px; background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.1); border-radius: 6px;
    color: rgba(255,255,255,.6); font-size: 12px; cursor: pointer;
    transition: all .18s; white-space: nowrap; flex-shrink: 0; font-family: inherit;
}
.copy-btn:hover { background: rgba(255,255,255,.14); color: #fff; }
.copy-btn.copied { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.35); color: var(--green-lt); }
.gw-alert {
    margin-top: 12px; padding: 10px 14px;
    background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25);
    border-radius: 8px; font-size: 12px; color: #fcd34d;
}

/* ── Manual confirm section ───────────────────────── */
.confirm-section {
    margin-top: 16px; padding: 18px;
    background: rgba(255,255,255,.03);
    border: 1px dashed rgba(255,255,255,.1); border-radius: 12px;
}
.confirm-title { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.6); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.confirm-hint  { font-size: 12px; color: rgba(255,255,255,.28); margin-bottom: 12px; }
.confirm-row   { display: flex; gap: 8px; }
.confirm-input {
    flex: 1; padding: 10px 14px; background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1); border-radius: 8px;
    color: #e2e2e0; font-size: 14px; font-family: monospace;
    box-shadow: var(--neu-inset);
}
.confirm-input::placeholder { color: rgba(255,255,255,.2); font-family: inherit; }
.confirm-input:focus { outline: none; border-color: rgba(255,255,255,.25); }
.confirm-btn {
    padding: 10px 18px; background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12); border-radius: 8px;
    color: #e2e2e0; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .18s; white-space: nowrap; font-family: inherit;
}
.confirm-btn:hover { background: rgba(255,255,255,.13); }

/* ── Top-up panel ─────────────────────────────────── */
.topup-balance-strip {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; background: rgba(16,185,129,.08);
    border: 1px solid rgba(16,185,129,.2); border-radius: 10px;
    margin-bottom: 18px; font-size: 13px; color: rgba(255,255,255,.52);
}
.topup-balance-strip .bal-val { font-size: 20px; font-weight: 700; color: var(--green-lt); }
.topup-amount-row { margin-bottom: 14px; }
.topup-input {
    width: 100%; padding: 14px 18px; background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1); border-radius: 10px;
    color: #e2e2e0; font-size: 22px; font-weight: 700;
    box-shadow: var(--neu-inset); font-family: inherit;
}
.topup-input::placeholder { color: rgba(255,255,255,.2); font-size: 15px; font-weight: 400; }
.topup-input:focus { outline: none; border-color: rgba(59,130,246,.5); }
.topup-preset-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.topup-preset {
    padding: 7px 16px; background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1); border-radius: 20px;
    color: rgba(255,255,255,.62); font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all .18s; font-family: inherit;
}
.topup-preset:hover { background: rgba(255,255,255,.13); color: #fff; }
.topup-preset.selected { background: rgba(59,130,246,.2); border-color: rgba(59,130,246,.4); color: #93c5fd; }

.gw-empty { text-align: center; padding: 36px 20px; color: rgba(255,255,255,.28); }
.gw-empty i { font-size: 36px; margin-bottom: 12px; display: block; color: rgba(245,158,11,.45); }
.gw-empty p { font-size: 14px; }

/* ── STK top-up row ───────────────────────────────── */
.stk-row { display: flex; gap: 8px; }
.stk-input {
    flex: 1; padding: 10px 14px; background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1); border-radius: 8px;
    color: #e2e2e0; font-size: 14px; box-shadow: var(--neu-inset); font-family: inherit;
}
.stk-input::placeholder { color: rgba(255,255,255,.25); }
.stk-input:focus { outline: none; border-color: rgba(16,185,129,.5); }
.stk-btn {
    padding: 10px 18px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none; border-radius: 8px; color: #fff;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: opacity .2s; white-space: nowrap; font-family: inherit;
}
.stk-btn:hover { opacity: .9; }

/* ═══════════════════════════════════════════════════
   MODALS — backdrop blur + neumorphic box
   ═══════════════════════════════════════════════════ */
.modal-backdrop {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    align-items: center; justify-content: center; padding: 16px;
}
.modal-backdrop.show { display: flex; }

.neu-modal {
    background: #222221;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 20px;
    box-shadow: 20px 20px 50px rgba(0,0,0,.7), -8px -8px 24px rgba(255,255,255,.04),
                0 0 0 1px rgba(255,255,255,.06);
    width: 100%; max-width: 440px;
    overflow: hidden;
    animation: modalSlideUp .22s cubic-bezier(.4,0,.2,1) both;
}
@keyframes modalSlideUp {
    from { opacity:0; transform:translateY(24px); }
    to   { opacity:1; transform:translateY(0); }
}

.neu-modal-head {
    padding: 20px 24px 16px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: flex-start; justify-content: space-between;
}
.neu-modal-title { font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 3px; }
.neu-modal-sub   { font-size: 12px; color: rgba(255,255,255,.38); }
.neu-modal-close {
    background: rgba(255,255,255,.07); border: none;
    border-radius: 8px; cursor: pointer; color: rgba(255,255,255,.4);
    font-size: 16px; width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    transition: all .18s; flex-shrink: 0; margin-left: 12px;
}
.neu-modal-close:hover { background: rgba(255,255,255,.14); color: #fff; }
.neu-modal-body { padding: 22px 24px; }
.neu-modal-foot {
    padding: 16px 24px;
    border-top: 1px solid rgba(255,255,255,.07);
    display: flex; gap: 10px; justify-content: flex-end;
}

/* Package mini-row in Pay modal */
.pay-modal-pkg {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px; margin-bottom: 16px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07); border-radius: 10px;
}
.pay-modal-pkg .pm-icon {
    width: 36px; height: 36px; border-radius: 8px;
    background: rgba(59,130,246,.15); color: #93c5fd;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.pay-modal-pkg .pm-name  { font-size: 13px; font-weight: 600; color: #e2e2e0; }
.pay-modal-pkg .pm-speed { font-size: 11px; color: rgba(255,255,255,.38); margin-top: 2px; }
.pay-modal-pkg .pm-price { margin-left: auto; font-size: 15px; font-weight: 700; color: var(--green-lt); }

/* Amount pill */
.pay-amount-pill {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; margin-bottom: 20px;
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.25); border-radius: 12px;
}
.pay-amount-pill .pill-label { font-size: 12px; color: rgba(255,255,255,.45); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.pay-amount-pill .pill-amount { font-size: 24px; font-weight: 800; color: var(--green-lt); }

/* Phone field */
.phone-label {
    font-size: 12px; font-weight: 600; color: rgba(255,255,255,.45);
    text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px;
    display: block;
}
.phone-input-wrap { position: relative; margin-bottom: 6px; }
.phone-input-wrap .flag {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    font-size: 18px; pointer-events: none;
}
.phone-neu-input {
    width: 100%; padding: 13px 14px 13px 46px;
    background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1); border-radius: 10px;
    color: #fff; font-size: 17px; font-weight: 600; font-family: inherit;
    box-shadow: var(--neu-inset); letter-spacing: .5px; box-sizing: border-box;
}
.phone-neu-input::placeholder { color: rgba(255,255,255,.22); font-weight: 400; font-size: 14px; letter-spacing: 0; }
.phone-neu-input:focus { outline: none; border-color: var(--green); box-shadow: var(--neu-inset), 0 0 0 3px rgba(16,185,129,.2); }

/* Modal buttons */
.stk-confirm-btn {
    flex: 1; padding: 13px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none; border-radius: 10px;
    color: #fff; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: opacity .2s, transform .15s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 18px rgba(16,185,129,.35); font-family: inherit;
}
.stk-confirm-btn:hover:not(:disabled) { opacity: .92; transform: translateY(-1px); }
.stk-confirm-btn:disabled { opacity: .5; cursor: default; transform: none; }

.ghost-btn {
    padding: 13px 20px; background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.07); border-radius: 10px;
    color: rgba(255,255,255,.55); font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .18s; font-family: inherit;
}
.ghost-btn:hover { background: rgba(255,255,255,.12); color: #e2e2e0; }

/* STK spinner */
.stk-spinner {
    width: 52px; height: 52px;
    border: 4px solid rgba(16,185,129,.15);
    border-top-color: var(--green);
    border-radius: 50%;
    animation: spin .9s linear infinite;
    margin: 0 auto 20px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.stk-status-head { font-size: 18px; font-weight: 700; color: #e2e2e0; margin-bottom: 8px; text-align: center; }
.stk-status-sub  { font-size: 13px; color: rgba(255,255,255,.42); text-align: center; line-height: 1.55; }
</style>

<div class="pay-page">
    <h1 class="pay-page-title"><i class="fas fa-credit-card" style="color:rgba(255,255,255,.35);margin-right:10px;"></i>Payments</h1>
    <p class="pay-page-sub">Renew your subscription or top up your account balance</p>

    <div class="pay-tabs">
        <button class="pay-tab active" id="tab-pkg"   onclick="switchTab('pkg')">
            <i class="fas fa-wifi"></i> Package Payment
        </button>
        <button class="pay-tab"        id="tab-topup" onclick="switchTab('topup')">
            <i class="fas fa-plus-circle"></i> Top Up Balance
        </button>
    </div>

    <!-- ══ Package Payment Panel ══ -->
    <div class="pay-panel active" id="panel-pkg">

    <div class="pay-main-grid">
        <!-- Card 1: Summary + Primary CTA -->
        <div class="pay-card">
            <div class="pay-card-head"><i class="fas fa-receipt"></i> Payment Summary</div>
            <div class="pay-card-body">
                <?php if ($package): ?>
                <div class="pkg-row">
                    <div class="pkg-icon"><i class="fas fa-<?= ($package['connection_type'] ?? 'pppoe') === 'hotspot' ? 'wifi' : 'network-wired' ?>"></i></div>
                    <div>
                        <div class="pkg-name"><?= htmlspecialchars($package['name']) ?></div>
                        <div class="pkg-speed"><?= (int)$package['download_speed'] ?>/<?= (int)$package['upload_speed'] ?> Mbps &middot; <?= ($package['validity_value'] ?? 30) . ' ' . ($package['validity_unit'] ?? 'days') ?></div>
                    </div>
                    <div class="pkg-price">KES <?= number_format($packagePrice, 0) ?></div>
                </div>
                <?php endif; ?>

                <div class="sum-row"><span>Package Price</span><span>KES <?= number_format($packagePrice, 2) ?></span></div>
                <div class="sum-row credit"><span>Account Balance</span><span>− KES <?= number_format($accountBalance, 2) ?></span></div>
                <div class="sum-total"><span>Amount to Pay</span><span>KES <?= number_format($amountToPay, 2) ?></span></div>

                <?php if ($amountToPay <= 0): ?>
                    <div class="balance-covered">
                        <i class="fas fa-check-circle"></i>
                        <span>Your balance covers this package fully. Click <strong>Activate Now</strong> to enable your service immediately.</span>
                    </div>
                    <button class="activate-btn" onclick="activateWithBalance()">
                        <i class="fas fa-bolt"></i> Activate Now
                    </button>

                <?php elseif ($stkGwId !== null): ?>
                    <button class="pay-now-btn" onclick="openPayModal()">
                        <i class="fas fa-mobile-alt"></i>
                        Pay KES <?= number_format($amountToPay, 0) ?> via M-Pesa
                        <?php if ($stkGwId === 0): ?>
                        <span style="font-size:11px;opacity:.75;font-weight:500;"> · via FortuNett</span>
                        <?php endif; ?>
                    </button>

                <?php else: ?>
                    <div style="margin-top:16px;padding:14px 16px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;font-size:13px;color:#fcd34d;">
                        <i class="fas fa-info-circle" style="margin-right:7px;"></i>
                        Use the payment methods below or contact your ISP.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 2: Payment Methods (paybill details, bank, etc.) -->
        <div class="pay-card" style="height:auto;">
            <div class="pay-card-head"><i class="fas fa-wallet"></i> Payment Methods</div>
            <div class="pay-card-body">
                <?php if (empty($gateways)): ?>
                <div class="gw-empty">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>No payment methods configured.<br>Contact your ISP for assistance.</p>
                </div>
                <?php else: ?>
                <div class="gw-list">
                    <?php foreach ($gateways as $idx => $g):
                        $creds   = json_decode($g['credentials'], true) ?? [];
                        $gwType  = $g['gateway_type'];
                        $iconCls = in_array($gwType, ['mpesa_api','paybill_no_api']) ? 'mpesa' : ($gwType === 'bank_account' ? 'bank' : 'other');
                        $iconFa  = $gwType === 'bank_account' ? 'fa-university' : ($gwType === 'paypal' ? 'fa-paypal' : 'fa-mobile-alt');
                    ?>
                    <div class="gw-item <?= $idx === 0 ? 'open' : '' ?>" id="gw-<?= $g['id'] ?>">
                        <button class="gw-trigger" onclick="toggleGw('gw-<?= $g['id'] ?>')">
                            <div class="gw-icon <?= $iconCls ?>"><i class="fas <?= $iconFa ?>"></i></div>
                            <div>
                                <div class="gw-label"><?= htmlspecialchars($g['gateway_name']) ?></div>
                                <div class="gw-type"><?= ucwords(str_replace('_', ' ', $gwType)) ?></div>
                            </div>
                            <i class="fas fa-chevron-down gw-chevron"></i>
                        </button>
                        <div class="gw-body">
                            <?php if ($gwType === 'mpesa_api'): ?>
                                <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:14px;">
                                    <i class="fas fa-info-circle" style="margin-right:6px;color:rgba(16,185,129,.6);"></i>
                                    Click the green button above to receive an M-Pesa PIN prompt on your phone.
                                </p>
                                <button class="pay-now-btn" style="font-size:14px;padding:13px;margin-top:0;"
                                        onclick="openPayModal()">
                                    <i class="fas fa-paper-plane"></i>
                                    Send STK Push — KES <?= number_format($amountToPay, 0) ?>
                                </button>

                            <?php elseif ($gwType === 'paybill_no_api'):
                                $useGenerated = !empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] == '1';
                                $displayAcct  = $useGenerated ? ($customer['account_number'] ?? '') : ($creds['account_number'] ?? '');
                            ?>
                                <div class="pb-row">
                                    <div>
                                        <div class="pb-field">Paybill Number</div>
                                        <div class="pb-val"><?= htmlspecialchars($creds['paybill_number'] ?? 'N/A') ?></div>
                                    </div>
                                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($creds['paybill_number'] ?? '') ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                                <?php if ($displayAcct): ?>
                                <div class="pb-row <?= $useGenerated ? 'highlight' : '' ?>">
                                    <div>
                                        <div class="pb-field"><?= $useGenerated ? 'Your Account Number' : 'Account Number' ?></div>
                                        <div class="pb-val"><?= htmlspecialchars($displayAcct) ?></div>
                                        <?php if ($useGenerated): ?><div class="pb-hint">Use as M-Pesa account reference</div><?php endif; ?>
                                    </div>
                                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($displayAcct) ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($creds['instructions'])): ?>
                                <div class="gw-alert"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($creds['instructions']) ?></div>
                                <?php endif; ?>

                            <?php elseif ($gwType === 'bank_account'): ?>
                                <?php if (!empty($creds['bank_name'])): ?>
                                <div class="pb-row"><div><div class="pb-field">Bank</div><div class="pb-val" style="font-size:16px;letter-spacing:0;"><?= htmlspecialchars($creds['bank_name']) ?></div></div></div>
                                <?php endif; ?>
                                <?php if (!empty($creds['account_name'])): ?>
                                <div class="pb-row"><div><div class="pb-field">Account Name</div><div class="pb-val" style="font-size:15px;letter-spacing:0;"><?= htmlspecialchars($creds['account_name']) ?></div></div></div>
                                <?php endif; ?>
                                <?php if (!empty($creds['account_number'])): ?>
                                <div class="pb-row">
                                    <div><div class="pb-field">Account Number</div><div class="pb-val"><?= htmlspecialchars($creds['account_number']) ?></div></div>
                                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($creds['account_number']) ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($platformPaybill): ?>
                <!-- Platform paybill (FortuNett shared shortcode) -->
                <div style="margin-top:<?= empty($gateways) ? '0' : '14px' ?>;padding:14px 16px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:12px;">
                    <div style="font-size:11px;font-weight:700;color:rgba(130,120,255,.9);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">
                        <i class="fas fa-shield-alt" style="margin-right:6px;"></i>Platform Paybill — FortuNett Technologies
                    </div>
                    <div class="pb-row" style="background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.25);margin-bottom:8px;">
                        <div>
                            <div class="pb-field"><?= ($platformPaybill['shortcode_type'] ?? 'paybill') === 'till' ? 'Till Number' : 'Paybill Number' ?></div>
                            <div class="pb-val" style="color:#a5b4fc;"><?= htmlspecialchars($platformPaybill['shortcode']) ?></div>
                        </div>
                        <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($platformPaybill['shortcode']) ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                    <?php if (!empty($customer['account_number'])): ?>
                    <div class="pb-row">
                        <div>
                            <div class="pb-field">Account Reference (required)</div>
                            <div class="pb-val" style="font-size:18px;"><?= htmlspecialchars($customer['account_number']) ?></div>
                            <div class="pb-hint" style="color:rgba(255,255,255,.35);margin-top:2px;">Enter exactly as shown when prompted</div>
                        </div>
                        <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($customer['account_number']) ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.pay-main-grid -->

        <!-- Card 3: Already paid? -->
        <div class="pay-card">
            <div class="pay-card-head"><i class="fas fa-check-double"></i> Already Paid?</div>
            <div class="pay-card-body">
                <p style="font-size:13px;color:rgba(255,255,255,.38);margin-bottom:14px;">
                    If you paid via Paybill or Bank transfer, enter your M-Pesa / bank reference below to confirm.
                </p>
                <form id="verifyPaymentForm" onsubmit="handleVerify(event)">
                    <div class="confirm-row">
                        <input type="text" id="trans_code" class="confirm-input" placeholder="e.g. RJH1234567" required>
                        <button type="submit" class="confirm-btn" id="verifyBtn"><i class="fas fa-search"></i> Confirm</button>
                    </div>
                </form>
            </div>
        </div>

    </div><!-- /#panel-pkg -->

    <!-- ══ Top Up Panel ══ -->
    <div class="pay-panel" id="panel-topup">
    <div class="pay-main-grid">
        <div class="pay-card">
            <div class="pay-card-head"><i class="fas fa-wallet"></i> Account Balance</div>
            <div class="pay-card-body">
                <div class="topup-balance-strip">
                    <span>Current Balance</span>
                    <span class="bal-val">KES <?= number_format($accountBalance, 2) ?></span>
                </div>
                <div style="font-size:12px;color:rgba(255,255,255,.28);display:flex;flex-direction:column;gap:7px;">
                    <div><i class="fas fa-check-circle" style="color:rgba(16,185,129,.55);margin-right:6px;"></i> Use balance to activate any package instantly</div>
                    <div><i class="fas fa-check-circle" style="color:rgba(16,185,129,.55);margin-right:6px;"></i> Balance carries over — never expires</div>
                    <div><i class="fas fa-check-circle" style="color:rgba(16,185,129,.55);margin-right:6px;"></i> Auto-renew when balance is sufficient</div>
                </div>
            </div>
        </div>

        <div class="pay-card" style="height:auto;">
            <div class="pay-card-head"><i class="fas fa-plus-circle"></i> Add Funds</div>
            <div class="pay-card-body">
                <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Amount (KES)</div>
                <div class="topup-amount-row">
                    <input type="number" id="topup_amount" class="topup-input" placeholder="Enter amount" min="1" step="1">
                </div>
                <div class="topup-preset-row">
                    <?php foreach ([100,200,500,1000,2000,5000] as $amt): ?>
                    <button class="topup-preset" onclick="setTopupAmount(<?= $amt ?>, this)">KES <?= number_format($amt, 0) ?></button>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($gateways)): ?>
                <div class="gw-empty"><i class="fas fa-exclamation-triangle"></i><p>No payment methods configured.</p></div>
                <?php else: ?>
                <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Payment Method</div>
                <div class="gw-list">
                    <?php foreach ($gateways as $idx => $g):
                        $creds   = json_decode($g['credentials'], true) ?? [];
                        $gwType  = $g['gateway_type'];
                        $iconCls = in_array($gwType, ['mpesa_api','paybill_no_api']) ? 'mpesa' : ($gwType === 'bank_account' ? 'bank' : 'other');
                        $iconFa  = $gwType === 'bank_account' ? 'fa-university' : ($gwType === 'paypal' ? 'fa-paypal' : 'fa-mobile-alt');
                    ?>
                    <div class="gw-item <?= $idx === 0 ? 'open' : '' ?>" id="tu-gw-<?= $g['id'] ?>">
                        <button class="gw-trigger" onclick="toggleGw('tu-gw-<?= $g['id'] ?>')">
                            <div class="gw-icon <?= $iconCls ?>"><i class="fas <?= $iconFa ?>"></i></div>
                            <div>
                                <div class="gw-label"><?= htmlspecialchars($g['gateway_name']) ?></div>
                                <div class="gw-type"><?= ucwords(str_replace('_', ' ', $gwType)) ?></div>
                            </div>
                            <i class="fas fa-chevron-down gw-chevron"></i>
                        </button>
                        <div class="gw-body">
                            <?php if ($gwType === 'mpesa_api'): ?>
                                <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:12px;">Enter amount above, then send the STK Push to your phone.</p>
                                <div class="stk-row">
                                    <input type="tel" id="tu_phone_<?= $g['id'] ?>" class="stk-input"
                                           value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" placeholder="07xxxxxxxx">
                                    <button class="stk-btn" onclick="initiateTopupSTK(<?= $g['id'] ?>, this)">
                                        <i class="fas fa-paper-plane"></i> Top Up
                                    </button>
                                </div>
                            <?php elseif ($gwType === 'paybill_no_api'):
                                $useGenerated = !empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] == '1';
                                $displayAcct  = $useGenerated ? ($customer['account_number'] ?? '') : ($creds['account_number'] ?? '');
                            ?>
                                <div class="pb-row">
                                    <div><div class="pb-field">Paybill</div><div class="pb-val"><?= htmlspecialchars($creds['paybill_number'] ?? 'N/A') ?></div></div>
                                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($creds['paybill_number'] ?? '') ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                                <?php if ($displayAcct): ?>
                                <div class="pb-row <?= $useGenerated ? 'highlight' : '' ?>">
                                    <div><div class="pb-field"><?= $useGenerated ? 'Your Account Number' : 'Account Number' ?></div><div class="pb-val"><?= htmlspecialchars($displayAcct) ?></div></div>
                                    <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($displayAcct) ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="confirm-section" style="margin-top:18px;">
                    <div class="confirm-title"><i class="fas fa-check-double"></i> Already Topped Up?</div>
                    <p class="confirm-hint">Enter your M-Pesa or bank reference to confirm.</p>
                    <form onsubmit="handleTopupVerify(event)">
                        <div class="confirm-row">
                            <input type="text" id="topup_trans_code" class="confirm-input" placeholder="e.g. RJH1234567" required>
                            <button type="submit" class="confirm-btn" id="topupVerifyBtn"><i class="fas fa-search"></i> Confirm</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /.pay-main-grid topup -->
    </div><!-- /#panel-topup -->
</div>

<!-- ══ PAY MODAL ══ -->
<div class="modal-backdrop" id="payModalBackdrop">
    <div class="neu-modal">
        <div class="neu-modal-head">
            <div>
                <div class="neu-modal-title"><i class="fas fa-mobile-alt" style="color:var(--green);margin-right:8px;font-size:15px;"></i>M-Pesa STK Push</div>
                <div class="neu-modal-sub">A PIN prompt will appear on the number below</div>
            </div>
            <button class="neu-modal-close" onclick="closePayModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="neu-modal-body">
            <?php if ($package): ?>
            <div class="pay-modal-pkg">
                <div class="pm-icon"><i class="fas fa-wifi"></i></div>
                <div>
                    <div class="pm-name"><?= htmlspecialchars($package['name']) ?></div>
                    <div class="pm-speed"><?= (int)$package['download_speed'] ?>/<?= (int)$package['upload_speed'] ?> Mbps</div>
                </div>
                <div class="pm-price">KES <?= number_format($packagePrice, 0) ?></div>
            </div>
            <?php endif; ?>

            <div class="pay-amount-pill">
                <span class="pill-label">Amount Due</span>
                <span class="pill-amount">KES <?= number_format($amountToPay, 2) ?></span>
            </div>

            <!-- Phone display / edit toggle -->
            <div id="phoneDisplayRow" style="display:flex;align-items:center;justify-content:space-between;
                background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:10px;
                padding:12px 16px;margin-bottom:4px;cursor:pointer;" onclick="togglePhoneEdit()">
                <div>
                    <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Sending STK Push to</div>
                    <div id="phoneDisplayVal" style="font-size:19px;font-weight:700;color:#e2e2e0;letter-spacing:1px;font-family:monospace;">
                        <?= htmlspecialchars($customer['phone'] ?? '—') ?>
                    </div>
                </div>
                <button type="button" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);
                    border-radius:8px;padding:7px 13px;color:rgba(255,255,255,.6);font-size:12px;font-weight:600;
                    cursor:pointer;white-space:nowrap;font-family:inherit;display:flex;align-items:center;gap:5px;"
                    onclick="togglePhoneEdit(event)">
                    <i class="fas fa-pen" style="font-size:10px;"></i> Change
                </button>
            </div>

            <!-- Expandable phone edit field -->
            <div id="phoneEditRow" style="display:none;margin-bottom:4px;">
                <label class="phone-label" for="payModalPhone" style="margin-top:12px;">Edit M-Pesa Number</label>
                <div class="phone-input-wrap">
                    <span class="flag">🇰🇪</span>
                    <input type="tel" id="payModalPhone" class="phone-neu-input"
                           value="<?= htmlspecialchars($customer['phone'] ?? '') ?>"
                           placeholder="07xxxxxxxx or 2547xxxxxxxx"
                           oninput="syncPhoneDisplay(this.value)">
                </div>
                <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:5px;">
                    <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                    Enter any M-Pesa-registered number to receive the PIN prompt on that phone.
                </div>
            </div>
            <!-- Hidden input always submitted -->
            <input type="hidden" id="payModalPhoneHidden" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
        </div>
        <div class="neu-modal-foot">
            <button class="ghost-btn" onclick="closePayModal()">Cancel</button>
            <button class="stk-confirm-btn" id="payModalBtn" onclick="submitPayModal()">
                <i class="fas fa-paper-plane"></i> Send STK Push
            </button>
        </div>
    </div>
</div>

<!-- ══ STK PROCESSING MODAL ══ -->
<div class="modal-backdrop" id="stkProcessingBackdrop">
    <div class="neu-modal" style="max-width:360px;">
        <div class="neu-modal-body" style="padding:40px 24px;text-align:center;">
            <div class="stk-spinner"></div>
            <div class="stk-status-head">Processing Payment</div>
            <div class="stk-status-sub" id="stkStatus">Please check your phone for the M-Pesa PIN prompt.</div>
        </div>
    </div>
</div>

<script>
const _base      = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname)) ? '/fortunett_technologies_' : '';
const _gwId      = <?= $stkGwId !== null ? $stkGwId : 'null' ?>;
const _amountDue = <?= $amountToPay ?>;

/* ── Tabs ─────────────────────────────────────────── */
function switchTab(tab) {
    document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
}

/* ── Accordion ────────────────────────────────────── */
function toggleGw(id) { document.getElementById(id).classList.toggle('open'); }

/* ── Copy ─────────────────────────────────────────── */
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const o = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.innerHTML = o; btn.classList.remove('copied'); }, 1800);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 1800);
    });
}

/* ── Pay Modal ────────────────────────────────────── */
function openPayModal() {
    if (_gwId === null || _gwId === undefined) { showCustToast('No M-Pesa gateway configured. Contact your ISP.', 'error'); return; }
    if (_amountDue <= 0) { showCustToast('No amount due — use Activate Now.', 'info'); return; }
    // Reset to display mode (not edit mode) each time modal opens
    document.getElementById('phoneDisplayRow').style.display = 'flex';
    document.getElementById('phoneEditRow').style.display = 'none';
    document.getElementById('payModalBackdrop').classList.add('show');
}
function closePayModal() {
    document.getElementById('payModalBackdrop').classList.remove('show');
}
document.getElementById('payModalBackdrop').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

function togglePhoneEdit(e) {
    if (e) e.stopPropagation();
    const displayRow = document.getElementById('phoneDisplayRow');
    const editRow    = document.getElementById('phoneEditRow');
    const isEditing  = editRow.style.display !== 'none';
    if (isEditing) {
        // Save and collapse
        const val = document.getElementById('payModalPhone').value.trim();
        if (val) {
            document.getElementById('payModalPhoneHidden').value = val;
            document.getElementById('phoneDisplayVal').textContent = val;
        }
        displayRow.style.display = 'flex';
        editRow.style.display    = 'none';
    } else {
        // Expand edit field
        displayRow.style.display = 'none';
        editRow.style.display    = 'block';
        setTimeout(() => document.getElementById('payModalPhone').focus(), 60);
    }
}

function syncPhoneDisplay(val) {
    document.getElementById('payModalPhoneHidden').value = val;
}

function submitPayModal() {
    // Use hidden input (always in sync with display or edit field)
    let phone = document.getElementById('payModalPhoneHidden').value.trim();
    // Fall back to edit field if somehow hidden is empty
    if (!phone) phone = (document.getElementById('payModalPhone').value || '').trim();
    if (!phone) {
        document.getElementById('phoneDisplayRow').style.display = 'none';
        document.getElementById('phoneEditRow').style.display = 'block';
        document.getElementById('payModalPhone').focus();
        return;
    }
    const btn = document.getElementById('payModalBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

    const fd = new FormData();
    fd.append('gateway_id', _gwId);
    fd.append('phone', phone);
    fd.append('amount', _amountDue);

    fetch(_base + '/customer/api/initiate_stk.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        closePayModal();
        if (data.success) {
            document.getElementById('stkStatus').textContent = 'Prompt sent! Enter your M-Pesa PIN on your phone.';
            document.getElementById('stkProcessingBackdrop').classList.add('show');
            pollStatus(data.checkout_id);
        } else {
            showCustToast(data.message || 'STK Push failed. Please try again.', 'error');
        }
    })
    .catch(() => {
        closePayModal();
        showCustToast('Connection error. Please try again.', 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-mobile-alt"></i> Send STK Push';
    });
}

/* ── Poll payment status ──────────────────────────── */
function pollStatus(checkoutId) {
    const interval = setInterval(() => {
        fetch(_base + '/customer/api/check_status.php?checkout_id=' + checkoutId)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'paid') {
                clearInterval(interval);
                document.getElementById('stkStatus').textContent = 'Payment received! Activating your service…';
                setTimeout(() => window.location.href = 'dashboard.php?payment=success', 2000);
            } else if (data.status === 'failed') {
                clearInterval(interval);
                document.getElementById('stkProcessingBackdrop').classList.remove('show');
                showCustToast('Payment failed or was cancelled.', 'error');
            }
        }).catch(() => {});
    }, 3000);
    setTimeout(() => {
        clearInterval(interval);
        document.getElementById('stkProcessingBackdrop').classList.remove('show');
        showCustToast('Payment timed out. Check your M-Pesa messages and use "Already Paid?" below if you completed it.', 'warning');
    }, 180000);
}

/* ── Topup STK ────────────────────────────────────── */
function initiateTopupSTK(gatewayId, btn) {
    const amount = parseFloat(document.getElementById('topup_amount').value);
    if (!amount || amount < 1) {
        showCustToast('Please enter a valid amount.', 'error');
        document.getElementById('topup_amount').focus(); return;
    }
    const phoneInput = document.getElementById('tu_phone_' + gatewayId);
    const phone = phoneInput ? phoneInput.value.trim() : '';
    if (!phone) { phoneInput && phoneInput.focus(); return; }

    document.getElementById('stkStatus').textContent = 'Initiating Top-Up via M-Pesa…';
    document.getElementById('stkProcessingBackdrop').classList.add('show');

    const fd = new FormData();
    fd.append('gateway_id', gatewayId);
    fd.append('phone', phone);
    fd.append('amount', amount);
    fd.append('type', 'topup');

    fetch(_base + '/customer/api/initiate_stk.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('stkStatus').textContent = 'Prompt sent! Enter your PIN on your phone.';
            pollTopupStatus(data.checkout_id);
        } else {
            document.getElementById('stkProcessingBackdrop').classList.remove('show');
            showCustToast(data.message || 'STK Push failed.', 'error');
        }
    })
    .catch(() => {
        document.getElementById('stkProcessingBackdrop').classList.remove('show');
        showCustToast('Connection error.', 'error');
    });
}

function pollTopupStatus(checkoutId) {
    const interval = setInterval(() => {
        fetch(_base + '/customer/api/check_status.php?checkout_id=' + checkoutId)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'paid') {
                clearInterval(interval);
                document.getElementById('stkStatus').textContent = 'Balance topped up! Refreshing…';
                setTimeout(() => window.location.href = 'payment.php?topup=success', 2000);
            } else if (data.status === 'failed') {
                clearInterval(interval);
                document.getElementById('stkProcessingBackdrop').classList.remove('show');
                showCustToast('Payment failed or was cancelled.', 'error');
            }
        }).catch(() => {});
    }, 3000);
}

/* ── Manual verify ────────────────────────────────── */
function handleVerify(e) {
    e.preventDefault();
    const code = document.getElementById('trans_code').value.trim();
    const btn  = document.getElementById('verifyBtn');
    if (!code) return;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';
    const fd = new FormData();
    fd.append('code', code);
    fetch(_base + '/api/customer/verify_manual_payment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustToast('Transaction submitted! Your account will activate once verified.', 'success');
            setTimeout(() => window.location.href = 'dashboard.php', 2200);
        } else {
            showCustToast(data.message || 'Verification failed.', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
        }
    })
    .catch(() => {
        showCustToast('Connection error.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
    });
}

function handleTopupVerify(e) {
    e.preventDefault();
    const amount = parseFloat(document.getElementById('topup_amount').value);
    if (!amount || amount < 1) {
        showCustToast('Please enter the top-up amount first.', 'error');
        document.getElementById('topup_amount').focus(); return;
    }
    const code = document.getElementById('topup_trans_code').value.trim();
    const btn  = document.getElementById('topupVerifyBtn');
    if (!code) return;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';
    const fd = new FormData();
    fd.append('code', code); fd.append('amount', amount); fd.append('type', 'topup');
    fetch(_base + '/api/customer/verify_manual_payment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustToast('Top-up submitted! Balance updates once verified.', 'success');
            setTimeout(() => window.location.href = 'payment.php?topup=submitted', 2200);
        } else {
            showCustToast(data.message || 'Verification failed.', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
        }
    })
    .catch(() => {
        showCustToast('Connection error.', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
    });
}

/* ── Preset amounts ───────────────────────────────── */
function setTopupAmount(amt, btn) {
    document.getElementById('topup_amount').value = amt;
    document.querySelectorAll('.topup-preset').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
}

/* ── Activate with balance ────────────────────────── */
function activateWithBalance() {
    fetch(_base + '/customer/api/activate.php', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.success) window.location.href = 'dashboard.php?activation=success';
        else showCustToast(data.message || 'Activation failed.', 'error');
    })
    .catch(() => showCustToast('Connection error.', 'error'));
}

/* ── URL auto-actions ─────────────────────────────── */
(function() {
    const p = new URLSearchParams(location.search);
    if (p.has('topup')) {
        switchTab('topup');
        if (p.get('topup') === 'success') showCustToast('Balance topped up successfully!', 'success');
        else if (p.get('topup') === 'submitted') showCustToast('Top-up submitted for verification.', 'info');
    }
    if (p.get('payment') === 'success') showCustToast('Payment successful!', 'success');
})();
</script>

<?php include 'includes/footer.php'; ?>
