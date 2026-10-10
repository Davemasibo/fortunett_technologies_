<?php
if (PHP_SAPI !== 'cli') exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/includes/sms_verify.php';
$st=$pdo->prepare('SELECT id,company_name,subdomain FROM tenants WHERE subdomain=?');
$st->execute(['ghettohlink']);$tenant=$st->fetch(PDO::FETCH_ASSOC);
if (!$tenant) throw new RuntimeException('Tenant not found');
$id=(int)$tenant['id'];$report=['tenant'=>$tenant];
$queries=[
 'recent_payments'=>"SELECT status,COUNT(*) AS records,MIN(payment_date) AS oldest,MAX(payment_date) AS newest FROM payments WHERE tenant_id=$id AND payment_date>NOW()-INTERVAL 3 DAY GROUP BY status",
 'recent_paid_clients'=>"SELECT c.id,c.status,c.connection_type,c.expiry_date,c.last_seen,c.package_id, (COALESCE(c.mikrotik_username,'')<>'') AS has_username,(COALESCE(c.mikrotik_password,'')<>'') AS has_password,MAX(p.payment_date) AS latest_payment,COUNT(*) AS payment_count FROM clients c JOIN payments p ON p.client_id=c.id AND p.tenant_id=c.tenant_id WHERE c.tenant_id=$id AND p.status='completed' AND p.payment_date>NOW()-INTERVAL 3 DAY GROUP BY c.id ORDER BY latest_payment DESC LIMIT 35",
 'router_config'=>"SELECT id,name,ip_address,vpn_ip,api_port,status FROM mikrotik_routers WHERE tenant_id=$id",
 'pending_provisions'=>"SELECT id,client_id,attempts,fail_reason,next_retry_at FROM pending_provisions WHERE tenant_id=$id",
 'sms_states_3d'=>"SELECT status,COUNT(*) AS records FROM sms_outbox WHERE tenant_id=$id AND sent_at>NOW()-INTERVAL 3 DAY GROUP BY status",
 'sms_last_errors'=>"SELECT id,client_id,status,provider_response FROM sms_outbox WHERE tenant_id=$id AND status='failed' ORDER BY id DESC LIMIT 4",
 'sms_logs_3d'=>"SELECT status,COUNT(*) AS records FROM sms_logs WHERE tenant_id=$id AND sent_at>NOW()-INTERVAL 3 DAY GROUP BY status",
 'sms_columns'=>"SHOW COLUMNS FROM sms_outbox",
 'services'=>"SELECT DISTINCT rs.client_id,rs.router_id,rs.status,rs.package_id,rs.paid_expiry_at,rs.expiry_policy_version FROM router_services rs JOIN clients c ON c.id=rs.client_id AND c.tenant_id=rs.tenant_id WHERE c.tenant_id=$id AND c.status='active' AND c.expiry_date>NOW()"
];
foreach($queries as $key=>$sql) {
 try {$report[$key]=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
 catch(Throwable $e){$report[$key]=['error'=>$e->getMessage()];}
}
$report['active_without_router_user_payment_evidence']=$pdo->query("SELECT c.id,c.connection_type,c.package_id,c.status,c.expiry_date,COUNT(p.id) AS completed_payments,MAX(p.payment_date) AS last_paid FROM clients c LEFT JOIN payments p ON p.client_id=c.id AND p.tenant_id=c.tenant_id AND p.status='completed' WHERE c.tenant_id=$id AND c.id IN (457,681) GROUP BY c.id")->fetchAll(PDO::FETCH_ASSOC);
$state=smsVerifyTenant($pdo,$id,true);
$report['sms_probe']=array_intersect_key($state,array_flip(['source','sender_id','verdict','detail','action','probe']));
echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
