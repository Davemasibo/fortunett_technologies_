<?php
require_once 'includes/config.php';
try {
    $stmt = $pdo->query("DESCRIBE packages");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns:\n";
    foreach ($columns as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
