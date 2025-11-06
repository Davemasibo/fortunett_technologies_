<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Get user ID from URL
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$user_id) {
    header('Location: clients.php');
    exit;
}

// Fetch user details
try {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: clients.php');
        exit;
    }
} catch (Exception $e) {
    die('Error fetching user: ' . $e->getMessage());
}

// Fetch user's package
$package = null;
try {
    $stmt = $db->prepare("SELECT p.* FROM packages p WHERE p.id = ?");
    $stmt->execute([$user['package_id'] ?? 0]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // No package
}

// Fetch user's payments
$payments = [];
try {
    $stmt = $db->prepare("SELECT * FROM payments WHERE client_id = ? ORDER BY payment_date DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // No payments
}

// Determine online status
$is_online = strtolower($user['status'] ?? '') === 'active' ? true : false;
$status_text = $is_online ? 'Currently Online' : 'Offline';
$status_color = $is_online ? '#10b981' : '#6b7280';

// Calculate time remaining
$time_remaining = 'Expired';
if (!empty($user['expiry_date'])) {
    $expiry = new DateTime($user['expiry_date']);
    $now = new DateTime();
    if ($expiry > $now) {
        $diff = $now->diff($expiry);
        $time_remaining = $diff->days . ' days ' . $diff->h . ' hours ' . $diff->i . ' minutes';
    }
}

// Handle actions
$action_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'pause') {
        try {
            $stmt = $db->prepare("UPDATE clients SET status = 'paused' WHERE id = ?");
            $stmt->execute([$user_id]);
            $action_result = 'success|Subscription paused successfully';
            $user['status'] = 'paused';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } elseif ($action === 'unpause') {
        try {
            $stmt = $db->prepare("UPDATE clients SET status = 'active' WHERE id = ?");
            $stmt->execute([$user_id]);
            $action_result = 'success|Subscription resumed successfully';
            $user['status'] = 'active';
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    } elseif ($action === 'change_expiry') {
        // update expiry_date for this user
        $newDate = trim($_POST['new_date'] ?? '');
        if ($newDate) {
            try {
                $stmt = $db->prepare("UPDATE clients SET expiry_date = ? WHERE id = ?");
                $stmt->execute([$newDate, $user_id]);
                $user['expiry_date'] = $newDate;
                $action_result = 'success|Expiry updated to ' . htmlspecialchars($newDate);
            } catch (Exception $e) {
                $action_result = 'error|' . $e->getMessage();
            }
        } else {
            $action_result = 'error|Invalid date provided';
        }
    } elseif ($action === 'update_user') {
        try {
            $full_name = $_POST['full_name'] ?? $user['full_name'];
            $phone = $_POST['phone'] ?? $user['phone_number'];
            $address = $_POST['address'] ?? $user['address'];
            $email = $_POST['email'] ?? $user['email'];
            
            $stmt = $db->prepare("UPDATE clients SET full_name = ?, phone_number = ?, address = ?, email = ? WHERE id = ?");
            $stmt->execute([$full_name, $phone, $address, $email, $user_id]);
            
            $action_result = 'success|User information updated successfully';
            $user['full_name'] = $full_name;
            $user['phone_number'] = $phone;
            $user['address'] = $address;
            $user['email'] = $email;
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
        <!-- Back Button -->
        <div style="margin-bottom: 20px;">
            <a href="clients.php" style="color: #667eea; text-decoration: none; font-size: 14px; font-weight: 500;">
                <i class="fas fa-arrow-left me-2"></i>Back to Users
            </a>
        </div>

        <!-- User Header with Status -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="flex: 1;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: 700;">
                        <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div>
                        <h1 style="font-size: 24px; margin: 0; font-weight: 700;"><?php echo htmlspecialchars($user['full_name'] ?? 'Unknown'); ?></h1>
                        <div style="color: #666; font-size: 13px;">@<?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
                    </div>
                    <span style="background: <?php echo $status_color; ?>20; color: <?php echo $status_color; ?>; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $status_color; ?>40; margin-left: auto;">
                        <i class="fas fa-circle me-1" style="font-size: 8px;"></i><?php echo $status_text; ?>
                    </span>
                </div>
                <div style="color: #666; font-size: 13px; margin-top: 12px;">
                    <i class="fas fa-box me-1"></i>Package: <strong><?php echo htmlspecialchars($package['name'] ?? 'N/A'); ?></strong> | 
                    <i class="fas fa-calendar me-1"></i>Expires: <strong><?php echo !empty($user['expiry_date']) ? date('F j, Y g:i A', strtotime($user['expiry_date'])) : 'N/A'; ?></strong>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if (strtolower($user['status'] ?? 'active') === 'active'): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="pause">
                    <button type="submit" style="padding: 8px 16px; border: 1px solid #fbbf24; background: #fef3c7; color: #92400e; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s;">
                        <i class="fas fa-pause me-1"></i>Pause
                    </button>
                </form>
                <?php else: ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="unpause">
                    <button type="submit" style="padding: 8px 16px; border: 1px solid #10b981; background: #d1fae5; color: #065f46; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s;">
                        <i class="fas fa-play me-1"></i>Unpause
                    </button>
                </form>
                <?php endif; ?>
                
                <button onclick="toggleEdit()" style="padding: 8px 16px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s; color: #333;">
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                
                <!-- link to dedicated STK payment page where phone/amount can be edited -->
                <a href="stk_payment.php?user_id=<?php echo (int)$user_id; ?>" class="btn" style="padding:8px 16px;background:#06b6d4;color:#fff;border-radius:6px;text-decoration:none;font-weight:500;">
                    <i class="fas fa-mobile-alt me-1"></i>Send STK Push
                </a>

                <!-- Change expiry: open a small prompt form (handled server-side) -->
                <button onclick="openChangeExpiry()" style="padding:8px 16px;border:1px solid #e5e7eb;background:#fff;border-radius:6px;">
                    Change Expiry
                </button>
            </div>
        </div>

        <!-- STK Push Modal -->
        <div id="stkModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 20px 25px rgba(0,0,0,0.15); max-width: 500px; width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Send STK Push Payment</h2>
                    <button onclick="toggleSTKModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280;">×</button>
                </div>

                <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #166534;">
                    <i class="fas fa-info-circle me-2"></i>
                    A payment prompt will be sent to the customer's phone. They will receive an STK push and can pay directly from their phone.
                </div>

                <form method="POST" onsubmit="return validateSTKForm()">
                    <input type="hidden" name="action" value="send_stk_push">
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Phone Number</label>
                        <input type="text" id="stkPhone" name="stk_phone" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" placeholder="e.g., 0712345678 or 254712345678" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;" required>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Format: 0712345678 or 254712345678</small>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Amount (KES)</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 16px; font-weight: 600;">KES</span>
                            <input type="number" id="stkAmount" name="stk_amount" value="<?php echo htmlspecialchars($package['price'] ?? 0); ?>" placeholder="0" step="0.01" min="0" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;" required>
                        </div>
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Package: <?php echo htmlspecialchars($package['name'] ?? 'N/A'); ?> - KES <?php echo number_format($package['price'] ?? 0, 2); ?></small>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" style="flex: 1; padding: 12px 16px; background: #06b6d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                            <i class="fas fa-paper-plane me-1"></i>Send STK Push
                        </button>
                        <button type="button" onclick="toggleSTKModal()" style="flex: 1; padding: 12px 16px; background: #f3f4f6; color: #333; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 20px 25px rgba(0,0,0,0.15); max-width: 500px; width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Edit User Information</h2>
                    <button onclick="toggleEdit()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280;">×</button>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_user">
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Phone Number</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Address</label>
                        <textarea name="address" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; min-height: 80px;"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" style="flex: 1; padding: 10px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                            Save Changes
                        </button>
                        <button type="button" onclick="toggleEdit()" style="flex: 1; padding: 10px 16px; background: #f3f4f6; color: #333; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabs -->
        <div style="display: flex; gap: 30px; margin-bottom: 20px; border-bottom: 2px solid #f3f4f6; padding-bottom: 0;">
            <a href="#general" onclick="switchTab(event, 'general')" class="tab-link active" style="padding: 12px 0; border-bottom: 3px solid #667eea; color: #667eea; text-decoration: none; font-weight: 600; font-size: 14px;">
                <i class="fas fa-list me-1"></i>General Information
            </a>
            <a href="#payments" onclick="switchTab(event, 'payments')" class="tab-link" style="padding: 12px 0; border-bottom: 3px solid transparent; color: #6b7280; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s;">
                <i class="fas fa-credit-card me-1"></i>Payments
            </a>
        </div>

        <!-- General Information Tab -->
        <div id="general" class="tab-content" style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <!-- Left Column -->
                <div>
                    <h3 style="margin: 0 0 20px 0; font-size: 14px; font-weight: 700; text-transform: uppercase; color: #666;">Account Information</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Account ID</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['id'] ?? ''); ?></span>
                            <button onclick="copyText('<?php echo $user['id']; ?>')" style="background: none; border: none; color: #667eea; cursor: pointer; font-size: 12px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Username</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['username'] ?? ''); ?></span>
                            <button onclick="copyText('<?php echo $user['username']; ?>')" style="background: none; border: none; color: #667eea; cursor: pointer; font-size: 12px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Full Name</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Package</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($package['name'] ?? 'N/A'); ?></span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Status</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px; text-transform: capitalize;"><?php echo htmlspecialchars($user['status'] ?? 'active'); ?></span>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <h3 style="margin: 0 0 20px 0; font-size: 14px; font-weight: 700; text-transform: uppercase; color: #666;">Contact Information</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Phone Number</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></span>
                            <button onclick="copyText('<?php echo $user['phone_number']; ?>')" style="background: none; border: none; color: #667eea; cursor: pointer; font-size: 12px;">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Email</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Address</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo htmlspecialchars($user['address'] ?? 'N/A'); ?></span>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 11px; color: #999; font-weight: 600; margin-bottom: 6px; text-transform: uppercase;">Time Remaining</label>
                        <span style="font-weight: 600; color: #333; font-size: 14px;"><?php echo $time_remaining; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payments Tab -->
        <div id="payments" class="tab-content" style="display: none; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px 0; font-size: 16px; font-weight: 700;">Payment History</h3>
            <div style="overflow: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #eee;">
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Date</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Amount</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Status</th>
                            <th style="padding: 12px 8px; text-align: left; font-weight: 600; font-size: 12px; color: #333;">Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6;">
                                <td style="padding: 12px 8px; font-size: 13px;"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                <td style="padding: 12px 8px; font-size: 13px; font-weight: 600;">KES <?php echo number_format($payment['amount'], 2); ?></td>
                                <td style="padding: 12px 8px; font-size: 13px;">
                                    <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo ucfirst($payment['status']); ?></span>
                                </td>
                                <td style="padding: 12px 8px; font-size: 13px;"><?php echo htmlspecialchars($payment['payment_method'] ?? 'Unknown'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="4" style="padding: 20px; text-align: center; color: #6b7280;">No payments found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    
    // Remove active state from all links
    document.querySelectorAll('.tab-link').forEach(el => {
        el.style.borderBottomColor = 'transparent';
        el.style.color = '#6b7280';
        el.style.fontWeight = '500';
    });
    
    // Show selected tab
    const tab = document.getElementById(tabName);
    if (tab) {
        tab.style.display = 'block';
        e.target.closest('.tab-link').style.borderBottomColor = '#667eea';
        e.target.closest('.tab-link').style.color = '#667eea';
        e.target.closest('.tab-link').style.fontWeight = '600';
    }
}

function toggleSTKModal() {
    const modal = document.getElementById('stkModal');
    modal.style.display = modal.style.display === 'none' ? 'flex' : 'none';
}

function toggleEdit() {
    const modal = document.getElementById('editModal');
    modal.style.display = modal.style.display === 'none' ? 'flex' : 'none';
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Copied to clipboard!');
    }).catch(() => {
        alert('Failed to copy');
    });
}

function validateSTKForm() {
    const phone = document.getElementById('stkPhone').value.trim();
    const amount = document.getElementById('stkAmount').value;
    
    if (!phone) {
        alert('Please enter a phone number');
        return false;
    }
    
    if (!amount || parseFloat(amount) <= 0) {
        alert('Please enter a valid amount');
        return false;
    }
    
    // Format validation
    if (!/^(\+?254|0)[0-9]{9}$/.test(phone.replace(/\s/g, ''))) {
        alert('Please enter a valid phone number (e.g., 0712345678 or 254712345678)');
        return false;
    }
    
    return confirm(`Send STK Push to ${phone} for KES ${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2})}?`);
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    const stkModal = document.getElementById('stkModal');
    const editModal = document.getElementById('editModal');
    
    if (e.target === stkModal) {
        toggleSTKModal();
    }
    if (e.target === editModal) {
        toggleEdit();
    }
});

// Replace old inline changeExpiry() implementation with a proper form submission
function openChangeExpiry() {
    const newDate = prompt('Enter new expiry date (YYYY-MM-DD HH:MM:SS):', '<?php echo addslashes($user['expiry_date'] ?? ''); ?>');
    if (!newDate) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    form.innerHTML = '<input type="hidden" name="action" value="change_expiry">' +
                     '<input type="hidden" name="new_date" value="' + newDate.replace(/"/g,'&quot;') + '">';
    document.body.appendChild(form);
    form.submit();
}
</script>

<style>
.tab-content {
    animation: fadeIn 0.2s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

input, textarea {
    font-family: inherit;
}

input:focus, textarea:focus {
    outline: none;
    border-color: #667eea !important;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}
</style>
