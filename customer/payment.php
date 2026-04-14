<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

// Resolve tenant_id from the client's own record
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

// Base path helper (same logic used site-wide)
$_base = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false
       || preg_match('/^\d+\.\d+\./', $_SERVER['HTTP_HOST'] ?? ''))
       ? '/fortunett_technologies_' : '';

include 'includes/header.php';
?>

<style>
/* ── Page shell ─────────────────────────────────────── */
.pay-page { padding: 28px 32px; max-width: 1100px; }
.pay-page-title   { font-size: 26px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px; }
.pay-page-sub     { font-size: 14px; color: rgba(255,255,255,.4); margin: 0 0 28px; }

/* ── Layout ─────────────────────────────────────────── */
.pay-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: start;
}
@media(max-width:860px){ .pay-grid{ grid-template-columns:1fr; } }

/* ── Dark card ──────────────────────────────────────── */
.pay-card {
    background: #222221;
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03);
    overflow: hidden;
    margin-bottom: 20px;
}
.pay-card-head {
    padding: 18px 22px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 15px;
    font-weight: 600;
    color: #e2e2e0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pay-card-head i { color: rgba(255,255,255,.4); font-size: 14px; }
.pay-card-body  { padding: 22px; }

/* ── Summary ────────────────────────────────────────── */
.pkg-row {
    display: flex;
    align-items: center;
    gap: 14px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 18px;
}
.pkg-icon {
    width: 44px; height: 44px;
    background: rgba(59,130,246,.15);
    color: #93c5fd;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.pkg-name  { font-weight: 600; color: #e2e2e0; font-size: 14px; }
.pkg-speed { font-size: 12px; color: rgba(255,255,255,.4); margin-top: 2px; }
.pkg-price { margin-left: auto; font-size: 20px; font-weight: 700; color: #6ee7b7; white-space: nowrap; }

.sum-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    padding: 9px 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
    color: rgba(255,255,255,.55);
}
.sum-row:last-child { border-bottom: none; }
.sum-row span:last-child { color: #e2e2e0; font-weight: 500; }
.sum-row.credit span:last-child { color: #6ee7b7; }
.sum-total {
    display: flex;
    justify-content: space-between;
    font-size: 18px;
    font-weight: 700;
    padding: 14px 0 0;
    margin-top: 6px;
    border-top: 1px solid rgba(255,255,255,.1);
    color: #fff;
}

.balance-covered {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.25);
    border-radius: 10px;
    font-size: 13px;
    color: #6ee7b7;
}

/* ── Payment method tabs ────────────────────────────── */
.gw-list   { display: flex; flex-direction: column; gap: 10px; }
.gw-item   {
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    overflow: hidden;
    transition: border-color .2s;
}
.gw-item.open { border-color: rgba(255,255,255,.18); }

.gw-trigger {
    width: 100%;
    background: rgba(255,255,255,.04);
    border: none;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    text-align: left;
    transition: background .18s;
}
.gw-trigger:hover { background: rgba(255,255,255,.07); }
.gw-icon {
    width: 38px; height: 38px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.gw-icon.mpesa { background: rgba(16,185,129,.15); color: #6ee7b7; }
.gw-icon.bank  { background: rgba(59,130,246,.15); color: #93c5fd; }
.gw-icon.other { background: rgba(99,102,241,.15); color: #a5b4fc; }

.gw-label { font-size: 14px; font-weight: 600; color: #e2e2e0; }
.gw-type  { font-size: 11px; color: rgba(255,255,255,.35); margin-top: 1px; }
.gw-chevron { margin-left: auto; color: rgba(255,255,255,.3); font-size: 12px; transition: transform .2s; }
.gw-item.open .gw-chevron { transform: rotate(180deg); }

.gw-body {
    display: none;
    padding: 18px;
    background: rgba(0,0,0,.2);
    border-top: 1px solid rgba(255,255,255,.06);
}
.gw-item.open .gw-body { display: block; }

/* ── Paybill display ────────────────────────────────── */
.pb-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 10px;
}
.pb-row:last-child { margin-bottom: 0; }
.pb-field { font-size: 10px; font-weight: 600; color: rgba(255,255,255,.35); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.pb-val   { font-size: 22px; font-weight: 800; color: #e2e2e0; letter-spacing: 2px; }
.pb-row.highlight { background: rgba(99,102,241,.1); border-color: rgba(99,102,241,.25); }
.pb-row.highlight .pb-val { color: #a5b4fc; }
.pb-hint  { font-size: 11px; color: rgba(99,102,241,.7); margin-top: 2px; }
.copy-btn {
    padding: 6px 12px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    color: rgba(255,255,255,.6);
    font-size: 12px;
    cursor: pointer;
    transition: all .18s;
    white-space: nowrap;
    flex-shrink: 0;
}
.copy-btn:hover { background: rgba(255,255,255,.14); color: #fff; }
.copy-btn.copied { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.35); color: #6ee7b7; }

/* ── STK Push input ─────────────────────────────────── */
.stk-row { display: flex; gap: 8px; }
.stk-input {
    flex: 1;
    padding: 10px 14px;
    background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    color: #e2e2e0;
    font-size: 14px;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,.35), inset -1px -1px 3px rgba(255,255,255,.03);
}
.stk-input::placeholder { color: rgba(255,255,255,.25); }
.stk-input:focus { outline: none; border-color: rgba(16,185,129,.5); }
.stk-btn {
    padding: 10px 18px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    border-radius: 8px;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .2s, transform .15s;
    white-space: nowrap;
}
.stk-btn:hover { opacity: .9; transform: translateY(-1px); }

/* ── Instructions alert ─────────────────────────────── */
.gw-alert {
    margin-top: 12px;
    padding: 10px 14px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.25);
    border-radius: 8px;
    font-size: 12px;
    color: #fcd34d;
}

/* ── Manual confirm form ────────────────────────────── */
.confirm-section {
    margin-top: 20px;
    padding: 18px;
    background: rgba(255,255,255,.03);
    border: 1px dashed rgba(255,255,255,.1);
    border-radius: 10px;
}
.confirm-title {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255,255,255,.65);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.confirm-hint { font-size: 12px; color: rgba(255,255,255,.3); margin-bottom: 12px; }
.confirm-row  { display: flex; gap: 8px; }
.confirm-input {
    flex: 1;
    padding: 10px 14px;
    background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    color: #e2e2e0;
    font-size: 14px;
    font-family: monospace;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,.35), inset -1px -1px 3px rgba(255,255,255,.03);
}
.confirm-input::placeholder { color: rgba(255,255,255,.2); font-family: inherit; }
.confirm-input:focus { outline: none; border-color: rgba(255,255,255,.25); }
.confirm-btn {
    padding: 10px 18px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 8px;
    color: #e2e2e0;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all .18s;
    white-space: nowrap;
}
.confirm-btn:hover { background: rgba(255,255,255,.13); }

/* ── Activate button ────────────────────────────────── */
.activate-btn {
    width: 100%;
    margin-top: 18px;
    padding: 13px;
    background: linear-gradient(135deg, rgba(16,185,129,.2) 0%, rgba(16,185,129,.1) 100%);
    border: 1px solid rgba(16,185,129,.35);
    border-radius: 10px;
    color: #6ee7b7;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    display: <?php echo ($amountToPay > 0) ? 'none' : 'flex'; ?>;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.activate-btn:hover { background: rgba(16,185,129,.25); transform: translateY(-1px); }

/* ── No gateways empty state ────────────────────────── */
.gw-empty {
    text-align: center;
    padding: 48px 20px;
    color: rgba(255,255,255,.3);
}
.gw-empty i { font-size: 40px; margin-bottom: 12px; display: block; color: rgba(245,158,11,.5); }
.gw-empty p { font-size: 14px; }

/* ── Page tabs ──────────────────────────────────────────────────── */
.pay-tabs {
    display: flex;
    gap: 4px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 50px;
    padding: 4px;
    margin-bottom: 28px;
    width: fit-content;
}
.pay-tab {
    padding: 9px 24px;
    border-radius: 40px;
    border: none;
    background: transparent;
    color: rgba(255,255,255,.45);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 7px;
}
.pay-tab.active {
    background: #222221;
    color: #e2e2e0;
    box-shadow: 4px 4px 10px rgba(0,0,0,.4), -2px -2px 6px rgba(255,255,255,.04);
}
.pay-tab i { font-size: 12px; }
.pay-panel { display: none; }
.pay-panel.active { display: block; }

/* ── Top-up panel ───────────────────────────────────────────────── */
.topup-amount-row { display: flex; gap: 8px; margin-bottom: 16px; }
.topup-input {
    flex: 1;
    padding: 12px 16px;
    background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px;
    color: #e2e2e0;
    font-size: 20px;
    font-weight: 700;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,.35), inset -1px -1px 3px rgba(255,255,255,.03);
    font-family: inherit;
}
.topup-input::placeholder { color: rgba(255,255,255,.2); font-size: 15px; font-weight: 400; }
.topup-input:focus { outline: none; border-color: rgba(59,130,246,.5); }
.topup-preset-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.topup-preset {
    padding: 6px 14px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 20px;
    color: rgba(255,255,255,.65);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all .18s;
    font-family: inherit;
}
.topup-preset:hover { background: rgba(255,255,255,.13); color: #fff; }
.topup-preset.selected { background: rgba(59,130,246,.2); border-color: rgba(59,130,246,.4); color: #93c5fd; }
.topup-balance-strip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: rgba(16,185,129,.08);
    border: 1px solid rgba(16,185,129,.2);
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 13px;
    color: rgba(255,255,255,.55);
}
.topup-balance-strip .bal-val { font-size: 18px; font-weight: 700; color: #6ee7b7; }

/* ── STK processing modal ───────────────────────────── */
.stk-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.7);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.stk-overlay.show { display: flex; }
.stk-modal {
    background: #222221;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 16px;
    padding: 36px 32px;
    text-align: center;
    max-width: 360px;
    width: 90%;
    box-shadow: 0 24px 64px rgba(0,0,0,.6);
}
.stk-spinner {
    width: 52px; height: 52px;
    border: 4px solid rgba(16,185,129,.15);
    border-top-color: #10b981;
    border-radius: 50%;
    animation: spin .9s linear infinite;
    margin: 0 auto 18px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.stk-modal h4  { color: #e2e2e0; font-size: 18px; font-weight: 700; margin-bottom: 8px; }
.stk-modal p   { color: rgba(255,255,255,.45); font-size: 14px; }
</style>

<div class="pay-page">
    <h1 class="pay-page-title"><i class="fas fa-credit-card" style="color:rgba(255,255,255,.4);margin-right:10px;"></i>Payments</h1>
    <p class="pay-page-sub">Manage payments and top up your account balance</p>

    <!-- Tab switcher -->
    <div class="pay-tabs">
        <button class="pay-tab active" id="tab-pkg" onclick="switchTab('pkg')">
            <i class="fas fa-wifi"></i> Package Payment
        </button>
        <button class="pay-tab" id="tab-topup" onclick="switchTab('topup')">
            <i class="fas fa-plus-circle"></i> Top Up Balance
        </button>
    </div>

    <!-- ══ Panel: Package Payment ══ -->
    <div class="pay-panel active" id="panel-pkg">
    <div class="pay-grid">

        <!-- ── Left: Summary ──────────────────────────── -->
        <div>
            <div class="pay-card">
                <div class="pay-card-head"><i class="fas fa-receipt"></i> Payment Summary</div>
                <div class="pay-card-body">
                    <?php if ($package): ?>
                    <div class="pkg-row">
                        <div class="pkg-icon"><i class="fas fa-wifi"></i></div>
                        <div>
                            <div class="pkg-name"><?php echo htmlspecialchars($package['name']); ?></div>
                            <div class="pkg-speed"><?php echo (int)$package['download_speed']; ?>/<?php echo (int)$package['upload_speed']; ?> Mbps &middot; <?php echo ($package['validity_value'] ?? 30) . ' ' . ($package['validity_unit'] ?? 'days'); ?></div>
                        </div>
                        <div class="pkg-price">KES <?php echo number_format($packagePrice, 0); ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="sum-row"><span>Package Price</span><span>KES <?php echo number_format($packagePrice, 2); ?></span></div>
                    <div class="sum-row credit"><span>Account Balance</span><span>− KES <?php echo number_format($accountBalance, 2); ?></span></div>
                    <div class="sum-total"><span>Amount to Pay</span><span>KES <?php echo number_format($amountToPay, 2); ?></span></div>
                </div>
            </div>

            <?php if ($amountToPay <= 0): ?>
            <div class="balance-covered">
                <i class="fas fa-check-circle" style="font-size:18px;flex-shrink:0;"></i>
                <span>Your account balance covers this package. Click <strong>Activate Now</strong> below.</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Right: Payment Methods ─────────────────── -->
        <div>
            <div class="pay-card">
                <div class="pay-card-head"><i class="fas fa-wallet"></i> Choose Payment Method</div>
                <div class="pay-card-body">

                    <?php if (empty($gateways)): ?>
                    <div class="gw-empty">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>No payment methods configured.<br>Please contact your ISP for assistance.</p>
                    </div>
                    <?php else: ?>
                    <div class="gw-list">
                        <?php foreach ($gateways as $idx => $g):
                            $creds    = json_decode($g['credentials'], true) ?? [];
                            $isFirst  = $idx === 0;
                            $gwType   = $g['gateway_type'];
                            $iconCls  = in_array($gwType, ['mpesa_api','paybill_no_api']) ? 'mpesa' : ($gwType === 'bank_account' ? 'bank' : 'other');
                            $iconFa   = $gwType === 'bank_account' ? 'fa-university' : ($gwType === 'paypal' ? 'fa-paypal' : 'fa-mobile-alt');
                        ?>
                        <div class="gw-item <?php echo $isFirst ? 'open' : ''; ?>" id="gw-<?php echo $g['id']; ?>">
                            <button class="gw-trigger" onclick="toggleGw('gw-<?php echo $g['id']; ?>')">
                                <div class="gw-icon <?php echo $iconCls; ?>"><i class="fas <?php echo $iconFa; ?>"></i></div>
                                <div>
                                    <div class="gw-label"><?php echo htmlspecialchars($g['gateway_name']); ?></div>
                                    <div class="gw-type"><?php echo ucwords(str_replace('_', ' ', $gwType)); ?></div>
                                </div>
                                <i class="fas fa-chevron-down gw-chevron"></i>
                            </button>
                            <div class="gw-body">
                                <?php if ($gwType === 'mpesa_api'): ?>
                                    <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:12px;">Pay instantly via M-Pesa STK Push. Your phone will receive a PIN prompt.</p>
                                    <div class="stk-row">
                                        <input type="tel" id="stk_phone_<?php echo $g['id']; ?>" class="stk-input"
                                               value="<?php echo htmlspecialchars($customer['phone']); ?>" placeholder="07xxxxxxxx">
                                        <button class="stk-btn" onclick="initiateSTK(<?php echo $g['id']; ?>, <?php echo $amountToPay; ?>, this)">
                                            <i class="fas fa-paper-plane"></i> Pay KES <?php echo number_format($amountToPay, 0); ?>
                                        </button>
                                    </div>

                                <?php elseif ($gwType === 'paybill_no_api'):
                                    $useGenerated = !empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] == '1';
                                    $displayAcct  = $useGenerated ? ($customer['account_number'] ?? '') : ($creds['account_number'] ?? '');
                                ?>
                                    <div class="pb-row">
                                        <div>
                                            <div class="pb-field">Business Number (Paybill)</div>
                                            <div class="pb-val"><?php echo htmlspecialchars($creds['paybill_number'] ?? 'N/A'); ?></div>
                                        </div>
                                        <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($creds['paybill_number'] ?? ''); ?>', this)">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                    <?php if ($displayAcct): ?>
                                    <div class="pb-row <?php echo $useGenerated ? 'highlight' : ''; ?>">
                                        <div>
                                            <div class="pb-field"><?php echo $useGenerated ? '🎯 Your Unique Account Number' : 'Account Number'; ?></div>
                                            <div class="pb-val"><?php echo htmlspecialchars($displayAcct); ?></div>
                                            <?php if ($useGenerated): ?><div class="pb-hint">Use this as your M-Pesa account reference</div><?php endif; ?>
                                        </div>
                                        <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($displayAcct); ?>', this)">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($creds['instructions'])): ?>
                                    <div class="gw-alert"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($creds['instructions']); ?></div>
                                    <?php endif; ?>

                                <?php elseif ($gwType === 'bank_account'): ?>
                                    <div class="pb-row">
                                        <div>
                                            <div class="pb-field">Bank</div>
                                            <div class="pb-val" style="font-size:16px;letter-spacing:0;"><?php echo htmlspecialchars($creds['bank_name'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                    <div class="pb-row">
                                        <div>
                                            <div class="pb-field">Account Name</div>
                                            <div class="pb-val" style="font-size:15px;letter-spacing:0;"><?php echo htmlspecialchars($creds['account_name'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                    <div class="pb-row">
                                        <div>
                                            <div class="pb-field">Account Number</div>
                                            <div class="pb-val"><?php echo htmlspecialchars($creds['account_number'] ?? ''); ?></div>
                                        </div>
                                        <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($creds['account_number'] ?? ''); ?>', this)">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                    <?php if (!empty($creds['paybill_number'])): ?>
                                    <div class="pb-row">
                                        <div>
                                            <div class="pb-field">Bank Paybill</div>
                                            <div class="pb-val"><?php echo htmlspecialchars($creds['paybill_number']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Manual confirmation -->
                    <div class="confirm-section">
                        <div class="confirm-title"><i class="fas fa-check-double"></i> Already Paid? Confirm Transaction</div>
                        <p class="confirm-hint">If you paid via Paybill or Bank transfer, enter your M-Pesa / bank reference code here.</p>
                        <form id="verifyPaymentForm" onsubmit="handleVerify(event)">
                            <div class="confirm-row">
                                <input type="text" id="trans_code" class="confirm-input" placeholder="e.g. RJH1234567" required>
                                <button type="submit" class="confirm-btn" id="verifyBtn">
                                    <i class="fas fa-search"></i> Confirm
                                </button>
                            </div>
                        </form>
                    </div>

                    <button id="activateBtn" class="activate-btn" onclick="activateWithBalance()">
                        <i class="fas fa-bolt"></i> Activate Now (balance covers package)
                    </button>

                </div>
            </div>
        </div>
    </div>
    </div><!-- /#panel-pkg -->

    <!-- ══ Panel: Top Up Balance ══ -->
    <div class="pay-panel" id="panel-topup">
        <div class="pay-grid">
            <!-- Left: Current balance info -->
            <div>
                <div class="pay-card">
                    <div class="pay-card-head"><i class="fas fa-wallet"></i> Account Balance</div>
                    <div class="pay-card-body">
                        <div class="topup-balance-strip">
                            <span>Current Balance</span>
                            <span class="bal-val">KES <?php echo number_format($accountBalance, 2); ?></span>
                        </div>
                        <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:16px;">
                            Top up your balance to pay for packages later or cover renewal costs automatically.
                        </p>
                        <div style="font-size:12px;color:rgba(255,255,255,.3);">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><i class="fas fa-check-circle" style="color:rgba(16,185,129,.6);"></i> Use balance to activate any package instantly</div>
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><i class="fas fa-check-circle" style="color:rgba(16,185,129,.6);"></i> Balance carries over — never expires</div>
                            <div style="display:flex;align-items:center;gap:6px;"><i class="fas fa-check-circle" style="color:rgba(16,185,129,.6);"></i> Auto-renew when balance is sufficient</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Top-up form -->
            <div>
                <div class="pay-card">
                    <div class="pay-card-head"><i class="fas fa-plus-circle"></i> Add Funds</div>
                    <div class="pay-card-body">
                        <!-- Amount input -->
                        <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Amount (KES)</div>
                        <div class="topup-amount-row">
                            <input type="number" id="topup_amount" class="topup-input" placeholder="Enter amount" min="1" step="1">
                        </div>
                        <div class="topup-preset-row">
                            <?php foreach ([100,200,500,1000,2000,5000] as $amt): ?>
                            <button class="topup-preset" onclick="setTopupAmount(<?php echo $amt; ?>)">KES <?php echo number_format($amt, 0); ?></button>
                            <?php endforeach; ?>
                        </div>

                        <?php if (empty($gateways)): ?>
                        <div class="gw-empty">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No payment methods configured.<br>Please contact your ISP for assistance.</p>
                        </div>
                        <?php else: ?>

                        <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Choose Payment Method</div>
                        <div class="gw-list">
                            <?php foreach ($gateways as $idx => $g):
                                $creds   = json_decode($g['credentials'], true) ?? [];
                                $gwType  = $g['gateway_type'];
                                $iconCls = in_array($gwType, ['mpesa_api','paybill_no_api']) ? 'mpesa' : ($gwType === 'bank_account' ? 'bank' : 'other');
                                $iconFa  = $gwType === 'bank_account' ? 'fa-university' : ($gwType === 'paypal' ? 'fa-paypal' : 'fa-mobile-alt');
                            ?>
                            <div class="gw-item <?php echo $idx === 0 ? 'open' : ''; ?>" id="tu-gw-<?php echo $g['id']; ?>">
                                <button class="gw-trigger" onclick="toggleGw('tu-gw-<?php echo $g['id']; ?>')">
                                    <div class="gw-icon <?php echo $iconCls; ?>"><i class="fas <?php echo $iconFa; ?>"></i></div>
                                    <div>
                                        <div class="gw-label"><?php echo htmlspecialchars($g['gateway_name']); ?></div>
                                        <div class="gw-type"><?php echo ucwords(str_replace('_', ' ', $gwType)); ?></div>
                                    </div>
                                    <i class="fas fa-chevron-down gw-chevron"></i>
                                </button>
                                <div class="gw-body">
                                    <?php if ($gwType === 'mpesa_api'): ?>
                                        <p style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:12px;">Pay via M-Pesa STK Push. Your phone will receive a PIN prompt.</p>
                                        <div class="stk-row">
                                            <input type="tel" id="tu_phone_<?php echo $g['id']; ?>" class="stk-input"
                                                   value="<?php echo htmlspecialchars($customer['phone']); ?>" placeholder="07xxxxxxxx">
                                            <button class="stk-btn" onclick="initiateTopupSTK(<?php echo $g['id']; ?>, this)">
                                                <i class="fas fa-paper-plane"></i> Top Up
                                            </button>
                                        </div>

                                    <?php elseif ($gwType === 'paybill_no_api'):
                                        $useGenerated = !empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] == '1';
                                        $displayAcct  = $useGenerated ? ($customer['account_number'] ?? '') : ($creds['account_number'] ?? '');
                                    ?>
                                        <p style="font-size:12px;color:rgba(255,255,255,.35);margin-bottom:12px;">Send the amount you entered above via M-Pesa to:</p>
                                        <div class="pb-row">
                                            <div>
                                                <div class="pb-field">Business Number (Paybill)</div>
                                                <div class="pb-val"><?php echo htmlspecialchars($creds['paybill_number'] ?? 'N/A'); ?></div>
                                            </div>
                                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($creds['paybill_number'] ?? ''); ?>', this)">
                                                <i class="fas fa-copy"></i> Copy
                                            </button>
                                        </div>
                                        <?php if ($displayAcct): ?>
                                        <div class="pb-row <?php echo $useGenerated ? 'highlight' : ''; ?>">
                                            <div>
                                                <div class="pb-field"><?php echo $useGenerated ? '🎯 Your Account Number' : 'Account Number'; ?></div>
                                                <div class="pb-val"><?php echo htmlspecialchars($displayAcct); ?></div>
                                                <?php if ($useGenerated): ?><div class="pb-hint">Use as M-Pesa account reference</div><?php endif; ?>
                                            </div>
                                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($displayAcct); ?>', this)">
                                                <i class="fas fa-copy"></i> Copy
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($creds['instructions'])): ?>
                                        <div class="gw-alert"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($creds['instructions']); ?></div>
                                        <?php endif; ?>

                                    <?php elseif ($gwType === 'bank_account'): ?>
                                        <div class="pb-row">
                                            <div><div class="pb-field">Bank</div><div class="pb-val" style="font-size:16px;letter-spacing:0;"><?php echo htmlspecialchars($creds['bank_name'] ?? ''); ?></div></div>
                                        </div>
                                        <div class="pb-row">
                                            <div><div class="pb-field">Account Name</div><div class="pb-val" style="font-size:15px;letter-spacing:0;"><?php echo htmlspecialchars($creds['account_name'] ?? ''); ?></div></div>
                                        </div>
                                        <div class="pb-row">
                                            <div><div class="pb-field">Account Number</div><div class="pb-val"><?php echo htmlspecialchars($creds['account_number'] ?? ''); ?></div></div>
                                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($creds['account_number'] ?? ''); ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Manual top-up confirmation -->
                        <div class="confirm-section" style="margin-top:16px;">
                            <div class="confirm-title"><i class="fas fa-check-double"></i> Already Paid? Confirm Top-Up</div>
                            <p class="confirm-hint">Enter your M-Pesa or bank reference code to confirm the top-up.</p>
                            <form onsubmit="handleTopupVerify(event)">
                                <div class="confirm-row">
                                    <input type="text" id="topup_trans_code" class="confirm-input" placeholder="e.g. RJH1234567" required>
                                    <button type="submit" class="confirm-btn" id="topupVerifyBtn">
                                        <i class="fas fa-search"></i> Confirm
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /#panel-topup -->
</div>

<!-- STK Processing Overlay -->
<div class="stk-overlay" id="stkOverlay">
    <div class="stk-modal">
        <div class="stk-spinner"></div>
        <h4>Processing Payment</h4>
        <p id="stkStatus">Please check your phone for the M-Pesa PIN prompt.</p>
    </div>
</div>

<script>
const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
    ? '/fortunett_technologies_' : '';

/* ── Accordion ─────────────────────────────────────── */
function toggleGw(id) {
    const el = document.getElementById(id);
    el.classList.toggle('open');
}

/* ── Copy to clipboard ─────────────────────────────── */
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('copied'); }, 1800);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.add('copied');
        setTimeout(() => { btn.innerHTML = btn.getAttribute('data-orig') || '<i class="fas fa-copy"></i> Copy'; btn.classList.remove('copied'); }, 1800);
    });
}

/* ── STK Push ──────────────────────────────────────── */
function initiateSTK(gatewayId, amount, btn) {
    const phoneInput = document.getElementById('stk_phone_' + gatewayId);
    const phone = phoneInput ? phoneInput.value.trim() : '';
    if (!phone) { phoneInput && phoneInput.focus(); return; }

    const overlay = document.getElementById('stkOverlay');
    overlay.classList.add('show');
    document.getElementById('stkStatus').textContent = 'Initiating STK Push…';

    const fd = new FormData();
    fd.append('gateway_id', gatewayId);
    fd.append('phone', phone);
    fd.append('amount', amount);

    fetch(_base + '/customer/api/initiate_stk.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('stkStatus').textContent = 'Prompt sent! Enter your PIN on your phone.';
            pollStatus(data.checkout_id);
        } else {
            overlay.classList.remove('show');
            showCustToast(data.message || 'STK Push failed. Please try again.', 'error');
        }
    })
    .catch(() => {
        overlay.classList.remove('show');
        showCustToast('Connection error. Please try again.', 'error');
    });
}

function pollStatus(checkoutId) {
    const overlay = document.getElementById('stkOverlay');
    const interval = setInterval(() => {
        fetch(_base + '/customer/api/check_status.php?checkout_id=' + checkoutId)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'paid') {
                clearInterval(interval);
                document.getElementById('stkStatus').textContent = 'Payment received! Activating…';
                setTimeout(() => window.location.href = 'dashboard.php?payment=success', 2000);
            } else if (data.status === 'failed') {
                clearInterval(interval);
                overlay.classList.remove('show');
                showCustToast('Payment failed or was cancelled.', 'error');
            }
        })
        .catch(() => {}); // keep polling silently
    }, 3000);
}

/* ── Manual verify ─────────────────────────────────── */
function handleVerify(e) {
    e.preventDefault();
    const code = document.getElementById('trans_code').value.trim();
    const btn  = document.getElementById('verifyBtn');
    if (!code) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';

    const fd = new FormData();
    fd.append('code', code);
    fetch(_base + '/api/customer/verify_manual_payment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustToast('Transaction submitted! Your account will be activated once verified.', 'success');
            setTimeout(() => window.location.href = 'dashboard.php', 2200);
        } else {
            showCustToast(data.message || 'Verification failed.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
        }
    })
    .catch(() => {
        showCustToast('Connection error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
    });
}

/* ── Tab switching ─────────────────────────────────── */
function switchTab(tab) {
    document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
}

/* ── Top-up preset amounts ─────────────────────────── */
function setTopupAmount(amt) {
    document.getElementById('topup_amount').value = amt;
    document.querySelectorAll('.topup-preset').forEach(b => b.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

/* ── Top-up STK Push ───────────────────────────────── */
function initiateTopupSTK(gatewayId, btn) {
    const amount = parseFloat(document.getElementById('topup_amount').value);
    if (!amount || amount < 1) {
        showCustToast('Please enter a valid amount to top up.', 'error');
        document.getElementById('topup_amount').focus();
        return;
    }
    const phoneInput = document.getElementById('tu_phone_' + gatewayId);
    const phone = phoneInput ? phoneInput.value.trim() : '';
    if (!phone) { phoneInput && phoneInput.focus(); return; }

    const overlay = document.getElementById('stkOverlay');
    overlay.classList.add('show');
    document.getElementById('stkStatus').textContent = 'Initiating Top-Up via M-Pesa…';

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
            overlay.classList.remove('show');
            showCustToast(data.message || 'STK Push failed. Please try again.', 'error');
        }
    })
    .catch(() => {
        overlay.classList.remove('show');
        showCustToast('Connection error. Please try again.', 'error');
    });
}

function pollTopupStatus(checkoutId) {
    const overlay = document.getElementById('stkOverlay');
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
                overlay.classList.remove('show');
                showCustToast('Payment failed or was cancelled.', 'error');
            }
        })
        .catch(() => {});
    }, 3000);
}

/* ── Manual top-up confirm ─────────────────────────── */
function handleTopupVerify(e) {
    e.preventDefault();
    const amount = parseFloat(document.getElementById('topup_amount').value);
    if (!amount || amount < 1) {
        showCustToast('Please enter the top-up amount first.', 'error');
        document.getElementById('topup_amount').focus();
        return;
    }
    const code = document.getElementById('topup_trans_code').value.trim();
    const btn  = document.getElementById('topupVerifyBtn');
    if (!code) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying…';

    const fd = new FormData();
    fd.append('code', code);
    fd.append('amount', amount);
    fd.append('type', 'topup');
    fetch(_base + '/api/customer/verify_manual_payment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustToast('Top-up submitted! Your balance will be updated once verified.', 'success');
            setTimeout(() => window.location.href = 'payment.php?topup=submitted', 2200);
        } else {
            showCustToast(data.message || 'Verification failed.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
        }
    })
    .catch(() => {
        showCustToast('Connection error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search"></i> Confirm';
    });
}

// Auto-switch to top-up tab if redirected after top-up
(function() {
    const p = new URLSearchParams(location.search);
    if (p.has('topup')) {
        switchTab('topup');
        if (p.get('topup') === 'success') showCustToast('Balance topped up successfully!', 'success');
        else if (p.get('topup') === 'submitted') showCustToast('Top-up submitted for verification.', 'info');
    }
    if (p.get('payment') === 'success') showCustToast('Payment successful!', 'success');
})();

/* ── Activate with balance ─────────────────────────── */
function activateWithBalance() {
    fetch(_base + '/customer/api/activate.php', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.success) window.location.href = 'dashboard.php?activation=success';
        else showCustToast(data.message || 'Activation failed.', 'error');
    })
    .catch(() => showCustToast('Connection error.', 'error'));
}
</script>

<?php include 'includes/footer.php'; ?>
