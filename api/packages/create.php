<?php
/**
 * API Endpoint: Create Package
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Prevent HTML error output matching Invalid JSON
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$name = $_POST['name'] ?? '';
$price = $_POST['price'] ?? 0;
// Speed text is display only, but we should parse or accept raw int
$download_speed = $_POST['download_speed'] ?? 0; 
$upload_speed = $_POST['upload_speed'] ?? 0;
$data_limit = $_POST['data_limit'] ?? 0; // Bytes

$speed_display = $download_speed . "Mbps / " . $upload_speed . "Mbps"; // Construct display string
$description = $_POST['description'] ?? '';
$mikrotik_profile = $_POST['mikrotik_profile'] ?? preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($name));
$rate_limit = $_POST['rate_limit'] ?? ($upload_speed . 'M/' . $download_speed . 'M'); 
$connection_type = $_POST['connection_type'] ?? 'pppoe';

if (empty($name) || empty($price)) {
    echo json_encode(['success' => false, 'message' => 'Name and Price are required']);
    exit;
}

    // Get tenant_id
    session_start();
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $user_id = $_SESSION['user_id'];
    $t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $t_stmt->execute([$user_id]);
    $tenant_id = $t_stmt->fetchColumn();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO packages (tenant_id, name, price, description, mikrotik_profile, rate_limit, connection_type, download_speed, upload_speed, data_limit, type, validity_value, validity_unit, device_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->execute([
        $tenant_id,
        $name, $price, $description, $mikrotik_profile, $rate_limit, $connection_type,
        $download_speed, $upload_speed, $data_limit, $connection_type,
        $_POST['validity_value'] ?? 30,
        $_POST['validity_unit'] ?? 'days',
        $_POST['device_limit'] ?? 1
    ]);
    $package_id = $pdo->lastInsertId();
    
    // 2. Create Profile on Router
    $router_stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
    $router = $router_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($router) {
        try {
            $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                if ($connection_type === 'hotspot') {
                    // Hotspot Profile
                    $profiles = $api->getHotspotUserProfiles();
                    $exists = false;
                    foreach ($profiles as $p) {
                        if ($p['name'] == $mikrotik_profile) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $api->createHotspotProfile($mikrotik_profile, $rate_limit);
                    }
                } else {
                    // PPPoE Profile (Default)
                    $profiles = $api->getPPPoEProfiles();
                    $exists = false;
                    foreach ($profiles as $p) {
                        if ($p['name'] == $mikrotik_profile) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $api->createPPPoEProfile($mikrotik_profile, '10.0.0.1', 'pppoe-pool', $rate_limit);
                    }
                }
                
                $api->disconnect();
            }
        } catch (Exception $e) {
            // Log error, but don't fail DB insert?
             error_log("Router profile sync failed: " . $e->getMessage());
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Package created successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
