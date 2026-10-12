<?php
require_once __DIR__ . '/../includes/sms_config.php';
function uwaziiCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$phone = '254712345678';
$request = smsUwaziiRequest($phone, "Payment received\nThank you", 'Uwazii-OTP', ' test-token ');
uwaziiCheck(in_array('X-Access-Token: test-token', $request['headers'], true), 'REST token header');
uwaziiCheck($request['body'][0]['number'] === [$phone] && $request['body'][0]['senderID'] === 'Uwazii-OTP', 'Array payload and transactional sender');
uwaziiCheck(!isset($request['body'][0]['api_key']) && $request['body'][0]['text'] === "Payment received\nThank you", 'Token stays out of body and message is preserved');
$accepted = json_encode(['status' => true, 'data' => [$phone => [['id_state' => 'message-id']]]]);
uwaziiCheck(smsUwaziiResponse(200, $accepted, $phone)['success'], 'Message ID confirms acceptance');
uwaziiCheck(!smsUwaziiResponse(200, '{"status":false,"errors":"Insufficient balance"}', $phone)['success'], 'HTTP 200 rejection fails');
uwaziiCheck(!empty(smsUwaziiResponse(200, '{"status":true,"data":{}}', $phone)['uncertain']), 'Missing IDs require history check');
uwaziiCheck(!empty(smsUwaziiResponse(401, '{}', $phone)['auth_failure']), 'Invalid token classified');
uwaziiCheck(!empty(smsUwaziiResponse(502, '<html>Bad gateway</html>', $phone)['uncertain']), 'Gateway failure avoids blind retry');
uwaziiCheck(!empty(smsUwaziiResponse(400, '{"status":false,"errors":"Invalid senderID"}', $phone)['sender_failure']), 'Sender rejection supports platform fallback');
uwaziiCheck(smsIsUwazii(['provider' => 'uwazii']) && smsIsUwazii(['api_url' => SMS_UWAZII_URL]), 'Provider and endpoint detection');
