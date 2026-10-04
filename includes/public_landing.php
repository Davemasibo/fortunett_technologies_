<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="FortuNett Technologies brings MikroTik management, ISP billing, M-Pesa payments and customer access automation into one workspace.">
    <meta name="theme-color" content="#f9faf4">
    <title><?= !empty($showSignup) ? 'Create your workspace - FortuNett Technologies' : ($showLogin ? 'Sign in - ' . landingEscape($branding['name']) : 'FortuNett Technologies - Your network. Working together.') ?></title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/public-landing.css?v=2">
    <script src="js/public-landing.js?v=2" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="brand" href="<?= landingEscape($publicHome) ?>" aria-label="FortuNett Technologies home"><span class="brand-symbol" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M5 11a17 17 0 0 1 22 0M9 16a11 11 0 0 1 14 0M13 21a5 5 0 0 1 6 0" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><circle cx="16" cy="26" r="1.7" fill="currentColor"/></svg></span><span>fortu<span class="brand-net">nett</span><small>TECHNOLOGIES</small></span></a>
    <nav class="desktop-nav" aria-label="Main navigation"><a href="<?= landingEscape($publicHome) ?>#features">Features</a><a href="<?= landingEscape($publicHome) ?>#how-it-works">How it works</a><a href="<?= landingEscape($publicHome) ?>#pricing">Pricing</a><a href="<?= landingEscape($publicHome) ?>#faq">FAQ</a></nav>
    <div class="header-actions"><button class="theme-toggle" type="button" aria-label="Switch to dark theme" title="Change theme" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5Z"/></svg></button><a class="nav-signin" href="<?= landingEscape($signInUrl ?? 'login.php?signin=1') ?>">Sign in</a><a class="button button-small" href="<?= landingEscape($signupUrl ?? 'signup.php') ?>">Create account <span aria-hidden="true">↗</span></a></div>
</header>
<main id="main">
<?php if ($showLogin): ?>
<section class="login-layout <?= !empty($showSignup) ? 'signup-layout' : '' ?>">
    <div class="login-intro"><span class="eyebrow"><span class="status-dot"></span> YOUR NETWORK, CONNECTED</span><h1><?= !empty($showSignup) ? 'Your network.<br>Your workspace.<br><em>Your next chapter.</em>' : 'Back to what<br>keeps you<br><em>connected.</em>' ?></h1><p>Customers, payments, and your MikroTik network.<br>One workspace to bring it all together.</p><div class="intro-line"><span>NETWORKING</span><span>BILLING</span><span>AUTOMATION</span></div><a class="text-link" href="<?= landingEscape($publicHome) ?>#features">Explore FortuNett <span aria-hidden="true">↗</span></a></div>
    <div class="login-card">
        <?php if (!empty($showSignup)): require __DIR__ . '/public_signup_form.php'; else: ?>
        <?php if ($branding['logo']): ?><img class="tenant-logo" src="<?= landingEscape($branding['logo']) ?>" alt="<?= landingEscape($branding['name']) ?> logo"><?php endif; ?>
        <span class="eyebrow"><?= $tenant_id ? 'YOUR WORKSPACE' : 'WELCOME BACK' ?></span>
        <h2><?= $tenant_id ? landingEscape($branding['name']) : 'Sign in to FortuNett' ?></h2><p class="muted">Use your existing email or username to continue.</p>
        <?php if ($error): ?><div class="login-error" role="alert"><?= $error ?></div><?php endif; ?>
        <?php if ($pendingGoogleLink): ?><p class="auth-notice">Confirm your existing password to link Google to this account. Future sign-ins can use Google.</p><?php endif; ?>
        <?php require __DIR__ . '/google_button.php'; ?>
        <form method="post" id="login-form">
            <input type="hidden" name="csrf" value="<?= landingEscape($_SESSION['login_csrf']) ?>">
            <label for="username">Email or username</label><input id="username" name="username" type="text" autocomplete="username" required placeholder="you@yournetwork.com" value="<?= landingEscape($_POST['username'] ?? ($pendingGoogleLink['email'] ?? '')) ?>">
            <div class="label-row"><label for="password">Password</label><a href="forgot_password.php">Forgot password?</a></div>
            <div class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password"><button type="button" id="show-password" aria-label="Show password" aria-pressed="false" hidden>Show</button></div>
            <button class="button submit-button" type="submit">Sign in to workspace <span aria-hidden="true">→</span></button>
        </form>
        <p class="signup-note">New to FortuNett? <a href="<?= landingEscape($signupUrl ?? 'signup.php') ?>">Create your account ↗</a></p>
        <?php if (!$tenant_id): ?><p class="workspace-note">Already have a workspace? Sign in at your assigned <strong>workspace.fortunetttech.site</strong> address.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
<section class="hero" aria-labelledby="hero-title">
    <div class="hero-inner"><?php if ($tenant_id): ?><p class="muted" style="margin-bottom:16px;">Welcome to <?= landingEscape($branding['name']) ?> &middot; Powered by FortuNett</p><?php endif; ?><span class="eyebrow hero-eyebrow"><span class="status-dot"></span> BUILT FOR THE PEOPLE CONNECTING EVERYONE ELSE</span>
    <h1 id="hero-title">Your network.<br><span>Working together.</span></h1>
    <p class="hero-topics">MikroTik <span>·</span> Billing <span>·</span> Automation <svg viewBox="0 0 20 28" aria-hidden="true"><path d="M12 0 0 16h8L6 28l14-17h-9Z" fill="currentColor"/></svg></p>
    <p class="hero-description">Connect your routers. Simplify your billing. Keep customers online.<br class="desktop-break"> Manage your ISP, M-Pesa payments, and everyday operations<br class="desktop-break"> from one FortuNett workspace.</p>
    <div class="hero-actions"><a class="button" href="<?= landingEscape($signupUrl ?? 'signup.php') ?>">Create your workspace <span aria-hidden="true">↗</span></a><a class="button button-outline" href="<?= landingEscape($signInUrl ?? 'login.php?signin=1') ?>">Sign in <span aria-hidden="true">→</span></a></div>
    <p class="hero-note">Less admin. More time to grow your network.</p>
    <ul class="capability-strip" aria-label="Platform capabilities"><li><span aria-hidden="true">⌘</span> MikroTik management</li><li><span aria-hidden="true">▤</span> M-Pesa billing</li><li><span aria-hidden="true">◎</span> Hotspot &amp; PPPoE</li><li><span aria-hidden="true">↗</span> Automated renewals</li></ul>
    </div>
</section>
<section class="section features" id="features">
    <div class="section-heading"><div><span class="eyebrow">ONE CONNECTED WORKSPACE</span><h2>A lot goes into your network.<br>Bring it all together.</h2></div><p>From a customer's first connection to their next renewal, stay on top of the details that matter.</p></div>
    <div class="feature-grid">
        <article class="feature-card feature-network"><div class="feature-icon" aria-hidden="true">⌘</div><h3>Your MikroTik network.<br>Under control.</h3><p>Manage routers, customer access, and service profiles with tools built for hotspot and PPPoE networks.</p><div class="network-diagram" aria-label="FortuNett connects your router to hotspot and PPPoE services"><span class="diagram-root">FortuNett workspace</span><span class="diagram-line"></span><span class="diagram-router">▦ &nbsp; MikroTik router</span><span class="diagram-line"></span><div class="diagram-branches"><span>◎ &nbsp; Hotspot</span><span>↔ &nbsp; PPPoE</span></div></div></article>
        <article class="feature-card"><div class="feature-icon" aria-hidden="true">▤</div><h3>Payments that<br>keep things moving.</h3><p>Track M-Pesa payments, manage invoices, and see your collections and payout balances in one place.</p><div class="mini-ledger"><span>Customer pays <b>M-Pesa</b></span><span>Payment confirmed <b class="green-text">Recorded ✓</b></span><span>Package renewed <b class="green-text">Access updated ↗</b></span></div></article>
        <article class="feature-card"><div class="feature-icon" aria-hidden="true">↗</div><h3>Less repetition.<br>More automation.</h3><p>Apply paid package time, send customer notifications, and manage expiry without doing every step by hand.</p><div class="automation-steps"><span><i>01</i> Confirm payment</span><span><i>02</i> Calculate package time</span><span><i>03</i> Update customer access</span></div></article>
    </div>
</section>
<section class="section workflow" id="how-it-works"><span class="eyebrow">FROM SETUP TO EVERYDAY OPERATIONS</span><h2>A simpler way to run your ISP.</h2><div class="workflow-grid"><article><span class="step-number">01 /</span><h3>Create your workspace</h3><p>Set up your business and get a dedicated space for your team and customers.</p></article><article><span class="step-number">02 /</span><h3>Connect your network</h3><p>Add your routers, configure packages, and bring your customer accounts together.</p></article><article><span class="step-number">03 /</span><h3>Let payments do the work</h3><p>Collect payments, renew access, and follow your network's activity from your dashboard.</p></article></div></section>
<section class="section" id="pricing"><div class="pricing-panel"><div><span class="eyebrow">ROOM FOR YOUR NEXT CONNECTION</span><h2>Your network is growing.<br>Your tools should keep up.</h2><p>Explore a workspace built around your PPPoE customers, hotspot collections, and day-to-day operations.</p></div><a class="button" href="<?= landingEscape($signupUrl ?? 'signup.php') ?>">Get started with FortuNett <span aria-hidden="true">↗</span></a></div></section>
<section class="section faq" id="faq"><div><span class="eyebrow">A FEW THINGS YOU MIGHT ASK</span><h2>Let's get you<br>connected.</h2></div><div class="faq-list"><details><summary>Can I keep using my existing tenant login?</summary><p>Yes. Your existing workspace subdomain and account credentials continue to work. Use the Sign in link above or open your usual workspace address.</p></details><details><summary>Does FortuNett support MikroTik?</summary><p>Yes. FortuNett provides tools for MikroTik provisioning and customer access across hotspot and PPPoE networks. Your router needs to be configured and reachable for access changes.</p></details><details><summary>Can customers pay for more than one package period?</summary><p>Confirmed payments can purchase multiple full package periods based on the package price. Remaining credit is kept on the customer's account.</p></details><details><summary>How are platform charges calculated?</summary><p>Your assigned plan determines the applicable PPPoE fees, hotspot commission, and monthly fee per configured MikroTik. Trial accounts do not pay the monthly router fee. Your monthly invoice shows the breakdown in your workspace's Billing page.</p></details></div></section>
<section class="closing"><span class="eyebrow">YOUR NEXT CHAPTER STARTS HERE</span><h2>Built for your network.<br>Ready for what comes next.</h2><a class="button" href="<?= landingEscape($signupUrl ?? 'signup.php') ?>">Create your workspace <span aria-hidden="true">↗</span></a></section>
<?php endif; ?>
</main>
<footer class="site-footer"><span>© <?= date('Y') ?> FortuNett Technologies</span><span>Networking. Billing. Connected.</span><a href="<?= landingEscape($signInUrl ?? 'login.php?signin=1') ?>">Workspace sign in ↗</a></footer>
</body></html>
