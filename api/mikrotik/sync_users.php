<?php
/**
 * API Endpoint: Sync Users with MikroTik Router
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

// Get router credentials
$stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'No active router found']);
    exit;
}

try {
    $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
    
    if (!$api->connect()) {
        throw new Exception("Could not connect to router");
    }
    
    // Get all clients from DB that should be on router
    $stmt = $pdo->query("SELECT * FROM clients WHERE status = 1 AND mikrotik_username != ''");
    $dbClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all users from router
    $routerUsers = $api->getPPPoEUsers();
    
    $stats = [
        'added' => 0,
        'updated' => 0,
        'failed' => 0
    ];
    
    foreach ($dbClients as $client) {
        $found = false;
        foreach ($routerUsers as $rUser) {
            if ($rUser['name'] == $client['mikrotik_username']) {
                $found = true;
                // Check if update needed (password or profile)
                // For simplicity, we just sync password for now if it doesn't match? 
                // RouterOS API doesn't return password usually for security, so we can't compare.
                // We'll skip update if found, unless force sync is requested.
                // Or maybe update profile if package changed?
                
                // Retrieve package profile name
                $pkgStmt = $pdo->prepare("SELECT mikrotik_profile FROM packages WHERE id = ?");
                $pkgStmt->execute([$client['package_id']]);
                $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
                $profile = $pkg['mikrotik_profile'] ?? 'default';
                
                if ($rUser['profile'] != $profile) {
                   try {
                       $api->updatePPPoEUser($client['mikrotik_username'], null, $profile);
                       $stats['updated']++;
                   } catch (Exception $e) {
                       $stats['failed']++;
                   }
                }
                break;
            }
        }
        
        if (!$found) {
            // Create user on router
             $pkgStmt = $pdo->prepare("SELECT mikrotik_profile FROM packages WHERE id = ?");
             $pkgStmt->execute([$client['package_id']]);
             $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
             $profile = $pkg['mikrotik_profile'] ?? 'default';
             
             try {
                 $api->addPPPoEUser($client['mikrotik_username'], $client['mikrotik_password'], $profile);
                 $stats['added']++;
             } catch (Exception $e) {
                 $stats['failed']++;
             }
        }
    }
    
    $api->disconnect();
    
    echo json_encode([
        'success' => true,
        'message' => "Sync complete. Added: {$stats['added']}, Updated: {$stats['updated']}, Failed: {$stats['failed']}",
        'stats' => $stats
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
