<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/email_helper.php';

$error = '';
$success = '';

// Get tenant branding based on subdomain or request
$branding = [
    'name' => 'FortuNNet Technologies',
    'color' => '#2C5282',
    'logo' => '',
    'background' => 'linear-gradient(135deg, #2C5282 0%, #3B6EA5 50%, #4A90E2 100%)'
];

$host = $_SERVER['HTTP_HOST'];
$hostParts = explode('.', $host);
$subdomain = $hostParts[0];

if (isset($_GET['tenant'])) {
    $subdomain = $_GET['tenant'];
}

$tenant_id = null;
if ($subdomain && $subdomain !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP)) {
    try {
        $stmt = $pdo->prepare("SELECT id, company_name FROM tenants WHERE subdomain = ? LIMIT 1");
        $stmt->execute([$subdomain]);
        $tenant = $stmt->fetch();
        
        if ($tenant) {
            $tenant_id = $tenant['id'];
            $branding['name'] = $tenant['company_name'];
            
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (!empty($settings['brand_color'])) {
                $branding['color'] = $settings['brand_color'];
                $branding['background'] = "linear-gradient(135deg, {$settings['brand_color']} 0%, {$settings['brand_color']}dd 100%)";
            }
            if (!empty($settings['system_logo'])) {
                $branding['logo'] = $settings['system_logo'];
            }
        }
    } catch (Exception $e) {}
}

if (!$tenant_id) {
    try {
        $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
        $profile = $stmt->fetch();
        if ($profile && !empty($profile['business_name'])) {
            $branding['name'] = $profile['business_name'];
        }
    } catch (Exception $e) {}
}

$business_name = $branding['name']; // Backwards compatibility for rest of file

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm  = trim($_POST['confirm_password']);

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        try {
            // Check if username/email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or email already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("
                    INSERT INTO users (username,email,password_hash,role,is_verified,verification_token)
                    VALUES (?, ?, ?, 'operator', 0, ?)
                ");
                $stmt->execute([$username, $email, $hash, $token]);

                // Send verification email
                $verifyLink = "https://" . $_SERVER['HTTP_HOST'] . "/verify.php?token=" . $token;
                $subject = "Verify Your Account - $business_name";
                $body = "
                    <h2>Welcome to $business_name</h2>
                    <p>Click below to verify your account:</p>
                    <p><a href='$verifyLink' style='padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>Verify Email</a></p>
                ";

                if (function_exists('sendEmail') && sendEmail($email, $subject, $body)) {
                    $success = "Account created! Please check your email to verify your account.";
                } else {
                    $success = "Account created, but failed to send verification email. Please contact admin.";
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
    <title>Sign Up - <?php echo htmlspecialchars($business_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/auth.css" rel="stylesheet">
    <style>
        :root {
            --brand-color: <?php echo $branding['color']; ?>;
            --brand-gradient: <?php echo $branding['background']; ?>;
        }
    </style>
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="icon-wrapper">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p>Create your account</p>
        </div>
        
        <div class="auth-body">
            <div class="welcome-text">
                <h2>Get Started</h2>
                <p>Enter your details to create an account</p>
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
                    <a href="login.php">Proceed to Login</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="username" class="form-control-auth" required placeholder="Choose a username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control-auth" required placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="password" id="password" class="form-control-auth" required placeholder="Create a password">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('password', this)"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control-auth" required placeholder="Confirm your password">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password', this)"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-auth">
                        <span>Sign Up</span>
                        <i class="fas fa-user-plus"></i>
                    </button>
                </form>
                
                <div class="auth-link">
                    Already have an account? <a href="login.php">Sign in here</a>
                </div>
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
