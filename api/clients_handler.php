<?php
/**
 * Legacy Client Handler (form-POST based)
 * All operations are tenant-scoped.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
redirectIfNotLoggedIn();

// ── Resolve tenant_id from session / user record ──────────────────────────────
$tenantId = $_SESSION['tenant_id'] ?? null;
if (!$tenantId) {
    $us = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $us->execute([$_SESSION['user_id']]);
    $tenantId = $us->fetchColumn() ?: null;
    if ($tenantId) $_SESSION['tenant_id'] = (int)$tenantId;
}
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Tenant context not found']);
    exit;
}

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => ''];

try {
    switch ($action) {

        case 'create':
            $stmt = $pdo->prepare("
                INSERT INTO clients
                    (tenant_id, full_name, name, email, phone, address,
                     company, mikrotik_username, status, subscription_plan, monthly_fee)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $tenantId,
                $_POST['full_name'],
                $_POST['full_name'],
                $_POST['email']              ?? '',
                $_POST['phone']              ?? '',
                $_POST['address']            ?? '',
                $_POST['company']            ?? '',
                $_POST['mikrotik_username']  ?? '',
                $_POST['status']             ?? 'inactive',
                $_POST['subscription_plan']  ?? '',
                $_POST['monthly_fee']        ?? 0,
            ]);
            $response = ['success' => true, 'message' => 'Client created successfully', 'id' => (int)$pdo->lastInsertId()];
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { $response['message'] = 'id is required'; break; }

            // Verify ownership before update
            $own = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ?");
            $own->execute([$id, $tenantId]);
            if (!$own->fetch()) {
                http_response_code(403);
                $response['message'] = 'Client not found or access denied';
                break;
            }

            $stmt = $pdo->prepare("
                UPDATE clients
                SET full_name = ?, name = ?, email = ?, phone = ?, address = ?,
                    company = ?, mikrotik_username = ?, status = ?, subscription_plan = ?, monthly_fee = ?
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                $_POST['full_name'],
                $_POST['full_name'],
                $_POST['email']             ?? '',
                $_POST['phone']             ?? '',
                $_POST['address']           ?? '',
                $_POST['company']           ?? '',
                $_POST['mikrotik_username'] ?? '',
                $_POST['status']            ?? 'inactive',
                $_POST['subscription_plan'] ?? '',
                $_POST['monthly_fee']       ?? 0,
                $id,
                $tenantId,
            ]);
            $response = ['success' => true, 'message' => 'Client updated successfully'];
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) { $response['message'] = 'id is required'; break; }

            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                $response['message'] = 'Client not found or access denied';
            } else {
                $response = ['success' => true, 'message' => 'Client deleted successfully'];
            }
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { $response['message'] = 'id is required'; break; }

            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                http_response_code(404);
                $response['message'] = 'Client not found';
            } else {
                $response = ['success' => true, 'data' => $client];
            }
            break;

        default:
            http_response_code(400);
            $response['message'] = 'Invalid action';
    }

} catch (Exception $e) {
    error_log("clients_handler.php: " . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Server error';
}

echo json_encode($response);
