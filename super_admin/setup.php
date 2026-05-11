<?php
/**
 * Super Admin One-Time Setup
 * Creates the first super admin user if none exists.
 * DELETE or restrict this file after first use.
 */
require_once __DIR__ . '/../includes/db_master.php';

// Ensure is_super_admin column exists
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
    if (!$col->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {}

// Check existing super admins
$existing = 0;
try {
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_super_admin = 1")->fetchColumn();
} catch (Exception $e) {}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $existing === 0) {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');

    if (!$username || !$email || !$password) {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, role, is_super_admin, tenant_id, created_at)
                 VALUES (?, ?, ?, 'superadmin', 1, NULL, NOW())"
            );
            $ins->execute([$username, $email, $hash]);
            $success = $username;
            $existing = 1;
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Setup — FortuNett Technologies</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; }
.card { background:#fff; border-radius:14px; box-shadow:0 24px 64px rgba(0,0,0,.4); width:100%; max-width:460px; overflow:hidden; }
.card-header { background:linear-gradient(135deg,#1a1a2e,#0f3460); padding:32px 28px; text-align:center; color:#fff; }
.card-header .badge { display:inline-block; background:rgba(255,255,255,.15); padding:4px 14px; border-radius:20px; font-size:11px; letter-spacing:1px; margin-bottom:14px; }
.card-header h1 { font-size:20px; font-weight:700; }
.card-header p { font-size:13px; opacity:.7; margin-top:6px; }
.card-body { padding:32px 28px; }
.form-group { margin-bottom:18px; }
.form-group label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; }
.form-group input { width:100%; padding:11px 14px; border:1.5px solid #d1d5db; border-radius:8px; font-size:14px; background:#f9fafb; transition:all .2s; }
.form-group input:focus { outline:none; border-color:#0f3460; background:#fff; box-shadow:0 0 0 3px rgba(15,52,96,.1); }
.btn { width:100%; padding:13px; background:linear-gradient(135deg,#1a1a2e,#0f3460); color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:opacity .2s; }
.btn:hover { opacity:.9; }
.alert { padding:12px 14px; border-radius:8px; font-size:13px; margin-bottom:18px; display:flex; align-items:flex-start; gap:10px; }
.alert.error   { background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; }
.alert.success { background:#d1fae5; color:#065f46; border-left:4px solid #10b981; }
.already-exists { text-align:center; padding:8px 0 16px; }
.already-exists i { font-size:40px; color:#10b981; margin-bottom:12px; }
.already-exists h3 { font-size:16px; font-weight:700; color:#111827; margin-bottom:6px; }
.already-exists p { font-size:13px; color:#6b7280; }
.login-link { display:block; text-align:center; margin-top:18px; font-size:13px; color:#6b7280; }
.login-link a { color:#0f3460; text-decoration:none; font-weight:600; }
.warning-banner { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; font-size:12px; color:#92400e; margin-bottom:18px; display:flex; gap:8px; align-items:flex-start; }
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="badge">ONE-TIME SETUP</div>
        <h1><i class="fas fa-shield-alt me-2"></i> FortuNett Super Admin</h1>
        <p>Create your platform administrator account</p>
    </div>
    <div class="card-body">
        <?php if ($existing > 0 && !$success): ?>
        <div class="already-exists">
            <i class="fas fa-check-circle"></i>
            <h3>Super admin already exists</h3>
            <p>A super admin account is already configured.<br>Please login normally.</p>
        </div>
        <a href="login.php" class="btn">Go to Login</a>
        <?php elseif ($success): ?>
        <div class="alert success">
            <i class="fas fa-check-circle" style="font-size:16px;margin-top:1px;"></i>
            <div>
                <strong>Account created!</strong><br>
                Super admin <strong><?= htmlspecialchars($success) ?></strong> is ready.<br>
                <em style="font-size:12px;">Delete or restrict this setup file now.</em>
            </div>
        </div>
        <a href="login.php" class="btn"><i class="fas fa-lock" style="margin-right:6px;"></i> Go to Login</a>
        <?php else: ?>
        <div class="warning-banner">
            <i class="fas fa-exclamation-triangle" style="margin-top:1px;"></i>
            <span>Delete <code>super_admin/setup.php</code> after creating your account.</span>
        </div>
        <?php if ($error): ?>
        <div class="alert error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus placeholder="e.g. platform_admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="admin@fortunetttech.site" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password (min 8 characters)</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn"><i class="fas fa-user-shield" style="margin-right:6px;"></i> Create Super Admin</button>
        </form>
        <a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to login</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
