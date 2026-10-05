<?php
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
superAdminGuard();
require_once __DIR__.'/../includes/onboarding_reminders.php';
$error=''; $tenants=[];
try {$tenants=onboardingTrialCandidates($pdo);} catch (PDOException $e) {$error='Run the self-service onboarding migration before using this report.';}
$esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Trial setup progress</title><link rel="stylesheet" href="../assets/css/setup-pages.css"></head><body><main class="workspace-page">
<a class="setup-button" href="tenants.php">Back to tenants</a><h1>Trial tenants with unfinished router setup</h1><p>Verification records show the last successful check, rather than guaranteeing current connectivity. Emails are prepared by the reminder job; this report does not send messages.</p>
<?php if ($error): ?><p role="alert"><?= $esc($error) ?></p><?php endif; ?>
<?php foreach ($tenants as $tenant): ?><section class="setup-card"><h2><?= $esc($tenant['company_name']) ?></h2><p><?= $esc($tenant['email']) ?> &middot; <?= $esc($tenant['step']) ?></p><p><?= $esc($tenant['failure'] ?: 'No failed verification recorded.') ?></p><p><?= (!$tenant['email_verified'] && !$tenant['is_verified']) ? 'Email verification required before reminders.' : 'Email verified.' ?></p><ul><?php foreach ($tenant['reminders'] as $reminder): ?><li><?= $esc($reminder['stage']) ?>: <?= $esc($reminder['sent_at'] ?: 'Not delivered') ?></li><?php endforeach; ?></ul><a class="setup-button" href="tenants.php?id=<?= (int)$tenant['id'] ?>">View tenant</a></section><?php endforeach; ?>
<?php if (!$tenants && !$error): ?><p>No active trial tenants need router setup.</p><?php endif; ?>
</main></body></html>
