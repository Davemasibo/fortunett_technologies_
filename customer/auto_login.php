<?php
if(session_status()===PHP_SESSION_NONE)session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/includes/auth.php';
$token=trim($_GET['token']??'');
if(!$token){header('Location: login.php');exit;}
$tenant=customerHostTenant($pdo);
$auth=new CustomerAuth($pdo);
$result=$auth->autoLogin($token,$_SERVER['REMOTE_ADDR']??null,null,$tenant);
if($result['success']){
    // Replace customer identity only after successful authentication.
    if(!empty($_SESSION['customer_token']))$auth->logout($_SESSION['customer_token']);
    session_regenerate_id(true);
    $_SESSION['customer_token']=$result['session_token'];
    $_SESSION['customer_data']=$result['client'];
    header('Location: dashboard.php?payment=success');
}else{
    // A repeated redirect is safe when this browser already has a valid session.
    header('Location: '.(getCurrentCustomer()?'dashboard.php':'login.php?session_expired=1'));
}
exit;
