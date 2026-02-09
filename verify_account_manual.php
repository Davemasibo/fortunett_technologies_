<?php
/**
 * Manual Account Verification
 * Use this to verify accounts when email is not available (localhost testing)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db_master.php';

echo "===================================\n";
echo "Manual Account Verification\n";
echo "===================================\n\n";

// Your verification token
$token = "37713720112107faccfc46d19ebe051bbc7fed333d10583530d73b0d46db3509";

try {
    // Find user with this verification token
    $stmt = $pdo->prepare("
        SELECT id, username, email, email_verified 
        FROM users 
        WHERE verification_token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("❌ No user found with this verification token.\n");
    }
    
    echo "Found user:\n";
    echo "  Username: {$user['username']}\n";
    echo "  Email: {$user['email']}\n";
    echo "  Currently verified: " . ($user['email_verified'] ? 'Yes' : 'No') . "\n\n";
    
    if ($user['email_verified']) {
        echo "✓ Account is already verified! You can login now.\n";
        exit(0);
    }
    
    // Verify the account
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET email_verified = 1,
            verification_token = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    if ($updateStmt->execute([$user['id']])) {
        echo "✓ Account verified successfully!\n\n";
        echo "You can now login with:\n";
        echo "  Username: {$user['username']}\n";
        echo "  Email: {$user['email']}\n";
        echo "  Password: [the password you chose during signup]\n\n";
        
        // Check if tenant was created
        $tenantStmt = $pdo->prepare("
            SELECT t.subdomain, t.company_name 
            FROM tenants t
            WHERE t.admin_user_id = ?
        ");
        $tenantStmt->execute([$user['id']]);
        $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tenant) {
            echo "Your tenant details:\n";
            echo "  Subdomain: {$tenant['subdomain']}\n";
            echo "  Company: {$tenant['company_name']}\n";
            echo "  URL: https://{$tenant['subdomain']}.fortunetttech.site\n";
            echo "  (Note: Subdomain will work once DNS is configured on production)\n";
        }
    } else {
        echo "❌ Failed to verify account.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}
