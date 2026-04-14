<?php
/**
 * Import Payments / Transactions from CSV
 * POST multipart/form-data with file field "csv_file"
 * Required columns: amount, client_phone OR client_name OR account_number
 * Optional columns:
 *   transaction_id, payment_method (mpesa/cash/bank), payment_date (YYYY-MM-DD HH:MM:SS),
 *   status (completed/pending/failed), notes
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

$imported = 0; $skipped = 0; $errors = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 1) continue;
    $data = array_combine($header, array_pad($row, count($header), ''));

    $amount = (float)($data['amount'] ?? 0);
    if ($amount <= 0) { $skipped++; continue; }

    // Resolve client
    $clientId = null;
    $lookups = [
        ['account_number', $data['account_number'] ?? ''],
        ['phone',          $data['client_phone'] ?? ($data['phone'] ?? '')],
        ['full_name',      $data['client_name']  ?? ($data['name']  ?? '')],
    ];
    foreach ($lookups as [$col, $val]) {
        $val = trim($val);
        if (!$val) continue;
        $chk = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND $col = ? LIMIT 1");
        $chk->execute([$tenantId, $val]);
        $clientId = $chk->fetchColumn() ?: null;
        if ($clientId) break;
    }

    $txId     = trim($data['transaction_id'] ?? '') ?: ('IMP-' . strtoupper(bin2hex(random_bytes(6))));
    $method   = strtolower(trim($data['payment_method'] ?? 'cash'));
    if (!in_array($method, ['mpesa','cash','bank','card'])) $method = 'cash';
    $status   = strtolower(trim($data['status'] ?? 'completed'));
    if (!in_array($status, ['completed','pending','failed'])) $status = 'completed';
    $notes    = trim($data['notes'] ?? '');

    // Parse payment date
    $rawDate = trim($data['payment_date'] ?? ($data['date'] ?? ''));
    $payDate = null;
    if ($rawDate) {
        $ts = strtotime($rawDate);
        if ($ts !== false) $payDate = date('Y-m-d H:i:s', $ts);
    }
    if (!$payDate) $payDate = date('Y-m-d H:i:s');

    try {
        // Skip exact duplicate (same transaction_id + tenant)
        if (strpos($txId, 'IMP-') !== 0) {
            $dup = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = ? AND transaction_id = ? LIMIT 1");
            $dup->execute([$tenantId, $txId]);
            if ($dup->fetchColumn()) { $skipped++; continue; }
        }

        $pdo->prepare("
            INSERT INTO payments
                (tenant_id, client_id, amount, payment_method,
                 transaction_id, status, payment_date, notes, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $tenantId,
            $clientId,
            $amount,
            $method,
            $txId,
            $status,
            $payDate,
            $notes,
        ]);
        $imported++;
    } catch (Throwable $e) {
        $errors[] = "Row (tx: $txId): " . $e->getMessage();
    }
}
fclose($fh);

echo json_encode([
    'success'  => true,
    'imported' => $imported,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'message'  => "$imported imported, $skipped skipped" . (count($errors) ? ', ' . count($errors) . ' error(s)' : ''),
]);
