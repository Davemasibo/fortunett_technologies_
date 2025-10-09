<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

$isp_profile = $pdo->query("SELECT * FROM isp_profile LIMIT 1")->fetch();

$expiry_date = new DateTime($isp_profile['subscription_expiry']);
$today = new DateTime();
$days_remaining = $today < $expiry_date ? $today->diff($expiry_date)->days : 0;
$is_expired = $today > $expiry_date;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew'])) {
    $months = intval($_POST['months'] ?? 1);
    $new_expiry = $is_expired ? new DateTime() : new DateTime($isp_profile['subscription_expiry']);
    $new_expiry->modify("+{$months} months");
    
    $stmt = $pdo->prepare("UPDATE isp_profile SET subscription_expiry = ? WHERE id = ?");
    $stmt->execute([$new_expiry->format('Y-m-d'), $isp_profile['id']]);
    
    header("Location: subscription.php?success=1");
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; border-radius: 15px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
            <h1 style="margin: 0 0 15px 0; font-size: 36px;"><i class="fas fa-crown me-3"></i><?php echo htmlspecialchars($isp_profile['business_name']); ?></h1>
            <div style="display: inline-block; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 25px; font-size: 16px;">
                <?php if ($is_expired): ?>
                    <i class="fas fa-exclamation-triangle me-2"></i>Subscription Expired
                <?php elseif ($days_remaining <= 7): ?>
                    <i class="fas fa-clock me-2"></i>Expires in <?php echo $days_remaining; ?> days
                <?php else: ?>
                    <i class="fas fa-check-circle me-2"></i>Active Subscription
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_expired): ?>
            <div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                <i class="fas fa-exclamation-circle me-2"></i><strong>Your subscription has expired!</strong> Please renew to continue using the system.
            </div>
        <?php elseif ($days_remaining <= 7): ?>
            <div style="padding: 20px; background: #fff3cd; color: #856404; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                <i class="fas fa-clock me-2"></i><strong>Renewal reminder:</strong> Your subscription expires in <?php echo $days_remaining; ?> days.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div style="padding: 20px; background: #d4edda; color: #155724; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <i class="fas fa-check-circle me-2"></i>Subscription renewed successfully!
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 30px;">
            <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 20px 0; color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 10px;"><i class="fas fa-info-circle me-2"></i>Plan Details</h3>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Plan Type</span>
                    <strong>Professional</strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Status</span>
                    <strong style="color: <?php echo $is_expired ? '#dc3545' : ($days_remaining <= 7 ? '#ffc107' : '#28a745'); ?>">
                        <?php echo $is_expired ? 'Expired' : 'Active'; ?>
                    </strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Expiry Date</span>
                    <strong><?php echo $expiry_date->format('F d, Y'); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0;">
                    <span style="color: #666;">Days Remaining</span>
                    <strong><?php echo $days_remaining; ?> days</strong>
                </div>
            </div>

            <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 20px 0; color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 10px;"><i class="fas fa-building me-2"></i>Business Info</h3>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Business Name</span>
                    <strong><?php echo htmlspecialchars($isp_profile['business_name']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Email</span>
                    <strong><?php echo htmlspecialchars($isp_profile['email']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                    <span style="color: #666;">Phone</span>
                    <strong><?php echo htmlspecialchars($isp_profile['phone']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 12px 0;">
                    <span style="color: #666;">Member Since</span>
                    <strong><?php echo date('F Y', strtotime($isp_profile['created_at'])); ?></strong>
                </div>
            </div>
        </div>

        <div style="background: white; padding: 35px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 25px 0; color: #333;"><i class="fas fa-sync-alt me-3"></i>Renew Subscription</h2>
            <form method="POST">
                <div style="display: grid; gap: 15px;">
                    <label style="padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid transparent; cursor: pointer; transition: all 0.3s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#fff'" onmouseout="this.style.borderColor='transparent'; this.style.background='#f8f9fa'">
                        <input type="radio" name="months" value="1" checked style="margin-right: 10px;">
                        <strong style="font-size: 18px;">1 Month Plan</strong>
                        <div style="font-size: 28px; font-weight: bold; color: #667eea; margin-top: 8px;">KES 5,000</div>
                    </label>
                    <label style="padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid transparent; cursor: pointer; transition: all 0.3s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#fff'" onmouseout="this.style.borderColor='transparent'; this.style.background='#f8f9fa'">
                        <input type="radio" name="months" value="3" style="margin-right: 10px;">
                        <strong style="font-size: 18px;">3 Months Plan</strong> <small style="color: #28a745; font-weight: 600;">(Save 10%)</small>
                        <div style="font-size: 28px; font-weight: bold; color: #667eea; margin-top: 8px;">KES 13,500</div>
                    </label>
                    <label style="padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid transparent; cursor: pointer; transition: all 0.3s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#fff'" onmouseout="this.style.borderColor='transparent'; this.style.background='#f8f9fa'">
                        <input type="radio" name="months" value="6" style="margin-right: 10px;">
                        <strong style="font-size: 18px;">6 Months Plan</strong> <small style="color: #28a745; font-weight: 600;">(Save 15%)</small>
                        <div style="font-size: 28px; font-weight: bold; color: #667eea; margin-top: 8px;">KES 25,500</div>
                    </label>
                    <label style="padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid transparent; cursor: pointer; transition: all 0.3s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#fff'" onmouseout="this.style.borderColor='transparent'; this.style.background='#f8f9fa'">
                        <input type="radio" name="months" value="12" style="margin-right: 10px;">
                        <strong style="font-size: 18px;">12 Months Plan</strong> <small style="color: #28a745; font-weight: 600;">(Save 20%)</small>
                        <div style="font-size: 28px; font-weight: bold; color: #667eea; margin-top: 8px;">KES 48,000</div>
                    </label>
                </div>
                <button type="submit" name="renew" style="width: 100%; margin-top: 25px; padding: 18px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; border-radius: 10px; font-size: 18px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-check me-2"></i>Renew Subscription Now
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
