<?php
require_once __DIR__ . '/includes/config.php';

try {
    echo "Adding missing invoice column to payments table...\n";
    
    // Add invoice column if it doesn't exist
    $pdo->exec("ALTER TABLE payments ADD COLUMN invoice VARCHAR(100)");
    
    echo "✓ Invoice column added successfully!\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "⊙ Invoice column already exists\n";
    } else {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

try {
    echo "\nVerifying all required columns exist...\n";
    
    // Verify clients table columns
    $clientsColumns = ['package_id', 'package_price', 'account_balance', 'expiry_date', 'auth_password', 'connection_type'];
    foreach ($clientsColumns as $col) {
        try {
            $pdo->query("SELECT $col FROM clients LIMIT 1");
            echo "✓ clients.$col exists\n";
        } catch (PDOException $e) {
            echo "✗ clients.$col missing\n";
        }
    }
    
    // Verify payments table columns
    $paymentsColumns = ['invoice', 'transaction_id', 'status', 'notes'];
    foreach ($paymentsColumns as $col) {
        try {
            $pdo->query("SELECT $col FROM payments LIMIT 1");
            echo "✓ payments.$col exists\n";
        } catch (PDOException $e) {
            echo "✗ payments.$col missing\n";
        }
    }
    
    echo "\n✓ Database schema verification complete!\n";
    
} catch (Exception $e) {
    echo "Error during verification: " . $e->getMessage() . "\n";
}
