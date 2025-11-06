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
    
    if ($action === 'update_2fa') {
        // 2FA Settings
        $two_factor_enabled = isset($_POST['two_factor_enabled']) ? 1 : 0;
        try {
            $stmt = $db->prepare("UPDATE isp_profile SET two_factor_enabled = ? WHERE id = 1");
            $stmt->execute([$two_factor_enabled]);
            $action_result = 'success|2FA settings updated successfully';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } elseif ($action === 'update_general') {
        // General Settings
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
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div>
        <!-- Page Header -->
        <div style="margin-bottom: 30px;">
            <h1 style="font-size: 28px; margin: 0 0 5px 0;">Settings</h1>
            <div style="color: #666; font-size: 14px;">Manage your system settings and preferences</div>
        </div>

        <!-- Settings Navigation -->
        <div style="display: flex; gap: 20px; margin-bottom: 30px; border-bottom: 2px solid #f3f4f6; padding-bottom: 0; flex-wrap: wrap;">
            <a href="#" onclick="switchTab(event, 'general')" class="settings-tab active" style="padding: 12px 0; border-bottom: 3px solid #667eea; color: #667eea; text-decoration: none; font-weight: 600; font-size: 14px; cursor: pointer;">
                <i class="fas fa-cog me-1"></i>General Settings
            </a>
            <a href="#" onclick="switchTab(event, '2fa')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-shield-alt me-1"></i>2FA Settings
            </a>
            <a href="#" onclick="switchTab(event, 'system-users')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-users-cog me-1"></i>System Users
            </a>
            <a href="#" onclick="switchTab(event, 'system-logs')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-history me-1"></i>System Logs
            </a>
            <a href="#" onclick="switchTab(event, 'refer')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-share-alt me-1"></i>Refer a Friend
            </a>
            <a href="#" onclick="switchTab(event, 'shop')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-shopping-cart me-1"></i>Shop Equipment
            </a>
            <a href="#" onclick="switchTab(event, 'support')" class="settings-tab" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; cursor: pointer; transition: all 0.2s;">
                <i class="fas fa-headset me-1"></i>Contact Support
            </a>
        </div>

        <!-- General Settings Tab -->
        <div id="general" class="settings-content" style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">General Settings</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_general">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Business Name</label>
                        <input type="text" name="business_name" value="<?php echo htmlspecialchars($profile['business_name'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Business Email</label>
                        <input type="email" name="business_email" value="<?php echo htmlspecialchars($profile['business_email'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Business Phone</label>
                        <input type="text" name="business_phone" value="<?php echo htmlspecialchars($profile['business_phone'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Currency</label>
                        <select style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                            <option selected>KES (Kenyan Shilling)</option>
                            <option>USD (US Dollar)</option>
                            <option>EUR (Euro)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Business Address</label>
                    <textarea name="business_address" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; min-height: 80px;"><?php echo htmlspecialchars($profile['business_address'] ?? ''); ?></textarea>
                </div>

                <button type="submit" style="padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
            </form>
        </div>

        <!-- 2FA Settings Tab -->
        <div id="2fa" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">Two-Factor Authentication</h2>
            
            <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                <p style="margin: 0; color: #166534; font-size: 14px;">
                    <i class="fas fa-info-circle me-2"></i>
                    Enable two-factor authentication to add an extra layer of security to your account.
                </p>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_2fa">
                
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                    <input type="checkbox" name="two_factor_enabled" id="2fa_check" <?php echo ($profile['two_factor_enabled'] ?? 0) ? 'checked' : ''; ?> style="width: 20px; height: 20px; cursor: pointer;">
                    <label for="2fa_check" style="cursor: pointer; margin: 0; font-size: 14px;">
                        <strong>Enable 2FA</strong>
                        <div style="color: #666; font-size: 12px; margin-top: 2px;">Require SMS or authenticator app verification on login</div>
                    </label>
                </div>

                <div style="margin-bottom: 20px; padding: 16px; background: #f9fafb; border-radius: 6px;">
                    <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 700;">2FA Method</h4>
                    <div style="display: flex; gap: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                            <input type="radio" name="2fa_method" value="sms" checked>
                            <span style="font-size: 13px;">SMS Authentication</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                            <input type="radio" name="2fa_method" value="app">
                            <span style="font-size: 13px;">Authenticator App</span>
                        </label>
                    </div>
                </div>

                <button type="submit" style="padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                    <i class="fas fa-save me-1"></i>Save 2FA Settings
                </button>
            </form>
        </div>

        <!-- System Users Tab -->
        <div id="system-users" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 700;">System Users</h2>
                <button style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600;">
                    <i class="fas fa-plus me-1"></i>Add User
                </button>
            </div>

            <div style="overflow: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #eee;">
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Username</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Email</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Role</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Status</th>
                            <th style="padding: 12px 8px; text-align: right; font-weight: 600; font-size: 12px; color: #333;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px 8px; font-size: 13px; font-weight: 600;">admin</td>
                            <td style="padding: 12px 8px; font-size: 13px;">admin@fortunett.com</td>
                            <td style="padding: 12px 8px; font-size: 13px;">
                                <span style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">Administrator</span>
                            </td>
                            <td style="padding: 12px 8px; font-size: 13px;">
                                <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">Active</span>
                            </td>
                            <td style="padding: 12px 8px; text-align: right;">
                                <button style="background: none; border: none; color: #667eea; cursor: pointer; font-size: 13px;">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- System Logs Tab -->
        <div id="system-logs" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">System Logs</h2>

            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input type="search" placeholder="Search logs..." style="flex: 1; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                <select style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                    <option>All Types</option>
                    <option>Login</option>
                    <option>Settings</option>
                    <option>Error</option>
                </select>
            </div>

            <div style="overflow: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #eee;">
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Date/Time</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Type</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">User</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px 8px; font-size: 13px;">Dec 15, 2024 10:30 AM</td>
                            <td style="padding: 12px 8px; font-size: 13px;">Login</td>
                            <td style="padding: 12px 8px; font-size: 13px;">admin</td>
                            <td style="padding: 12px 8px; font-size: 13px; color: #10b981;">User logged in</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Refer a Friend Tab -->
        <div id="refer" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">Refer a Friend</h2>

            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0 0 10px 0; font-size: 16px;">Share Your Referral Link</h3>
                <p style="margin: 0 0 20px 0; font-size: 13px; opacity: 0.9;">Earn commissions when your friends sign up using your link</p>
                
                <div style="display: flex; gap: 8px; align-items: center; background: rgba(255,255,255,0.1); padding: 12px; border-radius: 6px;">
                    <input id="referralLink" type="text" value="https://fortunett.com/ref/USER123" readonly style="flex: 1; background: transparent; border: none; color: white; font-size: 13px;">
                    <button onclick="copyReferralLink()" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 13px;">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 24px; font-weight: 700; color: #667eea; margin-bottom: 8px;">0</div>
                    <div style="font-size: 12px; color: #666;">Referrals</div>
                </div>
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 24px; font-weight: 700; color: #10b981; margin-bottom: 8px;">KES 0</div>
                    <div style="font-size: 12px; color: #666;">Earnings</div>
                </div>
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 24px; font-weight: 700; color: #f59e0b; margin-bottom: 8px;">0%</div>
                    <div style="font-size: 12px; color: #666;">Commission Rate</div>
                </div>
            </div>
        </div>

        <!-- Shop Equipment Tab -->
        <div id="shop" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">Shop Equipment</h2>

            <div style="text-align: center; padding: 40px 20px;">
                <i class="fas fa-shopping-bag" style="font-size: 48px; color: #e5e7eb; margin-bottom: 16px; display: block;"></i>
                <p style="color: #666; font-size: 14px; margin: 0;">
                    Equipment shop will be available soon. Check back later for networking and system equipment.
                </p>
            </div>
        </div>

        <!-- Contact Support Tab -->
        <div id="support" class="settings-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 700;">Contact Support</h2>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <div style="background: #f0f9ff; border: 1px solid #bae6fd; padding: 20px; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #0369a1;">Email Support</h4>
                    <p style="margin: 0; font-size: 13px; color: #333;">support@fortunett.com</p>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Response time: 2-4 hours</p>
                </div>

                <div style="background: #fdf2f8; border: 1px solid #fbcfe8; padding: 20px; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #9f1239;">Phone Support</h4>
                    <p style="margin: 0; font-size: 13px; color: #333;">+254 712 345 678</p>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Mon-Fri: 9AM - 6PM EAT</p>
                </div>
            </div>

            <form style="display: grid; gap: 16px;">
                <div>
                    <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Subject</label>
                    <input type="text" placeholder="How can we help?" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                </div>

                <div>
                    <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 8px; text-transform: uppercase;">Message</label>
                    <textarea placeholder="Describe your issue..." style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; min-height: 120px;"></textarea>
                </div>

                <button type="submit" style="padding: 10px 24px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                    <i class="fas fa-paper-plane me-1"></i>Send Message
                </button>
            </form>
        </div>

        <!-- Action Result Alert -->
        <?php if ($action_result): ?>
        <script>
            (function() {
                const [type, message] = '<?php echo $action_result; ?>'.split('|');
                const alertColor = type === 'success' ? '#d1fae5' : '#fee2e2';
                const alertTextColor = type === 'success' ? '#065f46' : '#991b1b';
                const alertEl = document.createElement('div');
                alertEl.style.cssText = `
                    position: fixed;
                    top: 80px;
                    right: 20px;
                    background: ${alertColor};
                    color: ${alertTextColor};
                    padding: 16px 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    z-index: 2000;
                    font-size: 14px;
                    border: 1px solid ${alertTextColor}40;
                `;
                alertEl.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>${message}`;
                document.body.appendChild(alertEl);
                setTimeout(() => alertEl.remove(), 5000);
            })();
        </script>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function switchTab(e, tabName) {
    e.preventDefault();
    
    // Hide all tabs
    document.querySelectorAll('.settings-content').forEach(el => el.style.display = 'none');
    
    // Remove active state from all tabs
    document.querySelectorAll('.settings-tab').forEach(el => {
        el.style.borderBottomColor = 'transparent';
        el.style.color = '#6b7280';
        el.style.fontWeight = '500';
    });
    
    // Show selected tab
    const tab = document.getElementById(tabName);
    if (tab) {
        tab.style.display = 'block';
        e.target.closest('.settings-tab').style.borderBottomColor = '#667eea';
        e.target.closest('.settings-tab').style.color = '#667eea';
        e.target.closest('.settings-tab').style.fontWeight = '600';
    }
}

function copyReferralLink() {
    const link = document.getElementById('referralLink');
    navigator.clipboard.writeText(link.value).then(() => {
        alert('Referral link copied to clipboard!');
    }).catch(() => {
        alert('Failed to copy link');
    });
}
</script>

<style>
.settings-content {
    animation: fadeIn 0.2s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: #667eea !important;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}
</style>


