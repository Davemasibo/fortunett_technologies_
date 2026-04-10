<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("SELECT tenant_id, role, username, account_prefix FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user          = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id     = $user['tenant_id'];
$accountPrefix = $user['account_prefix'] ?? strtoupper(substr($user['username'] ?? '', 0, 1));

if (!$tenant_id) die("Error: No tenant assigned to this user.");

$tenantStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$tenantStmt->execute([$tenant_id]);
$tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

$action_result  = '';
$active_tab     = 'general'; // default, may be overridden by POST action

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- 1. General Settings ---
    if ($action === 'update_general') {
        $active_tab  = 'general';
        $company_name = $_POST['company_name'] ?? '';
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE tenants SET company_name = ? WHERE id = ?")->execute([$company_name, $tenant_id]);

            $upsert = function($key, $val) use ($pdo, $tenant_id) {
                $c = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id=? AND setting_key=?");
                $c->execute([$tenant_id, $key]);
                if ($c->fetch()) {
                    $pdo->prepare("UPDATE tenant_settings SET setting_value=? WHERE tenant_id=? AND setting_key=?")->execute([$val,$tenant_id,$key]);
                } else {
                    $pdo->prepare("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)")->execute([$tenant_id,$key,$val]);
                }
            };
            $upsert('company_name',    $company_name);
            $upsert('business_email',  $_POST['business_email']   ?? '');
            $upsert('business_phone',  $_POST['business_phone']   ?? '');
            $upsert('business_address',$_POST['business_address'] ?? '');
            $upsert('currency',        $_POST['currency']         ?? 'KES');
            $upsert('brand_color',     $_POST['brand_color']      ?? '#3B6EA5');
            $upsert('brand_font',      $_POST['brand_font']       ?? 'Work Sans');
            $upsert('support_number',  $_POST['support_number']   ?? '');
            $upsert('support_email',   $_POST['support_email']    ?? '');
            $pdo->commit();
            $action_result = 'success|General settings updated successfully';
            $tenantStmt->execute([$tenant_id]);
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $action_result = 'error|' . $e->getMessage();
        }
    }

    // --- 2. Save Payment Gateway ---
    elseif ($action === 'save_gateway') {
        $active_tab   = 'payments';
        $gateway_type = $_POST['gateway_type'] ?? '';
        $gateway_name = $_POST['gateway_name'] ?? '';
        $gateway_id   = $_POST['gateway_id'] ?? '';
        $credentials  = [];
        if ($gateway_type === 'paybill_no_api') {
            $credentials['paybill_number']         = $_POST['paybill_number'] ?? '';
            $credentials['account_number']         = $_POST['account_number'] ?? '';
            $credentials['use_generated_accounts'] = isset($_POST['use_generated_accounts']) ? '1' : '0';
            $credentials['instructions']           = $_POST['instructions'] ?? '';
        } elseif ($gateway_type === 'bank_account') {
            $credentials['bank_name']      = $_POST['bank_name'] ?? '';
            $credentials['account_name']   = $_POST['bank_account_name'] ?? '';
            $credentials['account_number'] = $_POST['bank_account_number'] ?? '';
            $credentials['paybill_number'] = $_POST['bank_paybill'] ?? '';
        } elseif ($gateway_type === 'mpesa_api') {
            $credentials['consumer_key']    = $_POST['mpesa_consumer_key'] ?? '';
            $credentials['consumer_secret'] = $_POST['mpesa_consumer_secret'] ?? '';
            $credentials['passkey']         = $_POST['mpesa_passkey'] ?? '';
            $credentials['shortcode']       = $_POST['mpesa_shortcode'] ?? '';
            $credentials['environment']     = $_POST['mpesa_env'] ?? 'sandbox';
            $credentials['callback_url']    = trim($_POST['mpesa_callback_url'] ?? '');
        } elseif ($gateway_type === 'paypal') {
            $credentials['client_id']     = $_POST['paypal_client_id'] ?? '';
            $credentials['client_secret'] = $_POST['paypal_client_secret'] ?? '';
            $credentials['mode']          = $_POST['paypal_mode'] ?? 'sandbox';
        }
        try {
            if ($gateway_id) {
                $cur = $pdo->prepare("SELECT is_active, credentials FROM payment_gateways WHERE id=? AND tenant_id=?");
                $cur->execute([$gateway_id, $tenant_id]);
                $curRow = $cur->fetch(PDO::FETCH_ASSOC);
                if (!$curRow) {
                    $action_result = 'error|Gateway not found or access denied';
                } else {
                    $existingCreds = json_decode($curRow['credentials'], true) ?? [];
                    if ($gateway_type === 'mpesa_api') {
                        if (empty($credentials['consumer_secret'])) $credentials['consumer_secret'] = $existingCreds['consumer_secret'] ?? '';
                        if (empty($credentials['passkey']))          $credentials['passkey']          = $existingCreds['passkey'] ?? '';
                        if (empty($credentials['callback_url']))     $credentials['callback_url']     = $existingCreds['callback_url'] ?? '';
                    } elseif ($gateway_type === 'paypal') {
                        if (empty($credentials['client_secret'])) $credentials['client_secret'] = $existingCreds['client_secret'] ?? '';
                    }
                    $pdo->prepare("UPDATE payment_gateways SET gateway_type=?,gateway_name=?,credentials=?,is_active=? WHERE id=? AND tenant_id=?")
                        ->execute([$gateway_type, $gateway_name, json_encode($credentials), (int)$curRow['is_active'], $gateway_id, $tenant_id]);
                    $action_result = 'success|Gateway updated successfully';
                }
            } else {
                $pdo->prepare("INSERT INTO payment_gateways (tenant_id,gateway_type,gateway_name,credentials,is_active) VALUES (?,?,?,?,1)")
                    ->execute([$tenant_id, $gateway_type, $gateway_name, json_encode($credentials)]);
                $action_result = 'success|New payment gateway added successfully';
            }
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }

    // --- 3. Delete Gateway ---
    elseif ($action === 'delete_gateway') {
        $active_tab = 'payments';
        try {
            $pdo->prepare("DELETE FROM payment_gateways WHERE id=? AND tenant_id=?")->execute([$_POST['gateway_id'] ?? 0, $tenant_id]);
            $action_result = 'success|Payment gateway deleted';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }

    // --- 4. Save Email Config ---
    elseif ($action === 'save_email_config') {
        $active_tab = 'email';
        $host      = trim($_POST['smtp_host'] ?? '');
        $port      = (int)($_POST['smtp_port'] ?? 587);
        $user_smtp = trim($_POST['smtp_username'] ?? '');
        $pass      = $_POST['smtp_password'] ?? '';
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName  = trim($_POST['from_name'] ?? '');
        if ($pass === '••••••••') {
            $eRow = $pdo->prepare("SELECT smtp_password FROM email_configurations WHERE tenant_id=? LIMIT 1");
            $eRow->execute([$tenant_id]);
            $pass = $eRow->fetchColumn() ?: '';
        }
        try {
            $pdo->prepare("INSERT INTO email_configurations (tenant_id,smtp_host,smtp_port,smtp_username,smtp_password,from_email,from_name) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),smtp_username=VALUES(smtp_username),smtp_password=VALUES(smtp_password),from_email=VALUES(from_email),from_name=VALUES(from_name)")
                ->execute([$tenant_id, $host, $port, $user_smtp, $pass, $fromEmail, $fromName]);
            $action_result = 'success|Email SMTP settings saved successfully';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }

    // --- 5. Save SMS Config ---
    elseif ($action === 'save_sms_config') {
        $active_tab = 'sms';
        $provider  = trim($_POST['sms_provider'] ?? 'talksasa');
        $apiKey    = $_POST['sms_api_key'] ?? '';
        $senderId  = trim($_POST['sms_sender_id'] ?? '');
        $apiUrl    = trim($_POST['sms_api_url'] ?? 'https://api.talksasa.com/v1/sms/send');
        if ($apiKey === '••••••••') {
            $sRow = $pdo->prepare("SELECT api_key FROM sms_configurations WHERE tenant_id=? LIMIT 1");
            $sRow->execute([$tenant_id]);
            $apiKey = $sRow->fetchColumn() ?: '';
        }
        try {
            $pdo->prepare("INSERT INTO sms_configurations (tenant_id,provider,api_key,sender_id,api_url) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE provider=VALUES(provider),api_key=VALUES(api_key),sender_id=VALUES(sender_id),api_url=VALUES(api_url)")
                ->execute([$tenant_id, $provider, $apiKey, $senderId, $apiUrl]);
            $action_result = 'success|SMS settings saved successfully';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }

    // --- 6. WhatsApp Settings ---
    elseif ($action === 'update_whatsapp') {
        $active_tab = 'notifications';
        $upsert = function($key, $val) use ($pdo, $tenant_id) {
            $c = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id=? AND setting_key=?");
            $c->execute([$tenant_id, $key]);
            if ($c->fetch()) {
                $pdo->prepare("UPDATE tenant_settings SET setting_value=? WHERE tenant_id=? AND setting_key=?")->execute([$val,$tenant_id,$key]);
            } else {
                $pdo->prepare("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)")->execute([$tenant_id,$key,$val]);
            }
        };
        try {
            $upsert('wa_enabled',  isset($_POST['wa_enabled'])  ? '1' : '0');
            $upsert('wa_days',     $_POST['wa_days']     ?? '3');
            $upsert('wa_template', $_POST['wa_template'] ?? '');
            $action_result = 'success|WhatsApp settings saved';
        } catch (Exception $e) { $action_result = 'error|' . $e->getMessage(); }
    }

    // --- 7. Notification Settings ---
    elseif ($action === 'update_notifications') {
        $active_tab = 'notifications';
        $upsert = function($key, $val) use ($pdo, $tenant_id) {
            $c = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id=? AND setting_key=?");
            $c->execute([$tenant_id, $key]);
            if ($c->fetch()) {
                $pdo->prepare("UPDATE tenant_settings SET setting_value=? WHERE tenant_id=? AND setting_key=?")->execute([$val,$tenant_id,$key]);
            } else {
                $pdo->prepare("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,?,?)")->execute([$tenant_id,$key,$val]);
            }
        };
        try {
            $upsert('notify_mikrotik',    isset($_POST['notify_mikrotik'])    ? '1' : '0');
            $upsert('notify_phones',      $_POST['notify_phones']      ?? '');
            $upsert('notify_emails',      $_POST['notify_emails']      ?? '');
            $upsert('notify_payment_sms', isset($_POST['notify_payment_sms']) ? '1' : '0');
            $action_result = 'success|Notification settings saved';
        } catch (Exception $e) { $action_result = 'error|' . $e->getMessage(); }
    }
}

// --- Fetch all data for display ---
$settingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$settingsStmt->execute([$tenant_id]);
$tSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$gatewaysStmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? ORDER BY is_default DESC, created_at ASC");
$gatewaysStmt->execute([$tenant_id]);
$rawGateways = $gatewaysStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/payment_gateway.php';
$_pgm     = new PaymentGatewayManager($pdo);
$gateways = [];
foreach ($rawGateways as $gw) {
    $gwById = $_pgm->getGatewayById($gw['id'], true);
    $gw['credentials'] = $gwById ? $gwById['credentials'] : (json_decode($gw['credentials'], true) ?? []);
    $gateways[] = $gw;
}

// Fetch Email SMTP config
$emailCfg = [];
try {
    $ec = $pdo->prepare("SELECT * FROM email_configurations WHERE tenant_id=? LIMIT 1");
    $ec->execute([$tenant_id]);
    $emailCfg = $ec->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Fetch SMS config
$smsCfg = [];
try {
    $sc = $pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id=? LIMIT 1");
    $sc->execute([$tenant_id]);
    $smsCfg = $sc->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Check platform fallback availability
$hasPlatformEmail = false;
$hasPlatformSMS   = false;
try {
    $r = $pdo->query("SELECT id FROM platform_email_config WHERE id=1 AND is_active=1 AND smtp_host IS NOT NULL LIMIT 1");
    $hasPlatformEmail = (bool)($r && $r->fetchColumn());
    $r = $pdo->query("SELECT id FROM platform_sms_config WHERE id=1 AND is_active=1 AND api_key IS NOT NULL LIMIT 1");
    $hasPlatformSMS   = (bool)($r && $r->fetchColumn());
} catch (Exception $e) {}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
<div class="container-fluid">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1 text-dark fw-bold">Settings</h2>
        <p class="text-muted mb-0">Manage system configuration for <?php echo htmlspecialchars($tenant['company_name']); ?></p>
    </div>
</div>

<style>
/* ── Tab nav ────────────────────────────────────────────────── */
.nav-tabs .nav-link { color:#6B7280;font-weight:500;border:none;border-bottom:2px solid transparent;padding:11px 15px;font-size:13.5px; }
.nav-tabs .nav-link:hover { color:var(--primary-color);border-color:transparent;background:rgba(0,0,0,.02); }
.nav-tabs .nav-link.active { color:var(--primary-color);border-bottom:2px solid var(--primary-color);background:transparent;font-weight:600; }
.nav-tabs { border-bottom:1px solid #E5E7EB; }
.nav-tabs .nav-link i { margin-right:6px; }

/* ── Section headers ─────────────────────────────────────────── */
.set-section { margin-bottom:28px; }
.set-section-title {
    font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;
    display:flex;align-items:center;gap:8px;padding-bottom:10px;
    border-bottom:2px solid #F3F4F6;margin-bottom:18px;
}
.set-section-title i { color:var(--primary-color,#3B6EA5);font-size:14px; }

/* ── Info banner ─────────────────────────────────────────────── */
.set-info-banner {
    display:flex;align-items:flex-start;gap:12px;
    background:rgba(59,110,165,.06);border:1px solid rgba(59,110,165,.15);
    border-radius:10px;padding:14px 16px;margin-bottom:22px;font-size:13px;color:#374151;
}
.set-info-banner i { color:var(--primary-color,#3B6EA5);font-size:16px;flex-shrink:0;margin-top:1px; }
.set-info-banner.warn { background:#FEF3C7;border-color:#FCD34D;color:#92400E; }
.set-info-banner.warn i { color:#D97706; }
.set-info-banner.success { background:#D1FAE5;border-color:#A7F3D0;color:#065F46; }
.set-info-banner.success i { color:#059669; }

/* ── Form controls ───────────────────────────────────────────── */
.set-label { font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px; }
.set-hint  { font-size:11.5px;color:#9CA3AF;margin-top:4px; }
.set-input {
    width:100%;padding:9px 13px;border:1.5px solid #D1D5DB;border-radius:8px;
    font-size:13.5px;color:#111827;background:#fff;box-sizing:border-box;
    transition:border-color .15s;font-family:inherit;
}
.set-input:focus { outline:none;border-color:var(--primary-color);box-shadow:0 0 0 3px rgba(59,110,165,.1); }
.set-input[readonly] { background:#F9FAFB;color:#6B7280; }
.set-select { appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center; }

/* ── Secret wrap ─────────────────────────────────────────────── */
.sec-wrap { position:relative; }
.sec-wrap .set-input { padding-right:40px; }
.sec-toggle { position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;padding:3px; }
.sec-toggle:hover { color:#374151; }

/* ── Switch row ─────────────────────────────────────────────── */
.sw-row { display:flex;align-items:center;gap:14px;padding:13px 16px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;margin-bottom:14px; }
.sw-info strong { font-size:13.5px;font-weight:600;color:#111827;display:block; }
.sw-info span   { font-size:12px;color:#6B7280; }
.sw-info { flex:1; }
.set-switch { position:relative;width:44px;height:24px;flex-shrink:0; }
.set-switch input { opacity:0;width:0;height:0; }
.set-slider { position:absolute;inset:0;background:#D1D5DB;border-radius:24px;cursor:pointer;transition:.25s; }
.set-slider:before { content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.2); }
input:checked + .set-slider { background:var(--primary-color,#3B6EA5); }
input:checked + .set-slider:before { transform:translateX(20px); }

/* ── Save button ─────────────────────────────────────────────── */
.set-save-btn {
    background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);
    color:#fff;border:none;border-radius:8px;padding:11px 26px;font-size:13.5px;font-weight:600;
    cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:opacity .2s,transform .15s;
}
.set-save-btn:hover { opacity:.9;transform:translateY(-1px); }

/* ── Danger zone ─────────────────────────────────────────────── */
.danger-card { background:#FFF5F5;border:1.5px solid #FEE2E2;border-radius:10px;padding:18px 20px; }
.danger-card h6 { font-size:13px;font-weight:700;color:#991B1B;margin:0 0 6px; }
.danger-card p  { font-size:12.5px;color:#6B7280;margin:0 0 14px; }
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
        <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
            <li class="nav-item"><button class="nav-link" id="general-tab" data-bs-toggle="tab" data-bs-target="#general"><i class="fas fa-cog"></i>General</button></li>
            <li class="nav-item"><button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments"><i class="fas fa-money-bill-wave"></i>Payments</button></li>
            <li class="nav-item"><button class="nav-link" id="pppoe-tab" data-bs-toggle="tab" data-bs-target="#pppoe"><i class="fas fa-network-wired"></i>PPPoE</button></li>
            <li class="nav-item"><button class="nav-link" id="hotspot-tab" data-bs-toggle="tab" data-bs-target="#hotspot"><i class="fas fa-wifi"></i>Hotspot</button></li>
            <li class="nav-item"><button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email"><i class="fas fa-envelope"></i>Email</button></li>
            <li class="nav-item"><button class="nav-link" id="sms-tab" data-bs-toggle="tab" data-bs-target="#sms"><i class="fas fa-sms"></i>SMS</button></li>
            <li class="nav-item"><button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications"><i class="far fa-bell"></i>Notifications</button></li>
        </ul>
    </div>

    <div class="card-body p-4">
        <div class="tab-content" id="settingsTabsContent">

            <!-- ════════════════════════════════════════════════════
                 GENERAL
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="general" role="tabpanel">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_general">

                    <!-- Appearance -->
                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-palette"></i> Appearance</div>
                        <div class="row g-4">
                            <!-- Logo -->
                            <div class="col-12">
                                <label class="set-label">System Logo</label>
                                <div class="border rounded-3 p-4 text-center" style="border-style:dashed!important;background:#FAFAFA;">
                                    <?php if(!empty($tSettings['system_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($tSettings['system_logo']); ?>" style="max-height:50px;" class="mb-3"><br>
                                    <?php endif; ?>
                                    <i class="fas fa-cloud-upload-alt" style="font-size:24px;color:#D1D5DB;display:block;margin-bottom:8px;"></i>
                                    <span class="text-muted" style="font-size:13px;">Drag & drop or <label for="logo_upload" class="fw-bold cursor-pointer" style="color:var(--primary-color,#3B6EA5);">Browse</label></span>
                                    <input type="file" name="system_logo" id="logo_upload" class="d-none" accept="image/*">
                                    <div style="font-size:11px;color:#9CA3AF;margin-top:6px;">PNG, JPG or SVG — recommended 200×60px</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="set-label">Brand Color</label>
                                <div class="input-group">
                                    <input type="color" class="form-control form-control-color" style="max-width:50px;border-right:none;" name="brand_color_picker"
                                           value="<?php echo htmlspecialchars($tSettings['brand_color'] ?? '#3B6EA5'); ?>"
                                           oninput="document.getElementById('brand_code').value=this.value">
                                    <input type="text" class="form-control" name="brand_color" id="brand_code"
                                           value="<?php echo htmlspecialchars($tSettings['brand_color'] ?? '#3B6EA5'); ?>"
                                           oninput="document.querySelector('input[name=brand_color_picker]').value=this.value">
                                </div>
                                <div class="set-hint">Accent color for navbar, buttons, and active highlights — app background theme is fixed dark</div>
                            </div>
                            <div class="col-md-4">
                                <label class="set-label">Font</label>
                                <select name="brand_font" class="set-input set-select">
                                    <?php foreach(['Work Sans','Inter','Roboto','Poppins','Nunito'] as $f): ?>
                                    <option value="<?php echo $f; ?>" <?php echo ($tSettings['brand_font'] ?? 'Work Sans') === $f ? 'selected' : ''; ?>><?php echo $f; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="set-hint">Font used throughout the admin portal</div>
                            </div>
                            <div class="col-md-4">
                                <label class="set-label">Currency</label>
                                <select name="currency" class="set-input set-select">
                                    <option value="KES" <?php echo ($tSettings['currency'] ?? 'KES') === 'KES' ? 'selected' : ''; ?>>KES — Kenyan Shilling</option>
                                    <option value="USD" <?php echo ($tSettings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD — US Dollar</option>
                                    <option value="UGX" <?php echo ($tSettings['currency'] ?? '') === 'UGX' ? 'selected' : ''; ?>>UGX — Ugandan Shilling</option>
                                    <option value="TZS" <?php echo ($tSettings['currency'] ?? '') === 'TZS' ? 'selected' : ''; ?>>TZS — Tanzanian Shilling</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Business Info -->
                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-building"></i> Business Information</div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="set-label">Company Name <span class="text-danger">*</span></label>
                                <input type="text" name="company_name" class="set-input"
                                       value="<?php echo htmlspecialchars($tenant['company_name']); ?>" required>
                                <div class="set-hint">Shown on invoices, customer portal, and emails</div>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Business Email</label>
                                <input type="email" name="business_email" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['business_email'] ?? ''); ?>"
                                       placeholder="billing@yourisp.com">
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Business Phone</label>
                                <input type="text" name="business_phone" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['business_phone'] ?? ''); ?>"
                                       placeholder="+254700000000">
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Business Address</label>
                                <input type="text" name="business_address" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['business_address'] ?? ''); ?>"
                                       placeholder="123 Main St, Nairobi">
                            </div>
                        </div>
                    </div>

                    <!-- Support Contact -->
                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-headset"></i> Customer Support Contact</div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="set-label">Support Phone Number <span class="text-danger">*</span></label>
                                <input type="text" name="support_number" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['support_number'] ?? ''); ?>"
                                       placeholder="+2547...">
                                <div class="set-hint">Displayed in customer portal and payment page</div>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Support Email</label>
                                <input type="email" name="support_email" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['support_email'] ?? ''); ?>"
                                       placeholder="support@yourisp.com">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="set-save-btn"><i class="fas fa-save"></i> Save General Settings</button>
                </form>
            </div>

            <!-- ════════════════════════════════════════════════════
                 PAYMENTS
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="payments" role="tabpanel">
                <?php include 'settings_payments_partial.php'; ?>
            </div>

            <!-- ════════════════════════════════════════════════════
                 PPPOE
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="pppoe" role="tabpanel">
                <div class="set-info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>PPPoE Settings</strong><br>
                        Configure defaults for PPPoE subscribers. Most provisioning is done per-router — see the <a href="mikrotik.php" style="color:var(--primary-color);">MikroTik Routers</a> page for profile management.
                    </div>
                </div>

                <div class="set-section">
                    <div class="set-section-title"><i class="fas fa-users-cog"></i> Subscriber Maintenance</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="danger-card">
                                <h6><i class="fas fa-trash me-2"></i>Clear Inactive PPPoE Customers</h6>
                                <p>Remove subscribers who have been expired/inactive for more than 90 days. This cannot be undone.</p>
                                <form method="POST" onsubmit="return confirm('Remove all inactive PPPoE customers? This cannot be undone.');">
                                    <input type="hidden" name="action" value="clear_pppoe">
                                    <button type="submit" class="btn btn-outline-danger btn-sm fw-semibold">
                                        <i class="fas fa-trash me-2"></i>Clear Inactive PPPoE Customers
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="danger-card" style="background:#FFF7ED;border-color:#FED7AA;">
                                <h6 style="color:#9A3412;"><i class="fas fa-sync me-2"></i>Sync All Routers</h6>
                                <p>Force-sync all PPPoE profiles and user accounts from your MikroTik routers.</p>
                                <a href="mikrotik.php" class="btn btn-outline-warning btn-sm fw-semibold">
                                    <i class="fas fa-network-wired me-2"></i>Go to MikroTik Manager
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="set-section">
                    <div class="set-section-title"><i class="fas fa-chart-bar"></i> Quick Stats</div>
                    <?php
                    try {
                        $pppoeCount = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND connection_type='pppoe'");
                        $pppoeCount->execute([$tenant_id]);
                        $pppoe_total = (int)$pppoeCount->fetchColumn();
                        $pppoeActive = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND connection_type='pppoe' AND status='active'");
                        $pppoeActive->execute([$tenant_id]);
                        $pppoe_active = (int)$pppoeActive->fetchColumn();
                    } catch(Exception $e) { $pppoe_total = 0; $pppoe_active = 0; }
                    ?>
                    <div class="row g-3">
                        <div class="col-auto">
                            <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#1E40AF;"><?php echo number_format($pppoe_total); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Total PPPoE</div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#15803D;"><?php echo number_format($pppoe_active); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Active</div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div style="background:#FEF9C3;border:1px solid #FDE047;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#854D0E;"><?php echo number_format($pppoe_total - $pppoe_active); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Inactive</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════
                 HOTSPOT
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="hotspot" role="tabpanel">
                <div class="set-info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Hotspot Settings</strong><br>
                        Configure defaults for hotspot voucher customers. For router-level hotspot profiles and walled garden, configure directly on your MikroTik via <a href="mikrotik.php" style="color:var(--primary-color);">MikroTik Routers</a>.
                    </div>
                </div>

                <div class="set-section">
                    <div class="set-section-title"><i class="fas fa-users-cog"></i> User Maintenance</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="danger-card">
                                <h6><i class="fas fa-trash me-2"></i>Clear Inactive Hotspot Users</h6>
                                <p>Remove hotspot users who haven't logged in for more than 90 days. This cannot be undone.</p>
                                <form method="POST" onsubmit="return confirm('Remove all inactive hotspot users? This cannot be undone.');">
                                    <input type="hidden" name="action" value="clear_hotspot">
                                    <button type="submit" class="btn btn-outline-danger btn-sm fw-semibold">
                                        <i class="fas fa-trash me-2"></i>Clear Inactive Hotspot Users
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:10px;padding:18px 20px;">
                                <h6 style="color:#0C4A6E;font-size:13px;font-weight:700;margin:0 0 6px;"><i class="fas fa-ticket-alt me-2"></i>Voucher Management</h6>
                                <p style="font-size:12.5px;color:#6B7280;margin:0 0 12px;">Generate and manage hotspot vouchers for prepaid customers.</p>
                                <a href="clients.php?type=hotspot" class="btn btn-sm fw-semibold" style="background:var(--primary-color,#3B6EA5);color:#fff;border:none;">
                                    <i class="fas fa-ticket-alt me-2"></i>Manage Hotspot Users
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="set-section">
                    <div class="set-section-title"><i class="fas fa-chart-bar"></i> Quick Stats</div>
                    <?php
                    try {
                        $hsCount = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND connection_type='hotspot'");
                        $hsCount->execute([$tenant_id]);
                        $hs_total = (int)$hsCount->fetchColumn();
                        $hsActive = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND connection_type='hotspot' AND status='active'");
                        $hsActive->execute([$tenant_id]);
                        $hs_active = (int)$hsActive->fetchColumn();
                    } catch(Exception $e) { $hs_total = 0; $hs_active = 0; }
                    ?>
                    <div class="row g-3">
                        <div class="col-auto">
                            <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#1E40AF;"><?php echo number_format($hs_total); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Total Hotspot</div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#15803D;"><?php echo number_format($hs_active); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Active</div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div style="background:#FEF9C3;border:1px solid #FDE047;border-radius:10px;padding:14px 20px;text-align:center;">
                                <div style="font-size:24px;font-weight:700;color:#854D0E;"><?php echo number_format($hs_total - $hs_active); ?></div>
                                <div style="font-size:11px;color:#6B7280;font-weight:500;">Expired</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════
                 EMAIL
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="email" role="tabpanel">

                <?php if (empty($emailCfg)): ?>
                <div class="set-info-banner <?php echo $hasPlatformEmail ? 'success' : 'warn'; ?>">
                    <i class="fas fa-<?php echo $hasPlatformEmail ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <div>
                        <?php if ($hasPlatformEmail): ?>
                            <strong>Using platform SMTP</strong> — Emails are currently sent via FortuNett's shared mail server. Configure your own SMTP below to use your own domain and sender address.
                        <?php else: ?>
                            <strong>No email configured</strong> — No SMTP credentials found. Emails may use PHP mail() which often fails in production. Configure SMTP below for reliable delivery.
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="set-info-banner success">
                    <i class="fas fa-check-circle"></i>
                    <div><strong>Your SMTP is configured</strong> — Emails are sent from <strong><?php echo htmlspecialchars($emailCfg['from_email'] ?? $emailCfg['smtp_username'] ?? '?'); ?></strong> via <strong><?php echo htmlspecialchars($emailCfg['smtp_host'] ?? '?'); ?></strong>.</div>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="save_email_config">

                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-server"></i> SMTP Server</div>
                        <div class="row g-4">
                            <div class="col-md-8">
                                <label class="set-label">SMTP Host</label>
                                <input type="text" name="smtp_host" class="set-input"
                                       value="<?php echo htmlspecialchars($emailCfg['smtp_host'] ?? ''); ?>"
                                       placeholder="smtp.gmail.com or mail.yourdomain.com">
                                <div class="set-hint">Your SMTP server hostname</div>
                            </div>
                            <div class="col-md-4">
                                <label class="set-label">SMTP Port</label>
                                <select name="smtp_port" class="set-input set-select">
                                    <?php foreach([587=>'587 (STARTTLS — recommended)',465=>'465 (SSL)',25=>'25 (Plain — not recommended)'] as $p=>$l): ?>
                                    <option value="<?php echo $p; ?>" <?php echo (int)($emailCfg['smtp_port'] ?? 587) === $p ? 'selected' : ''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">SMTP Username</label>
                                <input type="text" name="smtp_username" class="set-input"
                                       value="<?php echo htmlspecialchars($emailCfg['smtp_username'] ?? ''); ?>"
                                       placeholder="your@email.com" autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">SMTP Password</label>
                                <div class="sec-wrap">
                                    <input type="password" name="smtp_password" id="smtp_password" class="set-input"
                                           placeholder="<?php echo !empty($emailCfg['smtp_password']) ? '••••••••' : 'Enter password'; ?>"
                                           autocomplete="new-password">
                                    <button type="button" class="sec-toggle" onclick="toggleVis('smtp_password',this)"><i class="fas fa-eye"></i></button>
                                </div>
                                <?php if(!empty($emailCfg['smtp_password'])): ?>
                                <div class="set-hint"><i class="fas fa-lock" style="font-size:10px;"></i> Leave blank to keep existing password</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-paper-plane"></i> Sender Identity</div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="set-label">From Email Address</label>
                                <input type="email" name="from_email" class="set-input"
                                       value="<?php echo htmlspecialchars($emailCfg['from_email'] ?? ''); ?>"
                                       placeholder="noreply@yourisp.com">
                                <div class="set-hint">The address customers will see in the From field</div>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">From Name</label>
                                <input type="text" name="from_name" class="set-input"
                                       value="<?php echo htmlspecialchars($emailCfg['from_name'] ?? $tenant['company_name'] ?? ''); ?>"
                                       placeholder="<?php echo htmlspecialchars($tenant['company_name'] ?? 'Your ISP'); ?>">
                                <div class="set-hint">Display name shown alongside the email address</div>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="submit" class="set-save-btn"><i class="fas fa-save"></i> Save Email Settings</button>
                        <?php if(!empty($emailCfg)): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testEmail()">
                            <i class="fas fa-paper-plane me-1"></i> Send Test Email
                        </button>
                        <span id="emailTestResult" style="font-size:12.5px;display:none;"></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:20px;padding:14px 16px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;font-size:12px;color:#6B7280;">
                        <strong style="color:#374151;">Popular SMTP providers:</strong>
                        Gmail: smtp.gmail.com:587 (use App Password, not account password) &nbsp;·&nbsp;
                        Zoho: smtp.zoho.com:587 &nbsp;·&nbsp;
                        Outlook: smtp-mail.outlook.com:587 &nbsp;·&nbsp;
                        Custom hosting: mail.yourdomain.com:587
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════════════
                 SMS
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="sms" role="tabpanel">

                <?php if (empty($smsCfg)): ?>
                <div class="set-info-banner <?php echo $hasPlatformSMS ? 'success' : 'warn'; ?>">
                    <i class="fas fa-<?php echo $hasPlatformSMS ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <div>
                        <?php if ($hasPlatformSMS): ?>
                            <strong>Using platform SMS</strong> — SMS messages are sent via FortuNett's shared TalkSasa account. Configure your own API key below to use your own sender ID and credit balance.
                        <?php else: ?>
                            <strong>No SMS configured</strong> — SMS notifications will not be delivered until you configure your TalkSasa API credentials below.
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="set-info-banner success">
                    <i class="fas fa-check-circle"></i>
                    <div><strong>SMS is configured</strong> — Sending via <strong><?php echo htmlspecialchars(ucfirst($smsCfg['provider'] ?? 'talksasa')); ?></strong> with sender ID <strong><?php echo htmlspecialchars($smsCfg['sender_id'] ?? '?'); ?></strong>.</div>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="save_sms_config">

                    <div class="set-section">
                        <div class="set-section-title"><i class="fas fa-sms"></i> SMS Provider</div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="set-label">Provider</label>
                                <select name="sms_provider" class="set-input set-select">
                                    <option value="talksasa" <?php echo ($smsCfg['provider'] ?? 'talksasa') === 'talksasa' ? 'selected' : ''; ?>>TalkSasa</option>
                                    <option value="africastalking" <?php echo ($smsCfg['provider'] ?? '') === 'africastalking' ? 'selected' : ''; ?>>Africa's Talking</option>
                                    <option value="other" <?php echo ($smsCfg['provider'] ?? '') === 'other' ? 'selected' : ''; ?>>Other (custom URL)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Sender ID</label>
                                <input type="text" name="sms_sender_id" class="set-input"
                                       value="<?php echo htmlspecialchars($smsCfg['sender_id'] ?? ''); ?>"
                                       placeholder="YOURISP" maxlength="11">
                                <div class="set-hint">Up to 11 characters — appears as the SMS sender name</div>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">API Key</label>
                                <div class="sec-wrap">
                                    <input type="password" name="sms_api_key" id="sms_api_key" class="set-input"
                                           placeholder="<?php echo !empty($smsCfg['api_key']) ? '••••••••' : 'Your TalkSasa API key'; ?>"
                                           autocomplete="new-password">
                                    <button type="button" class="sec-toggle" onclick="toggleVis('sms_api_key',this)"><i class="fas fa-eye"></i></button>
                                </div>
                                <?php if(!empty($smsCfg['api_key'])): ?>
                                <div class="set-hint"><i class="fas fa-lock" style="font-size:10px;"></i> Leave blank to keep existing key</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">API URL <span style="font-weight:400;color:#9CA3AF;">(optional override)</span></label>
                                <input type="url" name="sms_api_url" class="set-input"
                                       value="<?php echo htmlspecialchars($smsCfg['api_url'] ?? 'https://api.talksasa.com/v1/sms/send'); ?>"
                                       placeholder="https://api.talksasa.com/v1/sms/send">
                                <div class="set-hint">Leave as default for TalkSasa</div>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="submit" class="set-save-btn"><i class="fas fa-save"></i> Save SMS Settings</button>
                        <?php if(!empty($smsCfg)): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testSMS()">
                            <i class="fas fa-sms me-1"></i> Send Test SMS
                        </button>
                        <span id="smsTestResult" style="font-size:12.5px;display:none;"></span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:20px;padding:14px 16px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;font-size:12px;color:#6B7280;">
                        <strong style="color:#374151;">Getting API credentials:</strong>
                        Register at <strong>talksasa.com</strong> → Dashboard → API Keys. Sender IDs may require approval (1–3 business days).
                    </div>
                </form>
            </div>

            <!-- ════════════════════════════════════════════════════
                 NOTIFICATIONS
            ════════════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="notifications" role="tabpanel">

                <!-- WhatsApp Reminders -->
                <div class="set-section">
                    <div class="set-section-title"><i class="fab fa-whatsapp"></i> WhatsApp Reminders</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_whatsapp">
                        <div class="sw-row">
                            <div class="sw-info">
                                <strong>Enable WhatsApp Reminders</strong>
                                <span>Send automatic expiry reminders to customers via WhatsApp</span>
                            </div>
                            <label class="set-switch">
                                <input type="checkbox" name="wa_enabled" <?php echo ($tSettings['wa_enabled'] ?? '') == '1' ? 'checked' : ''; ?>>
                                <span class="set-slider"></span>
                            </label>
                        </div>
                        <div class="row g-4 mb-4">
                            <div class="col-md-3">
                                <label class="set-label">Days Before Expiry</label>
                                <input type="number" name="wa_days" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['wa_days'] ?? '3'); ?>"
                                       min="1" max="30">
                                <div class="set-hint">Send reminder this many days before expiry</div>
                            </div>
                            <div class="col-md-9">
                                <label class="set-label">Reminder Message Template</label>
                                <textarea name="wa_template" class="set-input" rows="3"
                                    style="resize:vertical;"><?php echo htmlspecialchars($tSettings['wa_template'] ?? 'Dear {name}, your internet package expires in {days} days. Amount due: KSh {amount}. Please pay to avoid disconnection.'); ?></textarea>
                                <div class="set-hint">Variables: <code>{name}</code>, <code>{days}</code>, <code>{amount}</code>, <code>{expiry_date}</code></div>
                            </div>
                        </div>
                        <button type="submit" class="set-save-btn"><i class="fas fa-save"></i> Save WhatsApp Settings</button>
                    </form>
                </div>

                <hr style="border-color:#F3F4F6;margin:28px 0;">

                <!-- System Notifications -->
                <div class="set-section">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_notifications">
                        <div class="set-section-title"><i class="fas fa-router"></i> MikroTik Status Alerts</div>
                        <div class="sw-row">
                            <div class="sw-info">
                                <strong>Enable Router Status Notifications</strong>
                                <span>Get alerted when a MikroTik router goes offline or comes back online</span>
                            </div>
                            <label class="set-switch">
                                <input type="checkbox" name="notify_mikrotik" <?php echo ($tSettings['notify_mikrotik'] ?? '') == '1' ? 'checked' : ''; ?>>
                                <span class="set-slider"></span>
                            </label>
                        </div>
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <label class="set-label">Alert Phone Numbers</label>
                                <input type="text" name="notify_phones" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['notify_phones'] ?? ''); ?>"
                                       placeholder="0722000000, 0733000000">
                                <div class="set-hint">Comma-separated — receive SMS when router goes offline</div>
                            </div>
                            <div class="col-md-6">
                                <label class="set-label">Alert Email Addresses</label>
                                <input type="text" name="notify_emails" class="set-input"
                                       value="<?php echo htmlspecialchars($tSettings['notify_emails'] ?? ''); ?>"
                                       placeholder="admin@yourisp.com, tech@yourisp.com">
                                <div class="set-hint">Comma-separated — receive email when router goes offline</div>
                            </div>
                        </div>

                        <div class="set-section-title" style="margin-top:8px;"><i class="fas fa-receipt"></i> Payment Notifications</div>
                        <div class="sw-row">
                            <div class="sw-info">
                                <strong>Payment Confirmation SMS to Hotspot Users</strong>
                                <span>Send an automatic SMS confirmation after a successful hotspot payment</span>
                            </div>
                            <label class="set-switch">
                                <input type="checkbox" name="notify_payment_sms" <?php echo ($tSettings['notify_payment_sms'] ?? '') == '1' ? 'checked' : ''; ?>>
                                <span class="set-slider"></span>
                            </label>
                        </div>

                        <button type="submit" class="set-save-btn"><i class="fas fa-save"></i> Save Notification Settings</button>
                    </form>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div><!-- /card-body -->
</div><!-- /card -->

</div>
</div>

<?php if ($action_result):
    $ar_parts  = explode('|', $action_result, 2);
    $ar_type   = $ar_parts[0];
    $ar_msg    = $ar_parts[1] ?? $ar_parts[0];
    $ar_is_err = ($ar_type === 'error');
    $ar_bg     = $ar_is_err ? '#DC2626' : '#059669';
    $ar_icon   = $ar_is_err ? 'fa-exclamation-circle' : 'fa-check-circle';
?>
<div id="action-banner" style="position:fixed;top:18px;right:18px;z-index:9999;max-width:420px;
     background:<?php echo $ar_bg; ?>;color:#fff;padding:14px 18px;border-radius:10px;
     box-shadow:0 4px 20px rgba(0,0,0,.35);display:flex;align-items:center;gap:10px;font-size:14px;">
    <i class="fas <?php echo $ar_icon; ?>" style="font-size:18px;flex-shrink:0;"></i>
    <span><?php echo htmlspecialchars($ar_msg); ?></span>
    <button onclick="document.getElementById('action-banner').remove()"
            style="margin-left:auto;background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;">&times;</button>
</div>
<script>setTimeout(function(){var b=document.getElementById('action-banner');if(b)b.remove();},6000);</script>
<?php endif; ?>

<script>
/* ── Tab activation from URL hash or PHP active_tab ─────────── */
document.addEventListener('DOMContentLoaded', function () {
    const phpTab = '<?php echo $active_tab; ?>';
    const hashTab = location.hash.replace('#', '');
    const target  = hashTab || phpTab;

    if (target) {
        const tabEl = document.getElementById(target + '-tab');
        if (tabEl) {
            const tab = new bootstrap.Tab(tabEl);
            tab.show();
        }
    } else {
        // Default: show general
        const def = document.getElementById('general-tab');
        if (def) new bootstrap.Tab(def).show();
    }

    // Update hash on tab change
    document.querySelectorAll('#settingsTabs .nav-link').forEach(btn => {
        btn.addEventListener('shown.bs.tab', function (e) {
            const id = e.target.id.replace('-tab', '');
            history.replaceState(null, null, '#' + id);
        });
    });
});

/* ── Helpers ─────────────────────────────────────────────────── */
function toggleVis(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    const hidden = el.type === 'password';
    el.type = hidden ? 'text' : 'password';
    btn.querySelector('i').className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
}

function testEmail() {
    const btn = event.target;
    const res = document.getElementById('emailTestResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing…';
    res.style.display = 'none';
    fetch('api/settings/test_email.php', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            res.style.display = 'inline';
            res.style.color = d.success ? '#059669' : '#DC2626';
            res.textContent = d.message;
        })
        .catch(() => { res.style.display='inline'; res.style.color='#DC2626'; res.textContent='Connection error'; })
        .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane me-1"></i> Send Test Email'; });
}

function testSMS() {
    const btn = event.target;
    const res = document.getElementById('smsTestResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing…';
    res.style.display = 'none';
    fetch('api/settings/test_sms.php', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            res.style.display = 'inline';
            res.style.color = d.success ? '#059669' : '#DC2626';
            res.textContent = d.message;
        })
        .catch(() => { res.style.display='inline'; res.style.color='#DC2626'; res.textContent='Connection error'; })
        .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-sms me-1"></i> Send Test SMS'; });
}

/* ── Gateway helpers (used by payment partial) ───────────────── */
function resetGatewayForm() {
    const form = document.getElementById('gatewayForm');
    if (form) form.reset();
    const gid  = document.getElementById('gateway_id');
    const gtype= document.getElementById('gateway_type');
    if (gid)   gid.value  = '';
    if (gtype) gtype.value = '';
    const title = document.getElementById('pgFormTitle');
    if (title) title.innerHTML = '<i class="fas fa-plus-circle"></i> Add New Payment Gateway';
    const btnTxt = document.getElementById('pgSaveBtnText');
    if (btnTxt) btnTxt.textContent = 'Save Gateway';
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.field-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.preserve-note').forEach(n => n.style.display = 'none');
}
</script>

<?php include 'includes/footer.php'; ?>
