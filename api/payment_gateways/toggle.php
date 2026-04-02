<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
    exit;
}

$gateway_id = (int)($_POST['gateway_id'] ?? 0);
$is_active   = (int)($_POST['is_active'] ?? 0); // 0 or 1

if (!$gateway_id) {
    echo json_encode(['success' => false, 'message' => 'Gateway ID required']);
    exit;
}

try {
    // Make sure the gateway belongs to this tenant
    $check = $pdo->prepare("SELECT id FROM payment_gateways WHERE id = ? AND tenant_id = ?");
    $check->execute([$gateway_id, $tenant_id]);
    if (!$check->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Gateway not found']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE payment_gateways SET is_active = ? WHERE id = ? AND tenant_id = ?");
    $upd->execute([$is_active ? 1 : 0, $gateway_id, $tenant_id]);

    echo json_encode([
        'success'   => true,
        'is_active' => (bool)$is_active,
        'message'   => $is_active ? 'Gateway activated — customers can now use this payment method' : 'Gateway deactivated — hidden from customers'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
