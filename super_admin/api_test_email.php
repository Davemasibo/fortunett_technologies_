<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

header('Content-Type: application/json');

$to = trim($_POST['to'] ?? '');
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $to = $_SESSION['email'] ?? '';
}
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'No valid recipient email. Please set your email in your profile.']);
    exit;
}

require_once __DIR__ . '/../includes/email_helper.php';

$subject = 'FortuNett Platform — SMTP Test';
$sentAt  = date('Y-m-d H:i:s');
$host    = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost');

$body=fortunettEmail('Your SMTP test',
    '<p>This message checks whether your configured SMTP service can accept an email. Receiving it confirms delivery to this mailbox; inbox placement and authentication should be checked in the message headers.</p>'.fortunettEmailSummary(['Sent at'=>$sentAt,'Server'=>html_entity_decode($host,ENT_QUOTES,'UTF-8')]),
    ['category'=>'Delivery test','preheader'=>'Check delivery and authentication in this message?s headers.']);

$result = sendEmail($to, $subject, $body);

if ($result === true) {
    echo json_encode(['success' => true, 'message' => "Test email sent to {$to}"]);
} else {
    echo json_encode(['success' => false, 'message' => is_string($result) ? $result : 'Failed to send email.']);
}
