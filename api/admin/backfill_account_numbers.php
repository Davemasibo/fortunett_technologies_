<?php
/**
 * Admin Utility: Backfill account numbers for clients that don't have one.
 * Call once via browser or curl after deployment.
 * Secured: only accessible to logged-in admin.
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/account_number_generator.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get tenant_id for the logged-in admin
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$tenant_id = $stmt->fetchColumn();

if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'No tenant context']);
    exit;
}

try {
    $gen = new AccountNumberGenerator($pdo);

    // Find clients in this tenant with missing or numeric-only account numbers
    $stmt = $pdo->prepare("
        SELECT id, account_number FROM clients
        WHERE tenant_id = ?
        AND (account_number IS NULL OR account_number = '' OR account_number REGEXP '^[0-9]+$')
        ORDER BY id ASC
    ");
    $stmt->execute([$tenant_id]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $skipped = 0;
    $results = [];

    foreach ($clients as $c) {
        try {
            $newAcct = $gen->generateAccountNumber($tenant_id);
            $pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?")
                ->execute([$newAcct, $c['id']]);
            $results[] = ['id' => $c['id'], 'old' => $c['account_number'], 'new' => $newAcct];
            $updated++;
        } catch (Exception $e) {
            $skipped++;
            $results[] = ['id' => $c['id'], 'error' => $e->getMessage()];
        }
    }

    echo json_encode([
        'success' => true,
        'tenant_id' => $tenant_id,
        'updated' => $updated,
        'skipped' => $skipped,
        'details' => $results
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
