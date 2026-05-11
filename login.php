<?php
require_once __DIR__ . '/includes/db_master.php';
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
                    $encodedEmail = urlencode($user['email']);
                    $error = "Please verify your email address before logging in. <br><a href='resend_verification.php?email={$encodedEmail}' style='color: #EF4444; text-decoration: underline; margin-top: 5px; display: inline-block;'>Resend verification email</a>";
                } elseif (!empty($user['is_super_admin'])) {
                    // Super admin: redirect to dedicated portal
                    loginUser($user['id'], $user['username'], $user['role']);
                    $_SESSION['is_super_admin'] = true;
                    header("Location: super_admin/index.php");
                    exit;
                } else {
                    // Tenant user: enforce tenant isolation
                    if ($tenant_id && $user['tenant_id'] != $tenant_id) {
                        $error = "This account does not belong to this workspace.";
                    } elseif (!$user['tenant_id'] && $tenant_id) {
                        $error = "Account not associated with a tenant. Contact support.";
                    } else {
                        loginUser($user['id'], $user['username'], $user['role']);
                        // Set tenant context in session
                        $activeTenantId = $user['tenant_id'] ?? $tenant_id;
                        if ($activeTenantId) {
                            $_SESSION['tenant_id'] = (int)$activeTenantId;
                            // Fetch subdomain for session
                            $tSubStmt = $pdo->prepare("SELECT subdomain FROM tenants WHERE id = ?");
                            $tSubStmt->execute([$activeTenantId]);
                            $tSubRow = $tSubStmt->fetch();
                            if ($tSubRow) $_SESSION['tenant_subdomain'] = $tSubRow['subdomain'];
                        }
                        header("Location: dashboard.php");
                        exit;
                    }
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

// Get tenant branding based on subdomain or request
$branding = [
    'name' => 'FortuNNet Technologies',
    'color' => '#0f3460',
    'logo' => '',
    'background' => 'linear-gradient(135deg, #0d1117 0%, rgba(15,52,96,0.88) 100%)'
];

// Detect subdomain
$host = $_SERVER['HTTP_HOST'];
$hostParts = explode('.', $host);
$subdomain = $hostParts[0];

// Allow override for testing ?tenant=subdomain
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
            
            // Fetch settings
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (!empty($settings['brand_color'])) {
                $branding['color'] = $settings['brand_color'];
            }
            if (!empty($settings['system_logo'])) {
                $branding['logo'] = $settings['system_logo'];
            }
        }
    } catch (Exception $e) {}
}

// Fallback to ISP Profile if no tenant found
if (!$tenant_id) {
    try {
        $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
        $profile = $stmt->fetch();
        if ($profile && !empty($profile['business_name'])) {
            $branding['name'] = $profile['business_name'];
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Login — <?php echo htmlspecialchars($branding['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/auth.css?v=3">
    <?php
        // Convert brand hex color to rgb components for rgba() usage
        $hex = ltrim($branding['color'], '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    ?>
    <style>
        :root {
            --brand:          <?php echo $branding['color']; ?>;
            --brand-glow:     rgba(<?php echo "$r,$g,$b"; ?>, 0.35);
            --brand-gradient: linear-gradient(135deg, #0d1117 0%, rgba(<?php echo "$r,$g,$b"; ?>, 0.88) 100%);
        }
    </style>
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <?php if (!empty($branding['logo'])): ?>
                <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo"
                     style="height:48px;margin:0 auto 14px;display:block;object-fit:contain;">
            <?php else: ?>
                <div class="auth-icon-wrap">
                    <i class="fas fa-wifi"></i>
                </div>
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
            <p>ISP Billing &amp; Management</p>
        </div>

        <div class="auth-body">
            <div class="auth-subtitle">
                <h2>Welcome Back</h2>
                <p>Sign in to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Email or Username <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control-auth" required autofocus
                           placeholder="Enter your email or username">
                </div>

                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="form-control-auth"
                               required placeholder="Enter your password" style="padding-right:44px;">
                        <i class="fas fa-eye password-toggle" onclick="togglePw('password',this)"></i>
                    </div>
                </div>

                <div class="forgot-row">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-auth">
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="auth-link">
                Don't have an account? <a href="signup.php">Sign up here</a>
            </div>
        </div>
    </div>

    <script>
        function togglePw(id, icon) {
            const inp = document.getElementById(id);
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        }
    </script>
</body>
</html>
