<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Please sign in again.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); exit;
}
if (empty($_SESSION['sms_retry_csrf']) || !hash_equals($_SESSION['sms_retry_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Refresh the page and try again.']); exit;
}
$user = (int)$_SESSION['user_id'];
session_write_close();
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/sms_retry.php';
try {
    $stmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
    $stmt->execute([$user]);
    $tenant = (int)$stmt->fetchColumn();
    if (!$tenant) throw new InvalidArgumentException('Tenant account required.');
    $body = json_decode(file_get_contents('php://input'), true);
    smsRetrySchema($pdo);
    echo json_encode(retryFailedSms($pdo, $tenant, (int)($body['id'] ?? 0)));
} catch (InvalidArgumentException $e) {
    http_response_code(400); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('SMS retry: ' . $e->getMessage());
    http_response_code(503); echo json_encode(['success' => false, 'message' => 'Retry status unavailable. Refresh the history before trying again.']);
}
