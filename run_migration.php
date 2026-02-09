<?php
require_once __DIR__ . '/includes/db_master.php';

echo "Connected to database: " . $DB_NAME . "\n";

$sqlFile = __DIR__ . '/sql/migrations/sms_schema.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

try {
    // PDO can handle multiple statements if configured, but let's try separate execution if possible or just one big block
    // MySQL PDO usually allows multiple statements if data fetching isn't involved in the middle
    $pdo->exec($sql);
    echo "Migration executed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
