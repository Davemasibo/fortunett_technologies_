<?php
require_once __DIR__.'/onboarding_checks.php';
require_once __DIR__.'/email_brand.php';
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
    $body=fortunettEmail('Get your network ready',
        '<p>Hello <strong>'.fortunettEmailEscape($tenant['company_name']).'</strong>,</p><p>Your workspace is ready. Complete your router setup to start managing your network.</p>'
        .fortunettEmailSummary(['Your next step'=>$tenant['step']]).'<!-- email-action -->'
        .'<p style="font-weight:700;color:#14243b;">Before you begin</p><ul><li>Connect your MikroTik to the internet.</li><li>Have your laptop, WinBox and router login ready.</li><li>If the router serves customers, download a backup before making changes.</li></ul><p>Sign in with your existing account. Your setup guide walks you through connecting the router, choosing the customer network and verifying your services.</p><p style="font-size:13px;color:#64748b;">Already pasted the command? Continue to verification. If the router has not connected, check WinBox for a fetch or import error.</p>',
        ['category'=>'Router setup','preheader'=>$tenant['step'].'. Resume your guided MikroTik setup.','action_label'=>'Continue setup','action_url'=>$url]);
    return ['subject'=>'Continue your MikroTik setup','body'=>$body];
}
