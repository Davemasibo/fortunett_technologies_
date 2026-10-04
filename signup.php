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
        <span style="font-weight:600;">{$trialDays}-day free trial</span>
      </div>
      <div><span style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;">Trial Ends</span><br>
        <span style="font-weight:600;">{$trialEnds}</span>
      </div>
    </div>

    <p style="color:#374151;">{$signInMethod}</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="{$actionLink}" style="display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#2C5282,#4A90E2);color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;">{$actionLabel}</a>
    </p>

    <p style="font-size:13px;color:#6b7280;">If the button above doesn't work, copy and paste this link:<br>
    <a href="{$actionLink}" style="color:#2C5282;">{$actionLink}</a></p>

    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
    <p style="font-size:13px;color:#6b7280;margin:0;">
      <strong>Getting started:</strong> Open your workspace, complete the setup checklist, and add your MikroTik router under <em>Routers</em>,
      create service packages, then start adding customers. Your first {$trialDays} days are free — no credit card needed.
    </p>
  </div>
  <div style="background:#f8fafc;padding:16px 36px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;">
    {$business_name} &bull; <a href="mailto:support@fortunetttech.site" style="color:#2C5282;">support@fortunetttech.site</a>
  </div>
</div>
</body></html>
HTML;

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
