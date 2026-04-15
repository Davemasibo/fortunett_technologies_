<?php
/**
 * Customer Portal — Activate service using account balance
 *
 * POST (no body required — uses session for client context)
 * Returns: { success: bool, message: string }
 *
 * Deducts the package price from account_balance, sets client status='active',
 * extends expiry_date, records a payment, and triggers auto-provisioning.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/auto_provision.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (!isCustomerLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$customer = getCurrentCustomer();
if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Session invalid. Please log in again.']);
    exit;
}

$clientId  = (int)$customer['id'];
$tenantId  = (int)$customer['tenant_id'];
$packageId = (int)($customer['package_id'] ?? 0);

if (!$packageId) {
    echo json_encode(['success' => false, 'message' => 'No package selected. Please choose a package first.']);
    exit;
}

// Load package
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
$pkgStmt->execute([$packageId, $tenantId]);
$package = $pkgStmt->fetch(PDO::FETCH_ASSOC);

if (!$package) {
    echo json_encode(['success' => false, 'message' => 'Package not found.']);
    exit;
}

// Reload client for fresh balance (don't trust cached session data)
$clStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
$clStmt->execute([$clientId, $tenantId]);
$client = $clStmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    echo json_encode(['success' => false, 'message' => 'Client record not found.']);
    exit;
}

$packagePrice   = (float)$package['price'];
$accountBalance = (float)($client['account_balance'] ?? 0);

if ($accountBalance < $packagePrice) {
    echo json_encode([
        'success' => false,
        'message' => 'Insufficient balance (have KES ' . number_format($accountBalance, 2)
                   . ', need KES ' . number_format($packagePrice, 2) . ').'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $newBalance    = $accountBalance - $packagePrice;
    $validityValue = max(1, (int)($package['validity_value'] ?? 30));
    $validityUnit  = in_array($package['validity_unit'], ['days','weeks','months'])
                     ? $package['validity_unit'] : 'days';
    $expiryDate    = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));

    // Activate client and deduct balance atomically
    $pdo->prepare(
        "UPDATE clients
         SET status = 'active', expiry_date = ?, account_balance = ?
         WHERE id = ? AND tenant_id = ?"
    )->execute([$expiryDate, $newBalance, $clientId, $tenantId]);

    // Payment record
    $pdo->prepare(
        "INSERT INTO payments
         (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status)
         VALUES (?, ?, ?, 'balance', NOW(), ?, 'completed')"
    )->execute([$clientId, $tenantId, $packagePrice, 'BAL-' . time() . '-' . $clientId]);

    // Activity log
    try {
        $pdo->prepare(
            "INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
             VALUES (?, ?, 'activation', ?)"
        )->execute([
            $clientId, $tenantId,
            'Service activated from balance. KES ' . number_format($packagePrice, 2)
            . ' deducted. New balance: KES ' . number_format($newBalance, 2)
            . '. Expires: ' . $expiryDate
        ]);
    } catch (Exception $e) { /* log table may not exist */ }

    $pdo->commit();

    // Auto-provision best-effort (after commit so client is already active)
    autoProvisionClient($pdo, $clientId, $tenantId);

    echo json_encode([
        'success' => true,
        'message' => 'Service activated! Expires ' . date('M d, Y', strtotime($expiryDate)) . '.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Balance activation error (client $clientId): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Activation failed. Please try again or contact support.']);
}
