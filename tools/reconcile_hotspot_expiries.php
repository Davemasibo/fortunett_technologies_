<?php
/** Bulk reconciliation for every hotspot customer; no hard-coded tenant or account. */
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/hotspot_expiry_reconciliation.php';
require_once __DIR__ . '/../includes/dashboard_sync.php';
$options=getopt('',['tenant:','all-tenants','apply','terms:','tariffs:']);
$tenant=(int)($options['tenant'] ?? 0);$apply=array_key_exists('apply',$options);
if (!$tenant && !array_key_exists('all-tenants',$options)) exit("Usage: php tools/reconcile_hotspot_expiries.php --tenant=ID|--all-tenants [--terms=reviewed-history.json] [--apply]\n");
$tariff=[];
if (isset($options['tariffs'])) {
    $tariff=json_decode(file_get_contents($options['tariffs']),true,512,JSON_THROW_ON_ERROR);
    if (!$tenant || $tenant!==(int)($tariff['tenant_id'] ?? 0) || empty($tariff['source']) || empty($tariff['prices'])) throw new RuntimeException('Use the tariff file only with its explicit --tenant ID');
    require_once __DIR__ . '/../includes/hotspot_tariffs.php';
}
$terms=[];
if (isset($options['terms'])) {
    $terms=json_decode(file_get_contents($options['terms']),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($terms)) throw new RuntimeException('Historical terms must be a JSON object');
}
if ($apply) {
    dashboardSyncSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_tariff_repairs (id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id INT NOT NULL,package_id INT NOT NULL,evidence LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_expiry_repairs (id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id INT NOT NULL,client_id INT NOT NULL,old_expiry DATETIME NULL,new_expiry DATETIME NOT NULL,evidence LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
}
$packageChanges=$tariff?hotspotTariffPackagePlan($pdo,$tariff,$apply):[];
$st=$pdo->prepare("SELECT id,tenant_id FROM clients WHERE connection_type='hotspot' AND (?=0 OR tenant_id=?) ORDER BY tenant_id,id");$st->execute([$tenant,$tenant]);
$customers=$st->fetchAll(PDO::FETCH_ASSOC);$report=['mode'=>$apply?'apply':'preview','package_changes'=>$packageChanges,'customers_checked'=>0,'corrections'=>0,'review_required'=>0,'checks'=>[]];
foreach ($customers as $entry) {
    $key='payment-client-'.$entry['tenant_id'].'-'.$entry['id'];$locked=false;$row=$entry;
    try {
        $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$key]);$locked=(int)$lock->fetchColumn()===1;
        if (!$locked) throw new RuntimeException('Concurrent subscription update; retry this account');
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? FOR UPDATE');$st->execute([$entry['id'],$entry['tenant_id']]);$client=$st->fetch(PDO::FETCH_ASSOC);
        $row['account']=$client['account_number'];$row['old_expiry']=$client['expiry_date'];
        $plan=hotspotPurchaseEvidence($pdo,(int)$entry['tenant_id'],(int)$entry['id'],$terms,$tariff);$row=array_merge($row,$plan);
        if (!$plan['repairable']) { $report['review_required']++;$pdo->rollBack(); }
        elseif (empty($client['expiry_date']) || strtotime($plan['expiry'])>strtotime($client['expiry_date'])) {
            $row['repairable']=false;$row['reason']='Reconstructed entitlement would extend current access; requires review, not an automatic replay';$report['review_required']++;$pdo->rollBack();
        } elseif ($plan['expiry']===$client['expiry_date']) { $row['action']='unchanged';$pdo->rollBack(); }
        elseif (!$apply) { $row['action']='correct_and_queue_router';$pdo->rollBack();$report['corrections']++; }
        else {
            $pdo->prepare('INSERT INTO hotspot_expiry_repairs (tenant_id,client_id,old_expiry,new_expiry,evidence) VALUES (?,?,?,?,?)')->execute([$entry['tenant_id'],$entry['id'],$client['expiry_date'],$plan['expiry'],json_encode($plan)]);
            $pdo->prepare("UPDATE clients SET expiry_date=?,status=CASE WHEN status='active' AND ?<=NOW() THEN 'inactive' ELSE status END WHERE id=? AND tenant_id=?")->execute([$plan['expiry'],$plan['expiry'],$entry['id'],$entry['tenant_id']]);
            dashboardQueueCustomer($pdo,(int)$entry['tenant_id'],(int)$entry['id']);$pdo->commit();$row['action']='database_corrected_router_queued';$report['corrections']++;
        }
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();$row['error']=$e->getMessage();$report['review_required']++; }
    finally { if($locked)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
    $report['checks'][]=$row;$report['customers_checked']++;
}
$report['router_enforcement_verified']=false;
echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;
