<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
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
}

function logoutUser() {
    session_destroy();
    header("Location: login.php");
    exit;
}

function getCurrentTheme() {
    return isset($_COOKIE['theme']) ? $_COOKIE['theme'] : THEME_SYSTEM;
}

function getISPProfile($db) {
    $query = "SELECT * FROM isp_profile LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSubscriptionDaysLeft($expiry_date) {
    $expiry = new DateTime($expiry_date);
    $today = new DateTime();
    $interval = $today->diff($expiry);
    return $interval->days;
}
?>