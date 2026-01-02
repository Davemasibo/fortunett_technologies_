<?php
require_once __DIR__ . '/includes/config.php';
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
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                loginUser($user['id'], $user['username'], $user['role']);
                header("Location: dashboard.php");
                exit;
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
} catch (Exception $e) {
    // Use default
}
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .login-header i {
            font-size: 40px;
            color: white;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            font-size: 13px;
            opacity: 0.9;
            line-height: 1.5;
            font-weight: 400;
        }

        .login-body {
            padding: 40px 30px;
        }

        .welcome-text {
            text-align: center;
            margin-bottom: 30px;
        }

        .welcome-text h2 {
            font-size: 22px;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 8px;
        }

        .welcome-text p {
            font-size: 14px;
            color: #6B7280;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group label .required {
            color: #EF4444;
        }

        .input-wrapper {
            position: relative;
        }

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

        .form-group input::placeholder {
            color: #9CA3AF;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            font-size: 13px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #4B5563;
        }

        .remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .forgot-password {
            color: #3B6EA5;
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-password:hover {
            text-decoration: underline;
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
            box-shadow: 0 4px 12px rgba(44, 82, 130, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
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

        .demo-credentials {
            margin-top: 30px;
            padding: 20px;
            background: #F9FAFB;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
        }

        .demo-credentials h3 {
            font-size: 13px;
            font-weight: 600;
            color: #6B7280;
            margin-bottom: 12px;
            text-align: center;
        }

        .credential-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            font-size: 13px;
        }

        .credential-item i {
            width: 16px;
            color: #3B6EA5;
        }

        .credential-item strong {
            color: #374151;
            min-width: 60px;
        }

        .credential-item span {
            color: #6B7280;
        }

        .security-badges {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
        }

        .security-badges h4 {
            font-size: 12px;
            font-weight: 600;
            color: #6B7280;
            text-align: center;
            margin-bottom: 12px;
        }

        .badges-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            color: #4B5563;
        }

        .badge i {
            font-size: 12px;
        }

        .badge.ssl {
            color: #059669;
            border-color: #D1FAE5;
            background: #ECFDF5;
        }

        .badge.csrf {
            color: #DC2626;
            border-color: #FEE2E2;
            background: #FEF2F2;
        }

        .badge.mpesa {
            color: #059669;
            border-color: #D1FAE5;
            background: #ECFDF5;
        }

        .role-badges {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
            color: #6B7280;
        }

        .role-badge i {
            font-size: 10px;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }

            .login-header {
                padding: 30px 20px;
            }

            .login-body {
                padding: 30px 20px;
            }

            .badges-row {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="icon-wrapper">
                <i class="fas fa-wifi"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?> ISP Manager</h1>
            <p>Enterprise Internet Service Provider Management System</p>
        </div>
        
        <div class="login-body">
            <div class="welcome-text">
                <h2>Welcome Back</h2>
                <p>Sign in to access your ISP management dashboard</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="text" name="username" required autofocus placeholder="Enter your email or username">
                </div>
                
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" required placeholder="Enter your password">
                </div>
                
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    <span>Sign in to Dashboard</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            
            <div class="demo-credentials">
                <h3>Demo Credentials for Testing:</h3>
                <div class="credential-item">
                    <i class="fas fa-user-shield"></i>
                    <strong>Admin:</strong>
                    <span>admin@<?php echo strtolower(str_replace(' ', '', $business_name)); ?>.co.ke</span>
                </div>
                <div class="credential-item">
                    <i class="fas fa-user"></i>
                    <strong>Staff:</strong>
                    <span>staff@example.co.ke</span>
                </div>
                <div class="credential-item">
                    <i class="fas fa-headset"></i>
                    <strong>Support:</strong>
                    <span>support@example.co.ke</span>
                </div>
                <div class="credential-item">
                    <i class="fas fa-key"></i>
                    <strong>Password:</strong>
                    <span>admin123 / Staff@2025 / Support@2025</span>
                </div>
            </div>
            
            <div class="security-badges">
                <h4>Role-Based Access Control Enabled</h4>
                <div class="role-badges">
                    <div class="role-badge">
                        <i class="fas fa-circle" style="color: #EF4444;"></i>
                        <span>Admin</span>
                    </div>
                    <div class="role-badge">
                        <i class="fas fa-circle" style="color: #3B82F6;"></i>
                        <span>Staff</span>
                    </div>
                    <div class="role-badge">
                        <i class="fas fa-circle" style="color: #10B981;"></i>
                        <span>Support</span>
                    </div>
                </div>
                
                <div class="badges-row" style="margin-top: 12px;">
                    <div class="badge ssl">
                        <i class="fas fa-lock"></i>
                        <span>SSL Encrypted</span>
                    </div>
                    <div class="badge csrf">
                        <i class="fas fa-shield-alt"></i>
                        <span>CSRF Protected</span>
                    </div>
                    <div class="badge mpesa">
                        <i class="fas fa-check-circle"></i>
                        <span>M-Pesa Certified</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
