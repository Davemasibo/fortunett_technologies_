<?php
/**
 * Customer Portal Authentication Helper
 * Include this file to protect customer portal pages
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../classes/CustomerAuth.php';

session_start();

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
    if (!isCustomerLoggedIn()) {
        $currentUrl = urlencode($_SERVER['REQUEST_URI']);
        header('Location: /fortunett_technologies_/customer/login.php?redirect=' . $currentUrl);
        exit;
    }
    
    // Validate session
    $customer = getCurrentCustomer();
    if (!$customer) {
        header('Location: /fortunett_technologies_/customer/login.php?session_expired=1');
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
    header('Location: /fortunett_technologies_/customer/login.php?logged_out=1');
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
