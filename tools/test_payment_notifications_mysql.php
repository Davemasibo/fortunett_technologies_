<?php
/** Isolated database; provider doubles only, never sends real messages. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/payment_notifications.php';
$schema = 'fortunett_notice_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
function noticeCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
try {
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY,tenant_id INT,phone VARCHAR(30),connection_type VARCHAR(20),mikrotik_username VARCHAR(100),mikrotik_password VARCHAR(100))');
    $pdo->exec("INSERT INTO clients VALUES (1,2,'0712345678','hotspot','','')");
    $creds = ensurePaidLoginCredentials($pdo,2,1);
    noticeCheck($creds['username']==='254712345678' && strlen($creds['password'])===4, 'Credentials persist without any router connection');
    noticeCheck(ensurePaidLoginCredentials($pdo,2,1)===$creds, 'Retry preserves the exact login sent to the customer');
    try { ensurePaidLoginCredentials($pdo,3,1); throw new LogicException('Tenant boundary failed'); }
    catch (RuntimeException $e) { noticeCheck($e->getMessage()==='Payment customer not found','Credentials respect tenant isolation'); }
    $message = 'Username: '.$creds['username'].' Password: '.$creds['password'];
    $id = queuePaymentNotification($pdo,2,1,7,'REF7','0712345678',$message);
    noticeCheck(queuePaymentNotification($pdo,2,1,7,'REF7','0712345678',$message)===$id,'Duplicate callbacks retain one notification');
    $calls = 0;
    $rejected = function() use (&$calls) { $calls++; return ['success'=>false,'message'=>'No SMS balance']; };
    noticeCheck(deliverPaymentNotification($pdo,$id,$rejected)==='failed','Explicit provider rejection remains retryable');
    noticeCheck(deliverPaymentNotification($pdo,$id,$rejected)==='skipped' && $calls===1,'Repeated callback respects retry delay');
    $pdo->exec("UPDATE payment_notifications SET next_retry_at=NOW() WHERE id=$id");
    $accepted = function($phone,$text,$client) use (&$calls,$message) {
        $calls++; noticeCheck($text===$message && $client===1,'Retry retains customer credentials');
        return ['success'=>true];
    };
    noticeCheck(deliverPaymentNotification($pdo,$id,$accepted)==='sent','Failed payment SMS recovers after provider restoration');
    noticeCheck(deliverPaymentNotification($pdo,$id,$accepted)==='skipped' && $calls===2,'Accepted SMS never resends on callback replay');
    $id2 = queuePaymentNotification($pdo,2,1,8,'REF8','0712345678',$message);
    noticeCheck(deliverPaymentNotification($pdo,$id2,fn()=>['uncertain'=>true])==='unknown','Uncertain delivery is visible');
    noticeCheck(deliverPaymentNotification($pdo,$id2,$accepted)==='skipped','Uncertain send cannot automatically duplicate');
    $pdo->exec("INSERT INTO sms_logs (tenant_id,client_id,phone,message,status,reference) VALUES (2,1,'0712345678','Old rejected message','failed','REF9')");
    $id3 = queuePaymentNotification($pdo,2,1,9,'REF9','0712345678',$message);
    noticeCheck(deliverPaymentNotification($pdo,$id3,fn()=>['success'=>true])==='sent','Legacy failed log does not suppress login SMS');
    $id4 = queuePaymentNotification($pdo,2,1,10,'REF10','0712345678',$message);
    $pdo->exec("UPDATE payment_notifications SET status='sending' WHERE id=$id4");
    noticeCheck(deliverPaymentNotification($pdo,$id4,$accepted)==='skipped','Interrupted send remains held for provider verification');
    $pdo->exec("INSERT INTO sms_logs (tenant_id,client_id,phone,message,status,reference) VALUES (2,1,'0712345678','Payment received without credentials','sent','REF11'),(2,1,'0712345678','Login: existing/password','sent','REF12')");
    $id5 = queuePaymentNotification($pdo,2,1,11,'REF11','0712345678',$message);
    noticeCheck(deliverPaymentNotification($pdo,$id5,fn()=>['success'=>true])==='sent','Old receipt without credentials does not suppress login delivery');
    $id6 = queuePaymentNotification($pdo,2,1,12,'REF12','0712345678',$message);
    noticeCheck(deliverPaymentNotification($pdo,$id6,$accepted)==='skipped','Legacy accepted credential SMS is preserved without duplication');
    $pdo->exec("CREATE TABLE platform_sms_config (id INT PRIMARY KEY,api_key VARCHAR(100),sender_id VARCHAR(30),api_url VARCHAR(255),is_active INT)");
    $pdo->exec("INSERT INTO platform_sms_config VALUES (1,'TEST_KEY','DISCONTINUED','https://bulksms.talksasa.com/api/v3/sms/send',1)");
    $id7 = queuePaymentNotification($pdo,2,1,13,'REF13','0712345678',$message);
    $oldHash = paymentNotificationConfigHash($pdo,2);
    noticeCheck(deliverPaymentNotification($pdo,$id7,fn()=>['sender_failure'=>true])==='blocked_config','Discontinued sender waits for configuration repair');
    $pdo->prepare('UPDATE payment_notifications SET config_hash=? WHERE id=?')->execute([$oldHash,$id7]);
    $beforeCalls=$calls;
    retryPaymentNotifications($pdo,$accepted);
    noticeCheck($calls===$beforeCalls,'Unchanged rejected sender is not repeatedly contacted');
    $pdo->exec("UPDATE platform_sms_config SET sender_id='APPROVED'");
    retryPaymentNotifications($pdo,$accepted);
    noticeCheck($calls===$beforeCalls+1,'Approved sender configuration change automatically resumes queued SMS');
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
