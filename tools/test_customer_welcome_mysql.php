<?php
/** Temporary test schema and provider double; never sends real SMS. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/customer_welcome.php';
$schema = 'fortunett_welcome_test_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`"); $pdo->exec("USE `$schema`");
function welcomeDbCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
try {
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY,tenant_id INT,full_name VARCHAR(50),phone VARCHAR(30),connection_type VARCHAR(20),mikrotik_username VARCHAR(100),mikrotik_password VARCHAR(100),account_number VARCHAR(30),package_id INT)');
    $pdo->exec('CREATE TABLE packages (id INT PRIMARY KEY,tenant_id INT,name VARCHAR(50),price DECIMAL(10,2))');
    $pdo->exec('CREATE TABLE tenants (id INT PRIMARY KEY,company_name VARCHAR(50),subdomain VARCHAR(50),contact_phone VARCHAR(30))');
    $pdo->exec('CREATE TABLE tenant_settings (tenant_id INT,setting_key VARCHAR(100),setting_value TEXT)');
    $pdo->exec('CREATE TABLE platform_settings (setting_key VARCHAR(100),setting_value TEXT)');
    $pdo->exec('CREATE TABLE payment_gateways (id INT,tenant_id INT,gateway_type VARCHAR(50),credentials TEXT,is_active INT,is_default INT)');
    $pdo->exec("INSERT INTO tenants VALUES (2,'Homelink','homelinkfiber','0712345678')");
    $pdo->exec("INSERT INTO tenant_settings VALUES (2,'hs_paybill','123456')");
    $pdo->exec("INSERT INTO packages VALUES (5,2,'Home 10Mbps',1500)");
    $pdo->exec("INSERT INTO clients VALUES (3,2,'Jane','0712345678','pppoe','jane','pin123','HL001',5)");
    customerWelcomeSchema($pdo);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO customer_welcome_notifications (tenant_id,client_id,phone,message,status) VALUES (2,3,'0712345678','','preparing')");
    $pdo->rollBack();
    welcomeDbCheck((int)$pdo->query('SELECT COUNT(*) FROM customer_welcome_notifications')->fetchColumn()===0,'Rolled-back registration cannot leave a welcome notice');
    $pdo->exec("INSERT INTO customer_welcome_notifications (tenant_id,client_id,phone,message,status) VALUES (2,3,'0712345678','','preparing')");
    $calls=0;
    $sender=function($phone,$message,$client) use (&$calls) {
        $calls++;
        welcomeDbCheck($phone==='0712345678' && $client===3 && str_contains($message,'Username: jane') && str_contains($message,'Password: pin123') && str_contains($message,'123456'),'Recovered welcome retains real credentials and tenant payment details');
        return ['success'=>true];
    };
    retryCustomerWelcomes($pdo,$sender);
    welcomeDbCheck($calls===1 && $pdo->query('SELECT status FROM customer_welcome_notifications')->fetchColumn()==='sent','Interrupted preparation recovers and sends once');
    $id=queueCustomerWelcome($pdo,2,3);
    welcomeDbCheck(queueCustomerWelcome($pdo,2,3)===$id && (int)$pdo->query('SELECT COUNT(*) FROM customer_welcome_notifications')->fetchColumn()===1,'Repeated queue requests keep one registration notice');
    retryCustomerWelcomes($pdo,$sender);
    welcomeDbCheck($calls===1,'Accepted registration is never sent again by cron');
    $rejected=false; try { queueCustomerWelcome($pdo,9,3); } catch (InvalidArgumentException $e) { $rejected=true; }
    welcomeDbCheck($rejected,'Other tenants cannot queue this customer notice');
    $pdo->exec("UPDATE customer_welcome_notifications SET status='pending',next_retry_at=NOW()");
    welcomeDbCheck(deliverPaymentNotification($pdo,$id,fn()=>['success'=>false,'message'=>'Insufficient SMS balance'],true)==='failed','Provider rejection stays retryable in durable welcome queue');
    $pdo->exec("UPDATE customer_welcome_notifications SET next_retry_at=NOW()");
    retryCustomerWelcomes($pdo,$sender);
    welcomeDbCheck($calls===2,'Rejected welcome is recovered by cron');
    $pdo->exec("UPDATE customer_welcome_notifications SET status='pending',next_retry_at=NOW()");
    welcomeDbCheck(deliverPaymentNotification($pdo,$id,fn()=>['uncertain'=>true],true)==='unknown','Uncertain delivery is held for confirmation');
    retryCustomerWelcomes($pdo,$sender);
    welcomeDbCheck($calls===2,'Uncertain welcome is not duplicated by cron');
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
echo "Welcome database checks passed. No real SMS sent.\n";
