<?php
/**
 * API Endpoint: Delete Customer
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Security: Get tenant_id
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$user_id]);
$tenant_id = $t_stmt->fetchColumn();

$id = $_POST['id'] ?? 0;

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'ID required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get client details for router removal (AND verify tenant ownership)
    $stmt = $pdo->prepare("SELECT mikrotik_username, connection_type FROM clients WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
         throw new Exception("Customer not found or access denied");
    }
    
    // Delete from DB (Verify tenant again ideally, but ID + Tenant check above is sufficient coverage)
    $delStmt = $pdo->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?");
    $delStmt->execute([$id, $tenant_id]);
    
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
