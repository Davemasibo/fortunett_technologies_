<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

$identity = $_GET['identity'] ?? '';

if (!$user_id || !$identity) {
    echo json_encode(['connected' => false, 'message' => 'Unauthorized or missing identity']);
    exit;
}

try {
    // Get Tenant ID
    $tStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $tStmt->execute([$user_id]);
    $tenant_id = $tStmt->fetchColumn();

    if (!$tenant_id) {
        throw new Exception("Tenant not found");
    }

    // Check for recent activity on this router for this tenant
    // We look for 'active' or 'online' status within the last 5 minutes
    $stmt = $pdo->prepare("
        SELECT id, name, ip_address, status, last_seen 
        FROM mikrotik_routers 
        WHERE (name = ? OR identity = ?) 
        AND tenant_id = ? 
        AND (status = 'active' OR status = 'online')
        AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$identity, $identity, $tenant_id]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($router) {
        echo json_encode(['connected' => true, 'router' => $router]);
    } else {
        echo json_encode(['connected' => false]);
    }

} catch (Exception $e) {
    echo json_encode(['connected' => false, 'error' => $e->getMessage()]);
}
?>
