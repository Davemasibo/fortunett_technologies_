<?php
/**
 * POST /api/payment_gateways/set_default.php
 * Sets one gateway as the default for STK Push / customer payments.
 * All other gateways for this tenant are cleared of is_default.
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'Tenant not found']); exit; }

$gateway_id = (int)($_POST['gateway_id'] ?? 0);
if (!$gateway_id) { echo json_encode(['success'=>false,'message'=>'gateway_id required']); exit; }

try {
    // Verify ownership
    $chk = $pdo->prepare("SELECT id, gateway_type FROM payment_gateways WHERE id = ? AND tenant_id = ?");
    $chk->execute([$gateway_id, $tenant_id]);
    $gw = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$gw) { echo json_encode(['success'=>false,'message'=>'Gateway not found']); exit; }

    $pdo->beginTransaction();
    // Clear all defaults for this tenant
    $pdo->prepare("UPDATE payment_gateways SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
    // Set this one as default AND make sure it's active
    $pdo->prepare("UPDATE payment_gateways SET is_default = 1, is_active = 1 WHERE id = ? AND tenant_id = ?")->execute([$gateway_id, $tenant_id]);
    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'message'    => 'Set as default payment method for customers.',
        'gateway_id' => $gateway_id,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
