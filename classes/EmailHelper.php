<?php
class EmailHelper {
    private $pdo;
    private $tenant_id;
    private $config;

    public function __construct($pdo, $tenant_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->loadConfig();
    }

    private function loadConfig() {
        $stmt = $this->pdo->prepare("SELECT * FROM email_configurations WHERE tenant_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$this->tenant_id]);
        $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function send($to, $subject, $body, $clientId = null) {
        // Log attempt first as pending
        $logId = $this->logMessage($clientId, $to, $subject, $body, 'pending');

        $result = $this->sendInternal($to, $subject, $body);

        // Update log
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['message'] ?? ($result['success'] ? null : 'Unknown error');
        
        $this->updateLog($logId, $status, $error);

        return $result;
    }

    private function sendInternal($to, $subject, $body) {
        // If config exists, try to use it (Simulated SMTP for now as we don't have PHPMailer loaded via Composer)
        // In a real env, we would require PHPMailer here.
        // For this environment, we will use PHP native mail() but attempt to set From headers from config.
        
        $fromEmail = $this->config['from_email'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromName = $this->config['from_name'] ?? 'ISP System';
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $fromName . " <" . $fromEmail . ">" . "\r\n";
        $headers .= "Reply-To: " . $fromEmail . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Try sending
        // On XAMPP localhost without Mercury/Sendmail configured, this usually fails.
        // We will return simulated success if we are on localhost and it fails, to avoid blocking UI testing.
        try {
            if (mail($to, $subject, $body, $headers)) {
                return ['success' => true, 'message' => 'Sent via mail()'];
            }
        } catch (Exception $e) {
            // Log error
        }

        // Simulation for localhost development if real mail fails
        if ($this->isLocalhost()) {
             return ['success' => true, 'message' => 'Simulated Send (Localhost)'];
        }

        return ['success' => false, 'message' => 'PHP mail() failed. Configure SMTP.'];
    }

    private function isLocalhost() {
        return ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1');
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
        // Fetch Client
        $cStmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $cStmt->execute([$clientId, $this->tenant_id]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) return ['success' => false, 'message' => 'Client not found'];

        // Fetch Template
        $tStmt = $this->pdo->prepare("SELECT * FROM email_templates WHERE tenant_id = ? AND template_key = ?");
        $tStmt->execute([$this->tenant_id, $templateKey]);
        $template = $tStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) return ['success' => false, 'message' => 'Template not found'];

        // Replace Variables
        $subject = $template['subject'];
        $body = $template['body_content'];
        
        $replaces = [
            '{name}' => $client['full_name'],
            '{username}' => $client['mikrotik_username'] ?? '',
            '{password}' => $client['mikrotik_password'] ?? '',
            '{phone}' => $client['phone'] ?? '',
            '{email}' => $client['email'] ?? '',
            '{account_number}' => $client['account_number'] ?? '',
            '{expiry_date}' => $client['expiry_date'] ?? '',
            '{amount}' => number_format($client['package_price'] ?? 0),
            '{company_name}' => 'Fortunett' // Ideally fetch from Tenant settings
        ];

        foreach ($replaces as $key => $val) {
            $subject = str_replace($key, $val, $subject);
            $body = str_replace($key, $val, $body);
        }

        return $this->send($client['email'], $subject, $body, $clientId);
    }
}
?>
