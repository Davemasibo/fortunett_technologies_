<?php
/**
 * Import Customers from CSV
 * POST multipart/form-data with file field "csv_file"
 * Required column: full_name (or name)
 * Optional columns:
 *   phone, email, address, username, password,
 *   package_name (matched by name), connection_type (pppoe/hotspot),
 *   status (active/inactive/suspended/expired), expiry_date (YYYY-MM-DD),
 *   notes
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

$header = fgetcsv($fh);
if (!$header) { echo json_encode(['success'=>false,'message'=>'Empty file']); exit; }
$header = array_map(fn($h) => strtolower(trim($h)), $header);

// Build a package name → id map for this tenant
$pkgMap = [];
$pkgRows = $pdo->prepare("SELECT id, name FROM packages WHERE tenant_id = ?");
$pkgRows->execute([$tenantId]);
foreach ($pkgRows->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $pkgMap[strtolower(trim($p['name']))] = (int)$p['id'];
}

// Get tenant subdomain for account number prefix
$tStmt = $pdo->prepare("SELECT subdomain FROM tenants WHERE id = ?");
$tStmt->execute([$tenantId]);
$subdomain = $tStmt->fetchColumn() ?: 'ISP';
$prefix    = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $subdomain), 0, 4));

$imported = 0; $updated = 0; $errors = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 1) continue;
    $data = array_combine($header, array_pad($row, count($header), ''));

    // Accept either "full_name" or "name" column
    $fullName = trim($data['full_name'] ?? ($data['name'] ?? ''));
    if ($fullName === '') continue;

    $phone          = trim($data['phone'] ?? '');
    $email          = trim($data['email'] ?? '');
    $address        = trim($data['address'] ?? '');
    $username       = trim($data['username'] ?? '');
    $password       = trim($data['password'] ?? '');
    $connType       = strtolower(trim($data['connection_type'] ?? 'hotspot'));
    $notes          = trim($data['notes'] ?? '');
    $expiryDate     = trim($data['expiry_date'] ?? '');
    $statusRaw      = strtolower(trim($data['status'] ?? 'active'));
    $status         = in_array($statusRaw, ['active','inactive','suspended','expired']) ? $statusRaw : 'active';

    if (!in_array($connType, ['pppoe','hotspot'])) $connType = 'hotspot';

    // Resolve package
    $pkgName   = strtolower(trim($data['package_name'] ?? ($data['package'] ?? '')));
    $packageId = $pkgMap[$pkgName] ?? null;
    $planName  = $pkgName ?: null;

    // Validate expiry date
    if ($expiryDate && !preg_match('/^\d{4}-\d{2}-\d{2}/', $expiryDate)) {
        $expiryDate = '';
    }

    try {
        // Upsert by phone (if provided) or full_name + tenant_id
        $existing = null;
        if ($phone) {
            $chk = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND phone = ? LIMIT 1");
            $chk->execute([$tenantId, $phone]);
            $existing = $chk->fetchColumn() ?: null;
        }
        if (!$existing) {
            $chk = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND full_name = ? LIMIT 1");
            $chk->execute([$tenantId, $fullName]);
            $existing = $chk->fetchColumn() ?: null;
        }

        if ($existing) {
            // Update existing customer — only update non-empty fields
            $sets = ['full_name = ?', 'name = ?'];
            $vals = [$fullName, $fullName];
            if ($phone)      { $sets[] = 'phone = ?';           $vals[] = $phone; }
            if ($email)      { $sets[] = 'email = ?';           $vals[] = $email; }
            if ($address)    { $sets[] = 'address = ?';         $vals[] = $address; }
            if ($packageId)  { $sets[] = 'package_id = ?';      $vals[] = $packageId; }
            if ($planName)   { $sets[] = 'subscription_plan = ?'; $vals[] = $planName; }
            if ($connType)   { $sets[] = 'connection_type = ?'; $vals[] = $connType; }
            $sets[] = 'status = ?';  $vals[] = $status;
            if ($expiryDate) { $sets[] = 'expiry_date = ?';     $vals[] = $expiryDate; }
            // notes column not present in clients table — skipped
            $vals[] = $existing;
            $pdo->prepare("UPDATE clients SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            $updated++;
        } else {
            // Generate account number
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ?");
            $cntStmt->execute([$tenantId]);
            $cnt = (int)$cntStmt->fetchColumn() + 1;
            $accountNum = $prefix . str_pad($cnt, 4, '0', STR_PAD_LEFT);

            // Auto-generate username / password if not provided
            if (!$username) {
                $base     = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fullName));
                $username = substr($base, 0, 10) . rand(10, 99);
            }
            if (!$password) {
                $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
            }

            $expiryVal = $expiryDate ?: date('Y-m-d', strtotime('+30 days'));

            $pdo->prepare("
                INSERT INTO clients
                    (tenant_id, full_name, name, phone, email, address,
                     account_number, package_id, subscription_plan,
                     connection_type, status, expiry_date,
                     username, mikrotik_username, auth_password,
                     created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $tenantId, $fullName, $fullName, $phone, $email, $address,
                $accountNum, $packageId, $planName ?: ($pkgName ?: 'Standard'),
                $connType, $status, $expiryVal,
                $username, $username, password_hash($password, PASSWORD_DEFAULT),
            ]);
            $imported++;
        }
    } catch (Throwable $e) {
        $errors[] = "Row \"$fullName\": " . $e->getMessage();
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
