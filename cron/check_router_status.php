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
    $status = 'offline';
    $lastSeen = null;
    
    try {
        // Simple ping check using fsockopen
        $fp = @fsockopen($router['ip_address'], $router['api_port'] ?? 8728, $errno, $errstr, 2);
        
        if ($fp) {
            $status = 'online';
            $lastSeen = date('Y-m-d H:i:s');
            fclose($fp);
            
            // Optional: Try to get system resource info if RouterOS library is available
            if (class_exists('RouterOS\Client')) {
                try {
                    $config = new Config([
                        'host' => $router['ip_address'],
                        'user' => $router['username'],
                        'pass' => $router['password'],
                        'port' => (int)($router['api_port'] ?? 8728),
                    ]);
                    
                    $client = new Client($config);
                    $response = $client->query('/system/resource/print')->read();
                    
                    if ($response) {
                        $status = 'online';
                    }
                    
                    $client->disconnect();
                } catch (Exception $e) {
                    // Still mark as online if port is open, even if API fails
                    error_log("Router API error for {$router['name']}: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Router check error for {$router['name']}: " . $e->getMessage());
    }
    
    // Update router status
    $updateStmt = $pdo->prepare("UPDATE mikrotik_routers SET status = ?, last_seen = ? WHERE id = ?");
    $updateStmt->execute([$status, $lastSeen, $router['id']]);
    
    echo "Router {$router['name']} ({$router['ip_address']}): {$status}\n";
}

echo "Router status check completed at " . date('Y-m-d H:i:s') . "\n";
