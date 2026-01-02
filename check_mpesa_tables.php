<?php
require_once 'includes/config.php';

function describeTable($pdo, $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        echo "Table: $table\n";
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
        echo "\n";
    } catch (Exception $e) {
        echo "Table $table error: " . $e->getMessage() . "\n";
    }
}

describeTable($pdo, 'payments');
describeTable($pdo, 'mpesa_transactions');
?>
