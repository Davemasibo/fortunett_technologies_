<?php
/**
 * API Endpoint: Update Package
 */
ob_start();
ini_set('display_errors', 0);
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
require_once '../../includes/package_profile.php';
require_once __DIR__ . '/../../includes/dashboard_sync.php';
require_once __DIR__ . '/../../includes/validity.php';

// Validate Inputs
$id = (int)($_POST['id'] ?? 0);
$name = $_POST['name'] ?? '';
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : 0;
$download_speed = isset($_POST['download_speed']) && $_POST['download_speed'] !== '' ? (int)$_POST['download_speed'] : 0; 
$upload_speed = isset($_POST['upload_speed']) && $_POST['upload_speed'] !== '' ? (int)$_POST['upload_speed'] : 0;
$data_limit = isset($_POST['data_limit']) && $_POST['data_limit'] !== '' ? (int)$_POST['data_limit'] : 0;
$description = $_POST['description'] ?? '';
// See api/packages/create.php: the form always submits this field, so `??` never
// fired and the empty string was written straight to the column. Blank means
// "derive it", not "no profile".
$mikrotik_profile = trim($_POST['mikrotik_profile'] ?? '');
$rate_limit = packageRateLimit(['download_speed' => $download_speed, 'upload_speed' => $upload_speed]);
$connection_type = $_POST['connection_type'] ?? 'pppoe';
$hotspot_server = trim($_POST['hotspot_server'] ?? '');
try {
    if (!in_array($connection_type, ['hotspot', 'pppoe'], true)) throw new InvalidArgumentException('Invalid connection type');
    $profileTerms = [
        'download_speed' => $download_speed, 'upload_speed' => $upload_speed,
        'validity_value' => $_POST['validity_value'] ?? 30,
        'validity_unit' => packageValidityUnit($_POST['validity_unit'] ?? 'days', true),
        'device_limit' => $_POST['device_limit'] ?? 1,
    ];
    packageProfileSettings($profileTerms, $connection_type);
    if ($price < 0) throw new InvalidArgumentException('Package price cannot be negative');
} catch (InvalidArgumentException $e) {
    ob_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
}
$speed_display = $download_speed . "Mbps / " . $upload_speed . "Mbps";

if (empty($id) || empty($name)) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'ID and Name are required']);
    exit;
}

// Security: Check Tenant
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$user_id]);
$tenant_id = $t_stmt->fetchColumn();

// Check if package belongs to tenant
$check = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
$check->execute([$id, $tenant_id]);
if (!($oldPackage = $check->fetch(PDO::FETCH_ASSOC))) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Package not found or access denied']);
    exit;
}

$mikrotik_profile = packageProfileName([
    'id'               => $id,
    'name'             => $name,
    'mikrotik_profile' => $mikrotik_profile,
]);

try {
    dashboardSyncSchema($pdo);
    $pdo->beginTransaction();
    $profileOwner = $pdo->prepare('SELECT id FROM packages WHERE tenant_id = ? AND LOWER(mikrotik_profile) = LOWER(?) AND id <> ? LIMIT 1');
    $profileOwner->execute([$tenant_id, $mikrotik_profile, $id]);
    if ($profileOwner->fetchColumn()) throw new RuntimeException('This router profile belongs to another package. Choose a unique name or leave it blank.');


    if ($connection_type !== ($oldPackage['connection_type'] ?: $oldPackage['type'])) {
        $assigned = $pdo->prepare('SELECT id FROM clients WHERE tenant_id=? AND package_id=? LIMIT 1');
        $assigned->execute([$tenant_id, $id]);
        if ($assigned->fetchColumn()) throw new RuntimeException('This package has customers. Create a separate package to change its connection type.');
    }
    // Detect available columns so we don't fail on old DB schemas
    $colRows  = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    $colCache = array_flip($colRows);

    // Build SET clause dynamically
    $setCols = ['name=?','price=?','description=?','download_speed=?','upload_speed=?','data_limit=?','type=?'];
    $setVals = [
        $name, $price, $description,
        $download_speed, $upload_speed, $data_limit,
        $connection_type,
    ];

    if (isset($colCache['rate_limit']))        { $setCols[] = 'rate_limit=?';        $setVals[] = $rate_limit; }
    if (isset($colCache['connection_type']))   { $setCols[] = 'connection_type=?';   $setVals[] = $connection_type; }
    if (isset($colCache['mikrotik_profile']))  { $setCols[] = 'mikrotik_profile=?';  $setVals[] = $mikrotik_profile; }
    if (isset($colCache['validity_value']))    { $setCols[] = 'validity_value=?';    $setVals[] = (int)$profileTerms['validity_value']; }
    if (isset($colCache['validity_unit']))     { $setCols[] = 'validity_unit=?';     $setVals[] = $profileTerms['validity_unit']; }
    if (isset($colCache['device_limit']))      { $setCols[] = 'device_limit=?';      $setVals[] = (int)$profileTerms['device_limit']; }
    if (isset($colCache['hotspot_server']))   { $setCols[] = 'hotspot_server=?';   $setVals[] = $hotspot_server ?: null; }

    $setVals[] = $id;
    $stmt = $pdo->prepare("UPDATE packages SET " . implode(',', $setCols) . " WHERE id = ?");
    $stmt->execute($setVals);

    $networkChanged = false;
    $networkTerms = array_merge($profileTerms, ['mikrotik_profile' => $mikrotik_profile, 'connection_type' => $connection_type, 'hotspot_server' => $hotspot_server]);
    foreach ($networkTerms as $field => $value) {
        if ((string)($oldPackage[$field] ?? '') !== (string)$value) $networkChanged = true;
    }
    if ($networkChanged) dashboardQueuePackage($pdo, (int)$tenant_id, $id);
    $pdo->commit();
    ob_clean();
    echo json_encode(['success' => true, 'sync_pending' => $networkChanged, 'message' => $networkChanged ? 'Package saved. Applying settings to routers and connected customers.' : 'Package saved.']);

} catch (Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $re) {}
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
