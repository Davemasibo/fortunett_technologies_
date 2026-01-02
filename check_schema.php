<?php
require_once 'config/database.php';
$db = (new Database())->getConnection();
echo "--- CLIENTS TABLE ---\n";
try {
    $stmt = $db->query("DESCRIBE clients");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- PACKAGES TABLE ---\n";
try {
    $stmt = $db->query("DESCRIBE packages");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "\n--- MPESA TRANSACTIONS TABLE ---\n";
try {
    $stmt = $db->query("DESCRIBE mpesa_transactions");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
?>
