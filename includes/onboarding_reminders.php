<?php
require_once __DIR__.'/onboarding_checks.php';
function onboardingTrialCandidates(PDO $pdo): array {
    $st=$pdo->query("SELECT t.id,t.company_name,t.subdomain,t.created_at,u.email,u.email_verified,u.is_verified FROM tenants t JOIN users u ON u.id=t.admin_user_id AND u.tenant_id=t.id WHERE t.status='trial' AND t.trial_ends_at>NOW() ORDER BY t.created_at");
    $candidates=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tenant) {
        $rq=$pdo->prepare('SELECT id,name,status,last_seen,service_types FROM mikrotik_routers WHERE tenant_id=?'); $rq->execute([$tenant['id']]);
        $routers=$rq->fetchAll(PDO::FETCH_ASSOC); $checks=onboardingChecks($pdo,(int)$tenant['id']);
        $unfinished=array_filter($routers,fn($r)=>!onboardingRouterVerified($r,$checks[(int)$r['id']] ?? []));
        if ($routers && !$unfinished) continue;
        $connected=array_filter($routers,fn($r)=>in_array($r['status'],['active','online'],true) && !empty($r['last_seen']));
        $tenant['step']=!$routers ? 'Connect your first router' : (!$connected ? 'Finish your router connection' : 'Configure and verify your router services');
        $tenant['failure']='';
        foreach ($unfinished as $router) {
            $failure=$checks[(int)$router['id']]['failure_summary'] ?? '';
            if ($failure!=='') $tenant['failure']=$failure;
        }
        $rq=$pdo->prepare('SELECT stage,sent_at FROM onboarding_reminders WHERE tenant_id=?'); $rq->execute([$tenant['id']]);
        $tenant['reminders']=$rq->fetchAll(PDO::FETCH_ASSOC);
        $candidates[]=$tenant;
    }
    return $candidates;
}
function onboardingReminderStage(array $tenant,int $now): ?string {
    if (empty($tenant['email_verified']) && empty($tenant['is_verified'])) return null;
    $sent=array_column(array_filter($tenant['reminders'],fn($r)=>!empty($r['sent_at'])),'stage');
    $age=$now-strtotime($tenant['created_at']);
    $stage=$age>=3*86400 ? 'day3' : ($age>=86400 ? 'day1' : 'welcome');
    if (in_array($stage,$sent,true)) return null;
    foreach ($tenant['reminders'] as $r) if (!empty($r['sent_at']) && $now-strtotime($r['sent_at'])<86400) return null;
    return $stage;
}
function onboardingReminderMessage(array $tenant,string $domain): array {
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/i',$tenant['subdomain']) || !preg_match('/^[a-z0-9.-]+$/i',$domain)) throw new RuntimeException('Invalid workspace domain');
    $url='https://'.$tenant['subdomain'].'.'.$domain.'/onboarding.php';
    $escape=fn($s)=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
    $body='<h2>Finish your MikroTik setup</h2><p>Hello '.$escape($tenant['company_name']).',</p><p>Your next step: <strong>'.$escape($tenant['step']).'</strong>.</p><p>Have your router connected to the internet, a laptop, WinBox and your router login ready. If the router serves customers, download a backup before making changes.</p><p><a href="'.$escape($url).'">Continue setup</a></p><p>Sign in with your existing account. The guide will show the connection command, customer network selection and verification steps.</p><p>If you already pasted the script, return to the guide and verify configuration. Check the WinBox terminal for an import error if the router has not connected.</p>';
    return ['subject'=>'Continue your MikroTik setup','body'=>$body];
}
