<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_connect.php';

echo "Updating users table...\n";

try {
    // Add is_verified
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0");
        echo "Added is_verified column.\n";
    } catch (PDOException $e) { /* Ignore if exists */ }

    // Add verification_token
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL");
        echo "Added verification_token column.\n";
    } catch (PDOException $e) { /* Ignore if exists */ }

    // Add reset_token
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL");
        echo "Added reset_token column.\n";
    } catch (PDOException $e) { /* Ignore if exists */ }

    // Add reset_token_expiry
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expiry DATETIME DEFAULT NULL");
        echo "Added reset_token_expiry column.\n";
    } catch (PDOException $e) { /* Ignore if exists */ }

    echo "Database update completed.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
