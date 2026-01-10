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

// Calculate days until expiry
$daysLeft = getDaysUntilExpiry($customer['expiry_date']);
$isActive = isSubscriptionActive($customer['expiry_date']);

include 'includes/header.php';
?>

<div class="dashboard-container">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-content">
            <h1>Welcome back, <?php echo htmlspecialchars($customer['full_name'] ?? $customer['name']); ?>!</h1>
            <p>Manage your internet subscription and account</p>
        </div>
        <div class="account-badge">
            <span class="badge-label">Account</span>
            <span class="badge-value"><?php echo htmlspecialchars($customer['account_number']); ?></span>
        </div>
    </div>
    
    <!-- Status Cards -->
    <div class="status-grid">
        <div class="status-card <?php echo $isActive ? 'active' : 'inactive'; ?>">
            <div class="status-icon">
                <i class="fas fa-<?php echo $isActive ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            </div>
            <div class="status-info">
                <div class="status-label">Connection Status</div>
                <div class="status-value"><?php echo $isActive ? 'Active' : 'Expired'; ?></div>
            </div>
        </div>
        
        <div class="status-card">
            <div class="status-icon balance">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="status-info">
                <div class="status-label">Account Balance</div>
                <div class="status-value"><?php echo formatCurrency($customer['account_balance'] ?? 0); ?></div>
            </div>
        </div>
        
        <div class="status-card">
            <div class="status-icon expiry">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="status-info">
                <?php 
                    $now = new DateTime();
                    $expiry = new DateTime($customer['expiry_date']);
                    $interval = $now->diff($expiry);
                    
                    if ($now > $expiry) {
                        $timeLeft = "Expired";
                        $timeLabel = "Status";
                    } else {
                        $timeLabel = "Time Remaining";
                        if ($interval->days > 0) {
                            $timeLeft = $interval->days . " days";
                        } elseif ($interval->h > 0) {
                            $timeLeft = $interval->h . " hours";
                        } else {
                            $timeLeft = $interval->i . " min";
                        }
                    }
                ?>
                <div class="status-label"><?php echo $timeLabel; ?></div>
                <div class="status-value"><?php echo $timeLeft; ?></div>
            </div>
        </div>
    </div>
    
    <!-- Current Package -->
    <?php if ($package): ?>
    <div class="package-section">
        <div class="section-header">
            <h2><i class="fas fa-box"></i> Current Package</h2>
            <a href="packages.php" class="btn-change">Change Plan</a>
        </div>
        
        <div class="package-card current">
            <div class="package-header">
                <div class="package-icon">
                    <i class="fas fa-wifi"></i>
                </div>
                <div class="package-info">
                    <h3><?php echo htmlspecialchars($package['name']); ?></h3>
                    <p><?php echo htmlspecialchars($package['description'] ?? ''); ?></p>
                </div>
                <div class="package-price">
                    <div class="price-amount"><?php echo formatCurrency($package['price']); ?></div>
                    <div class="price-period">/<?php echo $package['validity_unit'] ?? 'month'; ?></div>
                </div>
            </div>
            
            <div class="package-features">
                <div class="feature-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Speed: <?php echo $package['download_speed']; ?>/<?php echo $package['upload_speed']; ?> Mbps</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-database"></i>
                    <span>Data: <?php echo $package['data_limit'] > 0 ? number_format($package['data_limit'] / 1073741824, 0) . ' GB' : 'Unlimited'; ?></span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-calendar"></i>
                    <span>Expires: <?php echo formatDate($customer['expiry_date']); ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="package-section">
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No Active Package</h3>
            <p>Choose a package to get started</p>
            <a href="packages.php" class="btn-primary">Browse Packages</a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <div class="actions-section">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
        <div class="actions-grid">
            <a href="payment.php" class="action-card">
                <div class="action-icon payment">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="action-info">
                    <h3>Make Payment</h3>
                    <p>Pay for your subscription</p>
                </div>
            </a>
            
            <a href="packages.php" class="action-card">
                <div class="action-icon packages">
                    <i class="fas fa-box"></i>
                </div>
                <div class="action-info">
                    <h3>View Packages</h3>
                    <p>Explore available plans</p>
                </div>
            </a>
            
            <a href="account.php" class="action-card">
                <div class="action-icon account">
                    <i class="fas fa-user"></i>
                </div>
                <div class="action-info">
                    <h3>My Account</h3>
                    <p>Update your profile</p>
                </div>
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
