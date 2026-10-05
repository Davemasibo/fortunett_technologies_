<?php
require_once __DIR__.'/email_brand.php';
/** Authenticated SMTP only: failure must never silently switch to local mail(). */
function fortunettSendConfiguredEmail(array $config,string $to,string $subject,string $body): bool|string {
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return 'Invalid recipient email address.';
    $host=trim((string)($config['smtp_host'] ?? ''));
    $from=trim((string)($config['from_email'] ?? $config['smtp_username'] ?? ''));
    if (!$host || empty($config['smtp_username']) || empty($config['smtp_password'])) return 'Authenticated SMTP is not configured. Set the SMTP host, username, password and verified sender address.';
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) return 'Configure a valid verified From email address.';
    $autoload=dirname(__DIR__).'/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) return 'PHPMailer is not installed. Run composer install.';
    $mail=new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host=$host; $mail->SMTPAuth=true;
        $mail->Username=$config['smtp_username']; $mail->Password=$config['smtp_password'];
        $mail->Port=(int)($config['smtp_port'] ?? 587);
        $mail->SMTPSecure=$mail->Port===465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout=10; $mail->Timelimit=15; $mail->CharSet='UTF-8';
        $mail->setFrom($from,($config['from_name'] ?? '') ?: 'FortuNett Technologies');
        if (!empty($config['reply_to']) && filter_var($config['reply_to'],FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($config['reply_to']);
        $mail->addAddress($to); $mail->isHTML(true);
        $mail->Subject=$subject; $mail->Body=fortunettEmailEnsureBrand($subject,$body);
        $mail->AltBody=fortunettEmailPlainText($mail->Body);
        $mail->send(); return true;
    } catch (Throwable $e) {
        error_log('FortuNett SMTP delivery failed: '.get_class($e));
        return 'SMTP delivery failed. Check SMTP credentials, verified sender and provider delivery logs.';
    }
}
