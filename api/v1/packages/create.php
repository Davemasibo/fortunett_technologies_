<?php
/**
 * POST /api/v1/packages/create.php
 * Body (JSON): name, price, connection_type, download_speed, upload_speed,
 *              validity_value, validity_unit, description, mikrotik_profile,
 *              device_limit, hotspot_server
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../classes/MikrotikAPI.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant context required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$name           = trim($body['name'] ?? '');
$price          = (float)($body['price'] ?? 0);
$connType       = in_array($body['connection_type'] ?? '', ['pppoe', 'hotspot']) ? $body['connection_type'] : 'pppoe';
$downloadSpeed  = (int)($body['download_speed'] ?? 0);
$uploadSpeed    = (int)($body['upload_speed'] ?? 0);
$validityValue  = (int)($body['validity_value'] ?? 30);
$validityUnit   = in_array($body['validity_unit'] ?? '', ['days', 'months', 'hours']) ? $body['validity_unit'] : 'days';
$description    = trim($body['description'] ?? '');
$mikrotikProfile = trim($body['mikrotik_profile'] ?? '') ?: preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($name));
$dataLimit      = (int)($body['data_limit'] ?? 0);
$deviceLimit    = (int)($body['device_limit'] ?? 1);
$hotspotServer  = trim($body['hotspot_server'] ?? '');
$rateLimit      = trim($body['rate_limit'] ?? '') ?: ($uploadSpeed . 'M/' . $downloadSpeed . 'M');

if ($name === '' || $price <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'name and price are required']);
    exit;
}

// Duplicate check
$dup = $pdo->prepare("SELECT id FROM packages WHERE tenant_id = ? AND name = ? AND COALESCE(NULLIF(connection_type,''),NULLIF(type,''),'pppoe') = ? LIMIT 1");
$dup->execute([$tenantId, $name, $connType]);
if ($dup->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => "A $connType package named \"$name\" already exists"]);
    exit;
}

try {
    $colRows = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    $colSet  = array_flip($colRows);

    $cols = ['tenant_id','name','price','description','download_speed','upload_speed','data_limit','type','status'];
    $vals = [$tenantId, $name, $price, $description, $downloadSpeed, $uploadSpeed, $dataLimit, $connType, 'active'];

    if (isset($colSet['rate_limit']))       { $cols[] = 'rate_limit';       $vals[] = $rateLimit; }
    if (isset($colSet['connection_type']))  { $cols[] = 'connection_type';  $vals[] = $connType; }
    if (isset($colSet['mikrotik_profile'])) { $cols[] = 'mikrotik_profile'; $vals[] = $mikrotikProfile; }
    if (isset($colSet['validity_value']))   { $cols[] = 'validity_value';   $vals[] = $validityValue; }
    if (isset($colSet['validity_unit']))    { $cols[] = 'validity_unit';    $vals[] = $validityUnit; }
    if (isset($colSet['device_limit']))     { $cols[] = 'device_limit';     $vals[] = $deviceLimit; }
    if (isset($colSet['hotspot_server']))   { $cols[] = 'hotspot_server';   $vals[] = $hotspotServer ?: null; }

    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare("INSERT INTO packages (" . implode(',', $cols) . ") VALUES ($ph)");
    $stmt->execute($vals);
    $packageId = (int)$pdo->lastInsertId();

    // Sync profile to active routers (best-effort)
    $routers = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
    $routers->execute([$tenantId]);
    foreach ($routers->fetchAll(PDO::FETCH_ASSOC) as $router) {
        try {
            $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                if ($connType === 'hotspot') {
                    $api->createHotspotProfile($mikrotikProfile, $rateLimit);
                } else {
                    $api->createPPPoEProfile($mikrotikProfile, null, null, $rateLimit);
                }
                $api->disconnect();
            }
        } catch (Throwable $e) {
            error_log("Router sync failed for router {$router['id']}: " . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'id' => $packageId, 'message' => 'Package created']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
