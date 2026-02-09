<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connect.php';

$message = '';
$messageType = ''; // success or danger
$icon = '';
$title = '';

// Get business name
$business_name = 'FortuNett Technologies';
try {
    $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
    $profile = $stmt->fetch();
    if ($profile && !empty($profile['business_name'])) {
        $business_name = $profile['business_name'];
    }
} catch (Exception $e) {}

if (!isset($_GET['token'])) {
    $title = "Invalid Link";
    $message = "The verification link is missing or invalid.";
    $messageType = "danger";
    $icon = "fa-exclamation-triangle";
} else {
    $token = trim($_GET['token']);

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE verification_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $title = "Verification Failed";
            $message = "The verification link is invalid, expired, or the account is already verified.";
            $messageType = "danger";
            $icon = "fa-times-circle";
        } else {
            $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?")->execute([$user['id']]);
            $title = "Verified Successfully";
            $message = "Your email has been successfully verified. You can now access your account.";
            $messageType = "success";
            $icon = "fa-check-circle";
        }
    } catch (PDOException $e) {
        $title = "Error";
        $message = "Database error occurred. Please try again later.";
        $messageType = "danger";
        $icon = "fa-exclamation-circle";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/auth.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="icon-wrapper">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p><?php echo htmlspecialchars($title); ?></p>
        </div>
        
        <div class="auth-body">
            <div class="alert alert-<?php echo $messageType; ?>" style="justify-content: center; text-align: center; flex-direction: column;">
                <i class="fas <?php echo $icon; ?>" style="font-size: 24px; margin-bottom: 10px;"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            
            <div class="auth-link">
                <?php if ($messageType === 'success'): ?>
                    <a href="login.php" class="btn-auth" style="text-decoration: none; color: white; display: flex;">
                        <span>Continue to Login</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                <?php else: ?>
                    <a href="login.php">Back to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
