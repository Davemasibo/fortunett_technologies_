<?php
class EmailHelper {
    private $pdo;
    private $tenant_id;
    private $config;
    private $using_platform = false;

    public function __construct($pdo, $tenant_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->loadConfig();
    }

    private function loadConfig() {
        $stmt = $this->pdo->prepare("SELECT * FROM email_configurations WHERE tenant_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$this->tenant_id]);
        $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->using_platform = false;

        if (!$this->config) {
            // Fall back to platform SMTP config
            try {
                $pStmt = $this->pdo->query("SELECT * FROM platform_email_config WHERE id = 1 AND is_active = 1 LIMIT 1");
                $platConfig = $pStmt ? $pStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($platConfig && !empty($platConfig['smtp_host'])) {
                    $this->config = $platConfig;
                    $this->using_platform = true;
                }
            } catch (Exception $e) {
                // platform_email_config table may not exist yet
            }
        }
    }

    public function isUsingPlatform(): bool {
        return $this->using_platform;
    }

    public function hasConfig(): bool {
        return !empty($this->config);
    }

    public function send($to, $subject, $body, $clientId = null) {
        $logId = $this->logMessage($clientId, $to, $subject, $body, 'pending');
        $result = $this->sendInternal($to, $subject, $body);
        $status = $result['success'] ? 'sent' : 'failed';
        $error  = $result['success'] ? null : ($result['message'] ?? 'Unknown error');
        $this->updateLog($logId, $status, $error);
        return $result;
    }

    private function sendInternal($to, $subject, $body) {
        // Use PHPMailer SMTP when smtp_host is configured
        if (!empty($this->config['smtp_host'])) {
            return $this->sendViaSMTP($to, $subject, $body);
        }

        // Fallback: PHP native mail()
        $fromEmail = $this->config['from_email'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName  = $this->config['from_name'] ?? 'ISP System';

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        try {
            if (mail($to, $subject, $body, $headers)) {
                return ['success' => true, 'message' => 'Sent via mail()'];
            }
        } catch (Exception $e) {
            // fall through
        }

        if ($this->isLocalhost()) {
            return ['success' => true, 'message' => 'Simulated Send (Localhost)'];
        }
        return ['success' => false, 'message' => 'PHP mail() failed. Configure SMTP.'];
    }

    private function sendViaSMTP($to, $subject, $body) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            return ['success' => false, 'message' => 'PHPMailer not installed (run composer install)'];
        }
        require_once $autoload;

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host     = $this->config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'];
            $port = (int)($this->config['smtp_port'] ?? 587);
            $mail->Port     = $port;
            $mail->SMTPSecure = ($port === 465)
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $fromEmail = $this->config['from_email'] ?? $this->config['smtp_username'];
            $fromName  = $this->config['from_name'] ?? 'ISP System';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            $via = $this->using_platform ? ' (via platform SMTP)' : '';
            return ['success' => true, 'message' => 'Sent via SMTP' . $via];
        } catch (PHPMailer\PHPMailer\Exception $e) {
            if ($this->isLocalhost()) {
                return ['success' => true, 'message' => 'Simulated Send (Localhost SMTP test)'];
            }
            return ['success' => false, 'message' => 'SMTP Error: ' . $mail->ErrorInfo];
        }
    }

    private function isLocalhost() {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    }

    private function logMessage($clientId, $to, $subject, $body, $status) {
        $stmt = $this->pdo->prepare("INSERT INTO email_outbox (tenant_id, client_id, recipient_email, subject, message_body, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$this->tenant_id, $clientId, $to, $subject, $body, $status]);
        return $this->pdo->lastInsertId();
    }

    private function updateLog($id, $status, $error) {
        $stmt = $this->pdo->prepare("UPDATE email_outbox SET status = ?, error_message = ? WHERE id = ?");
        $stmt->execute([$status, $error, $id]);
    }

    public function getTemplates() {
        $stmt = $this->pdo->prepare("SELECT * FROM email_templates WHERE tenant_id = ?");
        $stmt->execute([$this->tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveTemplate($key, $subject, $content) {
        $stmt = $this->pdo->prepare("INSERT INTO email_templates (tenant_id, template_key, subject, body_content) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_content = VALUES(body_content)");
        return $stmt->execute([$this->tenant_id, $key, $subject, $content]);
    }

    public function saveConfig($host, $port, $user, $pass, $fromEmail, $fromName) {
        $stmt = $this->pdo->prepare("INSERT INTO email_configurations (tenant_id, smtp_host, smtp_port, smtp_username, smtp_password, from_email, from_name) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port), smtp_username = VALUES(smtp_username), smtp_password = VALUES(smtp_password), from_email = VALUES(from_email), from_name = VALUES(from_name)");
        return $stmt->execute([$this->tenant_id, $host, $port, $user, $pass, $fromEmail, $fromName]);
    }

    public function sendTemplate($clientId, $templateKey) {
        $cStmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $cStmt->execute([$clientId, $this->tenant_id]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) return ['success' => false, 'message' => 'Client not found'];

        $tStmt = $this->pdo->prepare("SELECT * FROM email_templates WHERE tenant_id = ? AND template_key = ?");
        $tStmt->execute([$this->tenant_id, $templateKey]);
        $template = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) return ['success' => false, 'message' => 'Template not found'];

        $subject = $template['subject'];
        $body    = $template['body_content'];
        $replaces = [
            '{name}'           => $client['full_name'],
            '{username}'       => $client['mikrotik_username'] ?? '',
            '{password}'       => $client['mikrotik_password'] ?? '',
            '{phone}'          => $client['phone'] ?? '',
            '{email}'          => $client['email'] ?? '',
            '{account_number}' => $client['account_number'] ?? '',
            '{expiry_date}'    => $client['expiry_date'] ?? '',
            '{amount}'         => number_format($client['package_price'] ?? 0),
            '{company_name}'   => 'FortuNett',
        ];
        foreach ($replaces as $key => $val) {
            $subject = str_replace($key, $val, $subject);
            $body    = str_replace($key, $val, $body);
        }

        return $this->send($client['email'], $subject, $body, $clientId);
    }
}
?>
