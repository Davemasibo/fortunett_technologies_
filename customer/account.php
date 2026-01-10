<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

// Get package details
$package = null;
if ($customer['package_id']) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$customer['package_id']]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
}

include 'includes/header.php';
?>

<div class="account-container">
    <div class="page-header">
        <h1><i class="fas fa-user-circle"></i> My Account</h1>
        <p>Manage your profile and account settings</p>
    </div>
    
    <div class="account-grid">
        <!-- Account Information -->
        <div class="account-card">
            <div class="card-header">
                <h2><i class="fas fa-id-card"></i> Account Information</h2>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['full_name'] ?? $customer['name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Account Number</div>
                    <div class="info-value account-number"><?php echo htmlspecialchars($customer['account_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['email'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['phone'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['address'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Username</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['username'] ?? $customer['mikrotik_username']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Subscription Details -->
        <div class="account-card">
            <div class="card-header">
                <h2><i class="fas fa-box"></i> Subscription Details</h2>
            </div>
            <div class="card-body">
                <?php if ($package): ?>
                <div class="info-row">
                    <div class="info-label">Current Package</div>
                    <div class="info-value"><?php echo htmlspecialchars($package['name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Monthly Fee</div>
                    <div class="info-value"><?php echo formatCurrency($package['price']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Speed</div>
                    <div class="info-value"><?php echo $package['download_speed']; ?>/<?php echo $package['upload_speed']; ?> Mbps</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Data Limit</div>
                    <div class="info-value"><?php echo $package['data_limit'] > 0 ? number_format($package['data_limit'] / 1073741824, 0) . ' GB' : 'Unlimited'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Expiry Date</div>
                    <div class="info-value <?php echo isSubscriptionActive($customer['expiry_date']) ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatDate($customer['expiry_date']); ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge <?php echo strtolower($customer['status']); ?>">
                            <?php echo ucfirst($customer['status']); ?>
                        </span>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-box-open"></i>
                    <p>No active package</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Account Balance Card -->
    <div class="balance-card">
        <div class="balance-icon">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="balance-info">
            <div class="balance-label">Account Balance</div>
            <div class="balance-amount"><?php echo formatCurrency($customer['account_balance'] ?? 0); ?></div>
        </div>
        <a href="payment.php" class="btn-topup">
            <i class="fas fa-plus"></i> Top Up
        </a>
    </div>
    
    <!-- Update Profile Form -->
    <div class="account-card">
        <div class="card-header">
            <h2><i class="fas fa-edit"></i> Update Profile</h2>
        </div>
        <div class="card-body">
            <form id="updateProfileForm" onsubmit="handleProfileUpdate(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" 
                               value="<?php echo htmlspecialchars($customer['full_name'] ?? $customer['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control" 
                               value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" 
                               value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <!-- Change Password -->
    <div class="account-card">
        <div class="card-header">
            <h2><i class="fas fa-lock"></i> Change Password</h2>
        </div>
        <div class="card-body">
            <form id="changePasswordForm" onsubmit="handlePasswordChange(event)">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.account-container {
    max-width: 1200px;
    margin: 0 auto;
}

.account-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.account-card {
    background: white;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
}

.card-header {
    padding: 24px;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.card-header h2 {
    font-size: 18px;
    font-weight: 600;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-body {
    padding: 24px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid var(--gray-100);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-size: 13px;
    color: var(--gray-500);
    font-weight: 500;
}

.info-value {
    font-size: 14px;
    color: var(--gray-900);
    font-weight: 600;
}

.account-number {
    font-family: monospace;
    background: var(--gray-100);
    padding: 4px 12px;
    border-radius: 6px;
}

.text-success {
    color: var(--success);
}

.text-danger {
    color: var(--danger);
}

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

.balance-card {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
    color: white;
    padding: 32px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 24px;
}

.balance-icon {
    width: 64px;
    height: 64px;
    background: rgba(255,255,255,0.2);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
}

.balance-info {
    flex: 1;
}

.balance-label {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 4px;
}

.balance-amount {
    font-size: 32px;
    font-weight: 700;
}

.btn-topup {
    padding: 12px 24px;
    background: white;
    color: var(--primary);
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-topup:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 8px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(44,82,130,0.1);
}

.empty-message {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray-400);
}

.empty-message i {
    font-size: 48px;
    margin-bottom: 12px;
}

@media (max-width: 968px) {
    .account-grid {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .balance-card {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<script>
const API_BASE = '/fortunett_technologies_/api/customer';

async function handleProfileUpdate(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const btn = e.target.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
    
    try {
        const response = await fetch(API_BASE + '/update_profile.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Profile updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Update failed'));
        }
    } catch (error) {
        alert('Connection error. Please try again.');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

async function handlePasswordChange(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    if (formData.get('new_password') !== formData.get('confirm_password')) {
        alert('New passwords do not match!');
        return;
    }
    
    const btn = e.target.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    btn.disabled = true;
    
    try {
        const response = await fetch(API_BASE + '/change_password.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Password updated successfully!');
            e.target.reset();
        } else {
            alert('Error: ' + (data.message || 'Update failed'));
        }
    } catch (error) {
        alert('Connection error. Please try again.');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
