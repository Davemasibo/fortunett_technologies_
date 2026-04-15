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
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $basePath  = str_replace('/provision.php', '', $_SERVER['SCRIPT_NAME']);
        $serverUrl = $protocol . $_SERVER['HTTP_HOST'] . $basePath . '/auto_register.php';
        $mode      = str_starts_with($serverUrl, 'https://') ? "mode=https" : "mode=http";

        // Resolve the VPS IP so the router can allow API access from it
        $serverIp  = $_SERVER['SERVER_ADDR'] ?? @gethostbyname($_SERVER['HTTP_HOST']) ?? '';

        // Secure password for the managed admin user
        $adminPassword = bin2hex(random_bytes(8));

        $t = $token;  // short alias for embedding in heredoc strings

        echo "# Fortunett Technologies Provisioning Script\n";
        echo "# Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "# Tenant ID: $tenantId\n";
        echo "# Re-running this script is safe — it cleans up previous config first.\n\n";

        echo ":log info \"[Fortunett] Starting provisioning for $identity\";\n\n";

        // ── 1. Identity ────────────────────────────────────────────────────────
        echo "/system identity set name=\"$identity\";\n\n";

        // ── 2. Managed admin user (idempotent) ─────────────────────────────────
        echo ":do { /user remove [find name=\"fortunett_admin\"] } on-error={};\n";
        echo "/user add name=\"fortunett_admin\" group=full password=\"$adminPassword\" comment=\"Managed by Fortunett\";\n\n";

        // ── 3. Enable API service ──────────────────────────────────────────────
        echo "/ip service set api disabled=no port=8728;\n\n";

        // ── 4. Firewall: allow the Fortunett server to reach the API port ──────
        // Remove any previous rule, then insert an ACCEPT at position 0 so it
        // fires before any drop/reject rules in the input chain.
        if ($serverIp) {
            echo "# Allow Fortunett server API access\n";
            echo ":do { /ip firewall filter remove [find comment=\"Fortunett-API\"] } on-error={};\n";
            echo "/ip firewall filter add chain=input protocol=tcp dst-port=8728 src-address=$serverIp action=accept comment=\"Fortunett-API\" place-before=0;\n\n";
        }

        // ── 5. Heartbeat scheduler (idempotent) ────────────────────────────────
        echo ":local cmd \"/tool fetch $mode url=\\\"$serverUrl\\\" http-method=post http-data=\\\"provisioning_token=$t&router_ip=192.168.88.1&router_mac=\\$[/interface ethernet get ether1 mac-address]&router_identity=\\$[/system identity get name]&router_username=fortunett_admin&router_password=$adminPassword\\\" keep-result=no\";\n";
        echo ":do { /system scheduler remove [find name=\"fortunett_heartbeat\"] } on-error={};\n";
        echo "/system scheduler add name=\"fortunett_heartbeat\" interval=5m on-event=\$cmd start-time=startup;\n\n";

        // ── 6. Register immediately ────────────────────────────────────────────
        echo ":delay 2s;\n";
        echo "/tool fetch $mode url=\"$serverUrl\" http-method=post http-data=\"provisioning_token=$t&router_ip=192.168.88.1&router_mac=\$[/interface ethernet get ether1 mac-address]&router_identity=\$[/system identity get name]&router_username=fortunett_admin&router_password=$adminPassword\" keep-result=no;\n\n";

        echo ":log info \"[Fortunett] Provisioning complete\";\n";
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
