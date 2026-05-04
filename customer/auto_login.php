<?php
/**
 * Customer Portal Auto-Login
 * Used after hotspot payment to log the customer into the portal automatically.
 * Token is created in hotspot_stk_push.php and hotspot_payment_status.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    header('Location: login.php');
    exit;
}

// Clear any existing session so new credentials take effect (prevents stale-credential bug)
if (!empty($_SESSION['customer_token'])) {
    $_SESSION = [];
    session_regenerate_id(true);
}

$auth   = new CustomerAuth($pdo);
$result = $auth->autoLogin($token);

if ($result['success']) {
    $_SESSION['customer_token'] = $result['session_token'];
    $_SESSION['customer_data']  = $result['client'] ?? [];
    header('Location: dashboard.php');
} else {
    // Token used or expired — go to login
    header('Location: login.php?message=session_ready');
}
exit;
