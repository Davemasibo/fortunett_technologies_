<?php
class SMSHelper {
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
        $stmt = $this->pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$this->tenant_id]);
        $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->using_platform = false;

        if (!$this->config) {
            // Fall back to platform SMS config
            try {
                $pStmt = $this->pdo->query("SELECT * FROM platform_sms_config WHERE id = 1 AND is_active = 1 LIMIT 1");
                $platConfig = $pStmt ? $pStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($platConfig && !empty($platConfig['api_key'])) {
                    $this->config = $platConfig;
                    $this->using_platform = true;
                }
            } catch (Exception $e) {
                // platform_sms_config table may not exist yet
            }
        }
    }

    public function isUsingPlatform(): bool {
        return $this->using_platform;
    }

    public function hasConfig(): bool {
        return !empty($this->config);
    }

    public function send($phone, $message, $clientId = null) {
        if (!$this->config) {
            return ['success' => false, 'message' => 'SMS not configured. Set up SMS credentials in Settings or contact your platform admin.'];
        }

        $phone    = $this->formatPhone($phone);
        $response = $this->sendViaTalkSasa($phone, $message);
        $this->logMessage($clientId, $phone, $message, $response);

        return $response;
    }

    private function formatPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') return '254' . substr($phone, 1);
        return $phone;
    }

    private function sendViaTalkSasa($phone, $message) {
        $url      = $this->config['api_url'] ?? 'https://api.talksasa.com/v1/sms/send';
        $apiKey   = $this->config['api_key'];
        $senderId = $this->config['sender_id'];

        // Mock sending if no real key (localhost testing)
        if (empty($apiKey) || $apiKey === 'TEST_KEY') {
            $via = $this->using_platform ? ' (platform)' : '';
            return ['success' => true, 'message' => 'Simulated sent to ' . $phone . $via];
        }

        $data = [
            'api_key'   => $apiKey,
            'sender_id' => $senderId,
            'phone'     => $phone,
            'message'   => $message,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $via = $this->using_platform ? ' (via platform SMS)' : '';
            return ['success' => true, 'response' => $result, 'message' => 'Sent' . $via];
        }

        return ['success' => false, 'message' => 'Provider error (HTTP ' . $httpCode . '): ' . $result];
    }

    private function logMessage($clientId, $phone, $message, $response) {
        $status           = $response['success'] ? 'sent' : 'failed';
        $providerResponse = is_array($response) ? json_encode($response) : $response;
        $stmt = $this->pdo->prepare("INSERT INTO sms_outbox (tenant_id, client_id, recipient_phone, message, status, provider_response) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$this->tenant_id, $clientId, $phone, $message, $status, $providerResponse]);
    }

    public function getTemplates() {
        $stmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE tenant_id = ?");
        $stmt->execute([$this->tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveTemplate($key, $name, $content) {
        $stmt = $this->pdo->prepare("INSERT INTO sms_templates (tenant_id, template_key, template_name, template_content) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE template_name = VALUES(template_name), template_content = VALUES(template_content)");
        return $stmt->execute([$this->tenant_id, $key, $name, $content]);
    }

    public function saveConfig($provider, $apiKey, $senderId, $apiUrl) {
        $stmt = $this->pdo->prepare("INSERT INTO sms_configurations (tenant_id, provider, api_key, sender_id, api_url) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE provider = VALUES(provider), api_key = VALUES(api_key), sender_id = VALUES(sender_id), api_url = VALUES(api_url)");
        return $stmt->execute([$this->tenant_id, $provider, $apiKey, $senderId, $apiUrl]);
    }

    public function sendTemplate($clientId, $templateKey) {
        $cStmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $cStmt->execute([$clientId, $this->tenant_id]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) return ['success' => false, 'message' => 'Client not found'];

        $tStmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE tenant_id = ? AND template_key = ?");
        $tStmt->execute([$this->tenant_id, $templateKey]);
        $template = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) return ['success' => false, 'message' => 'Template not found'];

        $message = $template['template_content'];
        $message = str_replace('{name}',           $client['full_name'],              $message);
        $message = str_replace('{username}',       $client['mikrotik_username'] ?? '', $message);
        $message = str_replace('{password}',       $client['mikrotik_password'] ?? '', $message);
        $message = str_replace('{phone}',          $client['phone'] ?? '',             $message);
        $message = str_replace('{account_number}', $client['account_number'] ?? '',    $message);
        $message = str_replace('{expiry_date}',    $client['expiry_date'] ?? '',       $message);
        $message = str_replace('{amount}',         number_format($client['package_price'] ?? 0), $message);

        return $this->send($client['phone'], $message, $clientId);
    }
}
?>
