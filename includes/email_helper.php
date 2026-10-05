<?php
require_once __DIR__.'/db_master.php';
require_once __DIR__.'/email_transport.php';
require_once __DIR__.'/email_config.php';
/** True means SMTP acceptance, not inbox placement. */
function sendEmail($to,$subject,$body) {
    global $pdo;
    return fortunettSendConfiguredEmail(fortunettPlatformEmailConfig($pdo),(string)$to,(string)$subject,(string)$body);
}
