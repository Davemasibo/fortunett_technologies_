<?php
/**
 * Import Packages from CSV
 * POST multipart/form-data with file field "csv_file"
 * Columns (header row required):
 *   name, type, price, download_speed, upload_speed, validity_value, validity_unit,
 *   data_limit, device_limit, description, status
 * All fields except "name" are optional and fall back to sensible defaults.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']); exit;
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) {
    $s = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $s->execute([(int)$_SESSION['user_id']]);
    $tenantId = (int)$s->fetchColumn();
    if ($tenantId) $_SESSION['tenant_id'] = $tenantId;
}
if (!$tenantId) { echo json_encode(['success'=>false,'message'=>'Tenant not found']); exit; }

if (empty($_FILES['csv_file']['tmp_name'])) {
    echo json_encode(['success'=>false,'message'=>'No file uploaded']); exit;
}

$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
if (!$fh) { echo json_encode(['success'=>false,'message'=>'Cannot read file']); exit; }

// Read header row
$header = fgetcsv($fh);
if (!$header) { echo json_encode(['success'=>false,'message'=>'Empty file']); exit; }
$header = array_map(fn($h) => strtolower(trim($h)), $header);

$imported = 0; $updated = 0; $errors = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 1) continue;
    $data = array_combine($header, array_pad($row, count($header), ''));
    if (empty(trim($data['name'] ?? ''))) continue;

    $name          = trim($data['name']);
    $type          = strtolower(trim($data['type'] ?? 'hotspot'));
    $price         = (float)($data['price'] ?? 0);
    $dlSpeed       = (int)($data['download_speed'] ?? 10);
    $ulSpeed       = (int)($data['upload_speed'] ?? 5);
    $validVal      = (int)($data['validity_value'] ?? 30);
    $validUnit     = trim($data['validity_unit'] ?? 'days');
    $dataLimit     = (int)($data['data_limit'] ?? 0);
    $deviceLimit   = max(1, (int)($data['device_limit'] ?? 1));
    $description   = trim($data['description'] ?? '');
    $status        = in_array(strtolower($data['status'] ?? ''), ['active','inactive']) ? strtolower($data['status']) : 'active';
    $duration      = $validVal . ' ' . $validUnit;

    // Normalise type
    if (!in_array($type, ['pppoe','hotspot'])) $type = 'hotspot';
    if (!in_array($validUnit, ['days','weeks','months','hours'])) $validUnit = 'days';

    try {
        // Upsert: update if same name+tenant_id already exists
        $check = $pdo->prepare("SELECT id FROM packages WHERE tenant_id = ? AND name = ? LIMIT 1");
        $check->execute([$tenantId, $name]);
        $existing = $check->fetchColumn();

        if ($existing) {
            $pdo->prepare("
                UPDATE packages SET type=?, price=?, download_speed=?, upload_speed=?,
                    validity_value=?, validity_unit=?, data_limit=?, device_limit=?,
                    description=?, status=?, duration=?
                WHERE id=?
            ")->execute([$type,$price,$dlSpeed,$ulSpeed,$validVal,$validUnit,$dataLimit,$deviceLimit,$description,$status,$duration,$existing]);
            $updated++;
        } else {
            $pdo->prepare("
                INSERT INTO packages
                    (tenant_id,name,type,price,download_speed,upload_speed,
                     validity_value,validity_unit,data_limit,device_limit,
                     description,status,duration)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([$tenantId,$name,$type,$price,$dlSpeed,$ulSpeed,$validVal,$validUnit,$dataLimit,$deviceLimit,$description,$status,$duration]);
            $imported++;
        }
    } catch (Throwable $e) {
        $errors[] = "Row \"$name\": " . $e->getMessage();
    }
}
fclose($fh);

echo json_encode([
    'success'  => true,
    'imported' => $imported,
    'updated'  => $updated,
    'errors'   => $errors,
    'message'  => "$imported created, $updated updated" . (count($errors) ? ', ' . count($errors) . ' error(s)' : ''),
]);
