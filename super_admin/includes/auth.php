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
        $script = $_SERVER['SCRIPT_NAME'] ?? '/super_admin/index.php';
        $base = preg_replace('~/(?:api/)?super_admin/.*$~', '/super_admin/', $script);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && strpos($script, '/api/') === false) {
            $_SESSION['super_admin_return_to'] = basename($script) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        }
        header('Location: ' . $base . 'login.php');
        exit;
    }
}

function isSuperAdmin(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['is_super_admin']);
}

function superAdminLogin(int $userId, string $username): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $userId;
    $_SESSION['username']      = $username;
    $_SESSION['role']          = 'admin';
    $_SESSION['is_super_admin'] = true;
}

function superAdminDestination(): string {
    $destination = (string)($_SESSION['super_admin_return_to'] ?? 'index.php');
    unset($_SESSION['super_admin_return_to']);
    return preg_match('~^(?:index|tenants|billing|collections|disbursements|plans|mpesa|diagnostics|settings)\.php(?:\?[^\r\n]*)?$~D', $destination)
        ? $destination : 'index.php';
}

function superAdminLogout(): void {
    session_destroy();
    header('Location: login.php');
    exit;
}
