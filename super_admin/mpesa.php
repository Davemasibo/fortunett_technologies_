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
        store_number VARCHAR(20) DEFAULT '',
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
// Ensure store_number column exists on older schema
try { $pdo->exec("ALTER TABLE platform_mpesa_config ADD COLUMN store_number VARCHAR(20) DEFAULT '' AFTER shortcode_type"); } catch (Exception $e) {}

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
<link href="css/dark.css?v=2" rel="stylesheet">
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;
      --neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);--neu-text:#e2e2e0;--neu-muted:#9a9a95;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--neu-bg);display:flex;min-height:100vh;color:var(--neu-text);}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#111827 0%,#16213e 55%,#0f3460 100%);color:#fff;flex-shrink:0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;box-shadow:4px 0 24px rgba(0,0,0,.5);border-right:1px solid rgba(255,255,255,.06);}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:linear-gradient(135deg,#e94560,#c0305a);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;box-shadow:0 2px 8px rgba(233,69,96,.35);}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;color:#fff;}
.sidebar-brand p{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;overflow-y:auto;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.72);text-decoration:none;transition:all .2s;font-size:14px;border-left:3px solid transparent;}
.sidebar-menu a:hover{background:rgba(255,255,255,.08);color:#fff;border-left-color:rgba(255,255,255,.25);}
.sidebar-menu a.active{background:rgba(255,255,255,.13);color:#fff;border-left-color:#e94560;}
.sidebar-menu a i{width:18px;text-align:center;font-size:15px;}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.45);}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;background:var(--neu-bg);}
.topbar{background:var(--neu-surf);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--neu-border);position:sticky;top:0;z-index:99;box-shadow:0 2px 20px rgba(0,0,0,.4);}
.topbar h1{font-size:18px;font-weight:700;color:#fff;}
.topbar .user-info{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--neu-muted);}
.content{padding:28px;max-width:900px;background:var(--neu-bg);}
.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:24px;}
.card-head{padding:18px 22px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.02);border-radius:14px 14px 0 0;}
.card-head h3{font-size:15px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px;}
.card-body{padding:22px;}
.section-label{font-size:11px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.06em;margin:0 0 14px 0;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-field{display:flex;flex-direction:column;gap:6px;}
.form-field.full{grid-column:1/-1;}
.form-field label{font-size:13px;font-weight:600;color:var(--neu-muted);}
.form-field input,.form-field select,.form-field textarea{padding:9px 12px;border:1px solid rgba(255,255,255,.08);border-radius:8px;font-size:14px;color:var(--neu-text);background:#1a1a19;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);}
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{outline:none;border-color:#e94560;}
.form-field .hint{font-size:11px;color:var(--neu-muted);margin-top:2px;}
.url-auto{background:rgba(16,185,129,.07);border-color:rgba(16,185,129,.3) !important;color:#6ee7b7 !important;font-size:12px;font-family:monospace;}
.btn-save{padding:10px 24px;background:#e94560;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .2s;box-shadow:0 4px 14px rgba(233,69,96,.35);}
.btn-save:hover{opacity:.9;}
.btn-test{padding:10px 20px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;}
.status-pill.configured{background:rgba(16,185,129,.18);color:#6ee7b7;}
.status-pill.empty{background:rgba(239,68,68,.18);color:#fca5a5;}
.status-pill.sandbox{background:rgba(245,158,11,.18);color:#fcd34d;}
.toast-msg{display:none;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:600;margin-bottom:16px;}
.toast-msg.success{background:rgba(16,185,129,.12);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);}
.toast-msg.error{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.25);}
/* Settlement table */
.settle-table{width:100%;border-collapse:collapse;}
.settle-table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.05em;background:rgba(255,255,255,.04);border-bottom:1px solid var(--neu-border);}
.settle-table td{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:14px;color:var(--neu-text);}
.settle-table tbody tr:hover td{background:rgba(255,255,255,.035);}
.amount-cell{font-weight:700;color:#6ee7b7;}
.info-box{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:14px 16px;font-size:13px;color:#93c5fd;line-height:1.6;}
.info-box strong{color:#bfdbfe;}
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
        <h1><i class="fas fa-mobile-alt" style="color:#e94560;margin-right:8px;"></i>Platform M-Pesa Configuration</h1>
        <div class="user-info">
            <?php
            $isConfigured = !empty($cfg['consumer_key']) && !empty($cfg['shortcode']);
            $isLive = ($cfg['environment'] ?? 'sandbox') === 'production' || ($cfg['environment'] ?? 'sandbox') === 'live';
            ?>
            <?php if ($isConfigured): ?>
                <span class="status-pill <?= $isLive ? 'configured' : 'sandbox' ?>">
                    <i class="fas fa-circle" style="font-size:8px;"></i>
                    <?= $isLive ? 'Live (' . htmlspecialchars($cfg['shortcode']) . ')' : 'Sandbox' ?>
                </span>
            <?php else: ?>
                <span class="status-pill empty"><i class="fas fa-exclamation-circle" style="font-size:10px;"></i> Not Configured</span>
            <?php endif; ?>
            <div style="width:34px;height:34px;background:rgba(255,255,255,.12);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,.15);"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        </div>
    </div>

    <div class="content">

        <div id="toastMsg" class="toast-msg"></div>

        <?php
        // Detect known-bad passkey: sandbox test passkey used in production
        $sandboxPasskey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $isLiveEnv = in_array($cfg['environment'] ?? 'sandbox', ['production', 'live'], true);
        $hasSandboxPasskeyInProd = $isLiveEnv && !empty($cfg['passkey']) && $cfg['passkey'] === $sandboxPasskey;
        $hasMissingPasskey = $isLiveEnv && empty($cfg['passkey']);
        ?>
        <?php if ($hasSandboxPasskeyInProd): ?>
        <div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-exclamation-triangle" style="color:#fca5a5;margin-top:2px;flex-shrink:0;"></i>
            <div style="font-size:13px;color:#fca5a5;line-height:1.6;">
                <strong>Wrong passkey detected!</strong> The saved passkey is the Safaricom sandbox test passkey — it does not work with production shortcodes. STK Push requests will fail with error 500.001.1001.<br>
                <strong>Fix:</strong> Log into <a href="https://developer.safaricom.co.ke/MyApps" target="_blank" style="color:#93c5fd;">developer.safaricom.co.ke</a> → select your app → go to <strong>Lipa Na M-Pesa Online</strong> → copy the <strong>Online Passkey</strong> and paste it below.
            </div>
        </div>
        <?php elseif ($hasMissingPasskey): ?>
        <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-exclamation-triangle" style="color:#fcd34d;margin-top:2px;flex-shrink:0;"></i>
            <div style="font-size:13px;color:#fcd34d;line-height:1.6;">
                <strong>Passkey not saved.</strong> Environment is set to Production but no passkey is stored. STK Push will fail.<br>
                <strong>Fix:</strong> Get the Online Passkey from <a href="https://developer.safaricom.co.ke/MyApps" target="_blank" style="color:#93c5fd;">Safaricom Daraja</a> → Your App → Lipa Na M-Pesa Online.
            </div>
        </div>
        <?php endif; ?>

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
                            <label>Consumer Secret
                                <?php if (!empty($cfg['consumer_secret'])): ?>
                                <span style="font-size:10px;background:rgba(16,185,129,.18);color:#6ee7b7;padding:1px 7px;border-radius:10px;font-weight:600;margin-left:6px;">✓ Saved</span>
                                <?php else: ?>
                                <span style="font-size:10px;background:rgba(239,68,68,.18);color:#fca5a5;padding:1px 7px;border-radius:10px;font-weight:600;margin-left:6px;">Not set</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="consumer_secret" id="fConsumerSecret"
                                placeholder="<?php echo !empty($cfg['consumer_secret']) ? '••••••••' . substr($cfg['consumer_secret'], -4) . ' (leave blank to keep)' : 'Enter Consumer Secret'; ?>"
                                autocomplete="new-password">
                        </div>
                        <div class="form-field full">
                            <label>Passkey (Lipa Na M-Pesa Online Passkey)
                                <?php if (!empty($cfg['passkey'])): ?>
                                <span style="font-size:10px;background:rgba(16,185,129,.18);color:#6ee7b7;padding:1px 7px;border-radius:10px;font-weight:600;margin-left:6px;">✓ Saved</span>
                                <?php else: ?>
                                <span style="font-size:10px;background:rgba(239,68,68,.18);color:#fca5a5;padding:1px 7px;border-radius:10px;font-weight:600;margin-left:6px;">⚠ Not set — STK Push will fail</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="passkey" id="fPasskey"
                                placeholder="<?php echo !empty($cfg['passkey']) ? '••••••••' . substr($cfg['passkey'], -4) . ' (leave blank to keep)' : 'Enter Lipa Na Mpesa Passkey'; ?>"
                                autocomplete="new-password">
                            <span class="hint" style="color:#fcd34d;">
                                ⚠ The passkey is <strong>shortcode-specific and environment-specific</strong>.
                                Find yours in the <a href="https://developer.safaricom.co.ke/MyApps" target="_blank" style="color:#93c5fd;">Safaricom Daraja portal</a>
                                → Your App → <strong>Lipa Na M-Pesa Online</strong> → copy the <strong>Online Passkey</strong>.
                                The sandbox passkey (<code>bfb279f9…</code>) will NOT work in production.
                            </span>
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
                                <option value="till" <?php echo ($cfg['shortcode_type']??'')==='till'?'selected':''; ?>>Till Number (Buy Goods)</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Store / Head-Office Number <span style="font-weight:400;font-size:11px;opacity:.7;">(Till only — leave blank if same as shortcode)</span></label>
                            <input type="text" name="store_number" id="fStoreNumber"
                                value="<?php echo htmlspecialchars($cfg['store_number'] ?? ''); ?>"
                                placeholder="e.g. 600XXX — from Safaricom Daraja portal">
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
                            <?php
                            $savedCb = $cfg['callback_url'] ?? '';
                            $cbIsLocal = !empty($savedCb) && preg_match('/https?:\/\/(localhost|127\.)/i', $savedCb);
                            ?>
                            <?php if ($cbIsLocal): ?>
                            <span class="hint" style="color:#fca5a5;font-weight:600;">
                                ⚠️ Saved URL is localhost — Safaricom cannot reach it. Update to your public HTTPS domain (e.g. <code>https://fortunetttech.site/api/mpesa/callback.php</code>).
                            </span>
                            <?php else: ?>
                            <span class="hint">Must be a public HTTPS URL. Auto-detected: <code><?php echo htmlspecialchars($autoCallback); ?></code></span>
                            <?php endif; ?>
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

        <!-- Test STK Push Card -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-paper-plane" style="color:#f59e0b;"></i> Test STK Push (Super Admin)</h3>
            </div>
            <div class="card-body">
                <p style="font-size:12px;color:var(--neu-muted);margin-bottom:14px;line-height:1.6;">
                    Send a real STK push directly from the platform credentials — bypasses all tenant logic. Use this to confirm credentials work independently of the tenant portal.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;flex-wrap:wrap;">
                    <div>
                        <label style="font-size:11px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px;">Phone Number</label>
                        <input type="text" id="testStkPhone" placeholder="07XXXXXXXX"
                            style="width:100%;padding:8px 12px;background:#0d0d0d;border:1px solid rgba(255,255,255,.1);border-radius:7px;color:#e2e2e0;font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px;">Amount (KES)</label>
                        <input type="number" id="testStkAmount" value="1" min="1"
                            style="width:100%;padding:8px 12px;background:#0d0d0d;border:1px solid rgba(255,255,255,.1);border-radius:7px;color:#e2e2e0;font-size:13px;">
                    </div>
                    <button class="btn-save" onclick="sendTestStk()" id="testStkBtn" style="white-space:nowrap;padding:9px 20px;">
                        <i class="fas fa-paper-plane" style="margin-right:6px;"></i>Send STK Push
                    </button>
                </div>
                <div id="testStkResult" style="display:none;margin-top:12px;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;line-height:1.6;"></div>
            </div>
        </div>

        <!-- STK Push Diagnostics Card -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-stethoscope" style="color:#a78bfa;"></i> STK Push Diagnostics</h3>
                <button class="btn-test" onclick="loadStkLog()"><i class="fas fa-sync" id="stkLogRefreshIcon" style="margin-right:6px;"></i>Refresh</button>
            </div>
            <div class="card-body">
                <!-- STK Query Tool -->
                <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                    <div style="font-size:12px;font-weight:700;color:#a5b4fc;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;"><i class="fas fa-search" style="margin-right:6px;"></i>Query Safaricom — Did the phone prompt actually appear?</div>
                    <div style="font-size:12px;color:#c7d2fe;margin-bottom:10px;line-height:1.6;">
                        Paste a <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:3px;">CheckoutRequestID</code> from the STK log below to ask Safaricom directly whether the transaction was delivered and what the result was.
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="stkQueryId" placeholder="ws_CO_13042026181550587729909387"
                            style="flex:1;padding:8px 12px;background:#0d0d0d;border:1px solid rgba(255,255,255,.1);border-radius:7px;color:#e2e2e0;font-family:monospace;font-size:12px;min-width:200px;">
                        <button class="btn-test" onclick="queryStkStatus()" style="white-space:nowrap;padding:8px 16px;">
                            <i class="fas fa-satellite-dish" style="margin-right:6px;"></i>Query Safaricom
                        </button>
                    </div>
                    <div id="stkQueryResult" style="display:none;margin-top:10px;padding:10px 14px;border-radius:7px;font-size:12px;font-family:monospace;white-space:pre-wrap;line-height:1.7;"></div>
                </div>

                <p style="font-size:12px;color:var(--neu-muted);margin-bottom:10px;">
                    Every STK Push attempt is logged below with the Safaricom response and the callback URL used.
                </p>
                <div style="display:flex;gap:8px;margin-bottom:10px;">
                    <button class="btn-test" onclick="loadStkAllLog()" style="font-size:12px;padding:6px 14px;">All Attempts</button>
                    <button class="btn-test" onclick="loadStkLog()" style="font-size:12px;padding:6px 14px;background:rgba(239,68,68,.2);">Errors Only</button>
                </div>
                <div id="stkLogContent" style="font-size:11px;font-family:monospace;background:#0d0d0d;border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:14px;max-height:260px;overflow-y:auto;color:#6ee7b7;white-space:pre-wrap;word-break:break-all;">
                    Click "All Attempts" above, then trigger a test payment from a tenant portal.
                </div>
            </div>
        </div>

        <!-- Callback Diagnostics Card -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-satellite-dish" style="color:#34d399;"></i> Callback Diagnostics</h3>
                <button class="btn-test" onclick="loadCallbackLog()"><i class="fas fa-sync" id="cbLogRefreshIcon" style="margin-right:6px;"></i>Refresh</button>
            </div>
            <div class="card-body">
                <?php
                // Show the callback URL that will actually be used
                $savedCb = $cfg['callback_url'] ?? '';
                $constCb = 'https://fortunetttech.site/api/mpesa/callback.php';
                $effectiveCb = !empty($savedCb) && !preg_match('/https?:\/\/(localhost|127\.)/i', $savedCb)
                    ? $savedCb
                    : $constCb;
                $isLocalCb = preg_match('/https?:\/\/(localhost|127\.)/i', $savedCb);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="font-size:12px;color:var(--neu-muted);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Effective callback URL sent to Safaricom</div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <code style="font-size:12px;background:#0d0d0d;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:6px 12px;color:#6ee7b7;word-break:break-all;flex:1;">
                            <?php echo htmlspecialchars($effectiveCb); ?>
                        </code>
                        <button class="btn-test" onclick="checkCallbackUrl('<?php echo htmlspecialchars($effectiveCb); ?>')" style="font-size:12px;padding:6px 14px;white-space:nowrap;">
                            <i class="fas fa-plug" style="margin-right:5px;"></i>Test Reachability
                        </button>
                    </div>
                    <?php if ($isLocalCb && !empty($savedCb)): ?>
                    <div style="margin-top:6px;font-size:12px;color:#fca5a5;"><i class="fas fa-exclamation-triangle" style="margin-right:4px;"></i>Saved URL is localhost — Safaricom cannot reach it. Falling back to <code style="color:#fcd34d;"><?php echo htmlspecialchars($constCb); ?></code></div>
                    <?php elseif (empty($savedCb)): ?>
                    <div style="margin-top:6px;font-size:12px;color:#94a3b8;"><i class="fas fa-info-circle" style="margin-right:4px;"></i>No callback URL saved in DB — using the fallback constant above. For tenant-specific STK pushes, the tenant's own subdomain URL is used instead.</div>
                    <?php endif; ?>
                    <div id="cbReachResult" style="margin-top:8px;font-size:13px;font-weight:600;display:none;"></div>
                </div>

                <div style="font-size:12px;color:var(--neu-muted);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
                    Recent callbacks from Safaricom
                    <span id="cbLogTotal" style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:6px;"></span>
                </div>
                <div id="cbLogContent" style="font-size:11px;font-family:monospace;background:#0d0d0d;border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:14px;max-height:280px;overflow-y:auto;color:#a5f3fc;white-space:pre-wrap;word-break:break-all;">
                    Click Refresh to load the callback log.
                </div>
                <div style="margin-top:10px;font-size:12px;color:var(--neu-muted);line-height:1.6;">
                    <strong style="color:#e2e2e0;">Not seeing any callbacks?</strong> Safaricom must be able to reach the URL above over HTTPS with a valid SSL certificate.
                    Common causes: server firewall blocking port 443, expired SSL cert, or Safaricom's callback IP not whitelisted.
                </div>
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
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Testing…';
    showToast('Testing connection to Safaricom API…', 'success');

    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showToast(d.message, d.success ? 'success' : 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-vial" style="margin-right:6px;"></i>Test Connection';
        })
        .catch(() => {
            showToast('Network error during test.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-vial" style="margin-right:6px;"></i>Test Connection';
        });
}

function loadStkLog(file) {
    const el = document.getElementById('stkLogContent');
    el.textContent = 'Loading…';
    const fd = new FormData();
    fd.append('action', 'stk_log');
    fd.append('file', file || 'errors');
    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { el.textContent = d.lines || 'No entries yet.'; })
        .catch(() => { el.textContent = 'Could not load log.'; });
}

function loadStkAllLog() {
    loadStkLog('all');
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

function sendTestStk() {
    const phone  = document.getElementById('testStkPhone').value.trim();
    const amount = document.getElementById('testStkAmount').value.trim();
    const el     = document.getElementById('testStkResult');
    const btn    = document.getElementById('testStkBtn');

    if (!phone) { showToast('Enter a phone number.', 'error'); return; }
    if (!amount || amount < 1) { showToast('Amount must be at least 1.', 'error'); return; }

    el.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Sending…';

    const fd = new FormData();
    fd.append('action', 'test_stk');
    fd.append('phone', phone);
    fd.append('amount', amount);
    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px;"></i>Send STK Push';
            el.style.display = 'block';
            if (d.success) {
                el.style.background = 'rgba(16,185,129,.1)';
                el.style.border = '1px solid rgba(16,185,129,.2)';
                el.style.color = '#6ee7b7';
                el.innerHTML = '✅ STK Push sent! Check the phone for the M-Pesa PIN prompt.<br>'
                    + '<span style="font-size:11px;font-weight:400;font-family:monospace;">'
                    + 'CheckoutRequestID: ' + (d.checkout_request_id || '—') + '<br>'
                    + 'Shortcode: ' + (d.shortcode || '—') + ' | Callback: ' + (d.callback_url || '—')
                    + '</span><br><br>'
                    + '<span style="font-weight:400;font-size:12px;">Paste the CheckoutRequestID above into the "Query Safaricom" box in 30 seconds to confirm delivery.</span>';
                // Pre-fill the query box with this CheckoutRequestID
                if (d.checkout_request_id) document.getElementById('stkQueryId').value = d.checkout_request_id;
            } else {
                el.style.background = 'rgba(239,68,68,.1)';
                el.style.border = '1px solid rgba(239,68,68,.2)';
                el.style.color = '#fca5a5';
                el.textContent = '❌ ' + (d.message || 'Failed');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px;"></i>Send STK Push';
            showToast('Network error.', 'error');
        });
}

function queryStkStatus() {
    const id = document.getElementById('stkQueryId').value.trim();
    if (!id) { showToast('Paste a CheckoutRequestID first.', 'error'); return; }
    const el = document.getElementById('stkQueryResult');
    el.style.display = 'block';
    el.style.background = 'rgba(99,102,241,.08)';
    el.style.border = '1px solid rgba(99,102,241,.2)';
    el.style.color = '#a5b4fc';
    el.textContent = 'Querying Safaricom…';
    const fd = new FormData();
    fd.append('action', 'stk_query');
    fd.append('checkout_id', id);
    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.result_code === '0') {
                el.style.background = 'rgba(16,185,129,.08)';
                el.style.border = '1px solid rgba(16,185,129,.2)';
                el.style.color = '#6ee7b7';
            } else if (d.result_code === '17') {
                el.style.background = 'rgba(239,68,68,.08)';
                el.style.border = '1px solid rgba(239,68,68,.2)';
                el.style.color = '#fca5a5';
            } else {
                el.style.background = 'rgba(245,158,11,.08)';
                el.style.border = '1px solid rgba(245,158,11,.2)';
                el.style.color = '#fcd34d';
            }
            el.textContent = d.message || (d.success ? 'Query complete.' : d.message);
        })
        .catch(() => { el.style.color = '#fca5a5'; el.textContent = 'Network error.'; });
}

function loadCallbackLog() {
    const el = document.getElementById('cbLogContent');
    const icon = document.getElementById('cbLogRefreshIcon');
    el.textContent = 'Loading…';
    icon.classList.add('fa-spin');
    const fd = new FormData();
    fd.append('action', 'callback_log');
    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            icon.classList.remove('fa-spin');
            el.textContent = d.lines || 'No callbacks received yet.';
            const tot = document.getElementById('cbLogTotal');
            if (d.total) tot.textContent = '(' + d.total + ' total entries)';
        })
        .catch(() => { icon.classList.remove('fa-spin'); el.textContent = 'Could not load callback log.'; });
}

function checkCallbackUrl(url) {
    const el = document.getElementById('cbReachResult');
    el.style.display = 'block';
    el.style.color = '#fcd34d';
    el.textContent = 'Testing reachability…';
    const fd = new FormData();
    fd.append('action', 'check_callback');
    fd.append('url', url);
    fetch('../api/super_admin/mpesa_config.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            el.style.color = d.success ? '#6ee7b7' : '#fca5a5';
            el.textContent = d.message;
        })
        .catch(() => { el.style.color = '#fca5a5'; el.textContent = 'Network error during check.'; });
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Load on page load
loadSettlements();
loadStkLog();
loadCallbackLog();
</script>
</body>
</html>
