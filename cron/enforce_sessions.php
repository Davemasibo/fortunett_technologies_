<?php
/** Run every minute to disconnect expired customer sessions with zero grace. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('CRON_MODE', true);
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
require_once __DIR__ . '/../includes/session_enforcement.php';
$lock = fopen(sys_get_temp_dir() . '/fortunett-enforce-' . sha1(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
cron_heartbeat($pdo, 'enforce_sessions');
enforceCustomerSessions($pdo, function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
});
