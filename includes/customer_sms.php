<?php
require_once __DIR__ . '/../classes/SMSHelper.php';

function sendCustomerSms(PDO $pdo, int $tenant, int $clientId, string $message, $helper = null): array {
    $message = trim($message);
    if ($tenant < 1 || $clientId < 1 || $message === '' || strlen($message) > 10000) {
        return ['success'=>false, 'retryable'=>true, 'message'=>'Choose a customer and enter a message (maximum 10,000 bytes).'];
    }
    $stmt = $pdo->prepare('SELECT c.*, p.price AS package_price, p.name AS package_name FROM clients c LEFT JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id WHERE c.id=? AND c.tenant_id=?');
    $stmt->execute([$clientId, $tenant]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) return ['success'=>false, 'retryable'=>false, 'message'=>'Customer not found in your account.'];
    if (trim((string)($client['phone'] ?? '')) === '') {
        return ['success'=>false, 'retryable'=>true, 'message'=>'Add a phone number to this customer before sending an SMS.'];
    }
    $helper = $helper ?? new SMSHelper($pdo, $tenant);
    try { $message = $helper->renderPlaceholders($message, $client); }
    catch (InvalidArgumentException $e) { return ['success'=>false,'retryable'=>true,'message'=>$e->getMessage()]; }
    $result = $helper->send($client['phone'], $message, $clientId);
    return ['success'=>!empty($result['success']), 'retryable'=>empty($result['success']) && empty($result['uncertain']),
        'message'=>!empty($result['success']) ? 'SMS accepted by the provider for ' . $client['phone'] . '. Delivery to the phone may take a moment.'
            : ($result['message'] ?? 'The SMS provider rejected the message. Check SMS settings and try again.')];
}
