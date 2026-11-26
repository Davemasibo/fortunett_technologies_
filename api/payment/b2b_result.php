<?php
/** Authenticated B2B callback adapter; shared payout accounting remains in b2c_result.php. */
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/payouts.php';
$provided=(string)($_GET['token'] ?? '');
$expected=payoutCallbackToken($pdo);
if($provided==='' || !hash_equals($expected,$provided)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ResultCode'=>1,'ResultDesc'=>'Unauthorized callback']);
    exit;
}
require __DIR__.'/b2c_result.php';
