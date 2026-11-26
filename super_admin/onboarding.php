<?php
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
superAdminGuard();
require_once __DIR__.'/../includes/onboarding_reminders.php';
$error=''; $tenants=[];
try {$tenants=onboardingTrialCandidates($pdo);} catch (PDOException $e) {$error='Run the self-service onboarding migration before using this report.';}
$esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Trial setup progress · FortuNett Super Admin</title><link rel="stylesheet" href="css/dark.css?v=2"><link rel="stylesheet" href="css/shell.css?v=2"><link rel="stylesheet" href="css/admin.css?v=1"><link rel="stylesheet" href="/css/responsive-app.css?v=1"><script src="js/shell.js?v=2" defer></script>
<script src="/js/responsive-app.js?v=1" defer></script><style>.onboarding-card{padding:20px;margin:0 0 16px}.onboarding-card h2{font-size:16px;margin:0 0 8px}.onboarding-card p{margin:7px 0}.onboarding-card ul{margin:12px 0}</style>
</head><body class="sa-shell"><?php require __DIR__.'/includes/navigation.php'; ?><div class="main"><header class="topbar"><h1>Trial setup progress</h1><span class="sa-subtle">Tenant onboarding</span></header><main id="sa-main-content" class="content">
<div class="sa-page-heading"><div><span class="sa-eyebrow">TENANTS · ONBOARDING</span><h2>Trial tenants with unfinished router setup</h2><p>Verification records show the last successful check, rather than guaranteeing current connectivity. Emails are prepared by the reminder job; this report does not send messages.</p></div><a class="sa-btn sa-btn-secondary" href="tenants.php">Back to tenants</a></div>
<?php if ($error): ?><p role="alert"><?= $esc($error) ?></p><?php endif; ?>
<?php foreach ($tenants as $tenant): ?><section class="card onboarding-card"><h2><?= $esc($tenant['company_name']) ?></h2><p><?= $esc($tenant['email']) ?> &middot; <?= $esc($tenant['step']) ?></p><p><?= $esc($tenant['failure'] ?: 'No failed verification recorded.') ?></p><p><?= (!$tenant['email_verified'] && !$tenant['is_verified']) ? 'Email verification required before reminders.' : 'Email verified.' ?></p><ul><?php foreach ($tenant['reminders'] as $reminder): ?><li><?= $esc($reminder['stage']) ?>: <?= $esc($reminder['sent_at'] ?: 'Not delivered') ?></li><?php endforeach; ?></ul><a class="sa-btn sa-btn-secondary" href="tenants.php?id=<?= (int)$tenant['id'] ?>">View tenant</a></section><?php endforeach; ?>
<?php if (!$tenants && !$error): ?><p>No active trial tenants need router setup.</p><?php endif; ?>
</main></div></body></html>
