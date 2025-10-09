<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

// Fetch paybill settings
$profile = $pdo->query("SELECT paybill, paybill_account FROM isp_profile LIMIT 1")->fetch();
$paybill = $profile['paybill'] ?? '';
$account = $profile['paybill_account'] ?? '';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';
$amount = (float)($input['amount'] ?? 0);
$accRef = $input['account_reference'] ?? $account;

if (!$paybill || !$phone || $amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing paybill, phone or amount']);
    exit;
}

// TODO: Integrate Safaricom STK push using Daraja API here.
// This is a stub returning success for now.
echo json_encode(['success' => true, 'message' => 'STK request initiated', 'paybill' => $paybill, 'account' => $accRef]);


