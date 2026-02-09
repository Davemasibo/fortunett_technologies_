<?php
/**
 * Test Payment Gateway Creation
 * Creates a sample payment gateway to verify the system works
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db_master.php';
require_once 'includes/payment_gateway.php';

echo "===================================\n";
echo "Payment Gateway Test\n";
echo "===================================\n\n";

try {
    // Get the first user/tenant
    $stmt = $pdo->query("SELECT id, username, tenant_id FROM users ORDER BY id DESC LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("No user found. Please signup first.\n");
    }
    
    $tenantId = $user['tenant_id'];
    
    if (!$tenantId) {
        echo "Creating tenant for user {$user['username']}...\n";
        
        require_once 'includes/tenant.php';
        $tenantManager = TenantManager::getInstance($pdo);
        
        $subdomain = strtolower($user['username']);
        $companyName = ucfirst($user['username']) . " Network";
        
        $tenantId = $tenantManager->createTenant($subdomain, $companyName, $user['id']);
        
        if ($tenantId) {
            $pdo->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$tenantId, $user['id']]);
            echo "✓ Tenant created: $subdomain (ID: $tenantId)\n\n";
        } else {
            die("Failed to create tenant.\n");
        }
    }
    
    echo "Creating payment gateway for tenant ID: $tenantId\n";
    
    $paymentGateway = new PaymentGatewayManager($pdo);
    
    // Test 1: Paybill without API
    $credentials1 = [
        'paybill_number' => '123456',
        'account_number' => 'MYACCOUNT',
        'currency' => 'KES'
    ];
    
    $gatewayId1 = $paymentGateway->saveGateway(
        $tenantId,
        'paybill_no_api',
        'Test Paybill Gateway',
        $credentials1,
        true
    );
    
    if ($gatewayId1) {
        echo "✓ Created Paybill gateway (ID: $gatewayId1)\n";
    }
    
    // Test 2: Bank Account
    $credentials2 = [
        'bank_name' => 'Test Bank',
        'account_number' => '1234567890',
        'account_name' => 'Test Company Ltd',
        'branch' => 'Main Branch',
        'swift_code' => 'TESTKE22'
    ];
    
    $gatewayId2 = $paymentGateway->saveGateway(
        $tenantId,
        'bank_account',
        'Test Bank Account',
        $credentials2,
        false
    );
    
    if ($gatewayId2) {
        echo "✓ Created Bank Account gateway (ID: $gatewayId2)\n";
    }
    
    echo "\n";
    echo "Retrieving payment gateways...\n";
    $gateways = $paymentGateway->getActiveGateways($tenantId, false);
    
    echo "Found " . count($gateways) . " gateway(s):\n";
    foreach ($gateways as $gateway) {
        echo "  - {$gateway['gateway_name']} ({$gateway['gateway_type']})" . 
             ($gateway['is_default'] ? ' [DEFAULT]' : '') . "\n";
    }
    
    echo "\n✓ Payment gateway system is working!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
