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
    <meta name="theme-color" content="#0e0e0d">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Super Admin Login &mdash; FortuNett Technologies</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Shared dark-neumorphic auth styling. This page used to be a white card on
         a blue gradient, the only light-theme surface left in the platform. -->
    <link href="../css/auth.css" rel="stylesheet">
    <style>
      /* Super admin keeps the platform navy rather than a tenant brand colour */
      :root {
        --brand:          #0f3460;
        --brand-glow:     rgba(15, 52, 96, 0.42);
        --brand-gradient: linear-gradient(135deg, #0d1117 0%, rgba(15,52,96,0.92) 100%);
      }
    </style>
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-header">
        <div class="auth-header-badge">Super Admin</div>
        <h1><i class="fas fa-shield-halved"></i> FortuNett Technologies</h1>
        <p>Platform Administration Portal</p>
    </div>

    <div class="auth-body">
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <div class="auth-subtitle">
            <h2>Sign in</h2>
            <p>Restricted to FortuNett platform staff.</p>
        </div>

        <form method="POST" autocomplete="on">
            <div class="form-group">
                <label for="su-user">Username or Email</label>
                <div class="input-wrapper">
                    <input type="text" id="su-user" name="username" class="form-control-auth"
                           required autofocus autocomplete="username"
                           placeholder="admin@fortunetttech.site"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="su-pass">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="su-pass" name="password" class="form-control-auth"
                           required autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i class="fas fa-lock"></i> Secure Login
            </button>
        </form>

        <div class="auth-link">
            <a href="../login.php"><i class="fas fa-arrow-left"></i> Back to tenant login</a>
        </div>
    </div>
</div>
</body>
</html>
