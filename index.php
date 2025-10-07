<?php
// Simple entry point for Fortunett Technologies app.
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';

// If user session functions are available, redirect accordingly.
if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// Otherwise go to login page.
header('Location: login.php');
exit;
