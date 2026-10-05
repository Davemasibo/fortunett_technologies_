<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }
$_SESSION['signup_csrf'] ??= bin2hex(random_bytes(32));
$googleSignup = (isset($_GET['google']) || !empty($_POST['use_google'])) ? googlePending('google_signup') : null;
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

$branding = ['name'=>'FortuNett Technologies','logo'=>''];
$business_name = $branding['name'];
$tenant_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $username = trim($_POST['username'] ?? '');
    $email = $googleSignup ? $googleSignup['email'] : trim($_POST['email'] ?? '');
    $password = $googleSignup ? bin2hex(random_bytes(32)) : (string)($_POST['password'] ?? '');
    $confirm = $googleSignup ? $password : (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['signup_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['use_google']) && !$googleSignup) {
        $error = 'Google verification expired. Continue with Google again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,79}$/D', $username) || strlen($password) < 8) {
        $error = 'Use a username of 3-80 letters, numbers, dots, underscores or hyphens, and a password of at least 8 characters.';
    } elseif ($username === '' || $email === '' || $password === '' || $confirm === '') {
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
                if ($googleSignup) ensureGoogleIdentitySchema($pdo);
                $pdo->beginTransaction();
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

                    if ($googleSignup) {
                        googleLinkIdentity($pdo, $user_id, $googleSignup);
                        if ($googleSignup['authoritative']) {
                            $pdo->prepare('UPDATE users SET is_verified=1,email_verified=1,verification_token=NULL WHERE id=?')->execute([$user_id]);
                        }
                    }
                    $pdo->commit();
                    unset($_SESSION['google_signup']);

                    // Use platform domain from settings, fallback to hardcoded
                    $platformDomain = getPlatformSetting($pdo, 'platform_domain', 'fortunetttech.site');
                    $trialDays      = max(0, (int)getPlatformSetting($pdo, 'default_trial_days', 14));
                    $tenantUrl  = "https://" . $subdomain . "." . $platformDomain;
                    $loginLink  = $tenantUrl . "/login.php?signin=1";
                    $workspaceUrl = $loginLink;
                    $googleVerified = $googleSignup && $googleSignup['authoritative'];
                    $actionLink = $googleVerified ? $loginLink : $tenantUrl . "/verify.php?token=" . $token;
                    $actionLabel = $googleVerified ? 'Open your workspace' : 'Verify Email &amp; Login';
                    $signInMethod = $googleSignup ? 'Continue with Google using the email you registered with. No separate password was created.' : 'Sign in with your registered email or username and the password you chose.';
                    $trialEnds  = $trialDays > 0 ? date('d M Y', strtotime("+{$trialDays} days")) : 'N/A';

                    // Welcome + verification email
                    $subject = $googleVerified ? "Welcome to $business_name - Your Workspace Is Ready" : "Welcome to $business_name — Verify Your Account";
                    $body=fortunettEmail('Welcome to your workspace',
                        '<p>Hi <strong>'.fortunettEmailEscape($username).'</strong>,</p><p>Your ISP workspace has been created. '.($googleVerified ? 'You can sign in and connect your first MikroTik.' : 'Verify your email to sign in and connect your first MikroTik.').'</p>'
                        .fortunettEmailSummary(['Workspace'=>$tenantUrl,'Username'=>$username,'Plan'=>$trialDays.'-day free trial','Trial ends'=>$trialEnds])
                        .'<p>'.fortunettEmailEscape($signInMethod).'</p><p>Once signed in, open the setup guide to connect your router, create packages and add your first customer.</p>',
                        ['category'=>'Welcome','preheader'=>'Your workspace is ready. '.($googleVerified ? 'Connect your first router.' : 'Verify your email to get started.'),'action_label'=>html_entity_decode($actionLabel,ENT_QUOTES,'UTF-8'),'action_url'=>$actionLink]);

                    try {
                        $welcomeSent = function_exists('sendEmail') && sendEmail($email, $subject, $body) === true;
                    } catch (Throwable $mailError) {
                        $welcomeSent = false;
                        error_log('Signup welcome delivery failed: ' . get_class($mailError));
                    }
                    $mailNotice = $welcomeSent
                        ? 'Your workspace details have been emailed to you. Check your inbox and spam folder.'
                        : 'Your account is saved, but we could not send the welcome email. Keep the workspace link below.';
                    $nextStep = $googleVerified
                        ? 'Use Continue with Google on your workspace to sign in.'
                        : 'Verify your email before signing in. If the email did not arrive, use Resend verification on the sign-in page.';
                    $success = 'Workspace created successfully!<br>' . htmlspecialchars($nextStep, ENT_QUOTES, 'UTF-8')
                        . '<br>' . htmlspecialchars($mailNotice, ENT_QUOTES, 'UTF-8')
                        . '<br><strong>Your workspace:</strong> <a href="' . htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8') . '">'
                        . htmlspecialchars($tenantUrl, ENT_QUOTES, 'UTF-8') . '</a>';

                } else {
                    // Roll back the user record if tenant creation failed
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Failed to provision your workspace. Please try again or contact support.";
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Signup: " . $e->getMessage());
            $error = "Could not create your workspace. Please try again.";
        }
    }
}

$showSignup = true;
$showLogin = true;
$publicHome = 'login.php';
function landingEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/includes/public_landing.php';
