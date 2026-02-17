<?php
// Email helper - Gmail SMTP support for localhost and VPS
// Requires: PHPMailer (install via: composer require phpmailer/phpmailer)

require_once __DIR__ . '/db_master.php';

// Try to load PHPMailer
$phpmailerPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($phpmailerPath)) {
    require_once $phpmailerPath;
}

function sendEmail($to, $subject, $body) {
    global $pdo;

    // Load .env if available
    if (file_exists(dirname(__DIR__) . '/.env')) {
        require_once __DIR__ . '/env.php';
    }

    // Get Email Settings (prioritize .env, fallback to database)
    $mailHost = $_ENV['MAIL_HOST'] ?? null;
    $mailPort = $_ENV['MAIL_PORT'] ?? 587;
    $mailUsername = $_ENV['MAIL_USERNAME'] ?? null;
    $mailPassword = $_ENV['MAIL_PASSWORD'] ?? null;
    $mailFromName = $_ENV['MAIL_FROM_NAME'] ?? 'ISP Portal';

    // If no .env, try database settings
    if (!$mailHost || !$mailUsername) {
        try {
            $stmt = $pdo->query("SELECT * FROM email_settings WHERE id = 1");
            $settings = $stmt->fetch();
            if ($settings) {
                $mailHost = $settings['smtp_host'] ?? 'smtp.gmail.com';
                $mailPort = $settings['smtp_port'] ?? 587;
                $mailUsername = $settings['from_email'] ?? null;
                $mailPassword = $settings['smtp_password'] ?? null;
                $mailFromName = $settings['from_name'] ?? 'ISP Portal';
            }
        } catch (PDOException $e) {
            // Database not configured, use fallbacks
        }
    }

    // If still no credentials, use basic mail() function
    if (!$mailHost || !$mailUsername) {
        $fromEmail = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: $mailFromName <$fromEmail>\r\n";
        return mail($to, $subject, $body, $headers);
    }

    // Use PHPMailer if available
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $mailHost;
            $mail->SMTPAuth = true;
            $mail->Username = $mailUsername;
            $mail->Password = $mailPassword;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $mailPort;
            
            // Recipients
            $mail->setFrom($mailUsername, $mailFromName);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    // Final fallback: basic mail()
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: $mailFromName <$mailUsername>\r\n";
    return mail($to, $subject, $body, $headers);
}
?>
