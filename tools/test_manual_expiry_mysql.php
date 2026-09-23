<?php
/** Isolated database; never calls a router or sends messages. */
if (PHP_SAPI !== 'cli') exit;
if (getenv('TEST_MYSQL_DSN')) {
    $DB_HOST = getenv('TEST_MYSQL_HOST') ?: '127.0.0.1;port=13316';
    $DB_USER = getenv('TEST_MYSQL_USER') ?: 'root';
    $DB_PASS = getenv('TEST_MYSQL_PASSWORD') ?: '';
    $pdo = new PDO(getenv('TEST_MYSQL_DSN'),$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} else {
    require_once __DIR__ . '/../includes/db_master.php';
}
require_once __DIR__ . '/../includes/manual_expiry_check.php';
$schema = 'fortunett_expiry_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
function expiryAssert(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
try {
    $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY,tenant_id INT,status VARCHAR(20),expiry_date DATETIME) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE router_services (tenant_id INT,client_id INT,router_id INT) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO clients VALUES (1,1,'active',NOW()-INTERVAL 1 DAY),(2,1,'active',NOW()+INTERVAL 1 DAY),(3,2,'active',NOW()-INTERVAL 1 DAY),(4,1,'suspended',NOW()-INTERVAL 1 DAY),(5,1,'active',NULL),(6,1,'active',NOW()-INTERVAL 1 DAY)");
    $pdo->exec('INSERT INTO router_services VALUES (1,1,10),(1,1,11)');
    // A separate connection holds the same lock as a renewal in progress.
    $other = new PDO("mysql:host=$DB_HOST;dbname=$schema;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->query("SELECT GET_LOCK('payment-client-1-6',0)");
    $result = runTenantExpiryCheck($pdo,1);
    expiryAssert($result === ['updated'=>1,'remaining'=>1,'busy'=>1], 'Only overdue active accounts change; in-progress renewal is skipped');
    $states = $pdo->query('SELECT id,status FROM clients ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    expiryAssert($states === [1=>'inactive',2=>'active',3=>'active',4=>'suspended',5=>'active',6=>'active'], 'Future, other-tenant, suspended, missing-expiry and locked accounts stay untouched');
    expiryAssert((int)$pdo->query('SELECT COUNT(*) FROM dashboard_sync_jobs WHERE tenant_id=1 AND entity_id=1')->fetchColumn() === 2,'Both assigned routers receive durable disconnect jobs');
    $other->exec('UPDATE clients SET expiry_date=NOW()+INTERVAL 1 YEAR WHERE id=6');
    $other->query("SELECT RELEASE_LOCK('payment-client-1-6')");
    expiryAssert(runTenantExpiryCheck($pdo,1)['updated'] === 0,'Repeated check preserves renewed account and creates no duplicate disconnect jobs');
    // A queue failure must roll back the status transition.
    $pdo->exec("UPDATE clients SET status='active' WHERE id=1");
    $pdo->exec("CREATE TRIGGER reject_test_job BEFORE INSERT ON dashboard_sync_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test queue failure'");
    $failed=false;
    try { runTenantExpiryCheck($pdo,1); } catch (PDOException $e) { $failed=true; }
    expiryAssert($failed && $pdo->query('SELECT status FROM clients WHERE id=1')->fetchColumn()==='active','Queue failure rolls back status instead of losing router work');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (isset($other)) $other->query("SELECT RELEASE_LOCK('payment-client-1-6')");
    $pdo->exec("DROP DATABASE `$schema`");
}
