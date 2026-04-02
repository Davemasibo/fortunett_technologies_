<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/EmailHelper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$stmt = $pdo->prepare("SELECT tenant_id, email FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id = $user['tenant_id'] ?? 0;
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$email = new EmailHelper($pdo, $tenant_id);
if (!$email->hasConfig()) {
    echo json_encode(['success'=>false,'message'=>'No SMTP config found. Save settings first.']);
    exit;
}

$to      = $user['email'] ?? 'test@example.com';
$subject = 'FortuNett — Test Email';
$body    = '<p>This is a test email from your FortuNett ISP platform. If you received this, your SMTP configuration is working correctly.</p>';

$result = $email->send($to, $subject, $body);
echo json_encode([
    'success' => $result['success'],
    'message' => $result['success']
        ? 'Test email sent to ' . $to . ($email->isUsingPlatform() ? ' (via platform SMTP)' : '')
        : ($result['message'] ?? 'Send failed'),
]);
