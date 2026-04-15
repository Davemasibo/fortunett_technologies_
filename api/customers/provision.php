<?php
/**
 * Manually trigger auto-provisioning for a client.
 * Called from the customer detail page "Provision to Router" button.
 */
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/auto_provision.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = (int)$st->fetchColumn();

$client_id = (int)($_POST['client_id'] ?? 0);
if (!$client_id) {
    echo json_encode(['success' => false, 'message' => 'Client ID required']);
    exit;
}

// Verify client belongs to this tenant
$st = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ?");
$st->execute([$client_id, $tenant_id]);
if (!$st->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Client not found']);
    exit;
}

$result = autoProvisionClient($pdo, $client_id, $tenant_id);
echo json_encode($result);
