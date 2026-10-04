<?php
require_once __DIR__ . '/google_auth.php';
$googleReady=googleAuthReady();
$googleCentralOnly = !in_array(strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]), ['fortunetttech.site','localhost','127.0.0.1'], true);
if ($googleReady) {
    if (!googlePending('google_challenge')) $_SESSION['google_challenge']=['csrf'=>bin2hex(random_bytes(32)), 'nonce'=>bin2hex(random_bytes(32)), 'expires'=>time()+600];
}
?>
<div class="google-auth">
<?php if ($googleReady && $googleCentralOnly): ?>
    <a class="button google-central" href="google_start.php">Continue with Google</a>
<?php elseif ($googleReady): ?>
    <div id="google-button" data-client-id="<?= htmlspecialchars(googleClientId(), ENT_QUOTES) ?>" data-nonce="<?= htmlspecialchars($_SESSION['google_challenge']['nonce'], ENT_QUOTES) ?>" data-csrf="<?= htmlspecialchars($_SESSION['google_challenge']['csrf'], ENT_QUOTES) ?>"></div>
    <script src="js/google-signin.js?v=2" defer></script>
    <script src="https://accounts.google.com/gsi/client" async defer onload="window.renderFortunettGoogle?.()"></script>
    <p id="google-feedback" class="muted" role="status">Loading Google sign-in…</p>
<?php else: ?>
    <button type="button" class="google-unavailable" disabled><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5Z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6C44.4 38.03 46.98 31.87 46.98 24.55Z"/><path fill="#FBBC05" d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.78-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.53 2.56 10.78l7.97-6.19Z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.15 1.45-4.92 2.3-8.18 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48Z"/></svg>Continue with Google</button>
    <p class="muted google-note">Google sign-in is coming soon. Continue with email below.</p>
<?php endif; ?>
<div class="auth-divider"><span>or continue with email</span></div>
</div>
