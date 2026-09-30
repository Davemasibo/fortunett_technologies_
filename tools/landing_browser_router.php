<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/css/public-landing.css','/js/public-landing.js','/favicon.svg'], true)) return false;
if (in_array($path, ['/dashboard.php','/super_admin/index.php'], true)) {
    session_start(); header('Content-Type: application/json');
    echo json_encode(['user'=>$_SESSION['user_id'] ?? null, 'tenant'=>$_SESSION['tenant_id'] ?? null, 'super'=>$_SESSION['is_super_admin'] ?? false]); exit;
}
if (!in_array($path, ['/login.php','/index.php','/logout.php'], true)) { http_response_code(404); exit; }
foreach (['DB_HOST'=>'127.0.0.1;port=3308','DB_NAME'=>'fortunett_landing_browser_test','DB_USER'=>'root','DB_PASS'=>''] as $key=>$value) {
    putenv($key.'='.$value); $_SERVER[$key]=$value; $_ENV[$key]=$value;
}
$_SERVER['SCRIPT_NAME']=$path;
require dirname(__DIR__).$path;
