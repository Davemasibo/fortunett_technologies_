<?php
/**
 * SMS Helper Class
 * Sends SMS notifications to customers
 */
class SMSHelper {
    private $apiKey;
    private $apiUrl;
    private $senderId;
    
    public function __construct() {
        // Configure your SMS provider details here
        // Using Africa's Talking as example (you can change to your provider)
        $this->apiKey = 'YOUR_API_KEY'; // Replace with your SMS API key
        $this->apiUrl = 'https://api.africastalking.com/version1/messaging';
        $this->senderId = 'FortuNNet'; // Your sender ID
    }
    
    /**
     * Send SMS to a phone number
     */
    public function sendSMS($phone, $message) {
        try {
            // Format phone number (ensure it starts with country code)
            $phone = $this->formatPhoneNumber($phone);
            
            // Log SMS for debugging
            $this->logSMS($phone, $message);
            
            // For testing, you can disable actual sending
            // Remove this return to enable real SMS
            return [
                'success' => true,
                'message' => 'SMS logged (actual sending disabled for testing)',
                'phone' => $phone
            ];
            
            // Uncomment below to enable actual SMS sending via Africa's Talking
            /*
            $data = [
                'username' => 'YOUR_USERNAME',
                'to' => $phone,
                'message' => $message,
                'from' => $this->senderId
            ];
            
            $headers = [
                'apiKey: ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ];
            
            $ch = curl_init($this->apiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200 || $httpCode == 201) {
                return ['success' => true, 'message' => 'SMS sent successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to send SMS: ' . $response];
            }
            */
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'SMS error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Send welcome SMS with credentials
     */
    public function sendWelcomeSMS($phone, $username, $password, $packageName) {
        $message = "Welcome to FortuNNet Technologies!\n\n";
        $message .= "Your account has been activated.\n";
        $message .= "Package: {$packageName}\n";
        $message .= "Username: {$username}\n";
        $message .= "Password: {$password}\n\n";
        $message .= "Login at: http://192.168.88.1/login\n";
        $message .= "Support: +254700000000";
        
        return $this->sendSMS($phone, $message);
    }
    
    /**
     * Send payment confirmation SMS
     */
    public function sendPaymentConfirmationSMS($phone, $amount, $packageName, $expiryDate) {
        $message = "Payment Received!\n\n";
        $message .= "Amount: KES {$amount}\n";
        $message .= "Package: {$packageName}\n";
        $message .= "Valid until: {$expiryDate}\n\n";
        $message .= "Thank you for choosing FortuNNet!";
        
        return $this->sendSMS($phone, $message);
    }
    
    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // If starts with 0, replace with 254 (Kenya)
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        
        // If doesn't start with +, add it
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Log SMS for debugging and record keeping
     */
    private function logSMS($phone, $message) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/sms_log.txt';
        $logEntry = sprintf(
            "[%s] To: %s | Message: %s\n",
            date('Y-m-d H:i:s'),
            $phone,
            str_replace("\n", " | ", $message)
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
