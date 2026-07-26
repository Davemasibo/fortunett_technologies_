<?php
/**
 * Emits a paste-able RouterOS block that installs the portal auto-sync
 * script + scheduler on a router.
 *
 * For routers the server cannot dial into (no port forward, API disabled, VPN
 * down) — paste the output into WinBox → New Terminal once, and that router
 * keeps itself up to date from then on.
 *
 * Admin session:  /hotspot/self_update_script.php
 * By token:       /hotspot/self_update_script.php?token={provisioning_token}
 * Optional:       &interval=30m   (default 1h)
 */
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/hotspot_sync.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$tenantId = 0;

// Prefer the logged-in admin's tenant; fall back to the provisioning token so
// this can be curl'd during setup before anyone has logged in.
if (!empty($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ? LIMIT 1");
    $st->execute([$_SESSION['user_id']]);
    $tenantId = (int)$st->fetchColumn();
}
if (!$tenantId) {
    $token = trim($_GET['token'] ?? '');
    if ($token) {
        $st = $pdo->prepare("SELECT id FROM tenants WHERE provisioning_token = ? LIMIT 1");
        $st->execute([$token]);
        $tenantId = (int)($st->fetchColumn() ?: 0);
    }
}

header('Content-Type: text/plain; charset=utf-8');

if (!$tenantId) {
    http_response_code(403);
    exit("# Not authorised. Log in to the admin portal, or pass ?token={provisioning_token}\n");
}

$urls = hotspotPortalUrls($pdo, $tenantId);
if (!$urls) {
    http_response_code(400);
    exit("# This tenant has no provisioning token yet. Generate one in Settings first.\n");
}

// Only accept RouterOS-shaped intervals (5m, 30m, 1h, 12h, 1d)
$interval = trim($_GET['interval'] ?? '1h');
if (!preg_match('/^\d{1,3}[smhd]$/', $interval)) {
    $interval = '1h';
}

if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="fortunett-portal-sync.rsc"');
}

echo hotspotSyncInstallerRsc($urls['page'], $urls['version'], $interval);
echo "\n";
