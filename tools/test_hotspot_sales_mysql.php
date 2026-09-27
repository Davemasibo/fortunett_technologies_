<?php
/** Isolated database only. No router, payment gateway or SMS calls. */
if (PHP_SAPI !== 'cli') exit;
if (getenv('HOTSPOT_TEST_DSN')) {
    $pdo = new PDO(getenv('HOTSPOT_TEST_DSN'), getenv('HOTSPOT_TEST_USER') ?: 'root', getenv('HOTSPOT_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} else {
    require_once __DIR__ . '/../includes/db_master.php';
}
require_once __DIR__ . '/../includes/hotspot_location_sales.php';
require_once __DIR__ . '/../includes/hotspot_login_guard.php';
$schema = 'fortunett_hotspot_sales_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
function salesCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
try {
    $pdo->exec('CREATE TABLE clients(id INT PRIMARY KEY,tenant_id INT,connection_type VARCHAR(20))');
    $pdo->exec('CREATE TABLE payments(id INT PRIMARY KEY,tenant_id INT,client_id INT,amount DECIMAL(10,2),transaction_id VARCHAR(100),status VARCHAR(20),payment_date DATETIME)');
    $pdo->exec('CREATE TABLE mpesa_transactions(id INT PRIMARY KEY,tenant_id INT,client_id INT,checkout_request_id VARCHAR(100),mpesa_receipt_number VARCHAR(100))');
    $pdo->exec("INSERT INTO clients VALUES (1,1,'hotspot'),(2,2,'hotspot'),(3,1,'pppoe')");
    $pdo->exec("INSERT INTO payments VALUES
        (1,1,1,20,'receipt-a','completed','2026-09-27 10:00:00'),
        (2,1,1,30,'manual','completed','2026-09-27 10:00:00'),
        (3,1,1,40,'checkout-b','pending','2026-09-27 10:00:00'),
        (4,2,2,500,'receipt-a','completed','2026-09-27 10:00:00'),
        (5,1,3,1000,'pppoe','completed','2026-09-27 10:00:00'),
        (6,1,1,50,'checkout-old','completed','2026-09-26 23:59:59'),
        (7,1,1,60,'checkout-next','completed','2026-09-28 00:00:00')");
    $pdo->exec("INSERT INTO mpesa_transactions VALUES (1,1,1,'checkout-a','receipt-a'),(2,1,1,'checkout-a','receipt-a'),(3,2,2,'checkout-a','receipt-a')");
    $location=['router_id'=>7,'interface_name'=>'ether2','location_name'=>'Market','captured_at'=>'2026-09-27 09:59:00'];
    recordHotspotPurchaseLocation($pdo,'checkout-a',1,1,$location);
    recordHotspotPurchaseLocation($pdo,'checkout-a',1,1,array_merge($location,['interface_name'=>'ether3']));
    $rows=hotspotLocationSales($pdo,1,7,'2026-09-27 00:00:00','2026-09-28 00:00:00');
    $mapped=array_values(array_filter($rows,fn($row)=>$row['router_id']!==null));
    $unknown=array_values(array_filter($rows,fn($row)=>$row['router_id']===null));
    salesCheck(count($mapped)===1 && (int)$mapped[0]['sales']===1 && (float)$mapped[0]['revenue']===20.0,'Confirmed sales count once despite duplicate transaction rows; other tenants, PPPoE, pending payments and other dates are excluded');
    salesCheck($mapped[0]['interface_name']==='ether2','Later reconnects cannot move purchase location');
    salesCheck(count($unknown)===1 && (float)$unknown[0]['revenue']===30.0,'Manual and unlocated payments remain explicitly unattributed');
    $other=hotspotLocationSales($pdo,1,8,'2026-09-27 00:00:00','2026-09-28 00:00:00');
    salesCheck(count($other)===1 && $other[0]['router_id']===null,'Another router cannot claim the attributed revenue');
    for($i=0;$i<8;$i++) salesCheck(hotspotLoginGuard($pdo,1,'254712345678','192.0.2.1'),'Login attempt within limit accepted');
    salesCheck(!hotspotLoginGuard($pdo,1,'254712345678','192.0.2.2'),'Account attempt limit persists across source IPs');
    salesCheck(hotspotLoginGuard($pdo,2,'254712345678','192.0.2.2'),'Login attempt limits are tenant scoped');
    $pdo->exec('UPDATE hotspot_login_attempts SET window_start=NOW()-INTERVAL 6 MINUTE');
    salesCheck(hotspotLoginGuard($pdo,1,'254712345678','192.0.2.1'),'Attempt budget resets after five minutes');
} finally {
    $pdo->exec("DROP DATABASE `$schema`");
}
