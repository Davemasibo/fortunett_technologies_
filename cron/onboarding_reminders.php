<?php
// Dry-run by default. Sending must be explicitly enabled by the operator.
if (PHP_SAPI!=='cli') {http_response_code(403); exit;}
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/onboarding_reminders.php';
$send=in_array('--send',$argv,true);
$st=$pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key=?'); $st->execute(['platform_domain']);
$domain=trim((string)($st->fetchColumn() ?: 'fortunetttech.site'));
$lock=$pdo->query("SELECT GET_LOCK('fortunett_onboarding_reminders',0)")->fetchColumn();
if (!$lock) exit("Another reminder process is running.\n");
try {
    if ($send) require_once __DIR__.'/../includes/email_helper.php';
    foreach (onboardingTrialCandidates($pdo) as $tenant) {
        $stage=onboardingReminderStage($tenant,time());
        if (!$stage || !filter_var($tenant['email'],FILTER_VALIDATE_EMAIL)) continue;
        $message=onboardingReminderMessage($tenant,$domain);
        echo ($send ? 'SEND' : 'PREVIEW').' tenant #'.$tenant['id'].' '.$stage.' — '.$tenant['step']."\n";
        if (!$send) {echo $message['body']."\n\n"; continue;}
        // Recheck immediately before delivery; completed tenants leave the candidate list.
        $current=array_filter(onboardingTrialCandidates($pdo),fn($t)=>(int)$t['id']===(int)$tenant['id']);
        if (!$current) continue;
        if (sendEmail($tenant['email'],$message['subject'],$message['body'])===true) {
            $st=$pdo->prepare('INSERT INTO onboarding_reminders (tenant_id,stage,sent_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE sent_at=NOW()'); $st->execute([$tenant['id'],$stage]);
        } else {fwrite(STDERR,'Delivery failed for tenant #'.$tenant['id']."; retry on next run.\n");}
    }
} finally {$pdo->query("SELECT RELEASE_LOCK('fortunett_onboarding_reminders')");}
