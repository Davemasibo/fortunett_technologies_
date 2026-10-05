<?php
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once __DIR__.'/../includes/onboarding_reminders.php';
$dir=__DIR__.'/../artifacts/email-preview';
if (!is_dir($dir)) mkdir($dir,0775,true);
$message=onboardingReminderMessage(['company_name'=>'Bluewave Networks','subdomain'=>'bluewave','step'=>'Finish your router connection'],'example.com');
file_put_contents($dir.'/onboarding.html',$message['body']);
file_put_contents($dir.'/customer.html',fortunettEmailEnsureBrand('Your internet subscription',
    '<p>Hello Amina,</p><p>Your internet subscription is active. Here are your service details.</p>'.fortunettEmailSummary(['Account'=>'BW-1024','Package'=>'Home 10 Mbps','Status'=>'Active','Valid until'=>'05 Nov 2026']).fortunettEmailButton('View your account','https://bluewave.example.com/customer/'),
    ['category'=>'Customer update','sender'=>'Bluewave Networks','support_email'=>'help@bluewave.example.com','preheader'=>'Your subscription is active. View your package and account details.']));
file_put_contents($dir.'/security.html',fortunettEmail('Reset your password','<p>Hello Amina,</p><p>We received a request to reset your password. This link expires in 30 minutes.</p><p>If you did not request this, you can ignore this email.</p>', ['category'=>'Account security','action_label'=>'Reset password','action_url'=>'https://bluewave.example.com/reset_password.php?token=synthetic-preview']));
echo "Synthetic email previews written to artifacts/email-preview. No messages sent.\n";
