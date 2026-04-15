<?php
/**
 * Debug: shows which IP the provisioning script will write into MikroTik firewall rules.
 * The correct IP is the one the VPS uses for OUTBOUND connections, not the DNS/CDN IP.
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$host       = $_SERVER['HTTP_HOST'];
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
$resolved   = @gethostbyname($host);

$serverAddrIsPublic = $serverAddr && filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
$resolvedIsPublic   = $resolved && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

// SERVER_ADDR is preferred because it is the actual NIC/outbound IP even behind Cloudflare
$willUseIp = $serverAddrIsPublic ? $serverAddr : ($resolvedIsPublic ? $resolved : 'unknown');
$isBehindCDN = $serverAddrIsPublic && $resolvedIsPublic && $serverAddr !== $resolved;

echo json_encode([
    'will_use_ip'      => $willUseIp,
    'server_addr'      => $serverAddr,
    'dns_resolved_ip'  => $resolved,
    'behind_cloudflare_or_cdn' => $isBehindCDN,
    'note' => $isBehindCDN
        ? "Site is behind CDN/Cloudflare. DNS resolves to {$resolved} (CDN) but outbound connections come from {$serverAddr} (real VPS). Using SERVER_ADDR for firewall rule."
        : "Using {$willUseIp} for MikroTik firewall rule.",
], JSON_PRETTY_PRINT);
