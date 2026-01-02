<?php
require_once 'includes/config.php';
try {
    $stmt = $pdo->query("DESCRIBE mikrotik_routers");
    echo "Table mikrotik_routers exists.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
