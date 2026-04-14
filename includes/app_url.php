<?php
/**
 * App URL Helper
 *
 * Returns the base URL path for this installation.
 * - On production  (*.fortunetttech.site) the web-root IS the project dir → ""
 * - On localhost dev the project lives under /fortunett_technologies_/ → that prefix
 *
 * Usage:
 *   require_once __DIR__ . '/app_url.php';
 *   $url = appBase() . '/api/admin/demo_customer_login.php';
 */
function appBase(): string {
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
    if ($host === 'localhost' || preg_match('/^\d+\.\d+\./', $host)) {
        return '/fortunett_technologies_';
    }
    return '';
}

/**
 * Full origin (scheme + host) without trailing slash.
 */
function appOrigin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
