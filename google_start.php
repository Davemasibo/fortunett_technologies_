<?php
require_once __DIR__.'/includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/google_bridge.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
try {
    $tenant=googleRequestTenant($pdo);
    if ($tenant!==null) {
        $state=bin2hex(random_bytes(32));
        $_SESSION['google_workspace_state']=['state'=>$state,'expires'=>time()+300];
        $request=googleBridgeIssue($pdo,$tenant,hash('sha256',$state),'request');
        header('Location: https://fortunetttech.site/google_start.php?request='.$request);exit;
    }
    $request=(string)($_GET['request']??'');
    if ($request!=='') {googleBridgeSchema($pdo);googleBridgeRequest($pdo,$request);$_SESSION['google_workspace_request']=['token'=>$request,'expires'=>time()+300];}
    else unset($_SESSION['google_workspace_request']);
    unset($_SESSION['google_challenge']);
    header('Location: https://fortunetttech.site/login.php?signin=1');exit;
} catch(Throwable $e) {
    http_response_code(400);echo 'Google sign-in could not start. Return to your workspace and try again.';
}
