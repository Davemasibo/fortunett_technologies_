<?php
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/tenant_payouts.php';
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('POST required.');}
    if(empty($_SESSION['user_id'])){http_response_code(401);throw new RuntimeException('Sign in to continue.');}
    $st=$pdo->prepare('SELECT u.id,u.tenant_id,u.role,t.admin_user_id FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.id=?');$st->execute([$_SESSION['user_id']]);$actor=$st->fetch(PDO::FETCH_ASSOC);
    if(!$actor || ((int)$actor['admin_user_id']!==(int)$actor['id'] && $actor['role']!=='admin')){http_response_code(403);throw new RuntimeException('Only a tenant administrator can initiate payouts.');}
    require_once __DIR__.'/../includes/auth.php';
    if(!isLoggedIn()){http_response_code(403);throw new RuntimeException('Sign in on your tenant portal.');}
    if(empty($_SESSION['billing_csrf']) || !hash_equals($_SESSION['billing_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('Reload billing before withdrawing.');}
    $request=(string)($_POST['request_id'] ?? '');
    if(empty($_SESSION['payout_requests'][$request])){http_response_code(403);throw new RuntimeException('Reload billing before withdrawing.');}
    if(empty($_POST['confirmed']))throw new RuntimeException('Confirm the amount and payout number first.');
    $batch=submitTenantWithdrawal($pdo,(int)$actor['tenant_id'],$request,false,null,$_SESSION['payout_requests'][$request]);
    echo json_encode(['success'=>true,'batch'=>$batch,'message'=>match($batch['status']){
        'paid'=>'Payout confirmed.','failed'=>'M-Pesa rejected the payout. Your collections remain available.',
        'unknown'=>'Payout confirmation is delayed. Do not submit again until its outcome is confirmed.',
        default=>'Payout submitted. Waiting for M-Pesa confirmation.'}]);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(409);error_log('Tenant withdrawal: '.$e->getMessage());echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Payouts are temporarily unavailable. Please try again later.':$e->getMessage()]);}
