<?php
/**
 * POST /api/billing/check_bill_status.php
 * Poll whether a tenant bill has been marked paid (used after STK push).
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['paid' => false]);
    exit;
}

$user_id = $_SESSION['user_id'];
$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$tenant_id = (int)$uStmt->fetchColumn();

$bill_id     = (int)($_POST['bill_id']     ?? 0);
$checkoutId  = trim($_POST['checkout_id']  ?? '');

if (!$bill_id || !$tenant_id) {
    echo json_encode(['paid' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM tenant_bills WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$bill_id, $tenant_id]);
    $status = $stmt->fetchColumn();
    echo json_encode(['paid' => ($status === 'paid')]);
} catch (Throwable $e) {
    echo json_encode(['paid' => false]);
}
