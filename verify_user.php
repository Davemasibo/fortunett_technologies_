<?php
/**
 * Simple User Verification by ID
 * Usage: php verify_user.php <user_id>
 */

require_once 'includes/db_master.php';

$userId = $argv[1] ?? null;

if (!$userId) {
    // If no ID provided, get the latest user
    $stmt = $pdo->query("SELECT id FROM users ORDER BY id DESC LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userId = $user['id'] ?? null;
}

if (!$userId) {
    die("No user found to verify.\n");
}

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET email_verified = 1, verification_token = NULL 
        WHERE id = ?
    ");
    
    if ($stmt->execute([$userId])) {
        echo "✓ User ID $userId verified successfully!\n";
        echo "You can now login.\n";
    } else {
        echo "✗ Failed to verify user.\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
