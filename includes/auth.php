<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) || isset($_SESSION['user']);
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function loginUser($user_id, $username, $role) {
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
    $_SESSION['user'] = [
        'id' => $user_id,
        'username' => $username,
        'role' => $role
    ];
}

function logoutUser() {
    session_destroy();
    header("Location: login.php");
    exit;
}

function getCurrentTheme() {
    return isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
}

function getISPProfile($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM isp_profile LIMIT 1");
        return $stmt->fetch();
    } catch (PDOException $e) {
        return ['business_name' => 'ISP Management', 'email' => '', 'phone' => '', 'subscription_expiry' => date('Y-m-d')];
    }
}

function getSubscriptionDaysLeft($expiry_date) {
    $expiry = new DateTime($expiry_date);
    $today = new DateTime();
    if ($today > $expiry) {
        return 0;
    }
    $interval = $today->diff($expiry);
    return $interval->days;
}

function ensureRole($requiredRole) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }

    $userRole = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? '';

    if (is_array($requiredRole)) {
        $allowed = in_array($userRole, $requiredRole, true);
    } else {
        $allowed = ($userRole === (string)$requiredRole);
    }

    if (!$allowed) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
        exit;
    }
}
?>