<?php
require_once __DIR__.'/../includes/onboarding_reminders.php';
function expectSetup(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n";}
$now=strtotime('2026-10-05 12:00:00');
$tenant=['company_name'=>'<Trial ISP>','subdomain'=>'trial-isp','email_verified'=>1,'is_verified'=>0,'created_at'=>date('Y-m-d H:i:s',$now-4*86400),'reminders'=>[],'step'=>'Connect your first router'];
expectSetup(onboardingReminderStage($tenant,$now)==='day3','Older tenants receive one current reminder rather than a backlog');
$tenant['reminders']=[['stage'=>'day1','sent_at'=>date('Y-m-d H:i:s',$now-3600)]];
expectSetup(onboardingReminderStage($tenant,$now)===null,'Reminders have a 24 hour delivery gap');
$tenant['reminders']=[['stage'=>'day3','sent_at'=>date('Y-m-d H:i:s',$now-2*86400)]];
expectSetup(onboardingReminderStage($tenant,$now)===null,'Delivered stages are not repeated');
$tenant['email_verified']=0;
expectSetup(onboardingReminderStage($tenant,$now)===null,'Unverified accounts do not receive setup reminders');
$message=onboardingReminderMessage($tenant,'example.com');
expectSetup(str_contains($message['body'],'https://trial-isp.example.com/onboarding.php') && str_contains($message['body'],'&lt;Trial ISP&gt;'),'Reminder links resume setup and tenant text is escaped');
$router=['service_types'=>'pppoe,hotspot'];
$check=['verified_at'=>'2026-10-05','services'=>'hotspot,pppoe'];
expectSetup(onboardingRouterVerified($router,$check),'Verification ignores service ordering');
$router['service_types']='hotspot';
expectSetup(!onboardingRouterVerified($router,$check),'Changed services require new verification');
$check['verified_at']=null;
expectSetup(!onboardingRouterVerified($router,$check),'Failed checks invalidate completion');
