<?php
// Temporary tables shadow production names only for this connection; no persisted data changes.
require __DIR__.'/../includes/db_master.php';
require __DIR__.'/../includes/analytics_range.php';
require __DIR__.'/../includes/router_collections.php';
$pdo->exec('CREATE TEMPORARY TABLE payments(id INT,tenant_id INT,client_id INT,amount DECIMAL(10,2),transaction_id VARCHAR(40),status VARCHAR(20),payment_date DATETIME)');
$pdo->exec('CREATE TEMPORARY TABLE mpesa_transactions(tenant_id INT,client_id INT,checkout_request_id VARCHAR(40),mpesa_receipt_number VARCHAR(40))');
$pdo->exec('CREATE TEMPORARY TABLE hotspot_purchase_locations(tenant_id INT,client_id INT,checkout_id VARCHAR(40),router_id INT)');
$pdo->exec("INSERT INTO payments VALUES (1,14,1,100,'r1','completed','2026-10-04'),(2,14,2,200,'r2','completed','2026-10-04'),(3,14,3,50,'manual','completed','2026-10-04'),(4,14,4,70,'r4','completed','2026-10-04'),(5,15,1,999,'r1','completed','2026-10-04'),(6,14,1,999,'failed','failed','2026-10-04'),(7,14,1,999,'old','completed','2025-01-01')");
$pdo->exec("INSERT INTO mpesa_transactions VALUES (14,1,'c1','r1'),(14,1,'c1','r1'),(14,2,'c2','r2'),(14,4,'c4','r4'),(15,1,'c1','r1')");
$pdo->exec("INSERT INTO hotspot_purchase_locations VALUES (14,1,'c1',20),(14,2,'c2',21),(14,4,'c4',20),(14,4,'c4',21),(15,1,'c1',99)");
$rows=routerCollectionRows($pdo,14,['bucket'=>'day','start'=>'2026-10-01','end_exclusive'=>'2026-11-01']);
$totals=[];$count=0;foreach($rows as $r){$totals[$r['router_id']??'unknown']=(float)$r['revenue'];$count+=(int)$r['sales'];}
if($totals!=[20=>100,21=>200,'unknown'=>120] || $count!==4)throw new RuntimeException('Attribution, duplicate callback or tenant isolation regression: '.json_encode($totals));
echo "PASS: canonical payments counted once; immutable router attribution; conflicting/manual purchases unattributed; tenant/status/date isolation.\n";
