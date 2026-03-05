<?php
/**
 * Payment Gateway Settings - Modern UI Partial
 * Included from settings.php within the Payments tab
 */
?>

<style>
.pg-container { padding: 0; }
.pg-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
.pg-title { font-size: 20px; font-weight: 700; color: #111827; }
.pg-subtitle { font-size: 13px; color: #6B7280; margin-top: 2px; }

/* Gateway Type Selector Cards */
.type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
.type-card { border: 2px solid #E5E7EB; border-radius: 10px; padding: 16px 12px; cursor: pointer; text-align: center; transition: all 0.2s ease; background: white; }
.type-card:hover { border-color: #6366f1; background: #f5f3ff; }
.type-card.selected { border-color: #6366f1; background: #eef2ff; }
.type-card .type-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 20px; }
.type-card .type-name { font-size: 13px; font-weight: 600; color: #374151; }
.type-card .type-desc { font-size: 11px; color: #9CA3AF; margin-top: 3px; }

/* Form Fields */
.field-section { background: #F9FAFB; border-radius: 10px; padding: 20px; border: 1px solid #E5E7EB; margin-bottom: 16px; display: none; }
.field-section.active { display: block; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.field-row.full { grid-template-columns: 1fr; }
.form-label-sm { font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 6px; }
.form-input { width: 100%; padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; background: white; transition: border-color 0.15s; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.toggle-row { display: flex; align-items: center; gap: 12px; padding: 12px; background: #eef2ff; border-radius: 8px; border: 1px solid #c7d2fe; margin-bottom: 16px; }
.toggle-switch { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: #D1D5DB; border-radius: 24px; cursor: pointer; transition: 0.3s; }
.toggle-slider:before { content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
input:checked + .toggle-slider { background: #6366f1; }
input:checked + .toggle-slider:before { transform: translateX(18px); }
.toggle-label { font-size: 13px; color: #374151; }
.toggle-label strong { display: block; font-weight: 600; }
.toggle-label span { font-size: 12px; color: #6B7280; }

/* Configured Gateways */
.gateways-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 24px; }
.gw-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; transition: box-shadow 0.2s; }
.gw-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.gw-card-header { padding: 16px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #F3F4F6; }
.gw-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.gw-icon.mpesa { background: #D1FAE5; color: #065F46; }
.gw-icon.paybill { background: #FEF3C7; color: #92400E; }
.gw-icon.bank { background: #DBEAFE; color: #1E40AF; }
.gw-icon.paypal { background: #EDE9FE; color: #5B21B6; }
.gw-name { font-weight: 700; font-size: 15px; color: #111827; }
.gw-type { font-size: 11px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; }
.gw-badge { margin-left: auto; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.gw-badge.active { background: #D1FAE5; color: #065F46; }
.gw-badge.inactive { background: #F3F4F6; color: #6B7280; }
.gw-body { padding: 14px 16px; }
.gw-detail { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
.gw-detail-label { color: #9CA3AF; }
.gw-detail-value { font-weight: 600; color: #111827; font-family: monospace; }
.gw-feature { display: inline-flex; align-items: center; gap: 4px; background: #eef2ff; color: #4338ca; font-size: 11px; padding: 3px 8px; border-radius: 20px; margin-top: 6px; }
.gw-footer { padding: 12px 16px; background: #F9FAFB; display: flex; gap: 8px; }
.gw-btn { padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid #E5E7EB; background: white; color: #374151; display: flex; align-items: center; gap: 6px; }
.gw-btn:hover { background: #F3F4F6; }
.gw-btn.danger { color: #DC2626; border-color: #FCA5A5; }
.gw-btn.danger:hover { background: #FEF2F2; }

/* Add Form Card */
.add-form-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; margin-bottom: 32px; }
.add-form-header { padding: 20px 24px; border-bottom: 1px solid #E5E7EB; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.add-form-header .title { font-size: 16px; font-weight: 700; color: white; }
.add-form-header .subtitle { font-size: 13px; color: rgba(255,255,255,0.8); margin-top: 2px; }
.add-form-body { padding: 24px; }
.save-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; padding: 12px 24px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
.save-btn:hover { opacity: 0.9; transform: translateY(-1px); }
.section-divider { border: none; border-top: 1px solid #E5E7EB; margin: 24px 0; }
.empty-state { text-align: center; padding: 40px; color: #9CA3AF; }
.empty-state i { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
</style>

<div class="pg-container">
    <!-- Header -->
    <div class="pg-header">
        <div>
            <div class="pg-title">💳 Payment Gateways</div>
            <div class="pg-subtitle">Configure how your customers pay for their subscriptions</div>
        </div>
    </div>

    <!-- Add Form -->
    <div class="add-form-card">
        <div class="add-form-header">
            <div class="title" id="pgFormTitle">➕ Add New Payment Gateway</div>
            <div class="subtitle">Choose a type below and fill in the details</div>
        </div>
        <div class="add-form-body">
            <form method="POST" id="gatewayForm">
                <input type="hidden" name="action" value="save_gateway">
                <input type="hidden" name="gateway_id" id="gateway_id">

                <!-- Step 1: Name -->
                <div class="field-row full" style="margin-bottom: 20px;">
                    <div>
                        <label class="form-label-sm">Gateway Name</label>
                        <input type="text" name="gateway_name" id="gateway_name" placeholder="e.g. Main M-Pesa, KCB Paybill..." class="form-input" required>
                    </div>
                </div>

                <!-- Step 2: Type Selector -->
                <label class="form-label-sm" style="margin-bottom: 12px;">Select Gateway Type</label>
                <input type="hidden" name="gateway_type" id="gateway_type" required>
                <div class="type-grid">
                    <div class="type-card" onclick="selectType('paybill_no_api', this)">
                        <div class="type-icon" style="background:#FEF3C7; color:#92400E;"><i class="fas fa-mobile-alt"></i></div>
                        <div class="type-name">Paybill</div>
                        <div class="type-desc">No API keys needed</div>
                    </div>
                    <div class="type-card" onclick="selectType('mpesa_api', this)">
                        <div class="type-icon" style="background:#D1FAE5; color:#065F46;"><i class="fas fa-bolt"></i></div>
                        <div class="type-name">M-Pesa API</div>
                        <div class="type-desc">Auto-confirm payments</div>
                    </div>
                    <div class="type-card" onclick="selectType('bank_account', this)">
                        <div class="type-icon" style="background:#DBEAFE; color:#1E40AF;"><i class="fas fa-university"></i></div>
                        <div class="type-name">Bank Account</div>
                        <div class="type-desc">Bank transfer</div>
                    </div>
                    <div class="type-card" onclick="selectType('paypal', this)">
                        <div class="type-icon" style="background:#EDE9FE; color:#5B21B6;"><i class="fab fa-paypal"></i></div>
                        <div class="type-name">PayPal</div>
                        <div class="type-desc">Online payments</div>
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
                            <label class="form-label-sm">Account Number (optional fallback)</label>
                            <input type="text" name="account_number" id="account_number" class="form-input" placeholder="e.g. ISP payments">
                        </div>
                    </div>
                    <!-- Auto Account Number Toggle -->
                    <div class="toggle-row">
                        <label class="toggle-switch">
                            <input type="checkbox" name="use_generated_accounts" id="use_generated_accounts" value="1" onchange="onToggleAutoAccounts(this)">
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong>🎯 Auto-assign unique account numbers per customer</strong>
                            <span>Each customer gets a unique code (e.g. B001, B002) to use as their paybill account number</span>
                        </div>
                    </div>
                    <div class="field-row full">
                        <div>
                            <label class="form-label-sm">Payment Instructions (shown to customer)</label>
                            <textarea name="instructions" id="instructions" class="form-input" rows="3" placeholder="e.g. Go to M-Pesa > Lipa na M-Pesa > Pay Bill > Enter number above..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- M-Pesa API Fields -->
                <div id="fields_mpesa_api" class="field-section">
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Consumer Key</label>
                            <input type="text" name="mpesa_consumer_key" id="mpesa_consumer_key" class="form-input" placeholder="Daraja consumer key">
                        </div>
                        <div>
                            <label class="form-label-sm">Consumer Secret</label>
                            <input type="password" name="mpesa_consumer_secret" id="mpesa_consumer_secret" class="form-input" placeholder="••••••••">
                        </div>
                    </div>
                    <div class="field-row">
                        <div>
                            <label class="form-label-sm">Passkey</label>
                            <input type="password" name="mpesa_passkey" id="mpesa_passkey" class="form-input" placeholder="••••••••">
                        </div>
                        <div>
                            <label class="form-label-sm">Shortcode (Paybill/Till)</label>
                            <input type="text" name="mpesa_shortcode" id="mpesa_shortcode" class="form-input" placeholder="e.g. 174379">
                        </div>
                    </div>
                    <div class="field-row full">
                        <div>
                            <label class="form-label-sm">Environment</label>
                            <select name="mpesa_env" id="mpesa_env" class="form-input">
                                <option value="sandbox">Sandbox (Testing)</option>
                                <option value="production">Production (Live)</option>
                            </select>
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
                            <input type="password" name="paypal_client_secret" id="paypal_client_secret" class="form-input" placeholder="••••••••">
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

                <hr class="section-divider">

                <div style="display: flex; gap: 12px; align-items: center;">
                    <button type="submit" class="save-btn">
                        <i class="fas fa-save"></i> Save Gateway
                    </button>
                    <button type="button" onclick="resetGatewayForm()" style="background: none; border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px 20px; cursor: pointer; font-size: 14px; color: #6B7280;">
                        Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Configured Gateways -->
    <div style="font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 16px;">
        <i class="fas fa-list-ul me-2" style="color: #6366f1;"></i> Configured Payment Methods
        <span style="font-size: 12px; font-weight: 400; color: #6B7280; margin-left: 8px;">These are shown to customers on the payment page</span>
    </div>

    <?php if (empty($gateways)): ?>
    <div class="empty-state">
        <i class="fas fa-credit-card"></i>
        <p style="font-size: 15px; font-weight: 600; color: #374151; margin-bottom: 4px;">No payment gateways configured</p>
        <p style="font-size: 13px;">Add a gateway above to start accepting payments</p>
    </div>
    <?php else: ?>
    <div class="gateways-grid">
        <?php foreach ($gateways as $g):
            $creds = is_array($g['credentials']) ? $g['credentials'] : (json_decode($g['credentials'], true) ?? []);
            $iconClass = match($g['gateway_type']) {
                'mpesa_api' => 'mpesa',
                'paybill_no_api' => 'paybill',
                'bank_account' => 'bank',
                'paypal' => 'paypal',
                default => 'paybill'
            };
            $icon = match($g['gateway_type']) {
                'mpesa_api' => 'fa-bolt',
                'paybill_no_api' => 'fa-mobile-alt',
                'bank_account' => 'fa-university',
                'paypal' => 'fa-paypal',
                default => 'fa-credit-card'
            };
            $typeLabel = match($g['gateway_type']) {
                'mpesa_api' => 'M-Pesa API',
                'paybill_no_api' => 'Paybill (Manual)',
                'bank_account' => 'Bank Transfer',
                'paypal' => 'PayPal',
                default => $g['gateway_type']
            };
        ?>
        <div class="gw-card">
            <div class="gw-card-header">
                <div class="gw-icon <?php echo $iconClass; ?>">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div>
                    <div class="gw-name"><?php echo htmlspecialchars($g['gateway_name']); ?></div>
                    <div class="gw-type"><?php echo $typeLabel; ?></div>
                </div>
                <span class="gw-badge <?php echo $g['is_active'] ? 'active' : 'inactive'; ?>">
                    <?php echo $g['is_active'] ? '● Active' : '○ Inactive'; ?>
                </span>
            </div>
            <div class="gw-body">
                <?php if ($g['gateway_type'] === 'paybill_no_api'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Paybill No.</span>
                        <span class="gw-detail-value"><?php echo htmlspecialchars($creds['paybill_number'] ?? '—'); ?></span>
                    </div>
                    <?php if (!empty($creds['use_generated_accounts']) && $creds['use_generated_accounts'] == '1'): ?>
                        <div class="gw-feature"><i class="fas fa-magic"></i> Auto account numbers enabled</div>
                    <?php endif; ?>
                <?php elseif ($g['gateway_type'] === 'mpesa_api'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Shortcode</span>
                        <span class="gw-detail-value"><?php echo htmlspecialchars($creds['shortcode'] ?? '—'); ?></span>
                    </div>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Environment</span>
                        <span class="gw-detail-value" style="font-family: inherit; color: <?php echo ($creds['environment'] ?? '') === 'production' ? '#059669' : '#D97706'; ?>">
                            <?php echo ucfirst($creds['environment'] ?? 'sandbox'); ?>
                        </span>
                    </div>
                <?php elseif ($g['gateway_type'] === 'bank_account'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Bank</span>
                        <span class="gw-detail-value" style="font-family: inherit;"><?php echo htmlspecialchars($creds['bank_name'] ?? '—'); ?></span>
                    </div>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Account No.</span>
                        <span class="gw-detail-value"><?php echo htmlspecialchars($creds['bank_account_number'] ?? $creds['account_number'] ?? '—'); ?></span>
                    </div>
                <?php elseif ($g['gateway_type'] === 'paypal'): ?>
                    <div class="gw-detail">
                        <span class="gw-detail-label">Mode</span>
                        <span class="gw-detail-value" style="font-family: inherit;"><?php echo ucfirst($creds['environment'] ?? $creds['paypal_mode'] ?? 'sandbox'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="gw-footer">
                <button class="gw-btn" onclick='editGateway(<?php echo htmlspecialchars(json_encode($g), ENT_QUOTES); ?>)'>
                    <i class="fas fa-edit"></i> Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete this gateway?');" style="display:inline;">
                    <input type="hidden" name="action" value="delete_gateway">
                    <input type="hidden" name="gateway_id" value="<?php echo $g['id']; ?>">
                    <button type="submit" class="gw-btn danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
let selectedTypeCard = null;

function selectType(type, card) {
    // Deselect all
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.field-section').forEach(s => s.classList.remove('active'));

    // Select clicked
    card.classList.add('selected');
    selectedTypeCard = card;
    document.getElementById('gateway_type').value = type;

    // Show relevant fields
    const section = document.getElementById('fields_' + type);
    if (section) section.classList.add('active');
}

function onToggleAutoAccounts(cb) {
    const instrEl = document.getElementById('instructions');
    if (cb.checked) {
        instrEl.value = instrEl.value || 'Go to M-Pesa > Lipa na M-Pesa > Pay Bill. Business No: [Paybill above]. Account No: [Use Your Account ID shown in your customer portal]. Enter Amount, then your PIN.';
    }
}

function resetGatewayForm() {
    document.getElementById('gatewayForm').reset();
    document.getElementById('gateway_id').value = '';
    document.getElementById('gateway_type').value = '';
    document.getElementById('pgFormTitle').textContent = '➕ Add New Payment Gateway';
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.field-section').forEach(s => s.classList.remove('active'));
}

function editGateway(g) {
    resetGatewayForm();
    document.getElementById('pgFormTitle').textContent = '✏️ Edit Gateway: ' + g.gateway_name;
    document.getElementById('gateway_id').value = g.id;
    document.getElementById('gateway_name').value = g.gateway_name;

    let creds = g.credentials;
    if (typeof creds === 'string') {
        try { creds = JSON.parse(creds); } catch(e) { creds = {}; }
    }

    // Select the type card
    document.querySelectorAll('.type-card').forEach(card => {
        if (card.getAttribute('onclick').includes("'" + g.gateway_type + "'")) {
            selectType(g.gateway_type, card);
        }
    });

    // Fill fields
    if (g.gateway_type === 'paybill_no_api') {
        document.getElementById('paybill_number').value = creds.paybill_number || '';
        document.getElementById('account_number').value = creds.account_number || '';
        document.getElementById('instructions').value = creds.instructions || '';
        document.getElementById('use_generated_accounts').checked = creds.use_generated_accounts == '1';
    } else if (g.gateway_type === 'mpesa_api') {
        document.getElementById('mpesa_consumer_key').value = creds.consumer_key || '';
        document.getElementById('mpesa_shortcode').value = creds.shortcode || '';
        document.getElementById('mpesa_env').value = creds.environment || 'sandbox';
    } else if (g.gateway_type === 'bank_account') {
        document.getElementById('bank_name').value = creds.bank_name || '';
        document.getElementById('bank_account_name').value = creds.account_name || creds.bank_account_name|| '';
        document.getElementById('bank_account_number').value = creds.account_number || creds.bank_account_number || '';
        document.getElementById('bank_paybill').value = creds.bank_paybill || creds.paybill_number || '';
    } else if (g.gateway_type === 'paypal') {
        document.getElementById('paypal_mode').value = creds.paypal_mode || creds.environment || 'sandbox';
    }

    // Scroll to form
    document.querySelector('.add-form-card').scrollIntoView({ behavior: 'smooth' });
}
</script>
