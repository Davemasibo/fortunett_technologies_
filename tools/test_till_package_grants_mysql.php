<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/test_customer_package_rules.php';
require_once __DIR__.'/../includes/account_resolver.php';
require_once __DIR__.'/../includes/payment_activation.php';
require_once __DIR__.'/../includes/package_profile.php';
date_default_timezone_set('Africa/Nairobi');
$port=(int)(getenv('PAYMENT_TEST_PORT') ?: 3306);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='fortunett_till_test_'.bin2hex(random_bytes(5));$pdo->exec("CREATE DATABASE `$schema`");$pdo->exec("USE `$schema`");
try {
    $pdo->exec("CREATE TABLE clients(id INT PRIMARY KEY,tenant_id INT,phone VARCHAR(40),account_number VARCHAR(40),connection_type VARCHAR(20),status VARCHAR(20),package_id INT,expiry_date DATETIME NULL,account_balance DECIMAL(12,2) DEFAULT 0,expiry_reminder_3d_sent INT DEFAULT 0,expiry_reminder_1d_sent INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE packages(id INT PRIMARY KEY,tenant_id INT,name VARCHAR(50),connection_type VARCHAR(20),type VARCHAR(20),status VARCHAR(20),price DECIMAL(12,2),validity_value INT,validity_unit VARCHAR(20),mikrotik_profile VARCHAR(50))");
    $pdo->exec("INSERT INTO packages VALUES (1,2,'Hotspot 30 min','hotspot','hotspot','active',20,30,'minutes','HS-30MIN'),(2,2,'PPPoE monthly','pppoe','pppoe','active',500,1,'months','PPPOE-MONTH'),(3,3,'Other tenant','hotspot','hotspot','active',20,1,'hours','OTHER')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,phone,account_number,connection_type,status,package_id) VALUES(1,2,'0712345678','H001','hotspot','expired',1),(2,2,'0723456789','P001','pppoe','expired',2)");
    $pdo->exec("CREATE TABLE pending_provisions(tenant_id INT,client_id INT,package_id INT,receipt VARCHAR(191),fail_reason TEXT,attempts INT DEFAULT 1,next_retry_at DATETIME,PRIMARY KEY(tenant_id,client_id))");
    $hotspot=customerAvailablePackages($pdo,2,['connection_type'=>'hotspot']);
    packageRuleCheck(count($hotspot)===1 && (int)$hotspot[0]['id']===1,'Hotspot catalog excludes PPPoE and other tenant packages');
    $pppoe=customerAvailablePackages($pdo,2,['connection_type'=>'pppoe']);
    packageRuleCheck(count($pppoe)===1 && (int)$pppoe[0]['id']===2,'PPPoE catalog excludes hotspot packages');
    packageRuleCheck(customerAvailablePackages($pdo,3,['connection_type'=>'pppoe'])===[],'No matching packages stays empty instead of cross-type fallback');
    $match=resolveAccountRef($pdo,'','254712345678',2);
    packageRuleCheck($match && (int)$match['client_id']===1,'Till without reference resolves unique payer phone');
    $start=time();$result=activatePaidSubscription($pdo,1,2,'TILL-1','TILL-1',$hotspot[0],40);
    packageRuleCheck($result['periods']===1 && abs(strtotime($result['expiry_date'])-($start+1800))<=3 && (float)$result['balance']===20.0,'Till hotspot payment grants exactly 30 minutes and retains excess credit');
    packageRuleCheck(packageProfileName($hotspot[0])==='HS-30MIN' && (int)$pdo->query('SELECT package_id FROM pending_provisions WHERE client_id=1')->fetchColumn()===1,'Provisioning uses paid hotspot package profile and package ID');
    $again=activatePaidSubscription($pdo,1,2,'TILL-1','TILL-1',$hotspot[0],40);
    packageRuleCheck($again['already_applied'] && $again['expiry_date']===$result['expiry_date'],'Duplicate till callback does not award extra duration');
    $result=activatePaidSubscription($pdo,2,2,'TILL-2','TILL-2',$pppoe[0],1000);
    packageRuleCheck($result['periods']===2 && abs(strtotime($result['expiry_date'])-strtotime(packageExpiryFrom(2,'months',$start)))<=3,'Till PPPoE payment grants two full calendar months');
    $pdo->exec("INSERT INTO clients(id,tenant_id,phone,account_number,connection_type,status,package_id) VALUES(3,2,'0712345678','P003','pppoe','active',2)");
    packageRuleCheck(resolveAccountRef($pdo,'','254712345678',2)===null,'Shared phone with hotspot and PPPoE accounts is held instead of guessed');
}finally{$pdo->exec("DROP DATABASE `$schema`");}
