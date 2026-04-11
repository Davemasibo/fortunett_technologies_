<?php
/**
 * Customer Portal Authentication Helper
 * Include this file to protect customer portal pages
 */

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/CustomerAuth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Check if customer is logged in
 */
function isCustomerLoggedIn() {
    return isset($_SESSION['customer_token']) && !empty($_SESSION['customer_token']);
}

/**
 * Get current customer data
 */
function getCurrentCustomer() {
    global $pdo;
    
    if (!isCustomerLoggedIn()) {
        return null;
    }
    
    $auth = new CustomerAuth($pdo);
    $result = $auth->validateSession($_SESSION['customer_token']);
    
    if ($result['valid']) {
        return $result['client'];
    }
    
    // Session invalid, clear it
    unset($_SESSION['customer_token']);
    unset($_SESSION['customer_data']);
    return null;
}

/**
 * Redirect to login if not authenticated
 */
function requireCustomerLogin() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Auto-detect app base path (works on both localhost/subdir and VPS root installs)
    $scriptFile = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $docRoot    = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $scriptRel  = str_replace($docRoot, '', $scriptFile);          // e.g. /fortunett_technologies_/customer/dashboard.php
    $basePath   = dirname(dirname($scriptRel));                    // e.g. /fortunett_technologies_
    $basePath   = ($basePath === '/' || $basePath === '.') ? '' : $basePath;
    $loginUrl   = $scheme . '://' . $host . $basePath . '/customer/login.php';

    if (!isCustomerLoggedIn()) {
        $currentUrl = urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . $loginUrl . '?redirect=' . $currentUrl);
        exit;
    }

    // Validate session
    $customer = getCurrentCustomer();
    if (!$customer) {
        header('Location: ' . $loginUrl . '?session_expired=1');
        exit;
    }
    
    // Store in session for easy access
    $_SESSION['customer_data'] = $customer;
    return $customer;
}

/**
 * Customer logout
 */
function customerLogout() {
    global $pdo;

    if (isset($_SESSION['customer_token'])) {
        $auth = new CustomerAuth($pdo);
        $auth->logout($_SESSION['customer_token']);
    }

    session_destroy();
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $loginUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/customer/login.php';
    header('Location: ' . $loginUrl . '?logged_out=1');
    exit;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return 'KES ' . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date) {
    if (empty($date)) return 'N/A';
    return date('M d, Y', strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($datetime) {
    if (empty($datetime)) return 'N/A';
    return date('M d, Y h:i A', strtotime($datetime));
}

/**
 * Check if subscription is active
 */
function isSubscriptionActive($expiryDate) {
    if (empty($expiryDate)) return false;
    return strtotime($expiryDate) > time();
}

/**
 * Get days until expiry
 */
function getDaysUntilExpiry($expiryDate) {
    if (empty($expiryDate)) return 0;
    $diff = strtotime($expiryDate) - time();
    return max(0, floor($diff / 86400));
}
