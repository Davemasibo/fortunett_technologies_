<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/email_helper.php';

$error = '';
$success = '';

// Read platform-wide settings
function getPlatformSetting($pdo, $key, $default = '') {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $val = $s->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) { return $default; }
}

// Block signups if disabled by super admin
$signupEnabled = getPlatformSetting($pdo, 'signup_enabled', '1');
if ($signupEnabled === '0' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    $error = "New registrations are currently closed. Please contact support.";
}

// Get tenant branding based on subdomain or request
$branding = [
    'name' => 'FortuNNet Technologies',
    'color' => '#0f3460',
    'logo' => '',
    'background' => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)'
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
                $branding['background'] = "linear-gradient(135deg, {$settings['brand_color']} 0%, {$settings['brand_color']}99 100%)";
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
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));

                // New ISP signups are tenant admins, not operators
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, is_verified, email_verified, verification_token)
                    VALUES (?, ?, ?, 'admin', 0, 0, ?)
                ");
                $stmt->execute([$username, $email, $hash, $token]);
                $user_id = (int)$pdo->lastInsertId();

                // Generate Subdomain and Create Tenant
                require_once __DIR__ . '/includes/tenant.php';
                $tenantManager = TenantManager::getInstance($pdo);

                $baseSubdomain = TenantManager::sanitizeSubdomain($username);
                if (empty($baseSubdomain)) {
                    $baseSubdomain = 'tenant' . $user_id;
                }

                $subdomain = $baseSubdomain;
                $counter = 1;
                while (!$tenantManager->isSubdomainAvailable($subdomain)) {
                    $subdomain = $baseSubdomain . $counter;
                    $counter++;
                }

                $companyName = $username . ' Network Solutions';
                $tenantId    = $tenantManager->createTenant($subdomain, $companyName, $user_id);

                if ($tenantId) {
                    // Account prefix = first alphanumeric char of the raw username
                    $firstAlphaNum = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $username));
                    $accountPrefix = !empty($firstAlphaNum) ? $firstAlphaNum[0] : strtoupper(substr($baseSubdomain, 0, 1));
                    // Ensure uniqueness across tenants: append digit if collision
                    $checkPrefix = $pdo->prepare("SELECT 1 FROM users WHERE account_prefix = ? AND id != ? LIMIT 1");
                    $checkPrefix->execute([$accountPrefix, $user_id]);
                    if ($checkPrefix->fetchColumn()) {
                        $pfxCounter = 2;
                        $base = $accountPrefix;
                        do {
                            $accountPrefix = $base . $pfxCounter++;
                            $checkPrefix->execute([$accountPrefix, $user_id]);
                        } while ($checkPrefix->fetchColumn());
                    }
                    $pdo->prepare("UPDATE users SET tenant_id = ?, account_prefix = ? WHERE id = ?")
                        ->execute([$tenantId, $accountPrefix, $user_id]);

                    // Assign default subscription plan from platform settings (fallback: starter)
                    $defaultPlanSlug = getPlatformSetting($pdo, 'auto_assign_plan_slug', 'starter');
                    $starterPlan = $pdo->prepare("SELECT id FROM platform_subscription_plans WHERE slug = ? LIMIT 1");
                    $starterPlan->execute([$defaultPlanSlug]);
                    $starterPlan = $starterPlan->fetchColumn();
                    if (!$starterPlan) {
                        $starterPlan = $pdo->query("SELECT id FROM platform_subscription_plans WHERE is_active=1 ORDER BY pppoe_fee_per_user DESC LIMIT 1")->fetchColumn();
                    }
                    if ($starterPlan) {
                        $pdo->prepare("UPDATE tenants SET subscription_plan_id = ? WHERE id = ?")
                            ->execute([$starterPlan, $tenantId]);
                    }

                    // Use platform domain from settings, fallback to hardcoded
                    $platformDomain = getPlatformSetting($pdo, 'platform_domain', 'fortunetttech.site');
                    $trialDays      = max(0, (int)getPlatformSetting($pdo, 'default_trial_days', 30));
                    $tenantUrl  = "https://" . $subdomain . "." . $platformDomain;
                    $loginLink  = $tenantUrl . "/login.php";
                    $verifyLink = $tenantUrl . "/verify.php?token=" . $token;
                    $trialEnds  = $trialDays > 0 ? date('d M Y', strtotime("+{$trialDays} days")) : 'N/A';

                    // Welcome + verification email
                    $subject = "Welcome to $business_name — Verify Your Account";
                    $body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#2C5282,#4A90E2);padding:36px;text-align:center;color:#fff;">
    <h1 style="margin:0;font-size:24px;">Welcome to {$business_name}!</h1>
    <p style="margin:10px 0 0;opacity:.85;">Your ISP management platform is ready</p>
  </div>
  <div style="padding:36px;">
    <p style="color:#374151;">Hi <strong>{$username}</strong>,</p>
    <p style="color:#374151;">Your dedicated ISP workspace has been created. Here are your details:</p>

    <div style="background:#f8fafc;border-radius:10px;padding:20px;margin:20px 0;">
      <div style="margin-bottom:12px;"><span style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;">Your Dashboard URL</span><br>
        <a href="{$tenantUrl}" style="color:#2C5282;font-weight:700;font-size:16px;">{$tenantUrl}</a>
      </div>
      <div style="margin-bottom:12px;"><span style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;">Username</span><br>
        <span style="font-weight:600;">{$username}</span>
      </div>
      <div style="margin-bottom:12px;"><span style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;">Plan</span><br>
        <span style="font-weight:600;">Starter (30-day free trial)</span>
      </div>
      <div><span style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;">Trial Ends</span><br>
        <span style="font-weight:600;">{$trialEnds}</span>
      </div>
    </div>

    <p style="text-align:center;margin:28px 0;">
      <a href="{$verifyLink}" style="display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#2C5282,#4A90E2);color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;">Verify Email &amp; Login</a>
    </p>

    <p style="font-size:13px;color:#6b7280;">If the button above doesn't work, copy and paste this link:<br>
    <a href="{$verifyLink}" style="color:#2C5282;">{$verifyLink}</a></p>

    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
    <p style="font-size:13px;color:#6b7280;margin:0;">
      <strong>Getting started:</strong> After verifying, add your MikroTik router under <em>Routers</em>,
      create service packages, then start adding customers. Your first 30 days are free — no credit card needed.
    </p>
  </div>
  <div style="background:#f8fafc;padding:16px 36px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    {$business_name} &bull; <a href="mailto:support@fortunetttech.site" style="color:#2C5282;">support@fortunetttech.site</a>
  </div>
</div>
</body></html>
HTML;

                    if (function_exists('sendEmail') && sendEmail($email, $subject, $body)) {
                        $success = "Account created! Check your email at <strong>" . htmlspecialchars($email) . "</strong> to verify and get started. Your dashboard: <a href='$tenantUrl'>$tenantUrl</a>";
                    } else {
                        $success = "Account created! Your dashboard URL is <a href='$tenantUrl'>$tenantUrl</a>. (Email delivery unavailable — please contact support to verify your account.)";
                    }
                } else {
                    // Roll back the user record if tenant creation failed
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                    $error = "Failed to provision your workspace. Please try again or contact support.";
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
    <title>Sign Up — <?php echo htmlspecialchars($business_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/auth.css?v=3">
    <?php
        $hex = ltrim($branding['color'], '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    ?>
    <style>
        :root {
            --brand:          <?php echo $branding['color']; ?>;
            --brand-glow:     rgba(<?php echo "$r,$g,$b"; ?>, 0.38);
            --brand-gradient: <?php echo $branding['background']; ?>;
        }
    </style>
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-icon-wrap">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1><?php echo htmlspecialchars($business_name); ?></h1>
            <p>Create your ISP workspace</p>
        </div>

        <div class="auth-body">
            <div class="auth-subtitle">
                <h2>Get Started</h2>
                <p>Enter your details to create an account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
                <div class="auth-link">
                    <a href="login.php">Proceed to Login &rarr;</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="username" class="form-control-auth" required
                               placeholder="Choose a username"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control-auth" required
                               placeholder="Enter your email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="password" id="password" class="form-control-auth"
                                   required placeholder="Create a password" style="padding-right:44px;">
                            <i class="fas fa-eye password-toggle" onclick="togglePw('password',this)"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password"
                                   class="form-control-auth" required placeholder="Confirm your password"
                                   style="padding-right:44px;">
                            <i class="fas fa-eye password-toggle" onclick="togglePw('confirm_password',this)"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-auth">
                        <span>Create Account</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>

                <div class="auth-link">
                    Already have an account? <a href="login.php">Sign in here</a>
                </div>
            <?php endif; ?>
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
