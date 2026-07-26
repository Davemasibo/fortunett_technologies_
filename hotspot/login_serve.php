<?php
/**
 * Serves a tenant-branded hotspot login page.
 * Called by RouterOS /tool/fetch so the router can pull the branded page
 * directly to its flash. No session auth — the token is the credential.
 *
 * URL: /hotspot/login_serve.php?token={provisioning_token}
 *
 * Routers re-run this on a schedule (see hotspot/self_update_script.php), so any
 * edit to login.html or a tenant's packages reaches every router without
 * re-provisioning.
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/render_login.php';

$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(400);
    exit('Token required');
}

try {
    $tenant = hotspotTenantByToken($pdo, $token);
    if (!$tenant) {
        http_response_code(403);
        exit('Invalid token');
    }

    $html = renderHotspotLoginPage($pdo, $tenant);

    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    // Lets a router (or a curl check) confirm which build it is holding
    header('X-Portal-Version: ' . substr(sha1($html), 0, 12));
    echo $html;

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    exit('Error: ' . $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}
