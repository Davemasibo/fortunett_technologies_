<?php
/**
 * Check Account Status and Login Info
 */

require_once 'includes/db_master.php';

echo "\n";
echo "========================================\n";
echo "  Account Status Check\n";
echo "========================================\n\n";

// Check latest user
$stmt = $pdo->query("
    SELECT u.*, t.subdomain, t.company_name, t.provisioning_token
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    ORDER BY u.id DESC 
    LIMIT 1
");

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ No users found in database.\n";
    exit(1);
}

echo "📋 YOUR ACCOUNT DETAILS:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Username      : " . $user['username'] . "\n";
echo "Email         : " . $user['email'] . "\n";
echo "Role          : " . $user['role'] . "\n";
echo "Verified      : " . ($user['email_verified'] ? '✓ YES' : '✗ NO') . "\n";
echo "Tenant ID     : " . ($user['tenant_id'] ?: 'Not created yet') . "\n";

if ($user['subdomain']) {
    echo "\n🌐 TENANT INFORMATION:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Subdomain     : " . $user['subdomain'] . "\n";
    echo "Company       : " . $user['company_name'] . "\n";
    echo "Production URL: https://{$user['subdomain']}.fortunetttech.site\n";
    echo "Localhost URL : http://localhost/fortunett_technologies_/\n";
    
    if ($user['provisioning_token']) {
        echo "\n🔑 MIKROTIK PROVISIONING:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Token: " . substr($user['provisioning_token'], 0, 20) . "...\n";
        echo "(Full token stored in database for MikroTik auto-registration)\n";
    }
}

echo "\n";

if (!$user['email_verified']) {
    echo "⚠️  ACCOUNT NOT VERIFIED\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Run this command to verify:\n";
    echo "  php verify_user.php {$user['id']}\n\n";
} else {
    echo "✅ ACCOUNT VERIFIED - READY TO LOGIN!\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "\n🚀 NEXT STEPS:\n";
    echo "1. Login at: http://localhost/fortunett_technologies_/login.php\n";
    echo "2. Test account number generation (add a client)\n";
    echo "3. Run deployment to production when ready\n\n";
}

// Check database schema
echo "📊 DATABASE SCHEMA STATUS:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$tables = ['tenants', 'payment_gateways', 'router_services', 'tenant_settings'];
foreach ($tables as $table) {
    $result = $pdo->query("SHOW TABLES LIKE '$table'");
    echo ($result->rowCount() > 0 ? '✓' : '✗') . " $table\n";
}

// Check columns
$columns = [
    'users' => ['tenant_id', 'email_verified', 'account_prefix'],
    'clients' => ['tenant_id', 'account_number', 'full_name']
];

echo "\n";
foreach ($columns as $table => $cols) {
    foreach ($cols as $col) {
        $result = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'");
        echo ($result->rowCount() > 0 ? '✓' : '✗') . " $table.$col\n";
    }
}

echo "\n";
echo "========================================\n\n";
