<?php
/**
 * POST /api/v1/packages/update.php
 * Body (JSON): id, name, price, connection_type, download_speed, upload_speed,
 *              validity_value, validity_unit, description, status, ...
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$id = (int)($body['id'] ?? 0);
if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

// Verify ownership
$existing = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
$existing->execute([$id, $tenantId]);
$pkg = $existing->fetch(PDO::FETCH_ASSOC);
if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Package not found']); exit; }

$name           = trim($body['name'] ?? $pkg['name']);
$price          = isset($body['price'])          ? (float)$body['price']          : (float)$pkg['price'];
$connType       = isset($body['connection_type']) && in_array($body['connection_type'], ['pppoe','hotspot'])
                    ? $body['connection_type'] : ($pkg['connection_type'] ?? $pkg['type'] ?? 'pppoe');
$downloadSpeed  = isset($body['download_speed'])  ? (int)$body['download_speed']  : (int)$pkg['download_speed'];
$uploadSpeed    = isset($body['upload_speed'])     ? (int)$body['upload_speed']    : (int)$pkg['upload_speed'];
$validityValue  = isset($body['validity_value'])  ? (int)$body['validity_value']  : (int)($pkg['validity_value'] ?? 30);
$validityUnit   = isset($body['validity_unit'])   ? $body['validity_unit']         : ($pkg['validity_unit'] ?? 'days');
$description    = isset($body['description'])     ? trim($body['description'])     : ($pkg['description'] ?? '');
$status         = isset($body['status']) && in_array($body['status'], ['active','inactive']) ? $body['status'] : $pkg['status'];
$mikrotikProfile = trim($body['mikrotik_profile'] ?? $pkg['mikrotik_profile'] ?? '');
$deviceLimit    = isset($body['device_limit'])    ? (int)$body['device_limit']    : (int)($pkg['device_limit'] ?? 1);
$hotspotServer  = trim($body['hotspot_server']    ?? $pkg['hotspot_server'] ?? '');
$rateLimit      = trim($body['rate_limit']        ?? $pkg['rate_limit'] ?? '') ?: ($uploadSpeed . 'M/' . $downloadSpeed . 'M');
$dataLimit      = isset($body['data_limit'])      ? (int)$body['data_limit']      : (int)($pkg['data_limit'] ?? 0);

try {
    $colRows = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    $colSet  = array_flip($colRows);

    $set  = ['name=?','price=?','description=?','download_speed=?','upload_speed=?','data_limit=?','type=?','status=?'];
    $vals = [$name, $price, $description, $downloadSpeed, $uploadSpeed, $dataLimit, $connType, $status];

    if (isset($colSet['rate_limit']))       { $set[] = 'rate_limit=?';       $vals[] = $rateLimit; }
    if (isset($colSet['connection_type']))  { $set[] = 'connection_type=?';  $vals[] = $connType; }
    if (isset($colSet['mikrotik_profile'])) { $set[] = 'mikrotik_profile=?'; $vals[] = $mikrotikProfile; }
    if (isset($colSet['validity_value']))   { $set[] = 'validity_value=?';   $vals[] = $validityValue; }
    if (isset($colSet['validity_unit']))    { $set[] = 'validity_unit=?';    $vals[] = $validityUnit; }
    if (isset($colSet['device_limit']))     { $set[] = 'device_limit=?';     $vals[] = $deviceLimit; }
    if (isset($colSet['hotspot_server']))   { $set[] = 'hotspot_server=?';   $vals[] = $hotspotServer ?: null; }

    $vals[] = $id;
    $vals[] = $tenantId;
    $pdo->prepare("UPDATE packages SET " . implode(',', $set) . " WHERE id=? AND tenant_id=?")->execute($vals);

    echo json_encode(['success' => true, 'message' => 'Package updated']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
