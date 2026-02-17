<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

// Get current user and tenant info
$user_id = $_SESSION['user_id'];

// Handle username update
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_username'])) {
    $new_username = trim($_POST['username'] ?? '');
    if (empty($new_username)) {
        $error_message = 'Username cannot be empty';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$new_username, $user_id]);
            $success_message = 'Username updated successfully';
        } catch (PDOException $e) {
            $error_message = 'Failed to update username: ' . $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare("SELECT u.*, t.company_name, t.subdomain 
                       FROM users u 
                       LEFT JOIN tenants t ON u.tenant_id = t.id 
                       WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$tenant_id = $user['tenant_id'];

// Fetch tenant settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .profile-container {
        padding: 30px;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .profile-header {
        margin-bottom: 32px;
    }
    
    .profile-header h1 {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 8px 0;
    }
    
    .profile-header p {
        color: #6B7280;
        font-size: 14px;
        margin: 0;
    }
    
    .profile-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .profile-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        border: 1px solid #E5E7EB;
    }
    
    .profile-card:hover {
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        transform: translateY(-2px);
        border-color: var(--primary-color);
    }
    
    .card-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
        font-size: 20px;
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: white;
    }
    
    .card-title {
        font-size: 14px;
        font-weight: 500;
        color: #6B7280;
        margin: 0 0 4px 0;
    }
    
    .card-value {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
        margin: 0;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 0;
        border-bottom: 1px solid #F3F4F6;
    }
    
    .info-row:last-child {
        border-bottom: none;
    }
    
    .info-label {
        font-size: 14px;
        font-weight: 500;
        color: #6B7280;
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: #111827;
    }
    
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        background: var(--primary-color);
        color: white;
    }
    
    .full-width-card {
        grid-column: 1 / -1;
    }
    
    .edit-section {
        background: #F9FAFB;
        border-radius: 8px;
        padding: 16px;
        margin-top: 20px;
    }
    
    .edit-form {
        display: flex;
        gap: 12px;
        align-items: end;
    }
    
    .edit-input {
        flex: 1;
        padding: 10px 14px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .btn-save {
        padding: 10px 20px;
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: transform 0.2s;
    }
    
    .btn-save:hover {
        transform: translateY(-1px);
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .alert-success {
        background: #D1FAE5;
        color: #065F46;
        border: 1px solid #10B981;
    }
    
    .alert-error {
        background: #FEE2E2;
        color: #991B1B;
        border: 1px solid #EF4444;
    }
</style>

<div class="main-content-wrapper">
    <div class="profile-container">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Header -->
        <div class="profile-header">
            <h1>Tenant Profile</h1>
            <p>View and manage your tenant account information</p>
        </div>
        
        <!-- Stats Cards -->
        <div class="profile-grid">
            <div class="profile-card">
                <div class="card-icon">
                    <i class="fas fa-building"></i>
                </div>
                <p class="card-title">Company Name</p>
                <p class="card-value"><?php echo htmlspecialchars($user['company_name'] ?? 'Not Set'); ?></p>
            </div>
            
            <div class="profile-card">
                <div class="card-icon">
                    <i class="fas fa-link"></i>
                </div>
                <p class="card-title">Subdomain URL</p>
                <p class="card-value" style="font-size: 14px; word-break: break-all;">
                    <?php 
                    $subdomain = $user['subdomain'] ?? 'not-set';
                    $fullUrl = "https://{$subdomain}.yourdomain.com";
                    echo htmlspecialchars($fullUrl); 
                    ?>
                </p>
            </div>
            
            <div class="profile-card">
                <div class="card-icon">
                    <i class="fas fa-user"></i>
                </div>
                <p class="card-title">Username</p>
                <p class="card-value"><?php echo htmlspecialchars($user['username']); ?></p>
                
                <!-- Edit Username Section -->
                <div class="edit-section">
                    <form method="POST" class="edit-form">
                        <input type="text" name="username" class="edit-input" value="<?php echo htmlspecialchars($user['username']); ?>" placeholder="Enter new username" required>
                        <button type="submit" name="update_username" class="btn-save">
                            <i class="fas fa-save"></i> Update
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="profile-card">
                <div class="card-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <p class="card-title">Role</p>
                <p class="card-value"><span class="badge"><?php echo ucfirst($user['role']); ?></span></p>
            </div>
        </div>
        
        <!-- Account Details -->
        <div class="profile-grid">
            <div class="profile-card full-width-card">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 20px 0; color: #111827;">Account Details</h3>
                
                <div class="info-row">
                    <span class="info-label">Email Address</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Support Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($settings['support_number'] ?? 'Not Set'); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Support Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($settings['support_email'] ?? 'Not Set'); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Brand Color</span>
                    <span class="info-value">
                        <span style="display: inline-flex; align-items: center; gap: 8px;">
                            <span style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($settings['brand_color'] ?? '#3B6EA5'); ?>; border: 1px solid #E5E7EB;"></span>
                            <?php echo htmlspecialchars($settings['brand_color'] ?? '#3B6EA5'); ?>
                        </span>
                    </span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Member Since</span>
                    <span class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></span>
                </div>
            </div>
        </div>
        
    </div>
</div>

<?php include 'includes/footer.php'; ?>