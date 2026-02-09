<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/tenant.php';

// Get parameters
$token = $_GET['token'] ?? '';
$identity = $_GET['identity'] ?? '';
$format = $_GET['format'] ?? 'json'; // json or rsc
$ip = $_SERVER['REMOTE_ADDR'];

// Validate Token
if (!$token) {
    if ($format === 'rsc') {
        echo ":log error \"Provisioning Token required\";";
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Provisioning Token required']);
    exit;
}

try {
    // Validate Token and Get Tenant
    $tenantManager = TenantManager::getInstance($pdo);
    $tenantId = $tenantManager->validateProvisioningToken($token);

    if (!$tenantId) {
        if ($format === 'rsc') {
            echo ":log error \"Invalid Provisioning Token\";";
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => 'Invalid Token']);
        exit;
    }

    if ($format === 'rsc') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="provision.rsc"');
        
        // Dynamic URL construction
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $serverUrl = $protocol . $_SERVER['HTTP_HOST'] . '/fortunett_technologies_/api/routers/auto_register.php';
        
        // Generate a secure password for the admin user
        $adminPassword = bin2hex(random_bytes(8));
        
        echo "# Fortunett Technologies Provisioning Script\n";
        echo "# Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "# Tenant ID: $tenantId\n\n";
        
        echo ":log info \"Starting Provisioning for $identity\";\n";
        echo "/system identity set name=\"$identity\";\n";
        
        // Create User
        echo "/user remove [find name=\"fortunett_admin\"];\n";
        echo "/user add name=\"fortunett_admin\" group=full password=\"$adminPassword\" comment=\"Managed by Fortunett\";\n";
        
        // Enable API
        echo "/ip service set api disabled=no port=8728;\n";
        
        // Add Scheduler for Heartbeat/Auto-Register (every 5 minutes)
        // This scheduler posts data to auto_register.php with the TOKEN
        // Note: Using a variable for the command to handle escaping better
        echo ":local cmd \"/tool fetch url=\\\"$serverUrl\\\" http-method=post http-data=\\\"provisioning_token=$token&router_ip=192.168.88.1&router_mac=\\$[/interface ethernet get ether1 mac-address]&router_identity=\\$[/system identity get name]&router_username=fortunett_admin&router_password=$adminPassword\\\" keep-result=no\";\n";
        echo "/system scheduler remove [find name=\"fortunett_heartbeat\"];\n";
        echo "/system scheduler add name=\"fortunett_heartbeat\" interval=5m on-event=\$cmd start-time=startup;\n";
        
        // Run Heartbeat Immediately to register
        echo ":delay 2s;\n";
        echo "/tool fetch url=\"$serverUrl\" http-method=post http-data=\"provisioning_token=$token&router_ip=192.168.88.1&router_mac=$[/interface ethernet get ether1 mac-address]&router_identity=$[/system identity get name]&router_username=fortunett_admin&router_password=$adminPassword\" keep-result=no;\n";
        
        echo ":log info \"Provisioning Complete\";\n";
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'Token Valid. Use format=rsc to get script.']);

} catch (Exception $e) {
    if ($format === 'rsc') {
        echo ":log error \"Provisioning Failed: " . addslashes($e->getMessage()) . "\";";
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
