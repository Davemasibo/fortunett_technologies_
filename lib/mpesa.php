<?php
/**
 * Minimal MPESA helper (Daraja OAuth + STK push)
 * - Uses config/mpesa.php constants
 * - Returns associative arrays with 'success' boolean and 'data' or 'error'
 */
require_once __DIR__ . '/../config/mpesa.php';

function mpesa_get_access_token() {
    $consumerKey = MPESA_CONSUMER_KEY;
    $consumerSecret = MPESA_CONSUMER_SECRET;
    $url = MPESA_OAUTH_URL;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_USERPWD, $consumerKey . ':' . $consumerSecret);
    curl_setopt($ch, CURLOPT_TIMEOUT, MPESA_HTTP_TIMEOUT);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => $err];
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['access_token'])) {
        return ['success' => true, 'data' => $data['access_token']];
    }
    return ['success' => false, 'error' => 'Failed to get token: ' . ($resp ?: 'no response')];
}

function mpesa_initiate_stk($phone, $amount, $accountRef = 'Account', $transactionDesc = 'Payment') {
    // validate required config
    if (empty(MPESA_SHORTCODE) || empty(MPESA_PASSKEY)) {
        return ['success' => false, 'error' => 'MPESA_SHORTCODE or MPESA_PASSKEY not configured'];
    }

    // normalize phone to international format without +
    $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phoneNorm) === 10 && substr($phoneNorm,0,1) === '0') $phoneNorm = '254' . substr($phoneNorm,1);
    if (substr($phoneNorm,0,1) === '+') $phoneNorm = ltrim($phoneNorm,'+');

    $tokenResp = mpesa_get_access_token();
    if (!$tokenResp['success']) return $tokenResp;
    $accessToken = $tokenResp['data'];

    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => round($amount,2),
        'PartyA' => $phoneNorm,
        'PartyB' => MPESA_SHORTCODE,
        'PhoneNumber' => $phoneNorm,
        'CallBackURL' => MPESA_CALLBACK_URL,
        'AccountReference' => $accountRef,
        'TransactionDesc' => $transactionDesc
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, MPESA_STK_PUSH_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, MPESA_HTTP_TIMEOUT);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => $err];
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300) {
        return ['success' => true, 'data' => $data];
    }
    return ['success' => false, 'error' => 'STK push failed: ' . ($resp ?: 'no response')];
}

?>
