<?php
/**
 * Create Tenant for "ecco" User
 */

require_once 'includes/db_master.php';
require_once 'includes/tenant.php';

echo "\n";
echo "Creating Tenant for 'ecco' User\n";
echo "================================\n\n";

try {
    // Get ecco user
    $stmt = $pdo->query("SELECT * FROM users WHERE username = 'ecco'");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("User 'ecco' not found!\n");
    }
    
    if ($user['tenant_id']) {
        echo "✓ User already has a tenant (ID: {$user['tenant_id']})\n";
        
        // Show tenant details
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$user['tenant_id']]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tenant) {
            echo "\nTenant Details:\n";
            echo "  Subdomain: {$tenant['subdomain']}\n";
            echo "  Company: {$tenant['company_name']}\n";
            echo "  URL: https://{$tenant['subdomain']}.fortunetttech.site\n";
        }
        exit(0);
    }
    
    echo "Creating tenant for user: {$user['username']}\n";
    
    $tenantManager = TenantManager::getInstance($pdo);
    
    $subdomain = 'ecco';
    $companyName = 'Ecco Network Solutions';
    
    // Check if subdomain available
    if (!$tenantManager->isSubdomainAvailable($subdomain)) {
        $subdomain = 'ecco1';
        echo "  'ecco' taken, using 'ecco1'\n";
    }
    
    $tenantId = $tenantManager->createTenant($subdomain, $companyName, $user['id']);
    
    if ($tenantId) {
        // Update user's tenant_id and account_prefix
        $pdo->prepare("UPDATE users SET tenant_id = ?, account_prefix = 'e' WHERE id = ?")
            ->execute([$tenantId, $user['id']]);
        
        echo "✓ Tenant created successfully!\n\n";
        echo "Tenant Details:\n";
        echo "  ID: $tenantId\n";
        echo "  Subdomain: $subdomain\n";
        echo "  Company: $companyName\n";
        echo "  Production URL: https://$subdomain.fortunetttech.site\n";
        echo "  Account Prefix: e (customers will get: e001, e002, e003...)\n\n";
        
        // Get provisioning token
        $stmt = $pdo->prepare("SELECT provisioning_token FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tenant['provisioning_token']) {
            echo "Provisioning Token (for MikroTik auto-registration):\n";
            echo "  " . $tenant['provisioning_token'] . "\n\n";
        }
        
        echo "✓ You can now login and test account number generation!\n";
        
    } else {
        echo "✗ Failed to create tenant\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
