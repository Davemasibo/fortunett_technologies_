<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';
$pendingGoogleLink = googlePending('google_link');

$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$branding = ['name'=>'FortuNett Technologies', 'logo'=>''];
$tenant_id = null;
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
$subdomain = null;
if (preg_match('/^([a-z0-9-]+)\.fortunetttech\.site$/D', $host, $match) && $match[1] !== 'www') $subdomain = $match[1];
// Local previews may select a tenant; public requests always use the hostname.
if (in_array($host, ['localhost','127.0.0.1'], true) && isset($_GET['tenant'])) $subdomain = (string)$_GET['tenant'];
if ($subdomain !== null) {
    $stmt = $pdo->prepare('SELECT id, company_name FROM tenants WHERE subdomain = ? LIMIT 1');
    $stmt->execute([$subdomain]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) { http_response_code(404); exit('Workspace not found. Check your workspace address.'); }
    $tenant_id = (int)$tenant['id'];
    $branding['name'] = $tenant['company_name'];
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'system_logo'");
        $stmt->execute([$tenant_id]);
        $logo = $stmt->fetchColumn();
        if (is_string($logo) && preg_match('~^(?:https://|/?uploads/)~i', $logo)) $branding['logo'] = $logo;
    } catch (PDOException $e) { /* Branding is optional. */ }
}
if (isLoggedIn() && !googlePending('google_workspace_request')) { header('Location: dashboard.php'); exit; }
$error = isset($_GET['google_error']) ? 'Google sign-in expired. Click Continue with Google to try again.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['login_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your sign-in session expired. Please try again.';
    } else {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?)" . ($tenant_id ? ' AND tenant_id = ?' : ''));
            $stmt->execute($tenant_id ? [$username, $username, $tenant_id] : [$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Check email verification (email_verified column)
                if (isset($user['email_verified']) && $user['email_verified'] == 0) {
                    $encodedEmail = urlencode($user['email']);
                    $error = "Please verify your email address before logging in. <br><a href='resend_verification.php?email={$encodedEmail}' style='color: #EF4444; text-decoration: underline; margin-top: 5px; display: inline-block;'>Resend verification email</a>";
                } elseif (!empty($user['is_super_admin'])) {
                    // Super admin: redirect to dedicated portal
                    completeGooglePasswordLink($pdo, $user);
                    session_regenerate_id(true);
                        loginUser($user['id'], $user['username'], $user['role']);
                    $_SESSION['is_super_admin'] = true;
                    header("Location: super_admin/index.php");
                    exit;
                } else {
                    // Tenant user: enforce tenant isolation
                    if (!$tenant_id) {
                        $workspaceUrl = googleTenantLoginUrl($pdo, (int)$user['tenant_id']);
                        $error = 'Sign in on your own workspace: <a href="' . htmlspecialchars($workspaceUrl, ENT_QUOTES, 'UTF-8') . '">Open your workspace</a>.';
                    } elseif ($user['tenant_id'] != $tenant_id) {
                        $error = "This account does not belong to this workspace.";
                    } elseif (!$user['tenant_id'] && $tenant_id) {
                        $error = "Account not associated with a tenant. Contact support.";
                    } else {
                        completeGooglePasswordLink($pdo, $user);
                    session_regenerate_id(true);
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
        } catch (Throwable $e) {
            $error = "Login error. Please try again.";
        }
    } else {
        $error = "Please enter both username and password";
    }
}

}

$publicHome = 'login.php';
$signInUrl = 'login.php?signin=1';
$signupUrl = $tenant_id !== null ? 'https://www.fortunetttech.site/signup.php' : 'signup.php';
if ($tenant_id !== null && in_array($host, ['localhost','127.0.0.1'], true)) {
    $publicHome .= '?tenant=' . rawurlencode($subdomain);
    $signInUrl .= '&tenant=' . rawurlencode($subdomain);
}
$showLogin = isset($_GET['signin']) || $_SERVER['REQUEST_METHOD'] === 'POST';
function landingEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/includes/public_landing.php';
