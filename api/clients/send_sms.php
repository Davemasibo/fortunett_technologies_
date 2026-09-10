<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Your session has expired. Sign in again.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
if (empty($_SESSION['sms_retry_csrf']) || !hash_equals($_SESSION['sms_retry_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Refresh this page before sending.']); exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$request = $body['request_id'] ?? '';
if (!is_string($request) || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid send request. Reopen the form.']); exit;
}
// Keep the session lock until the result is cached, so duplicate HTTP requests cannot send twice.
if (isset($_SESSION['customer_sms_requests'][$request])) {
    echo json_encode($_SESSION['customer_sms_requests'][$request]); exit;
}
try {
    require_once __DIR__ . '/../../includes/db_master.php';
    require_once __DIR__ . '/../../includes/customer_sms.php';
    $stmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
    $stmt->execute([$_SESSION['user_id']]);
    $tenant = (int)$stmt->fetchColumn();
    if (!$tenant) throw new InvalidArgumentException('Tenant account required.');
    $_SESSION['customer_sms_requests'][$request] = ['success'=>false,'retryable'=>false,'message'=>'Delivery could not be confirmed. Check SMS history before sending again.'];
    $result = sendCustomerSms($pdo, $tenant, (int)($body['client_id'] ?? 0), (string)($body['message'] ?? ''));
    $_SESSION['customer_sms_requests'][$request] = $result;
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('Customer SMS: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success'=>false,'retryable'=>false,'message'=>'Sending could not be confirmed. Check SMS history before trying again.']);
}
