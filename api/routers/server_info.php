<?php
/**
 * Debug: returns the public IP this server will use in provisioning scripts.
 * Visit /api/routers/server_info.php while logged in to verify the IP.
 * Delete this file once confirmed.
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$host     = $_SERVER['HTTP_HOST'];
$resolved = @gethostbyname($host);
$isPublic = $resolved && $resolved !== $host
    && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

echo json_encode([
    'http_host'    => $host,
    'resolved_ip'  => $resolved,
    'server_addr'  => $_SERVER['SERVER_ADDR'] ?? null,
    'will_use_ip'  => $isPublic ? $resolved : ($_SERVER['SERVER_ADDR'] ?? 'unknown'),
    'is_public'    => $isPublic,
    'note'         => $isPublic
        ? 'This IP will be written into the provisioning RSC firewall rule.'
        : 'WARNING: resolved IP is private/failed. Check DNS or set server IP manually.',
], JSON_PRETTY_PRINT);
