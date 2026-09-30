<?php
// Isolated local fixture; never connects to the application database.
if (PHP_SAPI !== 'cli') exit;
$pdo = new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_landing_browser_test';
if (in_array('--cleanup', $argv, true)) { $pdo->exec("DROP DATABASE IF EXISTS `$schema`"); exit; }
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY, company_name VARCHAR(100), subdomain VARCHAR(100))');
$pdo->exec("INSERT INTO tenants VALUES(9,'Ghetto Link','ghettohlink'),(10,'Ecoland Attic','ecolandattic')");
$pdo->exec('CREATE TABLE tenant_settings(tenant_id INT,setting_key VARCHAR(100),setting_value TEXT)');
$pdo->exec('CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY,username VARCHAR(100),email VARCHAR(100),password_hash VARCHAR(255),role VARCHAR(30),tenant_id INT NULL,is_super_admin TINYINT,email_verified TINYINT)');
$insert = $pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?)');
$hash = password_hash('Local-test-only-42!', PASSWORD_DEFAULT);
$insert->execute([1,'ghetto-admin','ghetto@example.test',$hash,'admin',9,0,1]);
$insert->execute([2,'ecoland-admin','ecoland@example.test',$hash,'admin',10,0,1]);
$insert->execute([3,'super-admin','super@example.test',$hash,'admin',null,1,1]);
$insert->execute([4,'unverified','unverified@example.test',$hash,'admin',9,0,0]);
$pdo->exec("ALTER TABLE users ADD is_verified TINYINT DEFAULT 0, ADD verification_token VARCHAR(100), ADD account_prefix VARCHAR(40)");
$pdo->exec("ALTER TABLE tenants MODIFY id INT AUTO_INCREMENT, ADD admin_user_id INT, ADD provisioning_token VARCHAR(100), ADD trial_ends_at DATE, ADD status VARCHAR(30), ADD subscription_plan_id INT");
$pdo->exec("CREATE TABLE platform_settings(setting_key VARCHAR(100),setting_value VARCHAR(255))");
$pdo->exec("INSERT INTO platform_settings VALUES('signup_enabled','1')");
$pdo->exec("CREATE TABLE platform_subscription_plans(id INT PRIMARY KEY,slug VARCHAR(40),is_active TINYINT,pppoe_fee_per_user DECIMAL(10,2))");
$pdo->exec("INSERT INTO platform_subscription_plans VALUES(1,'starter',1,15)");
echo "Landing fixture ready.\n";
