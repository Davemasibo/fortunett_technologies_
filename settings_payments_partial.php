<?php
/**
 * Payment Gateway Settings — Consistent UI partial
 * Included from settings.php within the Payments tab
 */
?>

<style>
/* ── Gateway section layout ─────────────────────────────────────── */
.pg-section-title {
    font-size: 15px; font-weight: 700; color: #e2e2e0;
    display: flex; align-items: center; gap: 8px; margin-bottom: 16px;
}
.pg-section-title i { color: var(--primary-color); }
.pg-section-sub { font-size: 12px; font-weight: 400; color: #9a9a95; margin-left: 4px; }

/* ── Add/Edit form card ──────────────────────────────────────────── */
.pg-form-card {
    background: rgb(34,34,33);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 28px;
    box-shadow: 14px 14px 28px rgba(0,0,0,.5), -7px -7px 18px rgba(255,255,255,.035);
}
.pg-form-header {
    padding: 18px 24px;
    border-bottom: 1px solid #E5E7EB;
    background: linear-gradient(135deg, var(--primary-dark, #2C5282) 0%, var(--primary-color, #3B6EA5) 100%);
    display: flex; align-items: center; justify-content: space-between;
}
.pg-form-header .title { font-size: 15px; font-weight: 700; color: #fff; }
.pg-form-header .subtitle { font-size: 12px; color: rgba(255,255,255,.8); margin-top: 2px; }
.pg-form-body { padding: 24px; }

/* ── Type selector ───────────────────────────────────────────────── */
.type-selector-wrap {
    background: rgb(26,26,25);
    border-radius: 12px;
    padding: 18px 16px 14px;
    margin-bottom: 20px;
    box-shadow: inset 0 2px 8px rgba(0,0,0,.35);
    border: 1px solid rgba(255,255,255,.05);
}
.type-selector-label {
    font-size: 11px; font-weight: 700; color: rgba(255,255,255,.4);
    text-transform: uppercase; letter-spacing: .08em; margin-bottom: 12px;
    display: flex; align-items: center; gap: 7px;
}
.type-selector-label i { font-size: 12px; color: rgba(255,255,255,.25); }
.type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; }
.type-card {
    background: rgb(34,34,33);
    border: 2px solid rgba(255,255,255,.07);
    border-radius: 12px;
    padding: 18px 12px 14px;
    cursor: pointer;
    text-align: center;
    transition: all .22s;
    box-shadow: 5px 5px 12px rgb(10,10,9), -3px -3px 8px rgb(44,44,42);
    position: relative;
    overflow: hidden;
}
.type-card::before {
    content: '';
    position: absolute; inset: 0; border-radius: 11px;
    background: linear-gradient(135deg, rgba(255,255,255,.04) 0%, transparent 60%);
    pointer-events: none;
}
.type-card:hover {
    border-color: var(--primary-color, #3B6EA5);
    box-shadow: 5px 5px 12px rgb(10,10,9), -3px -3px 8px rgb(44,44,42),
                0 0 0 3px rgba(59,110,165,.18);
    transform: translateY(-3px);
}
.type-card.selected {
    border-color: var(--primary-color, #3B6EA5);
    background: rgba(59,110,165,.2);
    box-shadow: 5px 5px 14px rgb(10,10,9), -3px -3px 9px rgb(44,44,42),
                0 0 0 3px rgba(59,110,165,.3),
                inset 0 0 20px rgba(59,110,165,.12);
    transform: translateY(-2px);
}
.type-card.selected::after {
    content: '\f00c';
    font-family: 'Font Awesome 5 Free'; font-weight: 900;
    position: absolute; top: 7px; right: 9px;
    font-size: 10px; color: var(--primary-color, #3B6EA5);
}
.type-card .type-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 10px; font-size: 19px;
    box-shadow: 3px 3px 8px rgba(0,0,0,.4);
}
.type-card .type-name { font-size: 13px; font-weight: 700; color: rgba(255,255,255,.88); }
.type-card .type-desc { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 3px; }

/* ── Field sections ──────────────────────────────────────────────── */
.field-section {
    background: rgb(26,26,25); border-radius: 10px; padding: 18px;
    border: 1px solid rgba(255,255,255,.06); margin-bottom: 16px; display: none;
}
.field-section.active { display: block; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.field-row.full { grid-template-columns: 1fr; }
.form-label-sm {
    font-size: 11px; font-weight: 600; color: #9a9a95;
    text-transform: uppercase; letter-spacing: .05em;
    display: block; margin-bottom: 5px;
}
.form-input {
    width: 100%; padding: 9px 12px;
    border: 1px solid rgba(255,255,255,.08); border-radius: 7px;
    font-size: 13.5px; background: rgb(27,27,26); color: #e2e2e0;
    box-shadow: inset 3px 3px 8px rgba(0,0,0,.55), inset -2px -2px 5px rgba(255,255,255,.05);
    transition: border-color .15s;
    box-sizing: border-box; font-family: inherit;
}
.form-input::placeholder { color: rgba(255,255,255,.3); }
.form-input:focus { outline: none; border-color: var(--primary-color); box-shadow: inset 3px 3px 8px rgba(0,0,0,.55), 0 0 0 3px rgba(59,110,165,.2); color: #fff; }
.secret-wrap { position: relative; }
.secret-wrap .form-input { padding-right: 38px; }
.secret-wrap .secret-toggle {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: rgba(255,255,255,.4); font-size: 14px;
    padding: 2px 4px;
}
.secret-wrap .secret-toggle:hover { color: #e2e2e0; }

/* ── Auto-account toggle ─────────────────────────────────────────── */
.toggle-row {
    display: flex; align-items: center; gap: 12px; padding: 11px 12px;
    background: rgba(59,110,165,.06); border-radius: 8px;
    border: 1px solid rgba(59,110,165,.15); margin-bottom: 14px;
}
.toggle-switch { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: rgba(255,255,255,.15); border-radius: 22px; cursor: pointer; transition: .25s; }
.toggle-slider:before {
    content: ''; position: absolute; height: 16px; width: 16px;
    left: 3px; bottom: 3px; background: rgba(255,255,255,.9); border-radius: 50%; transition: .25s;
}
input:checked + .toggle-slider { background: var(--primary-color); }
input:checked + .toggle-slider:before { transform: translateX(18px); }
.toggle-label { font-size: 12.5px; color: #e2e2e0; }
.toggle-label strong { display: block; font-weight: 600; margin-bottom: 1px; }
.toggle-label span { font-size: 11.5px; color: #9a9a95; }

/* ── Save / Reset buttons ────────────────────────────────────────── */
.pg-form-actions { display: flex; gap: 10px; align-items: center; margin-top: 4px; }
.pg-save-btn {
    background: linear-gradient(135deg, var(--primary-dark, #2C5282) 0%, var(--primary-color, #3B6EA5) 100%);
    color: #fff; border: none; border-radius: 8px;
    padding: 11px 22px; font-size: 13.5px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 7px; transition: opacity .2s, transform .15s;
}
.pg-save-btn:hover { opacity: .9; transform: translateY(-1px); }
.pg-reset-btn {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); border-radius: 8px;
    padding: 10px 18px; font-size: 13.5px; color: #9a9a95; cursor: pointer;
    transition: all .15s;
}
.pg-reset-btn:hover { background: rgba(255,255,255,.12); color: #e2e2e0; }

/* ── Configured gateways grid — always 2-col ────────────────────── */
.gateways-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
@media(max-width: 860px) { .gateways-grid { grid-template-columns: 1fr; } }
.gw-card {
    background: rgb(30,30,29); border: 1px solid rgba(255,255,255,.07); border-radius: 12px;
    overflow: hidden; transition: box-shadow .2s, border-color .2s;
    box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.025);
}
.gw-card:hover { box-shadow: 10px 10px 24px rgba(0,0,0,.5), -4px -4px 10px rgba(255,255,255,.03); border-color: rgba(255,255,255,.13); }
.gw-card-header { padding: 14px 16px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,.06); }
.gw-icon { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.gw-icon.mpesa   { background: rgba(52,211,153,.15); color: #6ee7b7; }
.gw-icon.paybill { background: rgba(251,191,36,.15);  color: #fcd34d; }
.gw-icon.bank    { background: rgba(96,165,250,.15);  color: #93c5fd; }
.gw-icon.paypal  { background: rgba(167,139,250,.15); color: #c4b5fd; }
.gw-name { font-weight: 700; font-size: 14px; color: #e2e2e0; }
.gw-type { font-size: 11px; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }
.gw-badge { margin-left: auto; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.gw-badge.active   { background: rgba(52,211,153,.15); color: #6ee7b7; border: 1px solid rgba(52,211,153,.25); }
.gw-badge.inactive { background: rgba(156,163,175,.1); color: rgba(255,255,255,.4); border: 1px solid rgba(255,255,255,.1); }
.gw-body { padding: 12px 16px; }
.gw-detail { display: flex; justify-content: space-between; align-items: center; margin-bottom: 7px; font-size: 12.5px; }
.gw-detail-label { color: rgba(255,255,255,.4); }
.gw-detail-value { font-weight: 600; color: #e2e2e0; font-family: monospace; font-size: 13px; }
.gw-feature { display: inline-flex; align-items: center; gap: 4px; background: rgba(59,110,165,.12); color: #93c5fd; font-size: 11px; padding: 3px 8px; border-radius: 20px; margin-top: 4px; }

/* ── Active toggle ───────────────────────────────────────────────── */
.gw-toggle-row { display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: rgba(255,255,255,.03); border-top: 1px solid rgba(255,255,255,.06); }
.gw-toggle-label { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.7); flex: 1; }
.gw-toggle-sub   { font-size: 11px; color: rgba(255,255,255,.35); }
.live-toggle { position: relative; width: 42px; height: 24px; flex-shrink: 0; display: inline-block; vertical-align: middle; }
.live-toggle input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
.live-slider { position: absolute; inset: 0; background: rgba(255,255,255,.18); border-radius: 24px; cursor: pointer; transition: background .25s; }
.live-slider:before { content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: rgba(255,255,255,.9); border-radius: 50%; transition: transform .25s; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
input:checked + .live-slider { background: #10B981; }
input:checked + .live-slider:before { transform: translateX(18px); }
.live-toggle-text { font-size: 11.5px; font-weight: 700; min-width: 28px; }
.live-toggle-text.on  { color: #34d399; }
.live-toggle-text.off { color: rgba(255,255,255,.35); }
@media(max-width: 860px) {
    .gateways-grid { grid-template-columns: 1fr; }
}

/* ── Gateway action buttons ──────────────────────────────────────── */
.gw-footer { padding: 10px 14px; display: flex; gap: 7px; border-top: 1px solid rgba(255,255,255,.06); background: rgba(255,255,255,.02); }
.gw-btn {
    padding: 7px 13px; border-radius: 7px; font-size: 12.5px; font-weight: 500;
    cursor: pointer; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.06); color: rgba(255,255,255,.7);
    display: inline-flex; align-items: center; gap: 5px; transition: all .15s;
}
.gw-btn:hover  { background: rgba(255,255,255,.12); color: #e2e2e0; }
.gw-btn.test   { color: #34d399; border-color: rgba(52,211,153,.3); }
.gw-btn.test:hover { background: rgba(52,211,153,.1); }
.gw-btn.danger { color: #f87171; border-color: rgba(248,113,113,.3); }
.gw-btn.danger:hover { background: rgba(248,113,113,.1); }

/* ── Empty state / test result ───────────────────────────────────── */
.pg-empty { text-align: center; padding: 40px; color: rgba(255,255,255,.4); }
.pg-empty i { font-size: 44px; margin-bottom: 12px; opacity: .35; display: block; }
.test-result {
    display: none; margin: 8px 0 0; padding: 9px 12px; border-radius: 7px;
    font-size: 12.5px; font-weight: 500;
}
.test-result.success { background: rgba(52,211,153,.12); color: #6ee7b7; border: 1px solid rgba(52,211,153,.25); }
.test-result.error   { background: rgba(248,113,113,.12); color: #fca5a5; border: 1px solid rgba(248,113,113,.25); }

/* ── Section divider ─────────────────────────────────────────────── */
.pg-divider { border: none; border-top: 1px solid rgba(255,255,255,.08); margin: 20px 0; }

/* ── Preserve-notice ─────────────────────────────────────────────── */
.preserve-note { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 4px; }

/* ── Credential saved/not-set badges ────────────────────────────── */
.cred-badge {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 10px; font-weight: 700; padding: 1px 7px;
    border-radius: 20px; margin-left: 6px;
    text-transform: none; letter-spacing: 0;
}
.cred-badge.saved  { background: rgba(16,185,129,.18); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
.cred-badge.notset { background: rgba(239,68,68,.18);  color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
.cred-badge.warn   { background: rgba(245,158,11,.18); color: #fcd34d; border: 1px solid rgba(245,158,11,.25); }
</style>

<div>
    <!-- ─── Add / Edit Form ─── -->
    <div class="pg-form-card">
        <div class="pg-form-header">
            <div>
                <div class="title" id="pgFormTitle">
                    <i class="fas fa-plus-circle"></i> Add New Payment Gateway
                </div>
                <div class="subtitle">Choose a type below and fill in the details</div>
            </div>
            <button type="button" onclick="resetGatewayForm()" class="pg-reset-btn" style="color:rgba(255,255,255,.8);border-color:rgba(255,255,255,.3);background:rgba(255,255,255,.1);">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="pg-form-body">
            <form method="POST" id="gatewayForm">
                <input type="hidden" name="action" value="save_gateway">
                <input type="hidden" name="gateway_id" id="gateway_id">

                <!-- Type Selector -->
                <input type="hidden" name="gateway_type" id="gateway_type" required>
                <div class="type-selector-wrap">
                    <div class="type-selector-label">
                        <i class="fas fa-th-large"></i>
                        Gateway Type <span style="color:#EF4444;font-size:13px;">*</span>
                        &nbsp;— choose a type below
                    </div>
                    <div class="type-grid">
                        <div class="type-card" data-type="paybill_no_api" onclick="selectType('paybill_no_api', this)">
                            <div class="type-icon" style="background:rgba(254,243,199,.15);color:#F59E0B;"><i class="fas fa-mobile-alt"></i></div>
                            <div class="type-name">Paybill</div>
                            <div class="type-desc">No API keys needed</div>
                        </div>
                        <div class="type-card" data-type="mpesa_api" onclick="selectType('mpesa_api', this)">
                            <div class="type-icon" style="background:rgba(209,250,229,.12);color:#34D399;"><i class="fas fa-bolt"></i></div>
                            <div class="type-name">M-Pesa API</div>
                            <div class="type-desc">STK Push auto-confirm</div>
                        </div>
                        <div class="type-card" data-type="bank_account" onclick="selectType('bank_account', this)">
                            <div class="type-icon" style="background:rgba(219,234,254,.12);color:#60A5FA;"><i class="fas fa-university"></i></div>
                            <div class="type-name">Bank Account</div>
                            <div class="type-desc">Bank transfer</div>
                        </div>
                        <div class="type-card" data-type="paypal" onclick="selectType('paypal', this)">
                            <div class="type-icon" style="background:rgba(237,233,254,.12);color:#A78BFA;"><i class="fab fa-paypal"></i></div>
                            <div class="type-name">PayPal</div>
                            <div class="type-desc">Online payments</div>
                        </div>
                    </div>
                </div>

                <!-- Gateway Display Name — shown after type is chosen -->
                <div id="gatewayNameWrap" class="field-row full" style="display:none;margin-bottom:18px;">
                    <div>
                        <label class="form-label-sm">Gateway Display Name <span style="color:#EF4444">*</span></label>
                        <input type="text" name="gateway_name" id="gateway_name"
                               placeholder="e.g. Main M-Pesa, KCB Paybill…"
                               class="form-input">
                    </div>
                </div>

                <!-- Paybill Fields -->
                <div id="fields_paybill_no_api" class="field-section">
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Paybill / Till Number</label>
                            <input type="text" name="paybill_number" id="paybill_number" class="form-input" placeholder="e.g. 400200">
                        </div>
                        <div>
                            <label class="form-label-sm">Default Account Number (fallback)</label>
                            <input type="text" name="account_number" id="account_number" class="form-input" placeholder="e.g. ISP payments">
                        </div>
                    </div>
                    <div class="toggle-row">
                        <label class="toggle-switch">
                            <input type="checkbox" name="use_generated_accounts" id="use_generated_accounts" value="1" onchange="onToggleAutoAccounts(this)">
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong>🎯 Auto-assign unique account numbers per customer</strong>
                            <span>Each customer gets a unique code (e.g. B001) to use as their paybill account number</span>
                        </div>
                    </div>
                    <div class="field-row full">
                        <div>
                            <label class="form-label-sm">Payment Instructions (shown to customer)</label>
                            <textarea name="instructions" id="instructions" class="form-input" rows="3"
                                placeholder="e.g. Go to M-Pesa › Lipa na M-Pesa › Pay Bill › Enter number above…"></textarea>
                        </div>
                    </div>
                </div>

                <!-- M-Pesa API Fields -->
                <div id="fields_mpesa_api" class="field-section">
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">
                                Consumer Key
                                <span id="badge_consumer_key" class="cred-badge" style="display:none;"></span>
                            </label>
                            <input type="text" name="mpesa_consumer_key" id="mpesa_consumer_key" class="form-input" placeholder="Daraja consumer key" autocomplete="off">
                        </div>
                        <div>
                            <label class="form-label-sm">
                                Consumer Secret
                                <span id="badge_consumer_secret" class="cred-badge" style="display:none;"></span>
                            </label>
                            <div class="secret-wrap">
                                <input type="password" name="mpesa_consumer_secret" id="mpesa_consumer_secret" class="form-input" autocomplete="new-password">
                                <button type="button" class="secret-toggle" onclick="toggleSecret('mpesa_consumer_secret',this)" title="Show/hide"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">
                                Passkey <span style="font-weight:400;text-transform:none;letter-spacing:0;">(Lipa na M-Pesa Online)</span>
                                <span id="badge_passkey" class="cred-badge" style="display:none;"></span>
                            </label>
                            <div class="secret-wrap">
                                <input type="password" name="mpesa_passkey" id="mpesa_passkey" class="form-input" autocomplete="new-password">
                                <button type="button" class="secret-toggle" onclick="toggleSecret('mpesa_passkey',this)" title="Show/hide"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="preserve-note" style="margin-top:4px;color:#fcd34d;">
                                <i class="fas fa-info-circle" style="font-size:10px;"></i>
                                Production passkey found in Daraja portal → Your App → <strong>Lipa Na M-Pesa Online</strong>
                            </div>
                        </div>
                        <div>
                            <label class="form-label-sm" id="lbl_mpesa_shortcode">Paybill Number</label>
                            <input type="text" name="mpesa_shortcode" id="mpesa_shortcode" class="form-input" placeholder="e.g. 4524255">
                            <div id="hint_mpesa_shortcode" style="margin-top:4px;font-size:11px;color:#9CA3AF;display:none;">
                                This is the <strong style="color:#fcd34d;">Till Number</strong> — the number customers pay to (shown on their M-Pesa prompt). Get it from M-Pesa Org Portal → Tills.
                            </div>
                        </div>
                        <div>
                            <label class="form-label-sm">Shortcode Type</label>
                            <select name="mpesa_shortcode_type" id="mpesa_shortcode_type" class="form-input" onchange="onMpesaShortcodeTypeChange(this)">
                                <option value="paybill">Paybill (CustomerPayBillOnline)</option>
                                <option value="till">Till / Buy Goods (CustomerBuyGoodsOnline)</option>
                            </select>
                        </div>
                        <div id="wrap_store_number">
                            <label class="form-label-sm" id="lbl_store_number">Store / Head-Office Number <span style="font-weight:400;color:#9CA3AF;">(Till only)</span></label>
                            <input type="text" name="mpesa_store_number" id="mpesa_store_number" class="form-input" placeholder="From Safaricom Org Portal">
                            <div id="hint_store_number" style="margin-top:4px;font-size:11px;color:#9CA3AF;display:none;">
                                The <strong style="color:#fcd34d;">Store / Head-Office Number</strong> used for password hashing — found in M-Pesa Org Portal under your organization shortcode. <strong style="color:#f87171;">Required for Till</strong> — leaving blank will send the wrong PartyB to Safaricom.
                            </div>
                        </div>
                    </div>
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Environment</label>
                            <select name="mpesa_env" id="mpesa_env" class="form-input" onchange="onMpesaEnvChange(this)">
                                <option value="sandbox">Sandbox (Testing — no real prompts)</option>
                                <option value="production">Production (Live — real phone prompts)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label-sm">Callback URL <span style="font-weight:400;color:#9CA3AF;">(auto-detected if blank)</span></label>
                            <input type="url" name="mpesa_callback_url" id="mpesa_callback_url" class="form-input"
                                   placeholder="https://yourdomain.com/api/payment/callback.php">
                            <div class="preserve-note" id="mpesa_cb_hint" style="display:block;">
                                <i class="fas fa-info-circle" style="font-size:10px;color:var(--primary-color);"></i>
                                Must be a public HTTPS URL — localhost won't work with real M-Pesa
                            </div>
                        </div>
                    </div>

                    <!-- Sandbox warning — redesigned dark neumorphic -->
                    <div id="sandbox-warning" style="display:none;margin-top:8px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.35);border-radius:10px;padding:14px 16px;">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <div style="width:34px;height:34px;background:rgba(245,158,11,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-flask" style="color:#fcd34d;font-size:15px;"></i>
                            </div>
                            <div>
                                <div style="font-size:13px;font-weight:700;color:#fcd34d;margin-bottom:4px;">Sandbox Mode — No real prompts will be sent</div>
                                <div style="font-size:12px;color:rgba(252,211,77,.8);line-height:1.6;">
                                    STK Push requests will succeed technically but <strong style="color:#fcd34d;">no phone prompt will appear on any real device.</strong>
                                    This is for testing API connectivity only.<br>
                                    Switch to <strong style="color:#fcd34d;">Production</strong> and enter your actual Safaricom Daraja credentials to push real prompts to customers.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Production confirmation banner -->
                    <div id="production-confirm" style="display:none;margin-top:8px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;">
                        <i class="fas fa-check-circle" style="color:#34D399;font-size:16px;flex-shrink:0;"></i>
                        <div style="font-size:12px;color:#6ee7b7;line-height:1.5;">
                            <strong>Production mode</strong> — real STK Push prompts will be sent to customer phones.
                            Make sure your passkey matches the <strong>production</strong> passkey from Daraja, not the sandbox one.
                        </div>
                    </div>
                </div>

                <!-- Bank Account Fields -->
                <div id="fields_bank_account" class="field-section">
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Bank Name</label>
                            <input type="text" name="bank_name" id="bank_name" class="form-input" placeholder="e.g. KCB Bank">
                        </div>
                        <div>
                            <label class="form-label-sm">Account Name</label>
                            <input type="text" name="bank_account_name" id="bank_account_name" class="form-input" placeholder="e.g. Best ISP Ltd">
                        </div>
                    </div>
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Account Number</label>
                            <input type="text" name="bank_account_number" id="bank_account_number" class="form-input" placeholder="e.g. 1234567890">
                        </div>
                        <div>
                            <label class="form-label-sm">Bank Paybill (optional)</label>
                            <input type="text" name="bank_paybill" id="bank_paybill" class="form-input" placeholder="e.g. 522522">
                        </div>
                    </div>
                </div>

                <!-- PayPal Fields -->
                <div id="fields_paypal" class="field-section">
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Client ID</label>
                            <input type="text" name="paypal_client_id" id="paypal_client_id" class="form-input" placeholder="PayPal Client ID">
                        </div>
                        <div>
                            <label class="form-label-sm">Client Secret</label>
                            <div class="secret-wrap">
                                <input type="password" name="paypal_client_secret" id="paypal_client_secret" class="form-input" placeholder="Leave blank to keep existing" autocomplete="new-password">
                                <button type="button" class="secret-toggle" onclick="toggleSecret('paypal_client_secret',this)" title="Show/hide"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="form-label-sm">Mode</label>
                        <select name="paypal_mode" id="paypal_mode" class="form-input">
                            <option value="sandbox">Sandbox (Testing)</option>
                            <option value="live">Live</option>
                        </select>
                    </div>
                </div>

                <hr class="pg-divider">

                <div class="pg-form-actions">
                    <button type="submit" class="pg-save-btn">
                        <i class="fas fa-save"></i> <span id="pgSaveBtnText">Save Gateway</span>
                    </button>
                    <button type="button" onclick="resetGatewayForm()" class="pg-reset-btn">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ─── Configured Gateways ─── -->
    <div class="pg-section-title">
        <i class="fas fa-list-ul"></i>
        Configured Payment Methods
        <span class="pg-section-sub">Shown to customers on the payment page</span>
    </div>

    <?php
    // Compute $hasMpesaApi here so it's available for the platform paybill display below
    // (it's also re-used later in the Platform Info Panel section)
    if (!isset($hasMpesaApi)) {
        $hasMpesaApi = false;
        foreach ($gateways as $gw) {
            if ($gw['gateway_type'] === 'mpesa_api' && $gw['is_active']) { $hasMpesaApi = true; break; }
        }
    }
    // Show Platform Shared Paybill as a default read-only gateway when no tenant M-Pesa API is configured
    $hasPlatformShortcode = !empty($platformShortcodeDB) || defined('MPESA_SHORTCODE');
    $platformPaybillNum = $platformShortcodeDB ?? (defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : null);
    $showPlatToggle = ($tSettings['show_platform_paybill'] ?? '1') !== '0';
    if (!$hasMpesaApi && $hasPlatformShortcode && $showPlatToggle):
    ?>
    <div class="gateways-grid" style="margin-bottom:16px;">
        <div class="gw-card" style="border-color:var(--primary-color,#3B6EA5);box-shadow:0 0 0 2px rgba(59,110,165,.18);">
            <div class="gw-card-header">
                <div class="gw-icon mpesa" style="position:relative;">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="gw-name">Platform M-Pesa Paybill</div>
                    <div class="gw-type">Shared STK Push via FortuNett</div>
                    <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(59,110,165,.15);color:var(--primary-color,#3B6EA5);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-top:4px;letter-spacing:.03em;">
                        <i class="fas fa-shield-alt" style="font-size:9px;"></i> FortuNett Managed &mdash; DEFAULT
                    </div>
                </div>
                <span class="gw-badge active">● Active</span>
            </div>
            <div class="gw-body">
                <div class="gw-detail">
                    <span class="gw-detail-label">Paybill No.</span>
                    <span class="gw-detail-value"><?= htmlspecialchars($platformPaybillNum) ?></span>
                </div>
                <div class="gw-detail">
                    <span class="gw-detail-label">Your Account Prefix</span>
                    <span class="gw-detail-value" style="color:var(--primary-color,#3B6EA5);font-family:monospace;"><?= htmlspecialchars($accountPrefix ?? '?') ?></span>
                </div>
                <div class="gw-detail">
                    <span class="gw-detail-label">Mode</span>
                    <span class="gw-detail-value" style="color:#10b981;">Customers pay &rarr; FortuNett collects &rarr; Weekly disbursement to you</span>
                </div>
            </div>
            <div class="gw-toggle-row" style="background:rgba(59,110,165,.06);border-top:1px solid rgba(59,110,165,.15);">
                <div>
                    <div class="gw-toggle-label" style="color:#93c5fd;">Platform-managed gateway</div>
                    <div class="gw-toggle-sub">Add your own M-Pesa API above to collect directly to your shortcode</div>
                </div>
                <span style="font-size:11px;color:rgba(255,255,255,.3);padding:4px 10px;background:rgba(255,255,255,.05);border-radius:20px;">Read-only</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasPlatformShortcode): ?>
    <!-- Toggle: show/hide platform paybill on customer portal — visible regardless of own M-Pesa status -->
    <form method="POST" style="margin-bottom:16px;" id="platformPaybillToggleForm">
        <input type="hidden" name="action" value="save_platform_paybill_toggle">
        <?php $showPlatTgl = $showPlatToggle; ?>
        <div style="background:#222221;border:1px solid <?= $hasMpesaApi ? 'rgba(99,102,241,.3)' : 'rgba(255,255,255,.07)' ?>;border-radius:10px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div style="display:flex;align-items:flex-start;gap:12px;flex:1;">
                <div style="width:36px;height:36px;background:rgba(99,102,241,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-shield-alt" style="color:#a5b4fc;font-size:15px;"></i>
                </div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:#e2e2e0;margin-bottom:3px;">
                        Show FortuNett platform paybill on customer portal
                        <?php if ($hasMpesaApi): ?>
                        <span style="font-size:10px;background:rgba(99,102,241,.15);color:#a5b4fc;padding:2px 7px;border-radius:20px;margin-left:6px;font-weight:700;">You have own M-Pesa</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:rgba(255,255,255,.38);">
                        <?php if ($hasMpesaApi): ?>
                        Your customers currently see both your M-Pesa gateway <em>and</em> the FortuNett platform paybill. Turn this off to hide the platform option and only show your own shortcode.
                        <?php else: ?>
                        Customers see the FortuNett platform shortcode as a payment option. Disable once you add your own M-Pesa API to collect directly.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="flex-shrink:0;display:flex;align-items:center;gap:10px;">
                <span style="font-size:12px;font-weight:600;color:<?= $showPlatTgl ? '#34d399' : 'rgba(255,255,255,.35)' ?>;" id="platToggleLabel">
                    <?= $showPlatTgl ? 'ON' : 'OFF' ?>
                </span>
                <label class="live-toggle" style="cursor:pointer;" title="Toggle platform paybill visibility">
                    <input type="checkbox" name="show_platform_paybill" id="showPlatformPaybill"
                           <?= $showPlatTgl ? 'checked' : '' ?>
                           onchange="document.getElementById('platToggleLabel').textContent=this.checked?'ON':'OFF';document.getElementById('platToggleLabel').style.color=this.checked?'#34d399':'rgba(255,255,255,.35)';this.form.submit()">
                    <span class="live-slider"></span>
                </label>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if (empty($gateways) && !(!$hasMpesaApi && $hasPlatformShortcode)): ?>
    <div class="pg-empty">
        <i class="fas fa-credit-card"></i>
        <p style="font-size:14px;font-weight:600;color:#374151;margin-bottom:4px;">No payment gateways yet</p>
        <p style="font-size:13px;">Add a gateway above to start accepting payments.</p>
    </div>
    <?php elseif (!empty($gateways)): ?>
    <div class="gateways-grid">
        <?php foreach ($gateways as $g):
            $creds = is_array($g['credentials']) ? $g['credentials'] : (json_decode($g['credentials'], true) ?? []);
            $iconClass = match($g['gateway_type']) {
                'mpesa_api'      => 'mpesa',
                'paybill_no_api' => 'paybill',
                'bank_account'   => 'bank',
                'paypal'         => 'paypal',
                default          => 'paybill'
            };
            $icon = match($g['gateway_type']) {
                'mpesa_api'      => 'fa-bolt',
                'paybill_no_api' => 'fa-mobile-alt',
                'bank_account'   => 'fa-university',
                'paypal'         => 'fa-paypal',
                default          => 'fa-credit-card'
            };
            $typeLabel = match($g['gateway_type']) {
                'mpesa_api'      => 'M-Pesa API (STK Push)',
                'paybill_no_api' => 'Paybill — Manual',
                'bank_account'   => 'Bank Transfer',
                'paypal'         => 'PayPal',
                default          => $g['gateway_type']
            };
        ?>
        <div class="gw-card" id="gw-card-<?= $g['id'] ?>" style="<?= $g['is_default'] ? 'border-color:var(--primary-color,#3B6EA5);box-shadow:0 0 0 2px rgba(59,110,165,.15);' : '' ?>">
            <div class="gw-card-header">
                <div class="gw-icon <?= $iconClass ?>">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="gw-name"><?= htmlspecialchars($g['gateway_name']) ?></div>
                    <div class="gw-type"><?= $typeLabel ?></div>
                    <?php if ($g['is_default']): ?>
                    <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(59,110,165,.1);color:var(--primary-dark,#2C5282);font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;margin-top:3px;letter-spacing:.03em;">
                        <i class="fas fa-star" style="font-size:9px;"></i> DEFAULT
                    </div>
                    <?php endif; ?>
                </div>
                <span class="gw-badge <?= $g['is_active'] ? 'active' : 'inactive' ?>" id="gw-badge-<?= $g['id'] ?>">
                    <?= $g['is_active'] ? '● Active' : '○ Inactive' ?>
                </span>
            </div>
            <div class="gw-body">
                <?php if ($g['gateway_type'] === 'paybill_no_api'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Paybill No.</span>
                        <span class="gw-detail-value"><?= htmlspecialchars($creds['paybill_number'] ?? '—') ?></span>
                    </div>
                    <?php if (!empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] === '1'): ?>
                        <div class="gw-feature"><i class="fas fa-magic"></i> Auto account numbers ON</div>
                    <?php endif; ?>
                <?php elseif ($g['gateway_type'] === 'mpesa_api'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Shortcode</span>
                        <span class="gw-detail-value"><?= htmlspecialchars($creds['shortcode'] ?? '—') ?></span>
                    </div>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Type</span>
                        <span class="gw-detail-value" style="font-family:inherit;"><?= ($creds['shortcode_type'] ?? 'paybill') === 'till' ? 'Till / Buy Goods' : 'Paybill' ?></span>
                    </div>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Environment</span>
                        <span class="gw-detail-value" style="font-family:inherit;color:<?= ($creds['environment'] ?? '') === 'production' ? '#059669' : '#D97706' ?>;">
                            <?= ($creds['environment'] ?? '') === 'production' ? '● Production' : '◌ Sandbox' ?>
                        </span>
                    </div>
                    <!-- Credential status row -->
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.05);">
                        <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.28);text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px;">Credentials</div>
                        <div style="display:flex;flex-direction:column;gap:5px;">
                            <?php
                            $ck = $creds['consumer_key'] ?? '';
                            $cs = $creds['consumer_secret'] ?? '';
                            $pk = $creds['passkey'] ?? '';
                            $credRows = [
                                ['Consumer Key',    $ck, strlen($ck) > 8  ? substr($ck,0,4).'…'.substr($ck,-4)   : $ck],
                                ['Consumer Secret', $cs, strlen($cs) > 8  ? '••••••••'.substr($cs,-4)             : ($cs ? '••••••••' : '')],
                                ['Passkey',         $pk, strlen($pk) > 10 ? '••••••••…'.substr($pk,-6)            : ($pk ? '••••••••' : '')],
                            ];
                            foreach ($credRows as [$lbl, $val, $disp]): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                <span style="font-size:11px;color:rgba(255,255,255,.38);"><?= $lbl ?></span>
                                <?php if ($val): ?>
                                <span style="font-size:11px;font-family:monospace;color:#6ee7b7;background:rgba(16,185,129,.1);padding:2px 8px;border-radius:20px;border:1px solid rgba(16,185,129,.2);">
                                    ✓ <?= htmlspecialchars($disp) ?>
                                </span>
                                <?php else: ?>
                                <span style="font-size:11px;color:#fca5a5;background:rgba(239,68,68,.1);padding:2px 8px;border-radius:20px;border:1px solid rgba(239,68,68,.2);">✗ Not set</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($g['gateway_type'] === 'bank_account'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Bank</span>
                        <span class="gw-detail-value" style="font-family:inherit;"><?= htmlspecialchars($creds['bank_name'] ?? '—') ?></span>
                    </div>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Account No.</span>
                        <span class="gw-detail-value"><?= htmlspecialchars($creds['account_number'] ?? '—') ?></span>
                    </div>
                <?php elseif ($g['gateway_type'] === 'paypal'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Mode</span>
                        <span class="gw-detail-value" style="font-family:inherit;"><?= ucfirst($creds['mode'] ?? $creds['environment'] ?? 'sandbox') ?></span>
                    </div>
                <?php endif; ?>

                <!-- Test result area -->
                <div class="test-result" id="test-result-<?= $g['id'] ?>"></div>
            </div>

            <!-- Active/Inactive toggle -->
            <div class="gw-toggle-row">
                <div>
                    <div class="gw-toggle-label">Customer Visibility</div>
                    <div class="gw-toggle-sub">Show / hide from payment page</div>
                </div>
                <span class="live-toggle-text <?= $g['is_active'] ? 'on' : 'off' ?>" id="toggle-text-<?= $g['id'] ?>">
                    <?= $g['is_active'] ? 'ON' : 'OFF' ?>
                </span>
                <label class="live-toggle">
                    <input type="checkbox" id="toggle-<?= $g['id'] ?>"
                           <?= $g['is_active'] ? 'checked' : '' ?>
                           onchange="toggleGateway(<?= $g['id'] ?>, this)">
                    <span class="live-slider"></span>
                </label>
            </div>

            <!-- Action buttons -->
            <div class="gw-footer" style="flex-wrap:wrap;gap:6px;">
                <button class="gw-btn" onclick='editGateway(<?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>)'>
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="gw-btn test" onclick="testGateway(<?= $g['id'] ?>, this)">
                    <i class="fas fa-plug"></i> Test
                </button>
                <?php if (!$g['is_default']): ?>
                <button class="gw-btn" style="color:var(--primary-dark,#2C5282);border-color:rgba(59,110,165,.3);"
                        onclick="setDefaultGateway(<?= $g['id'] ?>, this)" title="Make this the primary payment method for customers">
                    <i class="fas fa-star"></i> Set Default
                </button>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--primary-dark,#2C5282);font-weight:600;padding:7px 4px;">
                    <i class="fas fa-star"></i> Default
                </span>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Delete this gateway? This cannot be undone.');" style="display:inline;margin-left:auto;">
                    <input type="hidden" name="action" value="delete_gateway">
                    <input type="hidden" name="gateway_id" value="<?= $g['id'] ?>">
                    <button type="submit" class="gw-btn danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ─── Platform Shared Paybill Info Panel — minimal ─── -->
    <?php
    $platformShortcode = $platformShortcodeDB ?? (defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : null);
    $prefix         = $accountPrefix ?? '?';
    $disburseTarget = $tenantBound ?? [];
    $c2bUrl         = defined('MPESA_C2B_CONFIRMATION_URL') ? MPESA_C2B_CONFIRMATION_URL : 'https://fortunetttech.site/api/payment/c2b_confirmation.php';
    $cbUrl          = 'https://' . htmlspecialchars(explode('.', $_SERVER['HTTP_HOST'] ?? 'yourdomain.fortunetttech.site')[0]) . '.fortunetttech.site/api/payment/callback.php';
    if ($platformShortcode && $showPlatToggle):
    ?>
    <div style="margin-top:28px;">
        <div class="pg-section-title">
            <i class="fas fa-share-alt"></i>
            Platform Shared Paybill
            <span class="pg-section-sub">FortuNett paybill reference info</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <!-- Left: Paybill + Prefix -->
            <div style="background:rgb(30,30,29);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 20px;box-shadow:8px 8px 20px rgba(0,0,0,.35),-4px -4px 10px rgba(255,255,255,.02);">
                <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.28);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">
                    <i class="fas fa-mobile-alt" style="margin-right:5px;color:rgba(255,255,255,.2);"></i>Paybill Details
                </div>
                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:4px;">Paybill Number</div>
                        <div style="font-size:24px;font-weight:800;color:#e2e2e0;font-family:monospace;letter-spacing:2px;"><?= htmlspecialchars($platformShortcode) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:4px;">Your Account Prefix</div>
                        <div style="font-size:24px;font-weight:800;color:var(--primary-color,#3B6EA5);font-family:monospace;letter-spacing:2px;"><?= htmlspecialchars($prefix) ?></div>
                        <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:4px;">
                            e.g. Customer #1 → <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:3px;"><?= htmlspecialchars($prefix) ?>0001</code>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: URLs + Disbursement -->
            <div style="background:rgb(30,30,29);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px 20px;box-shadow:8px 8px 20px rgba(0,0,0,.35),-4px -4px 10px rgba(255,255,255,.02);">
                <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.28);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">
                    <i class="fas fa-link" style="margin-right:5px;color:rgba(255,255,255,.2);"></i>Integration
                </div>
                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:4px;">STK Push Callback URL</div>
                        <div style="font-size:11px;font-family:monospace;color:#93c5fd;word-break:break-all;background:rgba(99,102,241,.07);padding:6px 10px;border-radius:6px;border:1px solid rgba(99,102,241,.15);"><?= $cbUrl ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:4px;">C2B Confirmation URL</div>
                        <div style="font-size:11px;font-family:monospace;color:#93c5fd;word-break:break-all;background:rgba(99,102,241,.07);padding:6px 10px;border-radius:6px;border:1px solid rgba(99,102,241,.15);"><?= htmlspecialchars($c2bUrl) ?></div>
                    </div>
                    <div style="border-top:1px solid rgba(255,255,255,.06);padding-top:12px;">
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:6px;">Disbursement Target</div>
                        <?php if (!empty($disburseTarget)): ?>
                        <div style="font-size:12px;color:#6ee7b7;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-check-circle" style="font-size:13px;flex-shrink:0;"></i>
                            <?php if ($disburseTarget['type'] === 'bank_account'): ?>
                                <?= htmlspecialchars($disburseTarget['name']) ?> &mdash;
                                <?= htmlspecialchars($disburseTarget['creds']['bank_name'] ?? '') ?>
                                <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:3px;color:#a7f3d0;"><?= htmlspecialchars($disburseTarget['creds']['account_number'] ?? '') ?></code>
                            <?php else: ?>
                                <?= htmlspecialchars($disburseTarget['name']) ?> — Paybill
                                <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:3px;color:#a7f3d0;"><?= htmlspecialchars($disburseTarget['creds']['paybill_number'] ?? '') ?></code>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div style="font-size:12px;color:#fcd34d;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-exclamation-triangle" style="font-size:12px;flex-shrink:0;"></i>
                            No disbursement account — add a Bank Account or Paybill gateway above.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>
</div>

<script>
/* ── Type selector ──────────────────────────────────────────── */
function selectType(type, card) {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.field-section').forEach(s => s.classList.remove('active'));
    card.classList.add('selected');
    document.getElementById('gateway_type').value = type;
    const sec = document.getElementById('fields_' + type);
    if (sec) sec.classList.add('active');
    // Show display name field after type is selected
    const nameWrap = document.getElementById('gatewayNameWrap');
    if (nameWrap) {
        nameWrap.style.display = 'block';
        const nameInput = document.getElementById('gateway_name');
        if (nameInput && !nameInput.value) {
            // Auto-suggest a name based on type
            const suggestions = {
                'mpesa_api':       'M-Pesa STK Push',
                'paybill_no_api':  'M-Pesa Paybill',
                'bank_account':    'Bank Transfer',
                'paypal':          'PayPal'
            };
            nameInput.placeholder = suggestions[type] || 'e.g. Main Payment Gateway';
        }
        nameWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

/* ── Sandbox/production env banners ─────────────────────────── */
function onMpesaEnvChange(sel) {
    const isSandbox = sel.value === 'sandbox';
    const sandboxEl = document.getElementById('sandbox-warning');
    const prodEl    = document.getElementById('production-confirm');
    if (sandboxEl) sandboxEl.style.display = isSandbox ? 'block' : 'none';
    if (prodEl)    prodEl.style.display    = isSandbox ? 'none' : 'flex';
}

function onMpesaShortcodeTypeChange(sel) {
    const isTill = sel.value === 'till';
    const lblShortcode   = document.getElementById('lbl_mpesa_shortcode');
    const hintShortcode  = document.getElementById('hint_mpesa_shortcode');
    const hintStore      = document.getElementById('hint_store_number');
    const lblStore       = document.getElementById('lbl_store_number');
    const inpShortcode   = document.getElementById('mpesa_shortcode');
    const inpStore       = document.getElementById('mpesa_store_number');

    if (isTill) {
        lblShortcode.textContent = 'Till Number (PartyB — what customers pay to)';
        lblShortcode.style.color = '#fcd34d';
        inpShortcode.placeholder = 'e.g. 4524255 — from M-Pesa Org Portal → Tills';
        if (hintShortcode) hintShortcode.style.display = 'block';

        // Store number is required for till — highlight it
        lblStore.innerHTML = 'Store / Head-Office Number <span style="color:#f87171;font-weight:700;">*required for Till</span>';
        inpStore.placeholder = 'e.g. 654321 — from M-Pesa Org Portal (your org shortcode)';
        if (hintStore) hintStore.style.display = 'block';
    } else {
        lblShortcode.textContent = 'Paybill Number';
        lblShortcode.style.color = '';
        inpShortcode.placeholder = 'e.g. 4524255';
        if (hintShortcode) hintShortcode.style.display = 'none';

        lblStore.innerHTML = 'Store / Head-Office Number <span style="font-weight:400;color:#9CA3AF;">(Till only)</span>';
        inpStore.placeholder = 'From Safaricom Org Portal';
        if (hintStore) hintStore.style.display = 'none';
    }
}

/* ── Toggle password visibility ─────────────────────────────── */
function toggleSecret(fieldId, btn) {
    const inp = document.getElementById(fieldId);
    if (!inp) return;
    const isHidden = inp.type === 'password';
    inp.type = isHidden ? 'text' : 'password';
    btn.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
}

/* ── Auto-account hint ──────────────────────────────────────── */
function onToggleAutoAccounts(cb) {
    if (cb.checked) {
        const instr = document.getElementById('instructions');
        instr.value = instr.value ||
            'Go to M-Pesa › Lipa na M-Pesa › Pay Bill. Business No: [Paybill above]. ' +
            'Account No: [Your Account ID shown in customer portal]. Enter Amount, then PIN.';
    }
}

/* ── Reset form ─────────────────────────────────────────────── */
function resetGatewayForm() {
    document.getElementById('gatewayForm').reset();
    document.getElementById('gateway_id').value = '';
    document.getElementById('gateway_type').value = '';
    document.getElementById('pgFormTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Payment Gateway';
    document.getElementById('pgSaveBtnText').textContent = 'Save Gateway';
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.field-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.preserve-note').forEach(n => n.style.display = 'none');
    // Clear credential badges
    ['badge_consumer_key','badge_consumer_secret','badge_passkey'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.display = 'none'; el.textContent = ''; el.className = 'cred-badge'; }
    });
    // Reset placeholder text on secret fields
    const secretEl = document.getElementById('mpesa_consumer_secret');
    const passEl   = document.getElementById('mpesa_passkey');
    if (secretEl) secretEl.placeholder = 'Leave blank to keep existing';
    if (passEl)   passEl.placeholder   = 'Leave blank to keep existing';
    // Hide env banners
    const sandboxEl = document.getElementById('sandbox-warning');
    const prodEl    = document.getElementById('production-confirm');
    if (sandboxEl) sandboxEl.style.display = 'none';
    if (prodEl)    prodEl.style.display    = 'none';
    // Hide display name field until type is selected
    const nameWrap = document.getElementById('gatewayNameWrap');
    if (nameWrap) nameWrap.style.display = 'none';
}

/* ── Populate edit form ─────────────────────────────────────── */
function editGateway(g) {
    resetGatewayForm();

    document.getElementById('pgFormTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Gateway: ' + g.gateway_name;
    document.getElementById('pgSaveBtnText').textContent = 'Update Gateway';
    document.getElementById('gateway_id').value = g.id;
    document.getElementById('gateway_name').value = g.gateway_name;

    let creds = g.credentials;
    if (typeof creds === 'string') {
        try { creds = JSON.parse(creds); } catch(e) { creds = {}; }
    }
    creds = creds || {};

    // Select the right type card
    document.querySelectorAll('.type-card').forEach(card => {
        if (card.dataset.type === g.gateway_type) {
            selectType(g.gateway_type, card);
        }
    });

    // Fill in fields per type
    if (g.gateway_type === 'paybill_no_api') {
        document.getElementById('paybill_number').value       = creds.paybill_number || '';
        document.getElementById('account_number').value       = creds.account_number || '';
        document.getElementById('instructions').value         = creds.instructions || '';
        document.getElementById('use_generated_accounts').checked = creds.use_generated_accounts === '1';

    } else if (g.gateway_type === 'mpesa_api') {
        document.getElementById('mpesa_consumer_key').value    = creds.consumer_key    || '';
        document.getElementById('mpesa_shortcode').value       = creds.shortcode       || '';
        document.getElementById('mpesa_shortcode_type').value  = creds.shortcode_type  || 'paybill';
        document.getElementById('mpesa_store_number').value    = creds.store_number    || '';
        document.getElementById('mpesa_env').value             = creds.environment     || 'sandbox';
        document.getElementById('mpesa_callback_url').value    = creds.callback_url    || '';

        // Secret fields — always blank for security, show saved badge + hint placeholder
        const secretEl = document.getElementById('mpesa_consumer_secret');
        const passEl   = document.getElementById('mpesa_passkey');
        secretEl.value = '';
        passEl.value   = '';

        // Consumer Secret badge + placeholder
        const secretBadge = document.getElementById('badge_consumer_secret');
        if (creds.consumer_secret) {
            const tail = creds.consumer_secret.slice(-4);
            secretEl.placeholder = '••••••••' + tail + ' (leave blank to keep)';
            secretBadge.textContent = '✓ Saved';
            secretBadge.className = 'cred-badge saved';
        } else {
            secretEl.placeholder = 'Enter Consumer Secret';
            secretBadge.textContent = '✗ Not set';
            secretBadge.className = 'cred-badge notset';
        }
        secretBadge.style.display = 'inline-flex';

        // Passkey badge + placeholder
        const passKeyBadge = document.getElementById('badge_passkey');
        if (creds.passkey) {
            const tail = creds.passkey.slice(-6);
            passEl.placeholder = '••••••••…' + tail + ' (leave blank to keep)';
            passKeyBadge.textContent = '✓ Saved';
            passKeyBadge.className = 'cred-badge saved';
        } else {
            passEl.placeholder = 'Enter Lipa Na M-Pesa Online Passkey';
            passKeyBadge.textContent = '⚠ Not set — STK Push will fail';
            passKeyBadge.className = 'cred-badge warn';
        }
        passKeyBadge.style.display = 'inline-flex';

        // Consumer Key badge
        const keyBadge = document.getElementById('badge_consumer_key');
        if (creds.consumer_key) {
            keyBadge.textContent = '✓ Saved';
            keyBadge.className = 'cred-badge saved';
        } else {
            keyBadge.textContent = '✗ Not set';
            keyBadge.className = 'cred-badge notset';
        }
        keyBadge.style.display = 'inline-flex';

        onMpesaEnvChange(document.getElementById('mpesa_env'));
        onMpesaShortcodeTypeChange(document.getElementById('mpesa_shortcode_type'));

    } else if (g.gateway_type === 'bank_account') {
        document.getElementById('bank_name').value          = creds.bank_name     || '';
        document.getElementById('bank_account_name').value  = creds.account_name  || creds.bank_account_name || '';
        document.getElementById('bank_account_number').value= creds.account_number|| creds.bank_account_number || '';
        document.getElementById('bank_paybill').value       = creds.paybill_number|| creds.bank_paybill || '';

    } else if (g.gateway_type === 'paypal') {
        document.getElementById('paypal_client_id').value = creds.client_id || '';
        document.getElementById('paypal_mode').value      = creds.mode || creds.environment || 'sandbox';
        document.getElementById('paypal_client_secret').value = '';
    }

    // Scroll to form
    document.querySelector('.pg-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ── Toggle active/inactive ─────────────────────────────────── */
function toggleGateway(gatewayId, checkbox) {
    const isActive = checkbox.checked ? 1 : 0;
    const textEl   = document.getElementById('toggle-text-' + gatewayId);
    const badge    = document.getElementById('gw-badge-' + gatewayId);

    textEl.textContent = isActive ? 'ON' : 'OFF';
    textEl.className   = 'live-toggle-text ' + (isActive ? 'on' : 'off');

    const fd = new FormData();
    fd.append('gateway_id', gatewayId);
    fd.append('is_active',  isActive);

    fetch('api/payment_gateways/toggle.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (badge) {
                    badge.textContent = isActive ? '● Active' : '○ Inactive';
                    badge.className   = 'gw-badge ' + (isActive ? 'active' : 'inactive');
                }
            } else {
                // Revert
                checkbox.checked    = !checkbox.checked;
                textEl.textContent  = checkbox.checked ? 'ON' : 'OFF';
                textEl.className    = 'live-toggle-text ' + (checkbox.checked ? 'on' : 'off');
                showToast && showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(() => {
            checkbox.checked = !checkbox.checked;
            showToast && showToast('Connection error. Try again.', 'error');
        });
}

/* ── Set default gateway ─────────────────────────────────────── */
function setDefaultGateway(gatewayId, btn) {
    const origHtml = btn.innerHTML;
    btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled   = true;
    const fd = new FormData();
    fd.append('gateway_id', gatewayId);
    fetch('api/payment_gateways/set_default.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message || 'Failed to set default', 'error');
                btn.innerHTML = origHtml; btn.disabled = false;
            }
        })
        .catch(() => { showToast('Network error', 'error'); btn.innerHTML = origHtml; btn.disabled = false; });
}

/* ── Test gateway ────────────────────────────────────────────── */
function testGateway(gatewayId, btn) {
    const resultEl = document.getElementById('test-result-' + gatewayId);
    const origHtml = btn.innerHTML;
    btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Testing…';
    btn.disabled   = true;
    if (resultEl) { resultEl.style.display = 'none'; resultEl.className = 'test-result'; }

    const fd = new FormData();
    fd.append('gateway_id', gatewayId);

    fetch('api/payment_gateways/test.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (resultEl) {
                resultEl.style.display = 'block';
                resultEl.className     = 'test-result ' + (data.success ? 'success' : 'error');
                resultEl.innerHTML     = (data.success ? '✅ ' : '❌ ') + data.message +
                    (data.detail ? '<br><small style="opacity:.8;">' + data.detail + '</small>' : '');
            }
        })
        .catch(err => {
            if (resultEl) {
                resultEl.style.display = 'block';
                resultEl.className     = 'test-result error';
                resultEl.textContent   = '❌ Connection error — could not reach test endpoint.';
            }
        })
        .finally(() => {
            btn.innerHTML = origHtml;
            btn.disabled  = false;
        });
}
</script>
