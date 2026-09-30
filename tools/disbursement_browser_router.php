<?php
// Only runs under a local PHP development server, against the test database.
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/super_admin/(?:css|js)/[a-z.-]+\.(css|js)$~D', $path)) return false;
if (!in_array($path, ['/super_admin/login.php','/super_admin/logout.php','/super_admin/disbursements.php'], true)) {
    http_response_code(404); exit('Test route not found');
}
foreach (['DB_HOST'=>'127.0.0.1;port=3308','DB_NAME'=>'fortunett_disbursement_browser_test','DB_USER'=>'root','DB_PASS'=>''] as $key=>$value) {
    putenv($key . '=' . $value); $_SERVER[$key] = $value; $_ENV[$key] = $value;
}
$_SERVER['SCRIPT_NAME'] = $path;
require dirname(__DIR__) . $path;
