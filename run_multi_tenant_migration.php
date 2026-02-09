<?php
/**
 * Database Migration Runner for Multi-Tenant Schema
 * Run this file once to apply all multi-tenant database changes
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db_master.php';

echo "===================================\n";
echo "Multi-Tenant Schema Migration\n";
echo "===================================\n\n";

try {
    // Read the SQL migration file
    $migrationFile = __DIR__ . '/sql/migrations/multi_tenant_schema.sql';
    
    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found at: $migrationFile\n");
    }
    
    echo "Reading migration file...\n";
    $sql = file_get_contents($migrationFile);
    
    if (!$sql) {
        die("Error: Could not read migration file\n");
    }
    
    echo "Starting migration...\n\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            // Filter out empty statements and comments
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) &&
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $index => $statement) {
        try {
            // Skip if it's just whitespace or comments
            if (trim($statement) === '') continue;
            
            $pdo->exec($statement . ';');
            $successCount++;
            
            // Show progress for significant operations
            if (preg_match('/CREATE TABLE|ALTER TABLE|INSERT INTO/i', $statement)) {
                // Extract table name for display
                preg_match('/(CREATE TABLE|ALTER TABLE|INSERT INTO)\s+(\w+)/i', $statement, $matches);
                $table = $matches[2] ?? 'unknown';
                echo "✓ Executed on table: $table\n";
            }
        } catch (PDOException $e) {
            $errorCount++;
            // Only show errors for non-duplicate key constraints
            if (strpos($e->getMessage(), 'Duplicate') === false && 
                strpos($e->getMessage(), 'already exists') === false) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                echo "  Statement: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n===================================\n";
    echo "Migration Summary\n";
    echo "===================================\n";
    echo "Successful: $successCount\n";
    echo "Errors: $errorCount\n";
    echo "\n";
    
    // Verify key tables exist
    echo "Verifying schema...\n\n";
    
    $requiredTables = [
        'tenants',
        'payment_gateways',
        'router_services',
        'tenant_settings'
    ];
    
    foreach ($requiredTables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($result->rowCount() > 0) {
            echo "✓ Table '$table' exists\n";
        } else {
            echo "✗ Table '$table' is missing!\n";
        }
    }
    
    // Check if columns were added
    echo "\nVerifying new columns...\n\n";
    
    $columns = [
        'users' => 'tenant_id',
        'clients' => ['tenant_id', 'account_number'],
        'mikrotik_routers' => 'tenant_id',
        'packages' => 'tenant_id',
        'payments' => 'tenant_id'
    ];
    
    foreach ($columns as $table => $cols) {
        $colsToCheck = is_array($cols) ? $cols : [$cols];
        foreach ($colsToCheck as $col) {
            $result = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'");
            if ($result->rowCount() > 0) {
                echo "✓ Column '$table.$col' exists\n";
            } else {
                echo "✗ Column '$table.$col' is missing!\n";
            }
        }
    }
    
    echo "\n===================================\n";
    echo "Migration Complete!\n";
    echo "===================================\n\n";
    
    echo "Next Steps:\n";
    echo "1. Run migrate_existing_users.php to convert existing users to tenants\n";
    echo "2. Configure wildcard DNS for *.fortunetttech.site\n";
    echo "3. Set up SSL certificate for subdomains\n";
    echo "4. Test multi-tenant signup and login\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
