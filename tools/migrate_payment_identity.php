<?php
/** CLI-only, repeatable migration. Archive only gateway-proven duplicates. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function migratePaymentIdentity(PDO $pdo, bool $apply): void {
$pairs=$pdo->query("SELECT DISTINCT a.id duplicate_id,b.id keep_id FROM mpesa_transactions m
 JOIN payments a ON a.tenant_id=m.tenant_id AND a.client_id=m.client_id AND a.transaction_id=m.checkout_request_id
 JOIN payments b ON b.tenant_id=m.tenant_id AND b.client_id=m.client_id AND b.transaction_id=m.mpesa_receipt_number
 WHERE a.id<>b.id AND a.amount=b.amount AND a.amount=m.amount AND a.status='completed' AND b.status='completed'
 AND m.status='completed' AND m.result_code=0 AND m.checkout_request_id LIKE 'ws_CO_%'")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['proven_pairs'=>$pairs],JSON_PRETTY_PRINT)."\n";
if(!$apply) return;
foreach(['clients'=>"router_ownership VARCHAR(16) NOT NULL DEFAULT 'unknown'",'payments'=>'checkout_request_id VARCHAR(100) NULL'] as $table=>$definition) {
 $column=explode(' ',$definition)[0];
 if(!$pdo->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch()) $pdo->exec("ALTER TABLE $table ADD $definition");
}
$pdo->exec('CREATE TABLE IF NOT EXISTS payment_duplicate_archive (original_id INT PRIMARY KEY, canonical_id INT NOT NULL, row_json LONGTEXT NOT NULL, archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$pdo->beginTransaction();
try {
 foreach($pairs as $pair) {
  $id=(int)$pair['duplicate_id'];$keep=(int)$pair['keep_id'];
  foreach(['client_invoices','ledger_entries','isp_payout_queue','platform_commissions','platform_payment_allocations','payment_auto_logins'] as $table) {
   if($pdo->query("SELECT COUNT(*) FROM $table WHERE payment_id=$id")->fetchColumn()) throw new RuntimeException("Payment $id has dependent financial records; review required");
  }
  $row=$pdo->query("SELECT * FROM payments WHERE id=$id FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
  if(!$row) continue;
  $pdo->prepare('INSERT INTO payment_duplicate_archive(original_id,canonical_id,row_json) VALUES (?,?,?)')->execute([$id,$keep,json_encode($row,JSON_THROW_ON_ERROR)]);
  $pdo->exec("UPDATE mpesa_transactions SET payment_id=$keep WHERE payment_id=$id");
  $pdo->exec("DELETE FROM payments WHERE id=$id");
 }
 // Recover the recorded method from explicit manual provenance, never from phone/time guesses.
 $pdo->exec("UPDATE payments p JOIN mpesa_transactions m ON m.tenant_id=p.tenant_id AND m.client_id=p.client_id AND m.checkout_request_id=p.transaction_id
 SET p.payment_method=LOWER(TRIM(SUBSTRING_INDEX(SUBSTRING(m.result_desc,8),'|',1))),p.collection_type='direct'
 WHERE m.result_desc LIKE 'Manual:%' AND LOWER(TRIM(SUBSTRING_INDEX(SUBSTRING(m.result_desc,8),'|',1))) IN ('cash','bank_transfer','card','mpesa','mpesa_paybill','manual')");
 $pdo->exec("UPDATE payments p JOIN mpesa_transactions m ON m.tenant_id=p.tenant_id AND m.client_id=p.client_id AND (m.checkout_request_id=p.transaction_id OR m.mpesa_receipt_number=p.transaction_id)
 SET p.checkout_request_id=m.checkout_request_id WHERE m.checkout_request_id LIKE 'ws_CO_%' AND COALESCE(m.merchant_request_id,'') NOT LIKE 'MANUAL-%' AND COALESCE(m.result_desc,'') NOT LIKE 'Manual:%'");
 $pdo->commit();
} catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
if(!$pdo->query("SHOW INDEX FROM payments WHERE Key_name='uq_payment_checkout'")->fetch()) $pdo->exec('ALTER TABLE payments ADD UNIQUE KEY uq_payment_checkout(tenant_id,checkout_request_id)');
// Historical conflicting receipts are preserved. The registry reserves every old
// reference and enforces uniqueness for new writes, including legacy endpoints.
$pdo->exec('CREATE TABLE IF NOT EXISTS payment_reference_registry (tenant_id INT NOT NULL, reference VARCHAR(100) NOT NULL, PRIMARY KEY(tenant_id,reference)) ENGINE=InnoDB');
$pdo->exec("INSERT IGNORE INTO payment_reference_registry SELECT tenant_id,transaction_id FROM payments WHERE tenant_id IS NOT NULL AND NULLIF(TRIM(transaction_id),'') IS NOT NULL");
foreach(['insert'=>'INSERT','update'=>'UPDATE'] as $suffix=>$event) {
 $name='payment_identity_'.$suffix;
 if($pdo->query("SHOW TRIGGERS WHERE `Trigger`='$name'")->fetch())continue;
 $condition=$event==='INSERT'?"NEW.transaction_id IS NOT NULL":"NEW.transaction_id IS NOT NULL AND NOT (NEW.transaction_id <=> OLD.transaction_id AND NEW.tenant_id <=> OLD.tenant_id)";
 $pdo->exec("CREATE TRIGGER $name BEFORE $event ON payments FOR EACH ROW BEGIN
 SET NEW.transaction_id=NULLIF(TRIM(NEW.transaction_id),'');
 IF NEW.checkout_request_id IS NULL THEN
  IF NEW.transaction_id LIKE 'ws_CO_%' THEN SET NEW.checkout_request_id=NEW.transaction_id;
  ELSE SET NEW.checkout_request_id=(SELECT MAX(m.checkout_request_id) FROM mpesa_transactions m WHERE m.tenant_id=NEW.tenant_id AND m.client_id=NEW.client_id AND m.mpesa_receipt_number=NEW.transaction_id AND m.checkout_request_id LIKE 'ws_CO_%' AND COALESCE(m.merchant_request_id,'') NOT LIKE 'MANUAL-%'); END IF;
 END IF;
 IF $condition THEN INSERT INTO payment_reference_registry(tenant_id,reference) VALUES(NEW.tenant_id,NEW.transaction_id); END IF;
 END");
}
echo "Migration complete\n";

}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
 require_once __DIR__.'/../includes/db_master.php';
 if(in_array('--root-socket',$argv,true)) $pdo=new PDO("mysql:host=localhost;dbname=$DB_NAME;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 migratePaymentIdentity($pdo,in_array('--apply',$argv,true));
}
