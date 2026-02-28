<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/email_helper.php';

$error = '';
$success = '';

// Get branding details 
$business_name = 'FortuNNet Technologies';

try {
    $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
    $profile = $stmt->fetch();
    if ($profile && !empty($profile['business_name'])) {
        $business_name = $profile['business_name'];
    }
} catch (Exception $e) {}

if (isset($_GET['email']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? $_GET['email'] ?? '';
    
    if (empty($email)) {
        $error = "Email address is required.";
    } else {
        try {
            // Check if user exists and needs verification
            $stmt = $pdo->prepare("SELECT id, username, email_verified, verification_token, tenant_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = "No account found with this email address.";
            } elseif ($user['email_verified']) {
                $success = "This account is already verified! You can log in.";
            } else {
                $token = $user['verification_token'];
                if (empty($token)) {
                    // Generate a new token if one doesn't exist
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?")->execute([$token, $user['id']]);
                }
                
                // Determine the correct verification URL (subdomain if they have a tenant)
                $verifyUrl = "https://" . $_SERVER['HTTP_HOST'] . "/verify.php?token=" . $token;
                
                if ($user['tenant_id']) {
                    $tenantStmt = $pdo->prepare("SELECT subdomain FROM tenants WHERE id = ?");
                    $tenantStmt->execute([$user['tenant_id']]);
                    if ($tenant = $tenantStmt->fetch()) {
                        $verifyUrl = "https://" . $tenant['subdomain'] . ".fortunetttech.site/verify.php?token=" . $token;
                    }
                }
                
                $subject = "Verify Your Account - $business_name";
                $body = "
                    <h2>Welcome to $business_name</h2>
                    <p>We received a request to resend your verification email.</p>
                    <p>Click below to verify your account:</p>
                    <p><a href='$verifyUrl' style='padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>Verify Email & Login</a></p>
                ";

                if (function_exists('sendEmail') && sendEmail($email, $subject, $body)) {
                    $success = "Verification email resent successfully! Please check your inbox and spam folders.";
                } else {
                    $error = "Failed to send the verification email. Please contact support.";
                }
            }
        } catch (PDOException $e) {
            $error = "A database error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 48px;
            color: #3B6EA5;
            margin-bottom: 20px;
        }
        h2 { margin-bottom: 10px; color: #1F2937; }
        p { color: #6B7280; margin-bottom: 20px; font-size: 14px; }
        .form-group { margin-bottom: 20px; text-align: left; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500; }
        input {
            width: 100%; padding: 12px; border: 1px solid #D1D5DB;
            border-radius: 6px; box-sizing: border-box;
        }
        .btn {
            width: 100%; padding: 12px; background: #3B6EA5;
            color: white; border: none; border-radius: 6px;
            font-weight: 600; cursor: pointer; transition: 0.2s;
        }
        .btn:hover { background: #2C5282; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: left; }
        .alert-success { background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; }
        .alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
        .back-link { display: inline-block; margin-top: 20px; color: #3B6EA5; text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon"><i class="fas fa-envelope-open-text"></i></div>
        <h2>Resend Verification</h2>
        <p>Didn't receive the email? We'll send it again.</p>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="Enter your registered email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn">Resend Email</button>
        </form>

        <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
</body>
</html>
