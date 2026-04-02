<?php
/**
 * POST /api/clients/email_report.php
 * Body: client_id, report_html (base64-encoded report HTML), subject (optional)
 * Emails the customer report to the client's email address
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/EmailHelper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id  = (int)($_POST['client_id'] ?? 0);
$report_html = $_POST['report_html'] ?? '';
$subject     = trim($_POST['subject'] ?? '');

if (!$client_id) { echo json_encode(['success'=>false,'message'=>'client_id required']); exit; }

// Verify client belongs to tenant
$chk = $pdo->prepare("SELECT id, full_name, email FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
$client = $chk->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo json_encode(['success'=>false,'message'=>'Client not found']); exit; }

if (empty($client['email'])) {
    echo json_encode(['success'=>false,'message'=>'This customer has no email address on file.']);
    exit;
}

if (empty($report_html)) {
    echo json_encode(['success'=>false,'message'=>'Report content is required.']);
    exit;
}

// Decode if base64
$html = base64_decode($report_html, true);
if ($html === false) {
    $html = $report_html; // Use as-is if not base64
}

if (empty($subject)) {
    $subject = 'Your Account Report — ' . date('F Y');
}

try {
    $emailHelper = new EmailHelper($pdo, $tenant_id);
    $result = $emailHelper->send($client['email'], $subject, $html, $client_id);

    if ($result['success'] ?? false) {
        echo json_encode(['success'=>true, 'message'=>'Report sent to ' . $client['email']]);
    } else {
        echo json_encode(['success'=>false, 'message'=>$result['message'] ?? 'Failed to send email.']);
    }
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
