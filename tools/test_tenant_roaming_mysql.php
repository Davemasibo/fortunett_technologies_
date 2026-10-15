<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/tenant_roaming.php';
$schema='fortunett_roaming_test_'.bin2hex(random_bytes(5));$pdo->exec("CREATE DATABASE `$schema`");$pdo->exec("USE `$schema`");
function roamDbCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
try {
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY,tenant_id INT,package_id INT,status VARCHAR(20),expiry_date DATETIME,mikrotik_username VARCHAR(253),mikrotik_password VARCHAR(253),connection_type VARCHAR(20),bound_mac_address VARCHAR(20))');
    $pdo->exec('CREATE TABLE packages (id INT PRIMARY KEY,tenant_id INT,download_speed INT,upload_speed INT,device_limit INT)');
    $pdo->exec('INSERT INTO packages VALUES (1,14,10,5,2),(2,15,99,99,9)');
    $pdo->exec("INSERT INTO clients VALUES (1,14,1,'active',NOW()+INTERVAL 1 HOUR,'same-user','tenant14-password','hotspot',NULL),(2,15,2,'active',NOW()+INTERVAL 1 HOUR,'same-user','tenant15-password','hotspot',NULL),(3,14,1,'expired',NOW()-INTERVAL 1 HOUR,'expired-user','expired-password','hotspot',NULL),(4,14,1,'suspended',NOW()+INTERVAL 1 HOUR,'suspended-user','blocked-password','hotspot',NULL),(5,14,2,'active',NOW()+INTERVAL 1 HOUR,'bad-package','password','hotspot',NULL),(6,14,1,'active',NOW()+INTERVAL 1 HOUR,'tv-user','tv-password','hotspot','AA:BB:CC:DD:EE:FF')");
    $module=roamingSqlConfig(14,['host'=>'127.0.0.1','port'=>3306,'user'=>'test','password'=>'test','database'=>$schema]);
    function testRoamingQuery(string $config,string $key,string $user,string $mac=''): string {
        if(!preg_match('/'.preg_quote($key,'/').' = ("[^\n]*")/',$config,$match))throw new RuntimeException('Query missing');
        $sql=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
        return str_replace(['%{SQL-User-Name}','%{Calling-Station-Id}'],[$user,$mac],$sql);
    }
    $checks=$pdo->query(testRoamingQuery($module,'authorize_check_query','same-user'))->fetchAll(PDO::FETCH_NUM);
    roamDbCheck(count($checks)===2 && $checks[0][3]==='tenant14-password','Same username on another tenant cannot overwrite the authentication password');
    $reply=$pdo->query(testRoamingQuery($module,'authorize_reply_query','same-user'))->fetchAll(PDO::FETCH_NUM);
    $attrs=[];foreach($reply as $row)$attrs[$row[2]]=$row[3];
    roamDbCheck($attrs['Mikrotik-Rate-Limit']==='5M/10M' && $attrs['Mikrotik-Shared-Users']==='2','Roaming retains package speed and device limit');
    roamDbCheck((int)$attrs['Session-Timeout']<=3600 && (int)$attrs['Session-Timeout']>3500,'Session timeout uses remaining purchased time in Nairobi database time');
    foreach (['expired-user','suspended-user','bad-package','unknown'] as $username) roamDbCheck(!$pdo->query(testRoamingQuery($module,'authorize_check_query',$username))->fetchAll(),'Expired, suspended, foreign package or unknown customer cannot authenticate: '.$username);
    roamDbCheck(!$pdo->query(testRoamingQuery($module,'authorize_check_query','tv-user','11:22:33:44:55:66'))->fetchAll(),'Bound-device package rejects a different device at another location');
    roamDbCheck(count($pdo->query(testRoamingQuery($module,'authorize_check_query','tv-user','aa-bb-cc-dd-ee-ff'))->fetchAll())===2,'Bound device is admitted with normalized MAC format');
    roamingSessionSchema($pdo);
    $pdo->exec("INSERT INTO tenant_roaming_sessions (tenant_id,router_ip,username,session_id,started_at,updated_at) VALUES (14,'10.200.200.20','same-user','s1',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(14,'10.200.200.21','same-user','s2',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(15,'10.200.200.22','same-user','s3',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    roamDbCheck((int)$pdo->query(testRoamingQuery($module,'simul_count_query','same-user'))->fetchColumn()===2,'Concurrent sessions count both tenant routers and exclude other tenants');
} finally {$pdo->exec("DROP DATABASE `$schema`");}
echo "Roaming database checks passed.\n";
