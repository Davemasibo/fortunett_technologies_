<?php
require_once 'includes/config.php';
try {
    $stmt = $pdo->prepare("DESCRIBE mikrotik_routers");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo implode(", ", $columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
