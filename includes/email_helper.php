<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

function sendEmail($to, $subject, $body) {
    global $pdo;

    // Get SMTP settings from DB
    try {
        $stmt = $pdo->query("SELECT * FROM email_settings WHERE id = 1");
        $settings = $stmt->fetch();
    } catch (PDOException $e) {
        $settings = null;
    }

    $fromEmail = $settings['from_email'] ?? 'noreply@' . $_SERVER['HTTP_HOST'];
    $fromName = $settings['from_name'] ?? 'ISP Manager';

    // Headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . $fromName . " <" . $fromEmail . ">" . "\r\n";

    // Native mail fallback (Assuming no PHPMailer or specific library requested, standard mail is safest for generic PHP envs unless configured)
    // For production, using PHPMailer is recommended, but let's stick to simple mail() if no library is present, or simple SMTP via headers if possible.
    // Since user asked for "logic to send email", using mail() is the standard baseline.
    
    return mail($to, $subject, $body, $headers);
}
?>
