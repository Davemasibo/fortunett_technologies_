<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

// Get tenant context
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
// Fetch tenant_id and role
$stmt = $pdo->prepare("SELECT tenant_id, role, username FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id = $user['tenant_id'];

if (!$tenant_id) {
    die("Error: No tenant assigned to this user.");
}

// Fetch Tenant Profile
$tenantStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$tenantStmt->execute([$tenant_id]);
$tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

// Handle form submissions
$action_result = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // --- 1. Update General Settings ---
    if ($action === 'update_general') {
        $company_name = $_POST['company_name'] ?? '';
        $biz_email = $_POST['business_email'] ?? '';
        $biz_phone = $_POST['business_phone'] ?? '';
        $biz_address = $_POST['business_address'] ?? '';
        $currency = $_POST['currency'] ?? 'KES';

        try {
            $pdo->beginTransaction();
            // Update core tenant info
            $upd = $pdo->prepare("UPDATE tenants SET company_name = ? WHERE id = ?");
            $upd->execute([$company_name, $tenant_id]);

            // Helper to upsert tenant_settings
            $upsertSetting = function($key, $val) use ($pdo, $tenant_id) {
                $chk = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
                $chk->execute([$tenant_id, $key]);
                if ($chk->fetch()) {
                    $u = $pdo->prepare("UPDATE tenant_settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?");
                    $u->execute([$val, $tenant_id, $key]);
                } else {
                    $i = $pdo->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)");
                    $i->execute([$tenant_id, $key, $val]);
                }
            };
            $upsertSetting('business_email', $biz_email);
            $upsertSetting('business_phone', $biz_phone);
            $upsertSetting('business_address', $biz_address);
            $upsertSetting('currency', $currency);
            
            // Save branding fields
            $upsertSetting('brand_color', $_POST['brand_color'] ?? '#3B6EA5');
            $upsertSetting('brand_font', $_POST['brand_font'] ?? 'Work Sans');
            $upsertSetting('support_number', $_POST['support_number'] ?? '');
            $upsertSetting('support_email', $_POST['support_email'] ?? '');
            $upsertSetting('company_name', $company_name); // Also save to tenant_settings for consistency
            
            $pdo->commit();
            $action_result = 'success|General settings updated successfully';
            $tenantStmt->execute([$tenant_id]); // Refresh
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $action_result = 'error|' . $e->getMessage();
        }
    } 
    // --- 2. Update Appearance ---
    elseif ($action === 'update_appearance') {
        $app_theme = $_POST['app_theme'] ?? 'light';
        try {
            // Helper to upsert tenant_settings
            $upsertSetting = function($key, $val) use ($pdo, $tenant_id) {
                $chk = $pdo->prepare("SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
                $chk->execute([$tenant_id, $key]);
                if ($chk->fetch()) {
                    $u = $pdo->prepare("UPDATE tenant_settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?");
                    $u->execute([$val, $tenant_id, $key]);
                } else {
                    $i = $pdo->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)");
                    $i->execute([$tenant_id, $key, $val]);
                }
            };
            $upsertSetting('app_theme', $app_theme);
            $action_result = 'success|Appearance settings updated successfully';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }
    // --- 3. Add/Update Payment Gateway ---
    elseif ($action === 'save_gateway') {
        $gateway_type = $_POST['gateway_type'] ?? '';
        $gateway_name = $_POST['gateway_name'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $gateway_id = $_POST['gateway_id'] ?? '';
        $credentials = [];
        if ($gateway_type === 'paybill_no_api') {
            $credentials['paybill_number'] = $_POST['paybill_number'] ?? '';
            $credentials['account_number'] = $_POST['account_number'] ?? '';
            $credentials['instructions'] = $_POST['instructions'] ?? '';
        } elseif ($gateway_type === 'bank_account') {
            $credentials['bank_name'] = $_POST['bank_name'] ?? '';
            $credentials['account_name'] = $_POST['bank_account_name'] ?? '';
            $credentials['account_number'] = $_POST['bank_account_number'] ?? '';
            $credentials['paybill_number'] = $_POST['bank_paybill'] ?? '';
        } elseif ($gateway_type === 'mpesa_api') {
            $credentials['consumer_key'] = $_POST['mpesa_consumer_key'] ?? '';
            $credentials['consumer_secret'] = $_POST['mpesa_consumer_secret'] ?? '';
            $credentials['passkey'] = $_POST['mpesa_passkey'] ?? '';
            $credentials['shortcode'] = $_POST['mpesa_shortcode'] ?? '';
            $credentials['environment'] = $_POST['mpesa_env'] ?? 'sandbox';
        } elseif ($gateway_type === 'paypal') {
             $credentials['client_id'] = $_POST['paypal_client_id'] ?? '';
             $credentials['client_secret'] = $_POST['paypal_client_secret'] ?? '';
             $credentials['mode'] = $_POST['paypal_mode'] ?? 'sandbox';
        }
        $credsJson = json_encode($credentials);
        try {
            if ($gateway_id) {
                $stmt = $pdo->prepare("UPDATE payment_gateways SET gateway_type = ?, gateway_name = ?, credentials = ?, is_active = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$gateway_type, $gateway_name, $credsJson, $is_active, $gateway_id, $tenant_id]);
                $action_result = 'success|Gateway updated successfully';
            } else {
                $stmt = $pdo->prepare("INSERT INTO payment_gateways (tenant_id, gateway_type, gateway_name, credentials, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$tenant_id, $gateway_type, $gateway_name, $credsJson, $is_active]);
                $action_result = 'success|New Payment Gateway added successfully';
            }
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } 
    // --- 4. Delete Gateway ---
    elseif ($action === 'delete_gateway') {
        $gateway_id = $_POST['gateway_id'] ?? 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM payment_gateways WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$gateway_id, $tenant_id]);
            $action_result = 'success|Payment Gateway deleted';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }
}

// --- Fetch Data for Display ---
$settingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$settingsStmt->execute([$tenant_id]);
$tSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Removed Router fetch logic

$gatewaysStmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ?");
$gatewaysStmt->execute([$tenant_id]);
$gateways = $gatewaysStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1 text-dark fw-bold">Settings</h2>
                <p class="text-muted mb-0">Manage system configuration for <?php echo htmlspecialchars($tenant['company_name']); ?></p>
            </div>
        </div>

        <style>
            .nav-tabs .nav-link {
                color: #6B7280;
                font-weight: 500;
                border: none;
                border-bottom: 2px solid transparent;
                padding: 12px 16px;
            }
            .nav-tabs .nav-link:hover {
                color: var(--primary-color);
                border-color: transparent;
                background: rgba(0,0,0,0.02);
            }
            .nav-tabs .nav-link.active {
                color: var(--primary-color);
                border-bottom: 2px solid var(--primary-color);
                background: transparent;
                font-weight: 600;
            }
            .nav-tabs {
                border-bottom: 1px solid #E5E7EB;
            }
            .cursor-pointer { cursor: pointer; }
            .hover-card { transition: all 0.2s; }
            .hover-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important; }
        </style>

        <!-- Tabs -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general"><i class="fas fa-cog me-2"></i>General Settings</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments"><i class="fas fa-money-bill-wave me-2"></i>Payments</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="pppoe-tab" data-bs-toggle="tab" data-bs-target="#pppoe"><i class="fas fa-network-wired me-2"></i>PPPoE</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="hotspot-tab" data-bs-toggle="tab" data-bs-target="#hotspot"><i class="fas fa-wifi me-2"></i>Hotspot</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="whatsapp-tab" data-bs-toggle="tab" data-bs-target="#whatsapp"><i class="fab fa-whatsapp me-2"></i>WhatsApp</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications"><i class="far fa-bell me-2"></i>Notifications</button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-4">
                <div class="tab-content" id="settingsTabsContent">
                    
                    <!-- General Settings -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_general">
                            
                            <h5 class="fw-bold mb-3">Appearance</h5>
                            <p class="text-muted small mb-4">Configure your system appearance settings.</p>

                            <!-- Logo -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">System Logo</label>
                                <div class="border rounded p-4 text-center bg-light" style="border-style: dashed !important;">
                                    <?php if(!empty($tSettings['system_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($tSettings['system_logo']); ?>" class="mb-3" style="max-height: 50px;">
                                        <br>
                                    <?php endif; ?>
                                    <span class="text-muted">Drag & Drop your files or <label for="logo_upload" class="text-primary cursor-pointer fw-bold">Browse</label></span>
                                    <input type="file" name="system_logo" id="logo_upload" class="d-none" accept="image/*">
                                </div>
                                <small class="text-muted">Upload a Logo that will be used in the header of the system and login page.</small>
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">The name of your ISP / Wifi Company <span class="text-danger">*</span></label>
                                    <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($tenant['company_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Color</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" style="max-width: 50px;" name="brand_color_picker" value="<?php echo htmlspecialchars($tSettings['brand_color'] ?? '#fa8200'); ?>" onchange="document.getElementById('brand_code').value=this.value">
                                        <input type="text" class="form-control" name="brand_color" id="brand_code" value="<?php echo htmlspecialchars($tSettings['brand_color'] ?? '#fa8200'); ?>">
                                    </div>
                                    <small class="text-muted">What color should we use for the system?</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Font</label>
                                    <select name="brand_font" class="form-select">
                                        <option value="Work Sans" <?php echo ($tSettings['brand_font'] ?? '') === 'Work Sans' ? 'selected' : ''; ?>>Work Sans</option>
                                        <option value="Inter" <?php echo ($tSettings['brand_font'] ?? '') === 'Inter' ? 'selected' : ''; ?>>Inter</option>
                                        <option value="Roboto" <?php echo ($tSettings['brand_font'] ?? '') === 'Roboto' ? 'selected' : ''; ?>>Roboto</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Customer Support Number <span class="text-danger">*</span></label>
                                    <input type="text" name="support_number" class="form-control" value="<?php echo htmlspecialchars($tSettings['support_number'] ?? ''); ?>" placeholder="+2547...">
                                    <small class="text-muted">The number your clients can contact when they need support.</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Customer Support Email</label>
                                    <input type="email" name="support_email" class="form-control" value="<?php echo htmlspecialchars($tSettings['support_email'] ?? ''); ?>">
                                    <small class="text-muted">The email your clients can contact when they need support.</small>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary fw-bold px-4">Save changes</button>
                        </form>
                    </div>

                    <!-- Payments -->
                    <div class="tab-pane fade" id="payments" role="tabpanel">
                        <!-- Existing Payment Logic -->
                        <?php include 'settings_payments_partial.php'; ?>
                    </div>

                    <!-- PPPoE -->
                    <div class="tab-pane fade" id="pppoe" role="tabpanel">
                        <h5 class="fw-bold mb-3">PPPoE Maintenance</h5>
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <h6 class="fw-bold">Clear Inactive Customers</h6>
                                <p class="text-muted small">Remove customers who have been inactive for more than 90 days.</p>
                                <form method="POST" onsubmit="return confirm('Are you sure? This cannot be undone.');">
                                    <input type="hidden" name="action" value="clear_pppoe">
                                    <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash me-2"></i>Clear Inactive PPPoE Customers</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Hotspot -->
                    <div class="tab-pane fade" id="hotspot" role="tabpanel">
                        <h5 class="fw-bold mb-3">Hotspot Maintenance</h5>
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <h6 class="fw-bold">Clear Inactive Customers</h6>
                                <p class="text-muted small">Remove hotspot users who haven't logged in for 90 days.</p>
                                <form method="POST" onsubmit="return confirm('Are you sure? This cannot be undone.');">
                                    <input type="hidden" name="action" value="clear_hotspot">
                                    <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash me-2"></i>Clear Inactive Hotspot Users</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- WhatsApp -->
                    <div class="tab-pane fade" id="whatsapp" role="tabpanel">
                        <h5 class="fw-bold mb-3">WhatsApp Reminders</h5>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_whatsapp">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="wa_enabled" id="wa_enabled" <?php echo ($tSettings['wa_enabled'] ?? '') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="wa_enabled">Enable WhatsApp Reminders</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Days Before Expiry</label>
                                <input type="number" name="wa_days" class="form-control" value="<?php echo htmlspecialchars($tSettings['wa_days'] ?? '3'); ?>" style="max-width: 100px;">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message Template</label>
                                <textarea name="wa_template" class="form-control" rows="3"><?php echo htmlspecialchars($tSettings['wa_template'] ?? 'Dear {name}, your package expires in {days} days. Please pay to avoid disconnection.'); ?></textarea>
                                <small class="text-muted">Variables: {name}, {days}, {amount}</small>
                            </div>
                            <button type="submit" class="btn btn-primary fw-bold">Save WhatsApp Settings</button>
                        </form>
                    </div>

                    <!-- Notifications -->
                    <div class="tab-pane fade" id="notifications" role="tabpanel">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_notifications">
                            
                            <!-- Mikrotik Status -->
                            <div class="card border rounded mb-4">
                                <div class="card-header bg-white border-bottom-0 pt-3">
                                    <h6 class="fw-bold mb-0">Mikrotik Status Notifications</h6>
                                    <small class="text-muted">Notifications for Mikrotik status changes.</small>
                                </div>
                                <div class="card-body pt-0">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="notify_mikrotik" id="notify_mikrotik" <?php echo ($tSettings['notify_mikrotik'] ?? '') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-semibold" for="notify_mikrotik">Enable Mikrotik Status Notifications</label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Notification Phone Numbers</label>
                                        <input type="text" name="notify_phones" class="form-control" value="<?php echo htmlspecialchars($tSettings['notify_phones'] ?? ''); ?>" placeholder="0722000000, 0733000000">
                                        <small class="text-muted">Specify phone numbers that should receive Mikrotik offline notifications.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Notification Emails</label>
                                        <input type="text" name="notify_emails" class="form-control" value="<?php echo htmlspecialchars($tSettings['notify_emails'] ?? ''); ?>">
                                        <small class="text-muted">Select users who should receive Mikrotik status notifications via email.</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment Confirmations -->
                            <div class="card border rounded mb-4">
                                <div class="card-header bg-white border-bottom-0 pt-3">
                                    <h6 class="fw-bold mb-0">Payment Confirmation Notifications</h6>
                                </div>
                                <div class="card-body pt-0">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="notify_payment_sms" id="notify_payment_sms" <?php echo ($tSettings['notify_payment_sms'] ?? '') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-semibold" for="notify_payment_sms">Send payment confirmation SMS to hotspot users</label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary fw-bold">Save Notification Settings</button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($action_result): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1100">
  <div id="liveToast" class="toast align-items-center text-white <?php echo strpos($action_result, 'error') !== false ? 'bg-danger' : 'bg-success'; ?> border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
         <i class="fas <?php echo strpos($action_result, 'error') !== false ? 'fa-exclamation-circle' : 'fa-check-circle'; ?> me-2"></i>
         <?php echo explode('|', $action_result)[1]; ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleGatewayFields() {
    const type = document.getElementById('gateway_type').value;
    document.querySelectorAll('.gateway-fields').forEach(el => el.classList.add('d-none'));
    if (type) {
        const target = document.getElementById('fields_' + type);
        if (target) target.classList.remove('d-none');
    }
}

function resetRouterForm() {
    // Deprecated
}

function editGateway(g) {
    document.getElementById('gateway_id').value = g.id;
    document.getElementById('gateway_name').value = g.gateway_name;
    document.getElementById('gateway_type').value = g.gateway_type;
    document.getElementById('is_active').checked = g.is_active == 1;
    
    toggleGatewayFields();
    
    try {
        const c = JSON.parse(g.credentials);
        const fill = (id, val) => { if(document.getElementById(id)) document.getElementById(id).value = val || ''; };
        
        if (g.gateway_type === 'paybill_no_api') {
            fill('paybill_number', c.paybill_number);
            fill('account_number', c.account_number);
            fill('instructions', c.instructions);
        } else if (g.gateway_type === 'mpesa_api') {
            fill('mpesa_consumer_key', c.consumer_key);
            fill('mpesa_consumer_secret', c.consumer_secret);
            fill('mpesa_passkey', c.passkey);
            fill('mpesa_shortcode', c.shortcode);
            fill('mpesa_env', c.environment);
        } else if (g.gateway_type === 'bank_account') {
            fill('bank_name', c.bank_name);
            fill('bank_account_name', c.account_name);
            fill('bank_account_number', c.account_number);
            fill('bank_paybill', c.paybill_number);
        } else if (g.gateway_type === 'paypal') {
            fill('paypal_client_id', c.client_id);
            fill('paypal_client_secret', c.client_secret);
            fill('paypal_mode', c.mode);
        }
    } catch(e) {}
    
    document.getElementById('gatewayFormTitle').textContent = 'Edit Payment Gateway';
    document.getElementById('gatewayForm').scrollIntoView();
}

function resetGatewayForm() {
    document.getElementById('gatewayForm').reset();
    document.getElementById('gateway_id').value = '';
    document.getElementById('gatewayFormTitle').textContent = 'Add New Payment Gateway';
    toggleGatewayFields();
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Provisioning command copied to clipboard!');
    });
}
</script>

<?php include 'includes/footer.php'; ?>
