<?php
require_once __DIR__.'/includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/google_bridge.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
try {
    $tenant=googleRequestTenant($pdo);
    $pending=googlePending('google_workspace_state');
    if (!$tenant || !$pending) throw new InvalidArgumentException('Sign-in expired.');
    $result=googleBridgeConsume($pdo,(string)($_GET['ticket']??''),$tenant,$pending['state']);
    unset($_SESSION['google_workspace_state']);
    session_regenerate_id(true);
    unset($_SESSION['google_link'],$_SESSION['google_signup']);
    if ($result['action']==='link') {
        $_SESSION['google_link']=$result['payload'];
        header('Location: login.php?signin=1');exit;
    }
    $user=$result['payload'];
    loginUser($user['id'],$user['username'],$user['role']);
    $_SESSION['tenant_id']=$tenant;$_SESSION['is_super_admin']=false;
    $_SESSION['tenant_subdomain']=explode('.',strtolower($_SERVER['HTTP_HOST']))[0];
    $destination=!empty($_SESSION['resume_onboarding']) ? 'onboarding.php' : 'dashboard.php';
    unset($_SESSION['resume_onboarding']);
    header('Location: '.$destination);exit;
} catch(Throwable $e) {
    header('Location: login.php?signin=1&google_error=1');exit;
}
