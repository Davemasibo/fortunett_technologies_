<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$message = '';

// Load ISP profile
$profile = getISPProfile($db);

// Ensure paybill columns exist on isp_profile
try { $db->query("SELECT paybill FROM isp_profile LIMIT 1"); } catch (Exception $e) { try { $db->exec("ALTER TABLE isp_profile ADD COLUMN paybill VARCHAR(20) NULL AFTER phone"); } catch (Exception $ignored) {} }
try { $db->query("SELECT paybill_account FROM isp_profile LIMIT 1"); } catch (Exception $e) { try { $db->exec("ALTER TABLE isp_profile ADD COLUMN paybill_account VARCHAR(50) NULL AFTER paybill"); } catch (Exception $ignored) {} }

// Load current user
$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->bindParam(':id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $business_name = trim($_POST['business_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $paybill = trim($_POST['paybill'] ?? '');
        $paybill_account = trim($_POST['paybill_account'] ?? '');
        $stmt = $db->prepare("UPDATE isp_profile SET business_name = ?, email = ?, phone = ?, paybill = ?, paybill_account = ? LIMIT 1");
        if ($stmt->execute([$business_name, $email, $phone, $paybill, $paybill_account])) {
            $message = 'Profile updated successfully';
        } else {
            $message = 'Failed to update profile';
        }
    } elseif (isset($_POST['update_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($new && $new === $confirm) {
            if (password_verify($current, $user['password_hash'])) {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                if ($stmt->execute([$hash, $user['id']])) {
                    $message = 'Password updated successfully';
                } else {
                    $message = 'Failed to update password';
                }
            } else {
                $message = 'Current password is incorrect';
            }
        } else {
            $message = 'New passwords do not match';
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">Settings</h1>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">ISP Profile</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Business Name</label>
                                <input type="text" class="form-control" name="business_name" value="<?php echo htmlspecialchars($profile['business_name'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">M-Pesa Paybill</label>
                                <input type="text" class="form-control" name="paybill" value="<?php echo htmlspecialchars($profile['paybill'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Paybill Account (Business Shortcode/Account)</label>
                                <input type="text" class="form-control" name="paybill_account" value="<?php echo htmlspecialchars($profile['paybill_account'] ?? ''); ?>">
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_profile" class="btn btn-primary">Save Profile</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">Change Password</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_password" class="btn btn-warning">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>


