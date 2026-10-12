<?php
/** Temporary isolated database. No SMS or emails are sent. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/test_sms_billing.php';
if ($dsn=getenv('SMS_BILLING_TEST_DSN')) {
    $pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    date_default_timezone_set('Africa/Nairobi');
    $pdo->exec("SET time_zone='+03:00'");
} else { require_once __DIR__.'/../includes/db_master.php'; }
require_once __DIR__.'/../includes/platform_billing.php';
$schema='fortunett_sms_bill_test_'.bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`"); $pdo->exec("USE `$schema`");
try {
    $pdo->exec('CREATE TABLE platform_subscription_plans (id INT PRIMARY KEY,pppoe_fee_per_user DECIMAL(10,2) DEFAULT 25,hotspot_commission_rate DECIMAL(5,4) DEFAULT 0.03,base_monthly_fee DECIMAL(10,2) DEFAULT 0)');
    $pdo->exec("CREATE TABLE tenants (id INT PRIMARY KEY,subscription_plan_id INT,status VARCHAR(20)); INSERT INTO tenants VALUES (1,1,'active'),(2,2,'active')");
    $pdo->exec('INSERT INTO platform_subscription_plans (id) VALUES (1),(2)');
    $sql=file_get_contents(__DIR__.'/../sql/install.sql');
    preg_match('/CREATE TABLE IF NOT EXISTS `platform_invoices`.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',$sql,$match);
    $pdo->exec($match[0]);
    $pdo->exec('CREATE TABLE clients (id INT,tenant_id INT,status VARCHAR(20),connection_type VARCHAR(20))');
    $pdo->exec('CREATE TABLE payments (id INT,tenant_id INT,client_id INT,status VARCHAR(20),amount DECIMAL(12,2),payment_date DATETIME)');
    $pdo->exec('CREATE TABLE mikrotik_routers (id INT,tenant_id INT,last_seen DATETIME,status VARCHAR(20),created_at DATETIME)');
    ensureSmsInvoiceSchema($pdo);
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=1.25 WHERE id=1');
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=2 WHERE id=2');
    $pdo->exec("INSERT INTO tenants VALUES (3,1,'active')");
    $pdo->exec('UPDATE platform_subscription_plans SET base_monthly_fee=10 WHERE id=1');
    $pdo->exec("INSERT INTO mikrotik_routers VALUES (1,3,NOW(),'active',NOW())");
    $zeroUsageInvoice=ensureCurrentPlatformInvoice($pdo,3,date('Y-m-01'));
    smsBillCheck($zeroUsageInvoice && (int)$zeroUsageInvoice['sms_units']===0 && (float)$zeroUsageInvoice['sms_rate']===1.25 && (float)$zeroUsageInvoice['sms_amount']===0.0,'Zero-usage invoice shows configured package SMS rate without charging');
    $config=['provider'=>'uwazii']; $phone='254712345678';
    $accepted=['success'=>true,'response'=>json_encode(['status'=>true,'data'=>[$phone=>[['id_state'=>'a'],['id_state'=>'b']]]])];
    $id=beginPlatformSmsUsage($pdo,1,5,$config,$phone);
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=3 WHERE id=1');
    finishPlatformSmsUsage($pdo,$id,$accepted,$phone,'Hello',true);
    finishPlatformSmsUsage($pdo,$id,['success'=>false],$phone,'Hello',true);
    $row=$pdo->query("SELECT * FROM platform_sms_usage WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    smsBillCheck($row['status']==='accepted' && (int)$row['sms_units']===1 && (float)$row['amount']===1.25,'Multipart provider response still bills one SMS unit, with duplicate completion protection');
    foreach ([['success'=>false],['uncertain'=>true]] as $result) {
        $failed=beginPlatformSmsUsage($pdo,1,null,$config,$phone); finishPlatformSmsUsage($pdo,$failed,$result,$phone,'Hello',true);
    }
    $other=beginPlatformSmsUsage($pdo,2,null,$config,$phone);finishPlatformSmsUsage($pdo,$other,$accepted,$phone,'Hello',true);
    $period=date('Y-m-01');
    $invoice=ensureCurrentPlatformInvoice($pdo,1,$period);
    smsBillCheck($invoice && (float)$invoice['total_due']===3.0 && (int)$invoice['sms_units']===1 && (int)$invoice['sms_message_count']===1,'Monthly invoice includes only tenant accepted usage');
    smsBillCheck(ensureCurrentPlatformInvoice($pdo,1,$period)['total_due']===$invoice['total_due'],'Invoice regeneration does not double charge');
    $pdo->exec('UPDATE platform_invoices SET amount_paid=total_due,status=\'paid\' WHERE id='.(int)$invoice['id']);
    $late=beginPlatformSmsUsage($pdo,1,null,$config,$phone);finishPlatformSmsUsage($pdo,$late,['success'=>true],$phone,'Hello',false);
    smsBillCheck((float)ensureCurrentPlatformInvoice($pdo,1,$period)['total_due']===3.0,'Paid invoice stays frozen');
    $next=ensureCurrentPlatformInvoice($pdo,1,date('Y-m-01',strtotime($period.' +1 month')));
    smsBillCheck($next && (float)$next['sms_amount']===3.0 && (int)$next['sms_units']===1,'Unbilled sends after payment carry to next invoice');
    smsBillCheck((float)ensureCurrentPlatformInvoice($pdo,2,$period)['sms_amount']===2.0,'Second tenant uses its own package SMS rate');
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=0 WHERE id=2');
    $free=beginPlatformSmsUsage($pdo,2,null,$config,$phone);finishPlatformSmsUsage($pdo,$free,$accepted,$phone,'Hello',true);
    ensureCurrentPlatformInvoice($pdo,2,$period);
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=1.5 WHERE id=2');
    $repriced=ensureCurrentPlatformInvoice($pdo,2,$period);
    smsBillCheck((float)$repriced['sms_amount']===3.0 && (int)$repriced['sms_units']===2,'Previously zero-rate current unpaid SMS reflects updated package pricing');
    $pdo->exec('UPDATE platform_invoices SET amount_paid=1 WHERE id='.(int)$repriced['id']);
    $pdo->exec('UPDATE platform_subscription_plans SET sms_rate=10 WHERE id=2');
    smsBillCheck((float)ensureCurrentPlatformInvoice($pdo,2,$period)['sms_amount']===3.0,'Part-paid invoice remains frozen after rate update');
    require_once __DIR__.'/../classes/SMSHelper.php';
    $pdo->exec("CREATE TABLE sms_configurations (tenant_id INT,provider VARCHAR(50),api_key VARCHAR(255),sender_id VARCHAR(50),api_url VARCHAR(255),is_active INT)");
    $pdo->exec("CREATE TABLE platform_sms_config (id INT,provider VARCHAR(50),api_key VARCHAR(255),sender_id VARCHAR(50),api_url VARCHAR(255),is_active INT)");
    $pdo->exec("INSERT INTO sms_configurations VALUES (1,'uwazii','own-offline-token','Uwazii-OTP','https://restapi.uwaziimobile.com/v1/send',1)");
    $pdo->exec("INSERT INTO platform_sms_config VALUES (1,'uwazii','platform-offline-token','Uwazii-OTP','https://restapi.uwaziimobile.com/v1/send',1)");
    class MeteredSmsDouble extends SMSHelper {
        public array $responses=[];
        protected function sendProviderHttp($phone,$message) { return array_shift($this->responses); }
    }
    $before=(int)$pdo->query('SELECT COUNT(*) FROM platform_sms_usage')->fetchColumn();
    $helper=new MeteredSmsDouble($pdo,1);$helper->responses=[$accepted];
    $helper->send($phone,'Hello',5,false);
    smsBillCheck((int)$pdo->query('SELECT COUNT(*) FROM platform_sms_usage')->fetchColumn()===$before,'Tenant-owned provider sends incur no platform SMS charge');
    $helper=new MeteredSmsDouble($pdo,1);$helper->responses=[['success'=>false,'auth_failure'=>true],$accepted];
    $result=$helper->send($phone,'Hello',5,false);
    smsBillCheck($result['success'] && (int)$pdo->query('SELECT COUNT(*) FROM platform_sms_usage')->fetchColumn()===$before+1,'Successful platform fallback meters exactly one accepted send');
} finally { $pdo->exec("DROP DATABASE `$schema`"); }
