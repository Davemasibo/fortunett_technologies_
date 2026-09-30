<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Test-only session setup stays inside this local CLI-server fixture.
if (str_starts_with($path, '/__test/')) {
    session_start();
    $testDb=new PDO('mysql:host=127.0.0.1;port=3308;dbname=fortunett_landing_browser_test','root','');
    if ($path === '/__test/google-signup') $_SESSION['google_signup']=['sub'=>'new-google-user','email'=>'new-owner@gmail.com','authoritative'=>true,'expires'=>time()+600];
    if ($path === '/__test/google-link') $_SESSION['google_link']=['sub'=>'existing-google-user','email'=>'ghetto@example.test','authoritative'=>false,'expires'=>time()+600];
    if ($path === '/__test/close') $testDb->exec("UPDATE platform_settings SET setting_value='0' WHERE setting_key='signup_enabled'");
    if ($path === '/__test/open') $testDb->exec("UPDATE platform_settings SET setting_value='1' WHERE setting_key='signup_enabled'");
    if ($path === '/__test/linked') {echo $testDb->query("SELECT COUNT(*) FROM google_identities WHERE user_id=1")->fetchColumn();exit;}
    echo 'Fixture ready'; exit;
}
if (in_array($path, ['/css/public-landing.css','/js/public-landing.js','/js/google-signin.js','/favicon.svg'], true)) return false;
if (in_array($path, ['/dashboard.php','/super_admin/index.php'], true)) {
    session_start(); header('Content-Type: application/json');
    echo json_encode(['user'=>$_SESSION['user_id'] ?? null, 'tenant'=>$_SESSION['tenant_id'] ?? null, 'super'=>$_SESSION['is_super_admin'] ?? false]); exit;
}
if (!in_array($path, ['/login.php','/index.php','/logout.php','/signup.php','/api/auth/google.php'], true)) { http_response_code(404); exit; }
foreach (['DB_HOST'=>'127.0.0.1;port=3308','DB_NAME'=>'fortunett_landing_browser_test','DB_USER'=>'root','DB_PASS'=>''] as $key=>$value) {
    putenv($key.'='.$value); $_SERVER[$key]=$value; $_ENV[$key]=$value;
}
$_SERVER['SCRIPT_NAME']=$path;
require dirname(__DIR__).$path;
