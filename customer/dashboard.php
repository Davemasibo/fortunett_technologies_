<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/validity.php';
$customer = requireCustomerLogin();

// The portal stays available for account management after internet access expires.
$connectionPending=false;
try{$pending=$pdo->prepare('SELECT COUNT(*) FROM pending_provisions WHERE client_id=? AND tenant_id=?');$pending->execute([$customer['id'],$customer['tenant_id']]);$connectionPending=(int)$pending->fetchColumn()>0;}catch(PDOException $e){error_log('Customer setup status unavailable');}

// Get package details
$package = null;
if ($customer['package_id']) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$customer['package_id']]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate days until expiry
$daysLeft = getDaysUntilExpiry($customer['expiry_date']);
$isActive = ($customer['status'] ?? '') === 'active' && isSubscriptionActive($customer['expiry_date']);

$isDemo = isset($_GET['demo']) && $_GET['demo'] === '1'
       && ($customer['username'] ?? '') === '__demo_preview__';

include 'includes/header.php';
?>

<?php if ($isDemo): ?>
<div style="background:linear-gradient(90deg,#0f4c3a,#1a6b50);color:#d1fae5;padding:10px 20px;font-size:13px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.1);position:sticky;top:0;z-index:1000;">
    <i class="fas fa-eye" style="font-size:16px;"></i>
    <span><strong>Admin Preview Mode</strong> — You are viewing the customer portal as a demo user. Data shown is not real.</span>
    <a href="javascript:window.close()" style="margin-left:auto;color:#6ee7b7;font-weight:600;text-decoration:none;">✕ Close Preview</a>
</div>
<?php endif; ?>

<div class="dashboard-container">
    <?php if (($_GET['payment'] ?? '') === 'success'): ?>
    <div class="customer-payment-banner" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><div><strong>Welcome to your customer account</strong><small>You are signed in. Your current subscription and payment history are shown below.</small></div></div>
    <?php endif; ?>
    <?php if ($connectionPending): ?><div class="customer-payment-banner" role="status"><div><strong>Your internet connection is being set up</strong><small>Your account is available. Router setup will retry automatically; do not pay again. <?php if (!empty($customer['bound_mac_address'])): ?>Keep your purchased device connected to Wi-Fi.<?php else: ?>Once setup completes, use Connect to Internet below.<?php endif; ?></small></div></div><?php endif; ?>
    <?php if (($customer['connection_type'] ?? '') === 'hotspot' && empty($customer['bound_mac_address'])): ?><div style="margin-bottom:20px"><a class="btn-primary" style="display:inline-flex;padding:12px 20px;align-items:center;text-decoration:none" href="http://hotspot.fortunett.com/login">Connect to Internet</a></div><?php endif; ?>
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
    
    <?php if (!empty($customer['mikrotik_username']) && !empty($customer['mikrotik_password'])): ?>
    <section class="customer-credentials"><h2>Your internet sign-in</h2><p>Use these credentials to reconnect to the Hotspot or sign in to this customer portal.</p><div class="customer-credentials-grid"><label>Username<input id="customer-network-user" readonly autocomplete="off" value="<?= htmlspecialchars($customer['mikrotik_username'],ENT_QUOTES) ?>"><button type="button" onclick="copyNetworkCredential('customer-network-user')">Copy username</button></label><label>Password<input id="customer-network-password" type="password" readonly autocomplete="off" value="<?= htmlspecialchars($customer['mikrotik_password'],ENT_QUOTES) ?>"><button type="button" aria-pressed="false" onclick="const field=document.getElementById('customer-network-password');const show=field.type==='password';field.type=show?'text':'password';this.textContent=show?'Hide password':'Show password';this.setAttribute('aria-pressed',String(show))">Show password</button> <button type="button" onclick="copyNetworkCredential('customer-network-password')">Copy password</button></label></div><span id="credential-copy-status" role="status"></span></section>
    <script>
    async function copyNetworkCredential(id){const field=document.getElementById(id),status=document.getElementById('credential-copy-status');try{await navigator.clipboard.writeText(field.value);status.textContent='Copied. Keep your credentials private.';}catch(e){status.textContent='Copy unavailable. Show the value and select it to copy manually.';}}
    </script>
    <?php endif; ?>

    <!-- Status Cards -->
    <div class="status-grid">
        <div class="status-card <?php echo $isActive ? 'active' : 'inactive'; ?>">
            <div class="status-icon">
                <i class="fas fa-<?php echo $isActive ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            </div>
            <div class="status-info">
                <div class="status-label">Subscription</div>
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
                    <div class="price-period">for <?php echo htmlspecialchars(packageValidityLabel($package['validity_value'], $package['validity_unit'])); ?></div>
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
                    <span>Expires: <?php echo formatDateTime($customer['expiry_date']); ?></span>
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
