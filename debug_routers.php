<?php
require_once 'includes/config.php';
try {
    $stmt = $pdo->query("SELECT id, name, ip_address, status FROM mikrotik_routers");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
