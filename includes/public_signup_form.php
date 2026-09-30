<span class="eyebrow">MAKE ROOM FOR YOUR NETWORK</span>
<h2><?= $googleSignup ? 'Finish your workspace' : 'Create your account' ?></h2>
<p class="muted">Bring your customers, routers, and billing together.</p>
<?php if ($error): ?><div class="login-error" role="alert"><?= landingEscape($error) ?></div><?php endif; ?>
<?php if ($success): ?>
    <div class="auth-notice" role="status"><?= $success ?></div><a class="button submit-button" href="login.php?signin=1">Continue to sign in →</a>
<?php elseif ($signupEnabled === '0'): ?>
    <div class="auth-notice">New registrations are currently closed. Existing customers can still sign in.</div><a class="text-link" href="login.php?signin=1">Sign in →</a>
<?php else: ?>
    <?php if ($googleSignup): ?>
        <div class="auth-notice">Continuing as <strong><?= landingEscape($googleSignup['email']) ?></strong>. Choose a username for your new workspace.</div>
    <?php else: require __DIR__ . '/google_button.php'; endif; ?>
    <form method="post" id="signup-form" class="signup-form">
        <input type="hidden" name="csrf" value="<?= landingEscape($_SESSION['signup_csrf']) ?>">
        <?php if ($googleSignup): ?><input type="hidden" name="use_google" value="1"><?php endif; ?>
        <label for="username">Username</label><input id="username" name="username" autocomplete="username" maxlength="80" required placeholder="e.g. yournetwork" value="<?= landingEscape($_POST['username'] ?? '') ?>">
        <?php if (!$googleSignup): ?>
        <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required placeholder="you@yournetwork.com" value="<?= landingEscape($_POST['email'] ?? '') ?>">
        <label for="password">Password</label><div class="password-field"><input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required placeholder="At least 8 characters"><button type="button" id="show-password" aria-label="Show password" aria-pressed="false" hidden>Show</button></div>
        <label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required placeholder="Enter your password again">
        <?php endif; ?>
        <button class="button submit-button" type="submit">Create your workspace <span aria-hidden="true">↗</span></button>
    </form>
    <p class="signup-note">Already connected? <a href="login.php?signin=1">Sign in to your workspace ↗</a></p>
<?php endif; ?>
