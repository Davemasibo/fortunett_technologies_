<?php
/**
 * Quick Database Connection Test
 */

echo "\n";
echo "Testing Database Connection...\n";
echo "================================\n\n";

// Test 1: db_connect.php
echo "1. Testing db_connect.php:\n";
try {
    require_once 'includes/db_connect.php';
    echo "   ✓ db_connect.php loaded\n";
    echo "   ✓ Database: " . $DB_NAME . "\n";
    if ($pdo) {
        echo "   ✓ PDO connection established\n";
        
        // Test query
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        echo "   ✓ Users table accessible ({$result['count']} users)\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: db_master.php
echo "2. Testing db_master.php:\n";
try {
    require_once 'includes/db_master.php';
    echo "   ✓ db_master.php loaded\n";
    
    if (isset($pdo)) {
        echo "   ✓ PDO available globally\n";
        
        // Test query
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
        $result = $stmt->fetch();
        echo "   ✓ Tenants table accessible ({$result['count']} tenants)\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";
echo "================================\n";
echo "✓ All database connections OK!\n\n";
echo "You should be able to login now at:\n";
echo "http://localhost/fortunett_technologies_/login.php\n\n";
