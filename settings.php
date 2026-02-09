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
            $pdo->commit();
            $action_result = 'success|General settings updated successfully';
            $tenantStmt->execute([$tenant_id]); // Refresh
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $action_result = 'error|' . $e->getMessage();
        }
    } 
    // --- 2. Update/Add Router ---
    elseif ($action === 'update_router') {
        $router_ip = $_POST['router_ip'] ?? '';
        $router_username = $_POST['router_username'] ?? '';
        $router_password = $_POST['router_password'] ?? '';
        $router_port = $_POST['router_port'] ?? 8728;
        $router_name = $_POST['router_name'] ?? 'Main Router';
        $router_id = $_POST['router_id'] ?? '';
        try {
            if ($router_id) {
                $stmt = $pdo->prepare("UPDATE mikrotik_routers SET ip_address = ?, username = ?, password = ?, api_port = ?, name = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$router_ip, $router_username, $router_password, $router_port, $router_name, $router_id, $tenant_id]);
                $action_result = 'success|Router settings updated successfully';
            } else {
                $stmt = $pdo->prepare("INSERT INTO mikrotik_routers (tenant_id, name, ip_address, username, password, api_port, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$tenant_id, $router_name, $router_ip, $router_username, $router_password, $router_port]);
                $action_result = 'success|New router added successfully';
            }
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

$routersStmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id = ?");
$routersStmt->execute([$tenant_id]);
$routers = $routersStmt->fetchAll(PDO::FETCH_ASSOC);

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

        <!-- Provisioning Token Card -->
        <div class="card border-0 shadow-sm mb-4 bg-primary text-white">
            <div class="card-body p-4 d-flex align-items-center gap-3">
                <i class="fas fa-key fa-2x text-white-50"></i>
                <div class="flex-grow-1">
                    <h5 class="mb-1 text-white">Provisioning Token</h5>
                    <div class="d-flex align-items-center gap-2">
                        <code class="bg-white bg-opacity-25 px-2 py-1 rounded text-white user-select-all"><?php echo htmlspecialchars($tenant['provisioning_token']); ?></code>
                        <small class="text-white-50">Use this to auto-register routers via CLI.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab"><i class="fas fa-cog me-2"></i>General</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="routers-tab" data-bs-toggle="tab" data-bs-target="#routers" type="button" role="tab"><i class="fas fa-network-wired me-2"></i>Routers</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab"><i class="fas fa-money-bill-wave me-2"></i>Payments</button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-4">
                <div class="tab-content" id="settingsTabsContent">
                    
                    <!-- General Settings -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_general">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Company Name</label>
                                    <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($tenant['company_name']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Business Email</label>
                                    <input type="email" name="business_email" class="form-control" value="<?php echo htmlspecialchars($tSettings['business_email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Business Phone</label>
                                    <input type="text" name="business_phone" class="form-control" value="<?php echo htmlspecialchars($tSettings['business_phone'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Currency</label>
                                    <select name="currency" class="form-select">
                                        <option value="KES" <?php echo ($tSettings['currency'] ?? '') === 'KES' ? 'selected' : ''; ?>>KES (Kenyan Shilling)</option>
                                        <option value="USD" <?php echo ($tSettings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD (US Dollar)</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Business Address</label>
                                    <textarea name="business_address" class="form-control" rows="3"><?php echo htmlspecialchars($tSettings['business_address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save General Settings</button>
                        </form>
                    </div>

                    <!-- Routers -->
                    <div class="tab-pane fade" id="routers" role="tabpanel">
                        <!-- Easy Provisioning Section -->
                        <div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-start gap-3">
                            <i class="fas fa-magic fa-2x mt-1"></i>
                            <div class="w-100">
                                <h5 class="alert-heading fw-bold">One-Click Provisioning</h5>
                                <p class="mb-2">Run this command in your MikroTik Winbox Terminal to automatically connect this router to your portal.</p>
                                <?php
                                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
                                    $host = $_SERVER['HTTP_HOST'];
                                    $token = $tenant['provisioning_token'];
                                    $cmd = "/tool fetch url=\"$protocol://$host/fortunett_technologies_/api/routers/provision.php?token=$token&identity=ManagedRouter&format=rsc\" dst-path=provision.rsc; :delay 5s; /import provision.rsc;";
                                ?>
                                <div class="bg-dark p-3 rounded position-relative">
                                    <code class="text-success user-select-all" id="provisionCmd"><?php echo htmlspecialchars($cmd); ?></code>
                                    <button class="btn btn-sm btn-light position-absolute top-0 end-0 m-2" onclick="copyToClipboard('<?php echo addslashes($cmd); ?>')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mb-4">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>IP Address</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($routers as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['ip_address']); ?></td>
                                        <td><span class="badge bg-<?php echo $r['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" onclick='editRouter(<?php echo json_encode($r); ?>)'>Edit</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($routers)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No routers configured. Add one below.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <h5 class="card-title mb-3" id="routerFormTitle">Add New Router</h5>
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_router">
                                    <input type="hidden" name="router_id" id="router_id">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Router Name</label>
                                            <input type="text" name="router_name" id="router_name" placeholder="Main HQ Router" class="form-control" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">IP Address</label>
                                            <input type="text" name="router_ip" id="router_ip" placeholder="192.168.88.1" class="form-control" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">API Port</label>
                                            <input type="number" name="router_port" id="router_port" value="8728" class="form-control">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Username</label>
                                            <input type="text" name="router_username" id="router_username" placeholder="admin" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Password</label>
                                            <input type="password" name="router_password" id="router_password" class="form-control">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Save Router</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Payments -->
                    <div class="tab-pane fade" id="payments" role="tabpanel">
                        
                        <!-- List -->
                        <div class="d-flex flex-column gap-3 mb-4">
                            <?php foreach ($gateways as $g): ?>
                                <?php $creds = json_decode($g['credentials'], true); ?>
                                <div class="card card-body border shadow-sm d-flex flex-row justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($g['gateway_name']); ?></h6>
                                        <span class="badge bg-secondary text-uppercase"><?php echo str_replace('_', ' ', $g['gateway_type']); ?></span>
                                        <?php if ($g['gateway_type'] == 'paybill_no_api'): ?>
                                            <small class="text-muted ms-2">Paybill: <?php echo htmlspecialchars($creds['paybill_number'] ?? '-'); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" onclick='editGateway(<?php echo json_encode($g); ?>)'>Edit</button>
                                        <form method="POST" onsubmit="return confirm('Delete this gateway?');" class="d-inline">
                                            <input type="hidden" name="action" value="delete_gateway">
                                            <input type="hidden" name="gateway_id" value="<?php echo $g['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Add Form -->
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0" id="gatewayFormTitle">Add New Payment Gateway</h5>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetGatewayForm()">Reset Form</button>
                                </div>
                                <form method="POST" id="gatewayForm">
                                    <input type="hidden" name="action" value="save_gateway">
                                    <input type="hidden" name="gateway_id" id="gateway_id">

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Gateway Name</label>
                                            <input type="text" name="gateway_name" id="gateway_name" placeholder="e.g. Main M-Pesa" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Gateway Type</label>
                                            <select name="gateway_type" id="gateway_type" class="form-select" onchange="toggleGatewayFields()">
                                                <option value="">-- Select Type --</option>
                                                <option value="paybill_no_api">Paybill - Without API keys</option>
                                                <option value="mpesa_api">M-Pesa Paybill / Till (With API)</option>
                                                <option value="bank_account">Bank Account</option>
                                                <option value="paypal">PayPal</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Dynamic Fields -->
                                    <div id="fields_paybill_no_api" class="gateway-fields d-none">
                                         <div class="mb-3">
                                            <label class="form-label">Paybill / Till Number</label>
                                            <input type="text" name="paybill_number" id="paybill_number" class="form-control">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Account Number (Optional/Instruction)</label>
                                            <input type="text" name="account_number" id="account_number" class="form-control" placeholder="e.g. Enter your automated Account ID">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Payment Instructions</label>
                                            <textarea name="instructions" id="instructions" class="form-control" placeholder="e.g. Go to M-Pesa > Lipa na M-Pesa..."></textarea>
                                        </div>
                                    </div>

                                    <div id="fields_mpesa_api" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Consumer Key</label><input type="text" name="mpesa_consumer_key" id="mpesa_consumer_key" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Consumer Secret</label><input type="password" name="mpesa_consumer_secret" id="mpesa_consumer_secret" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Passkey</label><input type="password" name="mpesa_passkey" id="mpesa_passkey" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Shortcode</label><input type="text" name="mpesa_shortcode" id="mpesa_shortcode" class="form-control"></div>
                                            <div class="col-md-12">
                                                <label class="form-label">Environment</label>
                                                <select name="mpesa_env" id="mpesa_env" class="form-select">
                                                    <option value="sandbox">Sandbox</option>
                                                    <option value="production">Production</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="fields_bank_account" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" id="bank_name" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Account Name</label><input type="text" name="bank_account_name" id="bank_account_name" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Account Number</label><input type="text" name="bank_account_number" id="bank_account_number" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Bank Paybill</label><input type="text" name="bank_paybill" id="bank_paybill" class="form-control"></div>
                                        </div>
                                    </div>
                                    
                                    <div id="fields_paypal" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Client ID</label><input type="text" name="paypal_client_id" id="paypal_client_id" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Client Secret</label><input type="password" name="paypal_client_secret" id="paypal_client_secret" class="form-control"></div>
                                            <div class="col-md-12">
                                                <label class="form-label">Mode</label>
                                                <select name="paypal_mode" id="paypal_mode" class="form-select">
                                                    <option value="sandbox">Sandbox</option>
                                                    <option value="live">Live</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                                        <label class="form-check-label" for="is_active">Enable this gateway</label>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Save Payment Gateway</button>
                                </form>
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

function editRouter(r) {
    document.getElementById('router_id').value = r.id;
    document.getElementById('router_name').value = r.name;
    document.getElementById('router_ip').value = r.ip_address;
    document.getElementById('router_username').value = r.username;
    document.getElementById('router_port').value = r.api_port;
    document.getElementById('routerFormTitle').textContent = 'Edit Router';
    
    // Switch tab
    new bootstrap.Tab(document.querySelector('#routers-tab')).show();
    document.getElementById('routers').scrollIntoView();
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
