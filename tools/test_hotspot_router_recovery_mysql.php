<?php
/** Real selection SQL in a throwaway database; no router, payments or SMS. */
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../includes/db_master.php';
require __DIR__.'/../includes/hotspot_device.php';
$schema='fortunett_router_recovery_test_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
function routerRecoveryCheck(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS: $label\n";
}
try {
    $pdo->exec('CREATE TABLE mikrotik_routers (id INT PRIMARY KEY,tenant_id INT,status VARCHAR(30))');
    $pdo->exec('CREATE TABLE router_services (id INT PRIMARY KEY,tenant_id INT,client_id INT,router_id INT)');
    $pdo->exec('CREATE TABLE hotspot_device_context (tenant_id INT,client_id INT,mac_address VARCHAR(17),updated_at TIMESTAMP)');
    $pdo->exec("INSERT INTO mikrotik_routers VALUES (19,9,'inactive'),(20,10,'active'),(21,9,'suspended')");
    $client=['id'=>1,'connection_type'=>'hotspot'];
    routerRecoveryCheck(resolveClientRouter($pdo,$client,9)===19,'New paid customer retains sole tenant router after a failed health probe');
    $pdo->exec("UPDATE mikrotik_routers SET status='offline' WHERE id=19");
    routerRecoveryCheck(resolveClientRouter($pdo,$client,9)===19,'Offline health state still permits durable provisioning retries');
    $pdo->exec("INSERT INTO mikrotik_routers VALUES (22,9,'inactive')");
    try {resolveClientRouter($pdo,$client,9);throw new LogicException('Ambiguous router guessed');}
    catch(RuntimeException $e){routerRecoveryCheck($e->getMessage()==='Customer router assignment is required','Multiple routers require device evidence or an existing assignment');}
    $pdo->exec('INSERT INTO router_services VALUES (1,9,1,19)');
    routerRecoveryCheck(resolveClientRouter($pdo,$client,9)===19,'Existing assignment remains stable during a temporary outage');
    try {resolveClientRouter($pdo,['id'=>2,'connection_type'=>'hotspot'],11);throw new LogicException('Tenant router leaked');}
    catch(RuntimeException $e){routerRecoveryCheck($e->getMessage()==='Customer router assignment is required','Router recovery stays tenant scoped');}
} finally {$pdo->exec("DROP DATABASE `$schema`");}
