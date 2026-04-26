<?php
/**
 * Preview the branded hotspot login page using the correct DB token.
 * Requires an active admin session — visit this URL in your browser.
 */
require_once __DIR__ . '/../includes/db_master.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('Login required'); }

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

$s = $pdo->prepare("SELECT provisioning_token FROM tenants WHERE id = ?");
$s->execute([$tenant_id]);
$token = $s->fetchColumn();

if (!$token) { exit('No provisioning token found for your tenant. Contact support.'); }

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base  = $proto . '://' . $_SERVER['HTTP_HOST'];
$url   = $base . '/hotspot/login_serve.php?token=' . urlencode($token);

header('Location: ' . $url);
exit;
