<?php
/**
 * Router Status Checker
 * Background script to ping MikroTik routers and update their online/offline status
 */

require_once __DIR__ . '/../includes/db_master.php';

// Optional: Uncomment if RouterOS API library is installed via Composer
// require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

// Get all routers from database
$stmt = $pdo->query("SELECT * FROM mikrotik_routers");
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($routers as $router) {
    // Use 'active'/'inactive' — consistent with test_connection.php and all API queries
    $status   = 'inactive';
    $lastSeen = null;

    try {
        // Simple TCP reachability check on the RouterOS API port
        $fp = @fsockopen($router['ip_address'], $router['api_port'] ?? 8728, $errno, $errstr, 2);

        if ($fp) {
            $status   = 'active';
            $lastSeen = date('Y-m-d H:i:s');
            fclose($fp);
        }
    } catch (Exception $e) {
        error_log("Router check error for {$router['name']}: " . $e->getMessage());
    }

    // Update router status — skip routers that are 'pending' (never been test-connected)
    // so they stay in pending until an admin explicitly tests them
    if ($router['status'] !== 'pending') {
        $updateStmt = $pdo->prepare("UPDATE mikrotik_routers SET status = ?, last_seen = ? WHERE id = ?");
        $updateStmt->execute([$status, $lastSeen, $router['id']]);
    }

    echo "Router {$router['name']} ({$router['ip_address']}): {$status}\n";
}

echo "Router status check completed at " . date('Y-m-d H:i:s') . "\n";
