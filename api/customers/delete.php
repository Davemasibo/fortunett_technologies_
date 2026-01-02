<?php
/**
 * API Endpoint: Delete Customer
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

$id = $_POST['id'] ?? 0;

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'ID required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get client details for router removal
    $stmt = $pdo->prepare("SELECT mikrotik_username FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Delete from DB
    $delStmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    $delStmt->execute([$id]);
    
    // Remove from Router
    if ($client && !empty($client['mikrotik_username'])) {
        $router_stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
        $router = $router_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($router) {
            try {
                $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
                if ($api->connect()) {
                    // We need to know connection type to call right delete.
                    // Assuming client record has it.
                    $connType = $client['connection_type'] ?? 'pppoe';
                    
                    if ($connType === 'hotspot') {
                        $api->deleteHotspotUser($client['mikrotik_username']);
                    } else {
                        $api->deletePPPoEUser($client['mikrotik_username']);
                    }
                    $api->disconnect();
                }
            } catch (Exception $e) {
                // Ignore router errors on delete
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
