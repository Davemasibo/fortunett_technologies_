<?php
/**
 * API Endpoint: Create Package
 */
ob_start();
ini_set('display_errors', 0);
// Catch fatal errors (missing files, parse errors) and return valid JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    }
});
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$name = $_POST['name'] ?? '';
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : 0;
$download_speed = isset($_POST['download_speed']) && $_POST['download_speed'] !== '' ? (int)$_POST['download_speed'] : 0; 
$upload_speed = isset($_POST['upload_speed']) && $_POST['upload_speed'] !== '' ? (int)$_POST['upload_speed'] : 0;
$data_limit = isset($_POST['data_limit']) && $_POST['data_limit'] !== '' ? (int)$_POST['data_limit'] : 0; // Bytes

$speed_display = $download_speed . "Mbps / " . $upload_speed . "Mbps"; // Construct display string
$description = $_POST['description'] ?? '';
$mikrotik_profile = $_POST['mikrotik_profile'] ?? preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($name));
$rate_limit = $_POST['rate_limit'] ?? ($upload_speed . 'M/' . $download_speed . 'M'); 
$connection_type = $_POST['connection_type'] ?? 'pppoe';

if (empty($name) || empty($price)) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Name and Price are required']);
    exit;
}

    // Get tenant_id
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $user_id = $_SESSION['user_id'];
    $t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $t_stmt->execute([$user_id]);
    $tenant_id = $t_stmt->fetchColumn();

try {
    $pdo->beginTransaction();

    // Duplicate check — wrapped in try so missing columns don't crash outside the transaction
    try {
        $dupCheck = $pdo->prepare("SELECT id FROM packages WHERE tenant_id = ? AND name = ? AND connection_type = ? LIMIT 1");
        $dupCheck->execute([$tenant_id, $name, $connection_type]);
        if ($dupCheck->fetch()) {
            $pdo->rollBack();
            ob_clean(); echo json_encode(['success' => false, 'message' => "A $connection_type package named \"$name\" already exists."]);
            exit;
        }
    } catch (Throwable $dupErr) {
        // connection_type column may not exist yet — skip dup check, proceed with insert
    }

    // Build column list dynamically — skip columns that don't exist on this DB
    $cols = ['tenant_id','name','price','description','download_speed','upload_speed','data_limit','type','status'];
    $vals = [$tenant_id, $name, $price, $description, $download_speed, $upload_speed, $data_limit, $connection_type, 'active'];

    // Probe for optional columns once
    static $colCache = null;
    if ($colCache === null) {
        $colRows = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
        $colCache = array_flip($colRows);
    }
    if (isset($colCache['rate_limit']))        { $cols[] = 'rate_limit';        $vals[] = $rate_limit; }
    if (isset($colCache['connection_type']))   { $cols[] = 'connection_type';   $vals[] = $connection_type; }
    if (isset($colCache['mikrotik_profile']))  { $cols[] = 'mikrotik_profile';  $vals[] = $mikrotik_profile; }
    if (isset($colCache['validity_value']))    { $cols[] = 'validity_value';    $vals[] = isset($_POST['validity_value']) && $_POST['validity_value'] !== '' ? (int)$_POST['validity_value'] : 30; }
    if (isset($colCache['validity_unit']))     { $cols[] = 'validity_unit';     $vals[] = $_POST['validity_unit'] ?? 'days'; }
    if (isset($colCache['device_limit']))      { $cols[] = 'device_limit';      $vals[] = isset($_POST['device_limit']) && $_POST['device_limit'] !== '' ? (int)$_POST['device_limit'] : 1; }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colList      = implode(',', $cols);
    $stmt = $pdo->prepare("INSERT INTO packages ($colList) VALUES ($placeholders)");
    $stmt->execute($vals);
    $package_id = $pdo->lastInsertId();
    
    // 2. Create Profile on all active Routers for this tenant
    $router_stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
    $router_stmt->execute([$tenant_id]);
    $routers = $router_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($routers as $router) {
        try {
            $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                if ($connection_type === 'hotspot') {
                    // Hotspot Profile
                    $profiles = $api->getHotspotUserProfiles();
                    $exists = false;
                    foreach ((array)$profiles as $p) {
                        if (isset($p['name']) && $p['name'] == $mikrotik_profile) {
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
                    foreach ((array)$profiles as $p) {
                        if (isset($p['name']) && $p['name'] == $mikrotik_profile) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $api->createPPPoEProfile($mikrotik_profile, null, null, $rate_limit);
                    }
                }
                
                $api->disconnect();
            }
        } catch (Throwable $e) {
            // Log error, but don't fail DB insert
             error_log("Router profile sync failed for router ID " . $router['id'] . ": " . $e->getMessage());
        }
    }

    $pdo->commit();
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Package created successfully']);

} catch (Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $re) {}
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
