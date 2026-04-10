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

$body = "<html><body style=\"font-family:sans-serif;background:#f1f5f9;padding:20px;\">
<div style=\"max-width:500px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.08);\">
  <div style=\"background:linear-gradient(135deg,#0f3460,#16213e);padding:28px;text-align:center;color:#fff;\">
    <h2 style=\"margin:0;\">FortuNett SMTP Test</h2>
    <p style=\"margin:8px 0 0;opacity:.8;font-size:14px;\">Platform Super Admin</p>
  </div>
  <div style=\"padding:28px;color:#374151;\">
    <p>If you received this email, your SMTP configuration is working correctly.</p>
    <div style=\"background:#f8fafc;border-radius:8px;padding:14px;margin:16px 0;font-size:13px;\">
      <strong>Sent at:</strong> {$sentAt}<br>
      <strong>Server:</strong> {$host}
    </div>
  </div>
</div>
</body></html>";

$result = sendEmail($to, $subject, $body);

if ($result === true) {
    echo json_encode(['success' => true, 'message' => "Test email sent to {$to}"]);
} else {
    echo json_encode(['success' => false, 'message' => is_string($result) ? $result : 'Failed to send email.']);
}
