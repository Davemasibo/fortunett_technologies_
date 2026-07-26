<?php
/**
 * Returns a 12-char fingerprint of the login page this tenant would be served.
 *
 * The router's self-update scheduler fetches this first (a ~12 byte response) and
 * only downloads the full login.html when the fingerprint differs from the copy
 * on flash. That keeps the hourly check nearly free and avoids pointless flash
 * writes on hundreds of routers.
 *
 * URL: /hotspot/login_version.php?token={provisioning_token}
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/render_login.php';

$token = trim($_GET['token'] ?? '');

try {
    $tenant = $token ? hotspotTenantByToken($pdo, $token) : null;
    if (!$tenant) {
        ob_clean();
        http_response_code(403);
        exit('invalid');
    }

    // Hash the real rendered output, so a template edit, a brand-colour change or
    // a new package all produce a new version — no manual bumping to forget.
    $version = substr(sha1(renderHotspotLoginPage($pdo, $tenant)), 0, 12);

    ob_clean();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $version;   // no trailing newline — RouterOS compares file contents verbatim

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    exit('error');
}
