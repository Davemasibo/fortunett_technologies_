<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id=?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

$id       = (int)($_POST['id']         ?? 0);
$name     = trim($_POST['name']        ?? '');
$ip       = trim($_POST['ip_address']  ?? '');
$username = trim($_POST['username']    ?? '');
$password = trim($_POST['password']    ?? '');
$vpn_ip   = trim($_POST['vpn_ip']      ?? '');

if (!$id || !$name || !$ip) {
    echo json_encode(['status' => 'error', 'message' => 'Name and IP are required']);
    exit;
}

// vpn_ip: store null if blank so connection logic falls back to ip_address
$vpn_ip_val = $vpn_ip !== '' ? $vpn_ip : null;

try {
    if ($password) {
        $stmt = $pdo->prepare("UPDATE mikrotik_routers SET name=?, ip_address=?, username=?, password=?, vpn_ip=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$name, $ip, $username, $password, $vpn_ip_val, $id, $tenant_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE mikrotik_routers SET name=?, ip_address=?, username=?, vpn_ip=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$name, $ip, $username, $vpn_ip_val, $id, $tenant_id]);
    }
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
