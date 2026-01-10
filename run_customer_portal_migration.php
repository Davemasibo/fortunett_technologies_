<?php
/**
 * Run Customer Portal Schema Migration
 */
require_once __DIR__ . '/includes/config.php';

try {
    echo "Starting customer portal schema migration...\n\n";
    
    $sql = file_get_contents(__DIR__ . '/sql/migrations/2026-01-03-customer-portal-schema.sql');
    
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            echo "✓ Executed statement\n";
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                echo "✗ Error: " . $e->getMessage() . "\n";
            } else {
                echo "⊙ Skipped (already exists)\n";
            }
        }
    }
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
