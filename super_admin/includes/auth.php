<?php
/**
 * Super Admin Authentication Guard
 * Include at the top of every super_admin/ page.
 * Verifies the logged-in user has is_super_admin = TRUE.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function superAdminGuard() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['is_super_admin'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login.php');
        exit;
    }
}

function isSuperAdmin(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['is_super_admin']);
}

function superAdminLogin(int $userId, string $username): void {
    $_SESSION['user_id']       = $userId;
    $_SESSION['username']      = $username;
    $_SESSION['role']          = 'admin';
    $_SESSION['is_super_admin'] = true;
}

function superAdminLogout(): void {
    session_destroy();
    header('Location: login.php');
    exit;
}
