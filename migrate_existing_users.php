<?php
/**
 * Migrate Existing Users to Multi-Tenant System
 * Converts existing admin users to tenants with their own subdomains
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db_master.php';
require_once 'includes/tenant.php';

echo "===================================\n";
echo "Existing User Migration to Tenants\n";
echo "===================================\n\n";

try {
    $tenantManager = TenantManager::getInstance($pdo);
    
    // Get all existing admin users without tenants
    $stmt = $pdo->query("
        SELECT id, username, email 
        FROM users 
        WHERE role = 'admin' AND (tenant_id IS NULL OR tenant_id = 0)
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "No users found to migrate.\n";
        exit(0);
    }
    
    echo "Found " . count($users) . " user(s) to migrate.\n\n";
    
    foreach ($users as $user) {
        echo "Migrating user: {$user['username']} (ID: {$user['id']})\n";
        
        // Generate subdomain from username
        $subdomain = TenantManager::sanitizeSubdomain($user['username']);
        
        // Check if subdomain available
        $counter = 1;
        $originalSubdomain = $subdomain;
        while (!$tenantManager->isSubdomainAvailable($subdomain)) {
            $subdomain = $originalSubdomain . $counter;
            $counter++;
        }
        
        if ($subdomain !== $originalSubdomain) {
            echo "  Original subdomain taken, using: $subdomain\n";
        }
        
        // Create tenant
        $companyName = ucfirst($user['username']) . " Network";
        $tenantId = $tenantManager->createTenant($subdomain, $companyName, $user['id']);
        
        if ($tenantId) {
            // Update user's tenant_id
            $updateStmt = $pdo->prepare("UPDATE users SET tenant_id = ? WHERE id = ?");
            $updateStmt->execute([$tenantId, $user['id']]);
            
            echo "  ✓ Created tenant: $subdomain (ID: $tenantId)\n";
            echo "  ✓ Access URL: https://$subdomain.fortunetttech.site\n";
            
            // Migrate user's existing clients to this tenant
            $clientUpdateStmt = $pdo->prepare("
                UPDATE clients SET tenant_id = ? WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM clients WHERE tenant_id IS NULL LIMIT 100
                    ) as temp
                )
            ");
            $clientUpdateStmt->execute([$tenantId]);
            $clientCount = $clientUpdateStmt->rowCount();
            
            if ($clientCount > 0) {
                echo "  ✓ Migrated $clientCount client(s) to this tenant\n";
                
                // Generate account numbers for migrated clients
                require_once 'includes/account_number_generator.php';
                $accountGenerator = new AccountNumberGenerator($pdo);
                $generated = $accountGenerator->backfillAccountNumbers($tenantId);
                echo "  ✓ Generated $generated account number(s)\n";
            }
            
            // Migrate user's routers
            $routerUpdateStmt = $pdo->prepare("
                UPDATE mikrotik_routers SET tenant_id = ? WHERE tenant_id IS NULL LIMIT 10
            ");
            $routerUpdateStmt->execute([$tenantId]);
            $routerCount = $routerUpdateStmt->rowCount();
            
            if ($routerCount > 0) {
                echo "  ✓ Migrated $routerCount router(s) to this tenant\n";
            }
            
            echo "\n";
        } else {
            echo "  ✗ Failed to create tenant\n\n";
        }
    }
    
    echo "===================================\n";
    echo "Migration Complete!\n";
    echo "===================================\n\n";
    
    // Show summary
    $tenantCountStmt = $pdo->query("SELECT COUNT(*) as count FROM tenants");
    $tenantCount = $tenantCountStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Total tenants: $tenantCount\n\n";
    
    echo "Tenant List:\n";
    echo "------------\n";
    
    $tenantsStmt = $pdo->query("
        SELECT t.subdomain, t.company_name, u.username, u.email
        FROM tenants t
        LEFT JOIN users u ON t.admin_user_id = u.id
        ORDER BY t.created_at DESC
    ");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tenants as $tenant) {
        echo "• {$tenant['subdomain']}.fortunetttech.site\n";
        echo "  Company: {$tenant['company_name']}\n";
        echo "  Admin: {$tenant['username']} ({$tenant['email']})\n\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
