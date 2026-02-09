<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // Ensure DB connection is loaded
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Check email verification (email_verified column)
                if (isset($user['email_verified']) && $user['email_verified'] == 0) {
                    $error = "Please verify your email address before logging in.";
                } else {
                    loginUser($user['id'], $user['username'], $user['role']);
                    header("Location: dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid username or password";
            }
        } catch (PDOException $e) {
            $error = "Login error. Please try again.";
        }
    } else {
        $error = "Please enter both username and password";
    }
}

// Get business name from settings
$business_name = 'FortuNett Technologies';
try {
    $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
    $profile = $stmt->fetch();
    if ($profile && !empty($profile['business_name'])) {
        $business_name = $profile['business_name'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/modern-design.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 50%, #4A90E2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .login-header .icon-wrapper {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            backdrop-filter: blur(10px);
        }
        .login-header i { font-size: 40px; color: white; }
        .login-header h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .login-header p { font-size: 13px; opacity: 0.9; }
        .login-body { padding: 40px 30px; }
        .welcome-text { text-align: center; margin-bottom: 30px; }
        .welcome-text h2 { font-size: 22px; font-weight: 600; color: #1F2937; margin-bottom: 8px; }
        .welcome-text p { font-size: 14px; color: #6B7280; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px; }
        .form-group label .required { color: #EF4444; }
        .input-wrapper { position: relative; }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            font-size: 14px;
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            transition: all 0.2s;
            background: #F9FAFB;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3B6EA5;
            background: white;
            box-shadow: 0 0 0 3px rgba(59, 110, 165, 0.1);
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #234161 0%, #2F5A8A 100%);
            transform: translateY(-1px);
        }
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #FEE2E2;
            color: #991B1B;
            border-left: 4px solid #EF4444;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6B7280;
            cursor: pointer;
            z-index: 10;
        }
        .signup-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #6B7280;
        }
        .signup-link a { color: #3B6EA5; text-decoration: none; font-weight: 500; }
        .signup-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="icon-wrapper">
                <i class="fas fa-wifi"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p>ISP Billing & Management</p>
        </div>
        
        <div class="login-body">
            <div class="welcome-text">
                <h2>Welcome Back</h2>
                <p>Sign in to your account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email or Username <span class="required">*</span></label>
                    <input type="text" name="username" required autofocus placeholder="Enter your email">
                </div>
                
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" required placeholder="Enter your password">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; margin-bottom: 24px;">
                    <a href="forgot_password.php" style="color: #3B6EA5; text-decoration: none; font-size: 13px;">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            
            <div class="signup-link">
                Don't have an account? <a href="signup.php">Sign up here</a>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
