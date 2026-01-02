<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Get ISP profile
$profile = getISPProfile($db);

// Handle form submissions
$action_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_general') {
        $business_name = $_POST['business_name'] ?? '';
        $business_email = $_POST['business_email'] ?? '';
        $business_phone = $_POST['business_phone'] ?? '';
        $business_address = $_POST['business_address'] ?? '';
        
        try {
            $stmt = $db->prepare("UPDATE isp_profile SET business_name = ?, business_email = ?, business_phone = ?, business_address = ? WHERE id = 1");
            $stmt->execute([$business_name, $business_email, $business_phone, $business_address]);
            $action_result = 'success|General settings updated successfully';
            $profile = getISPProfile($db);
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } elseif ($action === 'update_router') {
        $router_ip = $_POST['router_ip'] ?? '';
        $router_username = $_POST['router_username'] ?? '';
        $router_password = $_POST['router_password'] ?? '';
        $router_port = $_POST['router_port'] ?? 8728;
        
        try {
            // Check if router exists
            $stmt = $db->query("SELECT id FROM mikrotik_routers WHERE id = 1");
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE mikrotik_routers SET ip_address = ?, username = ?, password = ?, api_port = ? WHERE id = 1");
                $stmt->execute([$router_ip, $router_username, $router_password, $router_port]);
            } else {
                $stmt = $db->prepare("INSERT INTO mikrotik_routers (id, name, ip_address, username, password, api_port) VALUES (1, 'Main Router', ?, ?, ?, ?)");
                $stmt->execute([$router_ip, $router_username, $router_password, $router_port]);
            }
            $action_result = 'success|Router settings updated successfully';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } elseif ($action === 'update_payments') {
        $mpesa_consumer_key = $_POST['mpesa_consumer_key'] ?? '';
        $mpesa_consumer_secret = $_POST['mpesa_consumer_secret'] ?? '';
        $mpesa_passkey = $_POST['mpesa_passkey'] ?? '';
        $mpesa_shortcode = $_POST['mpesa_shortcode'] ?? '';
        $mpesa_env = $_POST['mpesa_env'] ?? 'sandbox';
        
        // Save to config file
        $config_content = "<?php\n\nreturn [\n    'environment' => '$mpesa_env',\n    \n    'sandbox' => [\n        'consumer_key' => '$mpesa_consumer_key',\n        'consumer_secret' => '$mpesa_consumer_secret',\n        'passkey' => '$mpesa_passkey',\n        'shortcode' => '$mpesa_shortcode',\n        'initiator_name' => 'testapi',\n        'security_credential' => '',\n        'base_url' => 'https://sandbox.safaricom.co.ke'\n    ],\n    \n    'production' => [\n        'consumer_key' => '$mpesa_consumer_key',\n        'consumer_secret' => '$mpesa_consumer_secret',\n        'passkey' => '$mpesa_passkey',\n        'shortcode' => '$mpesa_shortcode',\n        'initiator_name' => '',\n        'security_credential' => '',\n        'base_url' => 'https://api.safaricom.co.ke'\n    ],\n    \n    'callback_url' => 'https://yourdomain.com/api/mpesa/callback.php',\n    'timeout_url' => 'https://yourdomain.com/api/mpesa/timeout.php',\n    'result_url' => 'https://yourdomain.com/api/mpesa/result.php',\n    \n    'account_reference' => 'ISP Payment',\n    'transaction_desc' => 'Internet Service Payment'\n];";
        
        if (file_put_contents('config/mpesa.php', $config_content)) {
            $action_result = 'success|Payment settings updated successfully';
        } else {
            $action_result = 'error|Failed to save payment settings';
        }
    }
}

// Fetch Router Settings
$router_settings = $db->query("SELECT * FROM mikrotik_routers WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

// Fetch M-Pesa Settings
$mpesa_config = require 'config/mpesa.php';
$current_env = $mpesa_config['environment'];
$mpesa_settings = $mpesa_config[$current_env];


include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .settings-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .settings-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .settings-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0 0 24px 0;
    }
    
    /* Tabs */
    .tabs-container {
        background: white;
        border-radius: 10px 10px 0 0;
        border: 1px solid #E5E7EB;
        border-bottom: none;
        padding: 0 24px;
        display: flex;
        gap: 8px;
    }
    
    .tab-btn {
        padding: 16px 20px;
        border: none;
        background: transparent;
        color: #6B7280;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
    }
    
    .tab-btn.active {
        color: #3B6EA5;
        border-bottom-color: #3B6EA5;
        font-weight: 600;
    }
    
    /* Content */
    .settings-content {
        background: white;
        border-radius: 0 0 10px 10px;
        border: 1px solid #E5E7EB;
        padding: 32px;
    }
    
    .tab-panel {
        display: none;
    }
    
    .tab-panel.active {
        display: block;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        color: #111827;
    }
    
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #3B6EA5;
        box-shadow: 0 0 0 3px rgba(59, 110, 165, 0.1);
    }
    
    .btn-save {
        padding: 10px 24px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-card {
        background: #F0F9FF;
        border: 1px solid #BAE6FD;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .info-card p {
        margin: 0;
        font-size: 14px;
        color: #0369A1;
    }
</style>

<div class="main-content-wrapper">
    <div class="settings-container">
        <!-- Header -->
        <div>
            <h1 class="settings-title">Settings</h1>
            <p class="settings-subtitle">Manage your system settings and preferences</p>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <button class="tab-btn active" onclick="switchTab('general')">
                <i class="fas fa-cog"></i> General Settings
            </button>
            <button class="tab-btn" onclick="switchTab('router')">
                <i class="fas fa-network-wired"></i> Router Settings
            </button>
            <button class="tab-btn" onclick="switchTab('payments')">
                <i class="fas fa-money-bill-wave"></i> Payment Settings
            </button>
            <button class="tab-btn" onclick="switchTab('security')">
                <i class="fas fa-shield-alt"></i> Security
            </button>
            <button class="tab-btn" onclick="switchTab('users')">
                <i class="fas fa-users-cog"></i> System Users
            </button>
        </div>

        <!-- Content -->
        <div class="settings-content">

            <!-- General Settings -->
            <div id="general" class="tab-panel active">
                <h2 style="font-size: 18px; font-weight: 600; margin: 0 0 20px 0;">General Settings</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_general">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" class="form-input" value="<?php echo htmlspecialchars($profile['business_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Business Email</label>
                            <input type="email" name="business_email" class="form-input" value="<?php echo htmlspecialchars($profile['business_email'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Business Phone</label>
                            <input type="text" name="business_phone" class="form-input" value="<?php echo htmlspecialchars($profile['business_phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Currency</label>
                            <select class="form-select">
                                <option selected>KES (Kenyan Shilling)</option>
                                <option>USD (US Dollar)</option>
                                <option>EUR (Euro)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Business Address</label>
                        <textarea name="business_address" class="form-textarea" rows="3"><?php echo htmlspecialchars($profile['business_address'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i>
                        Save Changes
                    </button>
                </form>
            </div>

            <!-- Router Settings -->
            <div id="router" class="tab-panel">
                <h2 style="font-size: 18px; font-weight: 600; margin: 0 0 20px 0;">Router Configuration</h2>
                
                <div class="info-card">
                    <p><i class="fas fa-info-circle"></i> Configure your MikroTik router connection details. Ensure API service is enabled on your router.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_router">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Router IP Address</label>
                            <input type="text" name="router_ip" class="form-input" value="<?php echo htmlspecialchars($router_settings['ip_address'] ?? ''); ?>" placeholder="192.168.88.1" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">API Port</label>
                            <input type="number" name="router_port" class="form-input" value="<?php echo htmlspecialchars($router_settings['api_port'] ?? '8728'); ?>" placeholder="8728">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Admin Username</label>
                            <input type="text" name="router_username" class="form-input" value="<?php echo htmlspecialchars($router_settings['username'] ?? ''); ?>" placeholder="admin" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Admin Password</label>
                            <input type="password" name="router_password" class="form-input" value="<?php echo htmlspecialchars($router_settings['password'] ?? ''); ?>" placeholder="••••••••">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn-save">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <button type="button" class="btn-save" style="background: white; border: 1px solid #D1D5DB; color: #374151;" onclick="testConnection()">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                    </div>
                </form>
            </div>

            <!-- Payment Settings -->
            <div id="payments" class="tab-panel">
                <h2 style="font-size: 18px; font-weight: 600; margin: 0 0 20px 0;">M-Pesa API Configuration</h2>
                
                <div class="info-card">
                    <p><i class="fas fa-info-circle"></i> Configure your Daraja API credentials to enable M-Pesa STK Push payments.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_payments">
                    
                    <div class="form-group">
                        <label class="form-label">Environment</label>
                        <select name="mpesa_env" class="form-select" style="max-width: 200px;">
                            <option value="sandbox" <?php echo ($current_env == 'sandbox') ? 'selected' : ''; ?>>Sandbox (Test)</option>
                            <option value="production" <?php echo ($current_env == 'production') ? 'selected' : ''; ?>>Production (Live)</option>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Consumer Key</label>
                            <input type="text" name="mpesa_consumer_key" class="form-input" value="<?php echo htmlspecialchars($mpesa_settings['consumer_key'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Consumer Secret</label>
                            <input type="password" name="mpesa_consumer_secret" class="form-input" value="<?php echo htmlspecialchars($mpesa_settings['consumer_secret'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Passkey</label>
                            <input type="password" name="mpesa_passkey" class="form-input" value="<?php echo htmlspecialchars($mpesa_settings['passkey'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Business Shortcode</label>
                            <input type="text" name="mpesa_shortcode" class="form-input" value="<?php echo htmlspecialchars($mpesa_settings['shortcode'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>

            <!-- Security Settings -->
            <div id="security" class="tab-panel">
                <h2 style="font-size: 18px; font-weight: 600; margin: 0 0 20px 0;">Security Settings</h2>
                
                <div class="info-card">
                    <p><i class="fas fa-info-circle"></i> Enable two-factor authentication to add an extra layer of security to your account.</p>
                </div>
                
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                    <input type="checkbox" id="2fa" style="width: 20px; height: 20px;">
                    <label for="2fa" style="margin: 0; font-size: 14px;">
                        <strong>Enable 2FA</strong>
                        <div style="color: #6B7280; font-size: 13px;">Require SMS or authenticator app verification on login</div>
                    </label>
                </div>
                
                <button class="btn-save">
                    <i class="fas fa-save"></i>
                    Save Security Settings
                </button>
            </div>

            <!-- System Users -->
            <div id="users" class="tab-panel">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="font-size: 18px; font-weight: 600; margin: 0;">System Users</h2>
                    <button class="btn-save">
                        <i class="fas fa-plus"></i>
                        Add User
                    </button>
                </div>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                        <tr>
                            <th style="padding: 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Username</th>
                            <th style="padding: 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Email</th>
                            <th style="padding: 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Role</th>
                            <th style="padding: 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Status</th>
                            <th style="padding: 12px; text-align: right; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #F3F4F6;">
                            <td style="padding: 14px; font-size: 14px; font-weight: 600;">admin</td>
                            <td style="padding: 14px; font-size: 14px;">admin@fortunett.com</td>
                            <td style="padding: 14px;">
                                <span style="background: #DBEAFE; color: #1E40AF; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500;">Administrator</span>
                            </td>
                            <td style="padding: 14px;">
                                <span style="background: #D1FAE5; color: #065F46; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500;">Active</span>
                            </td>
                            <td style="padding: 14px; text-align: right;">
                                <button style="background: none; border: none; color: #3B6EA5; cursor: pointer;">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($action_result): ?>
<div id="alert-toast" style="position: fixed; top: 20px; right: 20px; padding: 16px 24px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 12px; animation: slideIn 0.3s ease-out; z-index: 1000; <?php echo strpos($action_result, 'error') !== false ? 'background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B;' : 'background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46;'; ?>">
    <i class="fas <?php echo strpos($action_result, 'error') !== false ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
    <span style="font-weight: 500; font-size: 14px;"><?php echo explode('|', $action_result)[1]; ?></span>
</div>
<script>
    setTimeout(() => {
        const toast = document.getElementById('alert-toast');
        if (toast) {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease-out';
            setTimeout(() => toast.remove(), 300);
        }
    }, 4000);
</script>
<?php endif; ?>

<script>
function switchTab(tabName) {
    // Hide all panels
    document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Show selected panel
    document.getElementById(tabName).classList.add('active');
    
    // Activate button
    event.target.closest('.tab-btn').classList.add('active');
}
</script>

<?php include 'includes/footer.php'; ?>
