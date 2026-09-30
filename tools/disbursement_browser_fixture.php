<?php
// Local, isolated browser regression fixture. Never uses application credentials.
if (PHP_SAPI !== 'cli') exit;
$pdo = new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = 'fortunett_disbursement_browser_test';
if (in_array('--cleanup', $argv, true)) { $pdo->exec("DROP DATABASE IF EXISTS `$schema`"); exit; }
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$pdo->exec('CREATE TABLE users(id INT PRIMARY KEY, username VARCHAR(50), email VARCHAR(100), password_hash VARCHAR(255), is_super_admin TINYINT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?,?,1)')->execute(['browser-admin','browser@example.test',password_hash('Browser-test-only-42!',PASSWORD_DEFAULT)]);
$pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY,company_name VARCHAR(100))');
$pdo->exec("INSERT INTO tenants VALUES(9,'Ghetto Link (test)')");
$pdo->exec("CREATE TABLE payments(id INT PRIMARY KEY, tenant_id INT, amount DECIMAL(12,2), collection_type VARCHAR(20), status VARCHAR(20), payment_date DATETIME, created_at DATETIME, released_at DATETIME NULL, release_note VARCHAR(255)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO payments(id,tenant_id,amount,collection_type,status,payment_date,created_at) VALUES(1,9,15000,'platform','completed','2026-09-29 10:00:00','2026-09-29 10:00:00')");
$pdo->exec("CREATE TABLE isp_payout_queue(id INT PRIMARY KEY,tenant_id INT,payment_id INT,status VARCHAR(20),processed_at DATETIME NULL,notes TEXT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO isp_payout_queue(id,tenant_id,payment_id,status) VALUES(1,9,1,'pending')");
echo "Isolated browser fixture ready.\n";
