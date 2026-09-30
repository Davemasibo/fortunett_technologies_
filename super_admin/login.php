<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';

if (isSuperAdmin()) {
    header('Location: ' . superAdminDestination());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username && $password) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, is_super_admin FROM users WHERE (username = ? OR email = ?) AND is_super_admin = TRUE LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                superAdminLogin((int)$user['id'], $user['username']);
                header('Location: ' . superAdminDestination());
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
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101318"><link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Sign in &middot; FortuNett administration</title><link rel="stylesheet" href="css/admin.css?v=1">
<script src="js/login.js?v=1" defer></script>
</head><body class="sa-login">
<main class="sa-login-wrap">
    <section class="sa-login-intro" aria-label="FortuNett administration">
        <a class="sa-login-brand" href="login.php"><span class="sa-brand-mark" aria-hidden="true">F</span>FortuNett</a>
        <h1>Your platform.<br>One workspace.</h1>
        <p>Manage tenants, track collections, and keep every payout accounted for.</p>
        <span class="sa-login-tag">PLATFORM ADMINISTRATION</span>
    </section>
    <section class="sa-login-card" aria-labelledby="login-title">
        <span class="sa-eyebrow">SUPER ADMIN</span><h2 id="login-title">Welcome back</h2>
        <p>Sign in with your platform administrator account.</p>
        <?php if ($error): ?><div class="sa-alert sa-alert-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" id="sa-login-form">
            <div class="sa-field"><label for="su-user">Username or email</label>
                <input id="su-user" name="username" required autocomplete="username" autofocus placeholder="Enter your username or email" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) ?>"></div>
            <div class="sa-field"><label for="su-pass">Password</label><div class="sa-login-password">
                <input type="password" id="su-pass" name="password" required autocomplete="current-password" placeholder="Enter your password">
                <button type="button" class="sa-password-toggle" id="sa-show-password" aria-controls="su-pass" aria-pressed="false">Show</button></div></div>
            <button class="sa-btn sa-btn-primary" type="submit" id="sa-login-submit">Sign in <span aria-hidden="true">&rarr;</span></button>
        </form>
        <div class="sa-login-footer"><a href="../login.php">&larr; Back to tenant login</a></div>
        <p class="sa-login-small">Restricted to authorised platform staff.</p>
    </section>
</main></body></html>
