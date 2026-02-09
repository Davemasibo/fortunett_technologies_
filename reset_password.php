  GNU nano 7.2                                                                                                                                                                                                                       reset_password.php
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_master.php';

$error = "";
$success = "";
$validToken = false;

// Get business name
$business_name = 'FortuNett Technologies';
try {
    $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
    $profile = $stmt->fetch();
    if ($profile && !empty($profile['business_name'])) {
        $business_name = $profile['business_name'];
    }
} catch (Exception $e) {}

// STEP 1: token from URL
$token = $_GET['token'] ?? '';

if (!$token) {
    $error = "Invalid or missing reset token.";
} else {
    try {
        // STEP 2: check token validity
        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE reset_token = ?
            AND reset_token_expiry > NOW()
        ");
        $stmt->execute([$token]);

        $user = $stmt->fetch();

        if (!$user) {
            $error = "Reset link is invalid or has expired.";
        } else {
            $validToken = true;
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// STEP 3: handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {

    $password = trim($_POST['password']);
    $confirm  = trim($_POST['confirm']);

    if ($password === "" || $confirm === "") {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare("
                UPDATE users
                SET password_hash = ?,
                    reset_token = NULL,
                    reset_token_expiry = NULL
                WHERE id = ?
            ");
            $update->execute([$hash, $user['id']]);

            $success = "Password updated successfully.";
            $validToken = false; // Hide form
        } catch (PDOException $e) {
            $error = "Error updating password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/auth.css" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="icon-wrapper">
                <i class="fas fa-key"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p>Set a new password</p>
        </div>
        
        <div class="auth-body">
            
            <?php if ($error): ?>
                <div class="alert alert-danger" style="justify-content: center;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php if (!$validToken): ?>
                    <div class="auth-link">
                        <a href="login.php">Back to Login</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="justify-content: center; flex-direction: column;">
                    <i class="fas fa-check-circle" style="font-size: 24px; margin-bottom: 10px;"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <div class="auth-link">
                    <a href="login.php" class="btn-auth" style="text-decoration: none; color: white; display: flex;">
                        <span>Go to Login</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            <?php elseif ($validToken): ?>
                <form method="POST">
                    <div class="form-group">
                        <label>New Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="password" id="password" class="form-control-auth" required placeholder="Enter new password">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('password', this)"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm" id="confirm" class="form-control-auth" required placeholder="Confirm new password">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm', this)"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <span>Update Password</span>
                        <i class="fas fa-save"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>