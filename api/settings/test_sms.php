<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/SMSHelper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$stmt = $pdo->prepare("SELECT u.tenant_id, u.phone FROM users u WHERE u.id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id = $user['tenant_id'] ?? 0;
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$sms = new SMSHelper($pdo, $tenant_id);
if (!$sms->hasConfig()) {
    echo json_encode(['success'=>false,'message'=>'No SMS config found. Save settings first.']);
    exit;
}

// Get admin phone from tenant_settings or users table
$ps = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id=? AND setting_key='support_number' LIMIT 1");
$ps->execute([$tenant_id]);
$phone = $ps->fetchColumn() ?: ($user['phone'] ?? '0700000000');

$result = $sms->send($phone, 'FortuNett test SMS: Your SMS configuration is working correctly!');
echo json_encode([
    'success' => $result['success'],
    'message' => $result['success']
        ? 'Test SMS sent to ' . $phone . ($sms->isUsingPlatform() ? ' (via platform SMS)' : '')
        : ($result['message'] ?? 'Send failed'),
]);
