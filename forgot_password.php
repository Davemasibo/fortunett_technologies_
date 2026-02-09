<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/email_helper.php';

$error = '';
$success = '';

// Get business name
$business_name = 'FortuNett Technologies';
try {
    $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
    $profile = $stmt->fetch();
    if ($profile && !empty($profile['business_name'])) {
        $business_name = $profile['business_name'];
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if ($email === '') {
        $error = 'Please enter your email address.';
    } else {
        try {
            // Check if user exists
             $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = "No account found with that email.";
            } else {
                // Generate token & expiry
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                // Save to DB
                $update = $pdo->prepare("
                    UPDATE users
                    SET reset_token = ?, reset_token_expiry = ?
                    WHERE id = ?
                ");
                $update->execute([$token, $expiry, $user['id']]);

                // Send email
                $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;
                $subject = "Reset Your Password - $business_name";
                $body = "
                    <h2>Password Reset Request</h2>
                    <p>Hello {$user['username']},</p>
                    <p>You requested a password reset. Click the button below to set a new password. This link expires in 30 minutes.</p>
                    <p><a href='$resetLink' style='display:inline-block;padding:10px 20px;background-color:#28a745;color:white;text-decoration:none;border-radius:5px;'>Reset Password</a></p>
                    <p>If you did not request this, please ignore this email.</p>
                ";

                if (function_exists('sendEmail') && sendEmail($email, $subject, $body)) {
                    $success = "A password reset link has been sent to your email.";
                } else {
                    $error = "Failed to send reset email. Please try again later.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/auth.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="icon-wrapper">
                <i class="fas fa-lock-open"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p>Recover your password</p>
        </div>
        
        <div class="auth-body">
            <div class="welcome-text">
                <h2>Forgot Password?</h2>
                <p>Enter your email to reset your password</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div class="auth-link">
                    <a href="login.php">Back to Login</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control-auth" required placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <span>Send Reset Link</span>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
                
                <div class="auth-link">
                    Remembered your password? <a href="login.php">Sign in here</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
