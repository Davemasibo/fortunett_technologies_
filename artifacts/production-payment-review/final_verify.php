<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/includes/sms_verify.php';
require '/var/www/html/fortunett_technologies_/includes/cron_heartbeat.php';
$r=['checked_at'=>date(DATE_ATOM)];
$r['jobs']=['stk_poll'=>cron_last_run($pdo,'stk_poll'),'retry_provisions'=>cron_last_run($pdo,'retry_provisions')];
$r['credential_sms']=$pdo->query("SELECT status,COUNT(*) AS records,MAX(attempts) AS max_attempts FROM payment_notifications WHERE tenant_id=9 GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$r['paid_provision_backlog']=$pdo->query('SELECT COUNT(*) FROM pending_provisions WHERE tenant_id=9')->fetchColumn();
$r['ledger']=$pdo->query('SELECT status,COUNT(*) AS records,SUM(amount) AS amount FROM payments WHERE tenant_id=9 GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$state=smsVerifyTenant($pdo,9,false);
$r['sms_status']=array_intersect_key($state,array_flip(['verdict','sender_id','detail','last_failed_at','action']));
$r['credentials_present']=$pdo->query("SELECT COUNT(*) AS current_paid_accounts,SUM(COALESCE(c.mikrotik_username,'')<>'' AND COALESCE(c.mikrotik_password,'')<>'') AS with_credentials FROM clients c WHERE c.tenant_id=9 AND c.connection_type='hotspot' AND c.status='active' AND c.expiry_date>NOW() AND EXISTS (SELECT 1 FROM payments p WHERE p.client_id=c.id AND p.tenant_id=c.tenant_id AND p.status='completed')")->fetch(PDO::FETCH_ASSOC);
echo json_encode($r,JSON_PRETTY_PRINT).PHP_EOL;
