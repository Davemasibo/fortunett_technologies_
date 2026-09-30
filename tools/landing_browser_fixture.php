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
$pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,username VARCHAR(100),email VARCHAR(100),password_hash VARCHAR(255),role VARCHAR(30),tenant_id INT NULL,is_super_admin TINYINT,email_verified TINYINT)');
$insert = $pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?)');
$hash = password_hash('Local-test-only-42!', PASSWORD_DEFAULT);
$insert->execute([1,'ghetto-admin','ghetto@example.test',$hash,'admin',9,0,1]);
$insert->execute([2,'ecoland-admin','ecoland@example.test',$hash,'admin',10,0,1]);
$insert->execute([3,'super-admin','super@example.test',$hash,'admin',null,1,1]);
$insert->execute([4,'unverified','unverified@example.test',$hash,'admin',9,0,0]);
echo "Landing fixture ready.\n";
