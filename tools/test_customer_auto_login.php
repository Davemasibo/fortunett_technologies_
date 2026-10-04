<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/db_master.php';
require __DIR__.'/../classes/CustomerAuth.php';
// Connection-local fixtures shadow tables; no live customers or payments change.
$pdo->exec("CREATE TEMPORARY TABLE clients(id INT PRIMARY KEY,tenant_id INT,package_id INT,status VARCHAR(20),username VARCHAR(40),full_name VARCHAR(40),email VARCHAR(40),phone VARCHAR(20),account_number VARCHAR(30),account_balance DECIMAL(10,2),expiry_date DATETIME)");
$pdo->exec("CREATE TEMPORARY TABLE payment_auto_logins(id INT AUTO_INCREMENT PRIMARY KEY,client_id INT,tenant_id INT,login_token VARCHAR(64),status VARCHAR(20) DEFAULT 'pending',expires_at DATETIME,used_at DATETIME,ip_address VARCHAR(50))");
$pdo->exec("CREATE TEMPORARY TABLE customer_sessions(id INT AUTO_INCREMENT PRIMARY KEY,client_id INT,session_token VARCHAR(64),ip_address VARCHAR(50),mac_address VARCHAR(20),user_agent VARCHAR(255),expires_at DATETIME,last_activity DATETIME)");
$pdo->exec("CREATE TEMPORARY TABLE customer_activity_log(client_id INT,activity_type VARCHAR(20),description VARCHAR(100),ip_address VARCHAR(50),user_agent VARCHAR(255))");
$pdo->exec("CREATE TEMPORARY TABLE packages(id INT,device_limit INT)");
$pdo->exec("INSERT INTO packages VALUES(43,1)");
$pdo->exec("INSERT INTO clients VALUES(1,14,43,'active','customer','Alex','alex@example.test','0700000000','TEST001',0,DATE_ADD(NOW(),INTERVAL 1 DAY)),(2,15,43,'active','other','Other','other@example.test','0700000001','TEST002',0,DATE_ADD(NOW(),INTERVAL 1 DAY)),(3,14,43,'suspended','blocked','Blocked','blocked@example.test','0700000002','TEST003',0,DATE_ADD(NOW(),INTERVAL 1 DAY))");
$auth=new CustomerAuth($pdo);$checks=0;
function verifyPortal(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
$issue=function($token,$client=1,$expired=false)use($pdo){$pdo->prepare("INSERT INTO payment_auto_logins(client_id,login_token,expires_at) VALUES(?,?,".($expired?'DATE_SUB(NOW(),INTERVAL 1 MINUTE)':'DATE_ADD(NOW(),INTERVAL 5 MINUTE)').")")->execute([$client,$token]);};
$issue('first');$result=$auth->autoLogin('first',null,null,14);
verifyPortal($result['success'] && (int)$result['client']['tenant_id']===14,'paid identity includes correct tenant');
verifyPortal($auth->validateSession($result['session_token'])['valid'],'issued session opens protected portal');
verifyPortal(!$auth->autoLogin('first',null,null,14)['success'],'used token cannot be replayed');
$issue('second');verifyPortal($auth->autoLogin('second',null,null,14)['success'],'one internet device does not prevent another portal browser');
$issue('wrongtenant',2);verifyPortal(!$auth->autoLogin('wrongtenant',null,null,14)['success'],'cross-tenant token rejected');
verifyPortal($auth->autoLogin('wrongtenant',null,null,15)['success'],'rejected token remains usable on correct tenant');
$issue('expired',1,true);verifyPortal(!$auth->autoLogin('expired',null,null,14)['success'],'expired token rejected');
$issue('blocked',3);verifyPortal(!$auth->autoLogin('blocked',null,null,14)['success'],'suspended account cannot auto-login');
$issue('retry');$pdo->exec('DROP TEMPORARY TABLE customer_sessions');
$pdo->exec('CREATE TEMPORARY TABLE customer_sessions(unsupported INT)');
verifyPortal(!$auth->autoLogin('retry',null,null,14)['success'],'session storage failure fails closed');
verifyPortal($pdo->query("SELECT status FROM payment_auto_logins WHERE login_token='retry'")->fetchColumn()==='pending','session failure does not consume token');
echo "PASS $checks customer authentication checks".PHP_EOL;
