<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/tenant.php';

// ── Auth: require logged-in user ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Resolve tenant_id ─────────────────────────────────────────────────────────
// Prefer session (set after login), fall back to subdomain detection.
$tenantId = $_SESSION['tenant_id'] ?? null;
if (!$tenantId) {
    // Derive from the logged-in user record
    $userStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $tenantId = $userStmt->fetchColumn() ?: null;
    if ($tenantId) $_SESSION['tenant_id'] = $tenantId;
}
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Tenant context not found']);
    exit;
}

$method   = $_SERVER['REQUEST_METHOD'];
$response = [];

try {
    switch ($method) {

        // ── GET ───────────────────────────────────────────────────────────────
        case 'GET':
            if (isset($_GET['id'])) {
                // Single package — MUST belong to this tenant
                $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
                $stmt->execute([(int)$_GET['id'], $tenantId]);
                $package = $stmt->fetch(PDO::FETCH_ASSOC);
                $response = ['success' => true, 'package' => $package ?: null];
            } else {
                $status = $_GET['status'] ?? null;
                if ($status === 'active') {
                    $stmt = $pdo->prepare("SELECT * FROM packages WHERE status = 'active' AND tenant_id = ? ORDER BY price ASC");
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM packages WHERE tenant_id = ? ORDER BY created_at DESC");
                }
                $stmt->execute([$tenantId]);
                $response = ['success' => true, 'packages' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            }
            break;

        // ── POST (create) ─────────────────────────────────────────────────────
        case 'POST':
            $data = json_decode(file_get_contents('php://input'));
            if (empty($data->name) || !isset($data->price)) {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'name and price are required'];
                break;
            }
            $stmt = $pdo->prepare("
                INSERT INTO packages
                    (tenant_id, name, type, price, duration, features,
                     download_speed, upload_speed, data_limit,
                     validity_value, validity_unit, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $tenantId,
                $data->name,
                $data->type ?? 'hotspot',
                (float)$data->price,
                $data->duration ?? '30 days',
                $data->features ?? '',
                (int)($data->download_speed ?? 0),
                (int)($data->upload_speed ?? 0),
                (int)($data->data_limit ?? 0),
                (int)($data->validity_value ?? 30),
                $data->validity_unit ?? 'days',
            ]);
            $response = ['success' => true, 'message' => 'Package created', 'id' => (int)$pdo->lastInsertId()];
            break;

        // ── PUT (update) ──────────────────────────────────────────────────────
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'));
            if (empty($data->id)) {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'id is required'];
                break;
            }
            // Verify ownership
            $own = $pdo->prepare("SELECT id FROM packages WHERE id = ? AND tenant_id = ?");
            $own->execute([(int)$data->id, $tenantId]);
            if (!$own->fetch()) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'Package not found or access denied'];
                break;
            }
            $stmt = $pdo->prepare("
                UPDATE packages SET
                    name = ?, type = ?, price = ?, duration = ?, features = ?,
                    download_speed = ?, upload_speed = ?, data_limit = ?,
                    validity_value = ?, validity_unit = ?, status = ?
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                $data->name,
                $data->type ?? 'hotspot',
                (float)$data->price,
                $data->duration ?? '30 days',
                $data->features ?? '',
                (int)($data->download_speed ?? 0),
                (int)($data->upload_speed ?? 0),
                (int)($data->data_limit ?? 0),
                (int)($data->validity_value ?? 30),
                $data->validity_unit ?? 'days',
                $data->status ?? 'active',
                (int)$data->id,
                $tenantId,
            ]);
            $response = ['success' => true, 'message' => 'Package updated'];
            break;

        // ── DELETE ────────────────────────────────────────────────────────────
        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'));
            if (empty($data->id)) {
                http_response_code(400);
                $response = ['success' => false, 'message' => 'id is required'];
                break;
            }
            $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ? AND tenant_id = ?");
            $stmt->execute([(int)$data->id, $tenantId]);
            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'Package not found or access denied'];
            } else {
                $response = ['success' => true, 'message' => 'Package deleted'];
            }
            break;

        default:
            http_response_code(405);
            $response = ['success' => false, 'message' => 'Method not allowed'];
    }

} catch (PDOException $e) {
    error_log("api/packages.php: " . $e->getMessage());
    http_response_code(500);
    $response = ['success' => false, 'message' => 'Database error'];
}

echo json_encode($response);
