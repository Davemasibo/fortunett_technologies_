<?php
require_once 'config/database.php';
$db = (new Database())->getConnection();
try {
    $stmt = $db->query("SELECT * FROM clients LIMIT 1");
    $cols = array_keys($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    echo "Columns: " . implode(", ", $cols);
} catch (Exception $e) { echo $e->getMessage(); }
?>
