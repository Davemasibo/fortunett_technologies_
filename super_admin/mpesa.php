<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

// Auto-create table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_mpesa_config (
        id INT NOT NULL DEFAULT 1,
        consumer_key VARCHAR(255) DEFAULT '',
        consumer_secret VARCHAR(255) DEFAULT '',
        passkey VARCHAR(255) DEFAULT '',
        shortcode VARCHAR(20) DEFAULT '',
        shortcode_type ENUM('paybill','till') DEFAULT 'paybill',
        environment ENUM('sandbox','live','production') DEFAULT 'sandbox',
        callback_url VARCHAR(512) DEFAULT '',
        c2b_validation_url VARCHAR(512) DEFAULT '',
        c2b_confirmation_url VARCHAR(512) DEFAULT '',
        notes TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO platform_mpesa_config (id) VALUES (1)");
} catch (Exception $e) {}

// Fetch current config
$cfg = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$cfg) $cfg = [];

// Auto-detect callback URLs
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;
$autoCallback    = $baseUrl . '/api/mpesa/callback.php';
$autoC2bValidation   = $baseUrl . '/api/mpesa/c2b_validation.php';
$autoC2bConfirmation = $baseUrl . '/api/mpesa/c2b_confirmation.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform M-Pesa Config — FortuNett Super Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,var(--sa-mid) 0%,var(--sa-dark) 100%);color:#fff;flex-shrink:0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:var(--sa-accent);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;}
.sidebar-brand p{font-size:11px;opacity:.6;margin-top:2px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;overflow-y:auto;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.75);text-decoration:none;transition:all .2s;font-size:14px;border-left:3px solid transparent;}
.sidebar-menu a:hover{background:rgba(255,255,255,.07);color:#fff;}
.sidebar-menu a.active{background:rgba(255,255,255,.12);color:#fff;border-left-color:var(--sa-accent);}
.sidebar-menu a i{width:18px;text-align:center;font-size:15px;}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;opacity:.5;}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;}
.topbar{background:#fff;padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:99;}
.topbar h1{font-size:18px;font-weight:700;color:#1e293b;}
.content{padding:28px;max-width:900px;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:24px;}
.card-head{padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:15px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px;}
.card-body{padding:22px;}
.section-label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin:0 0 14px 0;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-field{display:flex;flex-direction:column;gap:6px;}
.form-field.full{grid-column:1/-1;}
.form-field label{font-size:13px;font-weight:600;color:#374151;}
.form-field input,.form-field select,.form-field textarea{padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;color:#111827;background:#fff;transition:border-color .2s;}
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{outline:none;border-color:var(--sa-accent);}
.form-field .hint{font-size:11px;color:#6B7280;margin-top:2px;}
.url-auto{background:#F0FDF4;border-color:#86EFAC !important;color:#065F46 !important;font-size:12px;font-family:monospace;}
.btn-save{padding:10px 24px;background:var(--sa-accent);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s;}
.btn-save:hover{opacity:.9;}
.btn-test{padding:10px 20px;background:#0f3460;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;}
.status-pill.configured{background:#D1FAE5;color:#065F46;}
.status-pill.empty{background:#FEE2E2;color:#991B1B;}
.status-pill.sandbox{background:#FEF3C7;color:#92400E;}
.toast-msg{display:none;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:600;margin-bottom:16px;}
.toast-msg.success{background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7;}
.toast-msg.error{background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;}
/* Settlement table */
.settle-table{width:100%;border-collapse:collapse;}
.settle-table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
.settle-table td{padding:12px 14px;border-bottom:1px solid #F3F4F6;font-size:14px;color:#111827;}
.settle-table tbody tr:hover{background:#F9FAFB;}
.amount-cell{font-weight:700;color:#065F46;}
.info-box{background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:14px 16px;font-size:13px;color:#1e40af;line-height:1.6;}
.info-box strong{color:#1d4ed8;}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="badge-sa">SUPER ADMIN</div>
        <h2><i class="fas fa-shield-alt me-2"></i>FortuNett</h2>
        <p>Platform Administration</p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php" class="active"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">FortuNett Technologies &copy; <?php echo date('Y'); ?></div>
</div>

<!-- Main -->
<div class="main">
    <div class="topbar">
        <h1><i class="fas fa-mobile-alt" style="color:var(--sa-accent);margin-right:8px;"></i>Platform M-Pesa Configuration</h1>
        <div style="display:flex;align-items:center;gap:10px;font-size:14px;color:#475569;">
            <?php
            $isConfigured = !empty($cfg['consumer_key']) && !empty($cfg['shortcode']);
            $isLive = ($cfg['environment'] ?? 'sandbox') === 'production' || ($cfg['environment'] ?? 'sandbox') === 'live';
            ?>
            <?php if ($isConfigured): ?>
                <span class="status-pill <?php echo $isLive ? 'configured' : 'sandbox'; ?>">
                    <i class="fas fa-circle" style="font-size:8px;"></i>
                    <?php echo $isLive ? 'Live (' . htmlspecialchars($cfg['shortcode']) . ')' : 'Sandbox'; ?>
                </span>
            <?php else: ?>
                <span class="status-pill empty"><i class="fas fa-exclamation-circle" style="font-size:10px;"></i> Not Configured</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

        <div id="toastMsg" class="toast-msg"></div>

        <!-- Info box -->
        <div class="info-box" style="margin-bottom:24px;">
            <strong>How this works:</strong> When a tenant has <em>no M-Pesa paybill configured</em>, STK Push payments are automatically routed through FortuNett's platform Safaricom account (shortcode below). The money lands in FortuNett's account and is settled to the tenant in batches. Payments collected this way are tagged <code>collection_type = 'platform'</code> in the payments table — visible in the Settlements panel below.
        </div>

        <!-- Credentials Card -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-key" style="color:var(--sa-accent);"></i> Safaricom Daraja Credentials</h3>
                <button class="btn-test" onclick="testCredentials()"><i class="fas fa-vial" style="margin-right:6px;"></i>Test Connection</button>
            </div>
            <div class="card-body">
                <form id="mpesaConfigForm" onsubmit="saveConfig(event)">
                    <!-- Section: API Keys -->
                    <p class="section-label">API Keys</p>
                    <div class="form-grid" style="margin-bottom:22px;">
                        <div class="form-field">
                            <label>Consumer Key</label>
                            <input type="text" name="consumer_key" id="fConsumerKey"
                                value="<?php echo htmlspecialchars($cfg['consumer_key'] ?? ''); ?>"
                                placeholder="Safaricom Consumer Key" autocomplete="off">
                        </div>
                        <div class="form-field">
                            <label>Consumer Secret</label>
                            <input type="password" name="consumer_secret" id="fConsumerSecret"
                                placeholder="Consumer Secret (leave blank to keep current)"
                                autocomplete="new-password">
                            <span class="hint">Leave blank to keep existing secret.</span>
                        </div>
                        <div class="form-field full">
                            <label>Passkey (LipaNaMpesa Online Passkey)</label>
                            <input type="password" name="passkey" id="fPasskey"
                                placeholder="Passkey (leave blank to keep current)"
                                autocomplete="new-password">
                            <span class="hint">Leave blank to keep existing passkey.</span>
                        </div>
                    </div>

                    <!-- Section: Paybill / Till -->
                    <p class="section-label">Paybill / Till Details</p>
                    <div class="form-grid" style="margin-bottom:22px;">
                        <div class="form-field">
                            <label>Business Shortcode</label>
                            <input type="text" name="shortcode" id="fShortcode"
                                value="<?php echo htmlspecialchars($cfg['shortcode'] ?? ''); ?>"
                                placeholder="e.g. 174379">
                        </div>
                        <div class="form-field">
                            <label>Shortcode Type</label>
                            <select name="shortcode_type">
                                <option value="paybill" <?php echo ($cfg['shortcode_type']??'paybill')==='paybill'?'selected':''; ?>>Paybill</option>
                                <option value="till" <?php echo ($cfg['shortcode_type']??'')==='till'?'selected':''; ?>>Till Number</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Environment</label>
                            <select name="environment" id="fEnvironment">
                                <option value="sandbox" <?php echo in_array($cfg['environment']??'sandbox',['sandbox'])?'selected':''; ?>>Sandbox (testing)</option>
                                <option value="production" <?php echo in_array($cfg['environment']??'',['production','live'])?'selected':''; ?>>Production (live)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Section: Callback URLs -->
                    <p class="section-label">Callback &amp; C2B URLs <span style="font-weight:400;text-transform:none;font-size:11px;color:#6B7280;">(register these in your Daraja portal)</span></p>
                    <div class="form-grid" style="margin-bottom:22px;">
                        <div class="form-field full">
                            <label>STK Push Callback URL</label>
                            <input type="text" name="callback_url" id="fCallbackUrl"
                                value="<?php echo htmlspecialchars($cfg['callback_url'] ?? $autoCallback); ?>"
                                placeholder="<?php echo htmlspecialchars($autoCallback); ?>">
                            <span class="hint">Auto-detected: <code><?php echo htmlspecialchars($autoCallback); ?></code></span>
                        </div>
                        <div class="form-field">
                            <label>C2B Validation URL</label>
                            <input type="text" name="c2b_validation_url"
                                value="<?php echo htmlspecialchars($cfg['c2b_validation_url'] ?? $autoC2bValidation); ?>"
                                placeholder="<?php echo htmlspecialchars($autoC2bValidation); ?>">
                        </div>
                        <div class="form-field">
                            <label>C2B Confirmation URL</label>
                            <input type="text" name="c2b_confirmation_url"
                                value="<?php echo htmlspecialchars($cfg['c2b_confirmation_url'] ?? $autoC2bConfirmation); ?>"
                                placeholder="<?php echo htmlspecialchars($autoC2bConfirmation); ?>">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="form-field full" style="margin-bottom:20px;">
                        <label>Internal Notes (optional)</label>
                        <textarea name="notes" rows="2" placeholder="e.g. Sandbox keys for testing, switch to prod before launch..."><?php echo htmlspecialchars($cfg['notes'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:flex;gap:12px;align-items:center;">
                        <button type="submit" class="btn-save"><i class="fas fa-save" style="margin-right:6px;"></i>Save Credentials</button>
                        <span style="font-size:12px;color:#94a3b8;">
                            Last updated: <?php echo !empty($cfg['updated_at']) ? date('d M Y H:i', strtotime($cfg['updated_at'])) : 'never'; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pending Settlements Card -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-exchange-alt" style="color:#f59e0b;"></i> Pending Settlements by Tenant</h3>
                <button class="btn-test" onclick="loadSettlements()" style="background:#f59e0b;">
                    <i class="fas fa-sync" id="settleRefreshIcon" style="margin-right:6px;"></i>Refresh
                </button>
            </div>
            <div class="card-body" style="padding:0;">
                <div id="settlementsContent" style="padding:20px;text-align:center;color:#94a3b8;font-size:14px;">
                    <i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Loading settlement data...
                </div>
            </div>
        </div>

        <!-- How to register C2B section -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-book" style="color:#3b82f6;"></i> Integration Guide</h3>
            </div>
            <div class="card-body" style="font-size:13px;line-height:1.8;color:#374151;">
                <p style="margin-bottom:12px;"><strong>Step 1 — Register URLs in Daraja:</strong><br>
                In your <a href="https://developer.safaricom.co.ke/" target="_blank">Safaricom Daraja portal</a>, go to your app → C2B → Register URL. Use the C2B Validation and Confirmation URLs above.
                </p>
                <p style="margin-bottom:12px;"><strong>Step 2 — Tenant account number format:</strong><br>
                Each tenant has a unique <em>account prefix</em> (e.g. <code>J</code> for Jelly, <code>OM</code> for OmegaNet). When a customer pays via C2B, they enter <code>PREFIX + ZERO-PADDED-ID</code> as the account number (e.g. <code>J0023</code>). The C2B confirmation endpoint automatically routes the payment to the correct tenant and client.
                </p>
                <p style="margin-bottom:0;"><strong>Step 3 — STK Push fallback:</strong><br>
                If a tenant has no M-Pesa credentials in <em>Settings → Payments</em>, STK Push requests automatically use these platform credentials. Payments are tagged <code>collection_type='platform'</code> and appear in the Pending Settlements table for reconciliation.
                </p>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script>
function showToast(msg, type) {
    const el = document.getElementById('toastMsg');
    el.textContent = msg;
    el.className = 'toast-msg ' + type;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function saveConfig(e) {
    e.preventDefault();
    const form = document.getElementById('mpesaConfigForm');
    const fd = new FormData(form);
    fd.append('action', 'save');
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Saving...';

    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message, 'success');
            } else {
                showToast(d.message || 'Save failed', 'error');
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save" style="margin-right:6px;"></i>Save Credentials';
        });
}

function testCredentials() {
    const fd = new FormData();
    fd.append('action', 'test');
    showToast('Testing connection to Safaricom API...', 'success');

    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => showToast(d.message, d.success ? 'success' : 'error'))
        .catch(() => showToast('Network error during test.', 'error'));
}

function loadSettlements() {
    const icon = document.getElementById('settleRefreshIcon');
    icon.classList.add('fa-spin');

    fetch('../api/super_admin/mpesa_settlements.php')
        .then(r => r.json())
        .then(d => {
            icon.classList.remove('fa-spin');
            const el = document.getElementById('settlementsContent');
            if (!d.success || !d.settlements || d.settlements.length === 0) {
                el.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:14px;"><i class="fas fa-check-circle" style="color:#22c55e;margin-right:8px;"></i>No pending platform-collected payments. All tenants are collecting via their own paybill, or no platform payments have been made yet.</div>';
                return;
            }
            let total = 0;
            let rows = d.settlements.map(s => {
                total += parseFloat(s.total_collected);
                return `<tr>
                    <td><strong>${escH(s.company_name || s.subdomain)}</strong><br><span style="font-size:12px;color:#6B7280;">${escH(s.subdomain)}.fortunetttech.site</span></td>
                    <td>${s.payment_count}</td>
                    <td class="amount-cell">KSh ${parseFloat(s.total_collected).toLocaleString('en-KE',{minimumFractionDigits:2})}</td>
                    <td style="font-size:13px;color:#6B7280;">${s.last_payment_date ? new Date(s.last_payment_date).toLocaleDateString('en-KE') : '—'}</td>
                    <td><button onclick="alert('Settlement recording coming soon.')" style="padding:5px 12px;border:none;background:#0f3460;color:#fff;border-radius:6px;font-size:12px;cursor:pointer;">Mark Settled</button></td>
                </tr>`;
            }).join('');
            el.innerHTML = `
                <div style="padding:12px 16px;background:#FEF3C7;border-bottom:1px solid #FDE68A;font-size:13px;color:#92400E;font-weight:600;">
                    <i class="fas fa-coins" style="margin-right:6px;"></i>Total pending settlement: <strong>KSh ${total.toLocaleString('en-KE',{minimumFractionDigits:2})}</strong>
                </div>
                <table class="settle-table">
                    <thead><tr><th>Tenant</th><th>Payments</th><th>Amount Owed</th><th>Last Payment</th><th>Action</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        })
        .catch(() => {
            document.getElementById('settleRefreshIcon').classList.remove('fa-spin');
            document.getElementById('settlementsContent').innerHTML = '<div style="padding:20px;text-align:center;color:#ef4444;font-size:14px;">Failed to load settlement data.</div>';
        });
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Load settlements on page load
loadSettlements();
</script>
</body>
</html>
