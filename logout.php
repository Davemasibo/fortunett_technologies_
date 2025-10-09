<?php
session_start();
session_unset();
session_destroy();

// Clear any cookies
if (isset($_COOKIE['theme'])) {
    setcookie('theme', '', time() - 3600, '/');
}

header("Location: login.php");
exit;
?>