<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';

if (isSuperAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, is_super_admin FROM users WHERE (username = ? OR email = ?) AND is_super_admin = TRUE LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                superAdminLogin((int)$user['id'], $user['username']);
                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid credentials or insufficient privileges.';
            }
        } catch (PDOException $e) {
            $error = 'Login error. Please try again.';
        }
    } else {
        $error = 'Please enter username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login — FortuNett Technologies</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { background:#fff; border-radius:14px; box-shadow:0 24px 64px rgba(0,0,0,.4); width:100%; max-width:420px; overflow:hidden; }
        .card-header { background:linear-gradient(135deg,#1a1a2e,#0f3460); padding:36px 30px; text-align:center; color:#fff; }
        .card-header .badge { display:inline-block; background:rgba(255,255,255,.15); padding:4px 14px; border-radius:20px; font-size:12px; letter-spacing:1px; margin-bottom:16px; }
        .card-header h1 { font-size:22px; font-weight:700; }
        .card-header p { font-size:13px; opacity:.75; margin-top:6px; }
        .card-body { padding:36px 30px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:7px; }
        .form-group input { width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; background:#f9fafb; transition:all .2s; }
        .form-group input:focus { outline:none; border-color:#0f3460; background:#fff; box-shadow:0 0 0 3px rgba(15,52,96,.1); }
        .btn { width:100%; padding:13px; background:linear-gradient(135deg,#1a1a2e,#0f3460); color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; transition:opacity .2s; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn:hover { opacity:.9; }
        .alert { background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; padding:12px 14px; border-radius:8px; font-size:14px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .back-link { text-align:center; margin-top:18px; font-size:13px; color:#6b7280; }
        .back-link a { color:#0f3460; text-decoration:none; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="badge">SUPER ADMIN</div>
        <h1><i class="fas fa-shield-alt me-2"></i> FortuNett Technologies</h1>
        <p>Platform Administration Portal</p>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username or Email</label>
                <input type="text" name="username" required autofocus placeholder="admin@fortunetttech.site">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn"><i class="fas fa-lock"></i> Secure Login</button>
        </form>
        <div class="back-link"><a href="../login.php"><i class="fas fa-arrow-left"></i> Back to tenant login</a></div>
    </div>
</div>
</body>
</html>
