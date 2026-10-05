<?php
namespace PHPMailer\PHPMailer {
    #[\AllowDynamicProperties]
    class PHPMailer {
        const ENCRYPTION_SMTPS='ssl'; const ENCRYPTION_STARTTLS='tls';
        public static ?self $last=null; public static bool $fail=false;
        public array $from=[]; public array $recipients=[];
        public function __construct($exceptions=true) {self::$last=$this;}
        public function isSMTP(): void {}
        public function isHTML($html): void {}
        public function setFrom($email,$name): void {$this->from=[$email,$name];}
        public function addAddress($to): void {$this->recipients[]=$to;}
        public function addReplyTo($to): void {}
        public function send(): bool {if (self::$fail) throw new \RuntimeException('Synthetic SMTP failure');return true;}
    }
}
namespace {
    $emailTestEnvironment=[];
    function get_env_var($key,$default='') {global $emailTestEnvironment;return $emailTestEnvironment[$key] ?? $default;}
    require_once __DIR__.'/../includes/email_transport.php';
    require_once __DIR__.'/../includes/onboarding_reminders.php';
    require_once __DIR__.'/../classes/EmailHelper.php';
    class EmailConfigTestPDO extends PDO {
        public array $queries=[]; public array $tenantConfig=[]; public array $platformConfig=[];
        public function __construct() {}
        public function prepare(string $query,array $options=[]): PDOStatement|false {return new EmailConfigTestStatement($this,$query);}
        public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {return new EmailConfigTestStatement($this,$query);}
        public function lastInsertId(?string $name=null): string|false {return '42';}
    }
    class EmailConfigTestStatement extends PDOStatement {
        public function __construct(private EmailConfigTestPDO $db,private string $sql) {}
        public function execute(?array $params=null): bool {$this->db->queries[]=[$this->sql,$params];return true;}
        public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
            return str_contains($this->sql,'email_configurations') ? ($this->db->tenantConfig ?: false) : ($this->db->platformConfig ?: false);
        }
    }
    function emailCheck(bool $ok,string $label): void {if (!$ok) throw new RuntimeException($label);echo "PASS: $label\n";}
    $message=onboardingReminderMessage(['company_name'=>'ISP <Branch>','subdomain'=>'branch','step'=>'Connect your first router'],'example.com');
    $html=$message['body'];
    emailCheck(str_contains($html,'bgcolor="#2563eb"') && str_contains($html,'Continue setup</a>'),'Setup action renders as a table-based button');
    emailCheck(str_contains($html,'ISP &lt;Branch&gt;') && !str_contains($html,'ISP <Branch>'),'Tenant text is escaped');
    emailCheck(fortunettEmailEnsureBrand('Reminder',$html)===$html,'Branding is not applied twice');
    emailCheck(str_contains(fortunettEmailPlainText($html),'https://branch.example.com/onboarding.php'),'Plain text retains actionable URLs');
    emailCheck(!str_contains(fortunettEmailPlainText($html),'@media'),'Plain text excludes CSS');
    $report=fortunettEmailEnsureBrand('Your report','<!doctype html><html><head><style>.balance{color:blue}</style></head><body><p class="balance">KSH 500</p></body></html>',['sender'=>'Branch ISP','support_email'=>'help@example.com']);
    emailCheck(substr_count($report,'<!doctype html>')===1 && str_contains($report,'.balance{color:blue}') && str_contains($report,'KSH 500'),'Customer reports retain content and styles without nested documents');
    try {fortunettEmailButton('Continue','javascript:alert(1)');throw new RuntimeException('Unsafe URL accepted');} catch (InvalidArgumentException $e) {echo "PASS: Unsafe action URLs are rejected\n";}
    $config=['smtp_host'=>'smtp.example.com','smtp_username'=>'sender@example.com','smtp_password'=>'synthetic-password','smtp_port'=>465,'from_email'=>'sender@example.com','from_name'=>'FortuNett Technologies'];
    emailCheck(fortunettSendConfiguredEmail($config,'recipient@example.com','Résumé',$html)===true,'SMTP acceptance returns true');
    $mail=\PHPMailer\PHPMailer\PHPMailer::$last;
    emailCheck($mail->CharSet==='UTF-8' && $mail->SMTPSecure==='ssl','UTF-8 and port 465 implicit TLS are configured');
    emailCheck($mail->from[0]==='sender@example.com' && str_contains($mail->AltBody,'/onboarding.php'),'Configured sender and plain-text action are preserved');
    $config['smtp_port']=587;fortunettSendConfiguredEmail($config,'recipient@example.com','Test',$html);
    emailCheck(\PHPMailer\PHPMailer\PHPMailer::$last->SMTPSecure==='tls','Port 587 uses STARTTLS');
    \PHPMailer\PHPMailer\PHPMailer::$fail=true;
    emailCheck(fortunettSendConfiguredEmail($config,'recipient@example.com','Test',$html)!==true,'SMTP failure is reported without fallback or simulated success');
    emailCheck(fortunettSendConfiguredEmail([],'recipient@example.com','Test',$html)!==true,'Missing SMTP configuration cannot be reported as delivered');
    $db=new EmailConfigTestPDO(); $db->platformConfig=$config;
    $emailTestEnvironment=['MAIL_HOST'=>'smtp.gmail.com','MAIL_USERNAME'=>'test-sender@gmail.com','MAIL_PASSWORD'=>'synthetic-env-password','MAIL_FROM_ADDRESS'=>'test-sender@gmail.com','MAIL_REPLY_TO'=>'help@example.com'];
    $resolved=fortunettPlatformEmailConfig($db);
    emailCheck($resolved['smtp_host']==='smtp.gmail.com' && $resolved['from_email']==='test-sender@gmail.com' && $resolved['reply_to']==='help@example.com','Environment sender and reply-to override platform settings together');
    unset($emailTestEnvironment['MAIL_PASSWORD']);
    emailCheck(empty(fortunettPlatformEmailConfig($db)['smtp_password']),'Environment SMTP does not borrow another configuration’s password');
    $emailTestEnvironment=[];
    emailCheck(fortunettPlatformEmailConfig($db)['from_email']==='sender@example.com','Platform settings are used when environment SMTP is absent');
    $db->tenantConfig=$config; $db->tenantConfig['from_email']='tenant@example.com';$db->tenantConfig['from_name']='Branch ISP';
    \PHPMailer\PHPMailer\PHPMailer::$fail=false;
    $helper=new EmailHelper($db,22);
    $result=$helper->send('recipient@example.com','Account update','<p>Your service is active.</p>',7);
    $mail=\PHPMailer\PHPMailer\PHPMailer::$last;
    emailCheck($result['success'] && $mail->from[0]==='tenant@example.com' && str_contains($mail->Body,'Sent by Branch ISP'),'Customer mail retains tenant SMTP identity inside the FortuNett design');
    emailCheck($db->queries[0][1]===[22] && str_contains($db->queries[0][0],'tenant_id = ?'),'Customer configuration lookup is tenant scoped');
    \PHPMailer\PHPMailer\PHPMailer::$fail=true;
    emailCheck(!$helper->send('recipient@example.com','Account update','Test',7)['success'],'Customer SMTP failure is not reported as a simulated success');
}
