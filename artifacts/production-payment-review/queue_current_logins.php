<?php
if(PHP_SAPI!=='cli')exit;
require '/var/www/html/fortunett_technologies_/includes/db_master.php';
require '/var/www/html/fortunett_technologies_/includes/payment_notifications.php';
require_once '/var/www/html/fortunett_technologies_/includes/sms_config.php';
$apply=in_array('--apply',$argv,true);
$query="SELECT c.id,c.phone,c.expiry_date,c.status,p.id AS payment_id,p.transaction_id,p.amount,p.payment_date,pk.name AS package_name
    FROM clients c JOIN packages pk ON pk.id=c.package_id AND pk.tenant_id=c.tenant_id
    JOIN payments p ON p.client_id=c.id AND p.tenant_id=c.tenant_id AND p.status='completed'
    WHERE c.tenant_id=9 AND c.status='active' AND c.expiry_date>NOW()
    AND p.id=(SELECT p2.id FROM payments p2 WHERE p2.tenant_id=c.tenant_id AND p2.client_id=c.id AND p2.status='completed' ORDER BY p2.payment_date DESC,p2.id DESC LIMIT 1)
    AND EXISTS (SELECT 1 FROM payment_activations a WHERE a.tenant_id=c.tenant_id AND a.client_id=c.id AND a.activation_key IN (p.transaction_id,p.checkout_request_id) AND a.expiry_date=c.expiry_date)";
$rows=$pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){
 $result=['client_id'=>$row['id'],'payment_id'=>$row['payment_id'],'expiry'=>$row['expiry_date'],'last_paid'=>$row['payment_date'],'action'=>$apply?'queued':'plan'];
 if($apply){
  $lock='payment-client-9-'.$row['id'];$st=$pdo->prepare('SELECT GET_LOCK(?,5)');$st->execute([$lock]);
  if(!(int)$st->fetchColumn()){echo json_encode(['client_id'=>$row['id'],'action'=>'busy']).PHP_EOL;continue;}
  try {
   $st=$pdo->prepare("SELECT expiry_date FROM clients WHERE id=? AND tenant_id=9 AND status='active' AND expiry_date>NOW()");$st->execute([$row['id']]);
   if($st->fetchColumn()!==$row['expiry_date'])throw new RuntimeException('Subscription changed; re-audit');
   $creds=ensurePaidLoginCredentials($pdo,9,(int)$row['id']);
   $message='KSH '.number_format($row['amount'],2).' received for '.$row['package_name'].'. Valid to '.date('d M Y H:i',strtotime($row['expiry_date'])).'. If not connected, open the Wi-Fi login page. Username: '.$creds['username'].' Password: '.$creds['password'].'. Ref '.$row['transaction_id'].'.';
   $id=queuePaymentNotification($pdo,9,(int)$row['id'],(int)$row['payment_id'],$row['transaction_id'],$row['phone'],$message);
   // The operator confirmed this sender was discontinued and the provider
   // explicitly rejected it. Wait for different settings without another send.
   [$cfg]=smsResolveConfig($pdo,9);
   if(trim($cfg['sender_id']??'')==='TALKSASA'){
    $pdo->prepare("UPDATE payment_notifications SET status='blocked_config',config_hash=?,last_error='TALKSASA sender discontinued; awaiting approved sender' WHERE id=? AND status IN ('pending','failed')")
     ->execute([paymentNotificationConfigHash($pdo,9),$id]);
   }
   $result['notification_id']=$id;
  }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
 }
 echo json_encode($result).PHP_EOL;
}
echo json_encode(['eligible_current_payments'=>count($rows),'mode'=>$apply?'apply':'dry-run','messages_sent_by_this_script'=>0]).PHP_EOL;
