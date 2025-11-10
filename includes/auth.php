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

/**
 * Format or generate an account number for a client.
 * Format: <BusinessInitial><zero-padded-id>
 * Example: business 'Fortunett' and id=6 => 'F00006' (initial 'F' + LPAD(id,5,'0'))
 * If client already has account_number, it returns that.
 * @param PDO|null $pdo optional PDO to read business name; if null, function will attempt to use global $pdo
 * @param array|int $clientOrId either client array (may contain 'account_number' & 'id') or numeric client id
 * @return string generated or existing account number
 */
function getAccountNumber($pdo = null, $clientOrId = null) {
    // support calling signature getAccountNumber($client) or getAccountNumber($pdo, $client)
    if ($clientOrId === null && is_array($pdo)) {
        $client = $pdo;
        $pdo = null;
    } else {
        $client = is_array($clientOrId) ? $clientOrId : null;
    }

    // If client provided and has account_number, return it
    if (is_array($client) && !empty($client['account_number'])) {
        return $client['account_number'];
    }

    // determine id
    $id = null;
    if (is_array($client) && isset($client['id'])) $id = (int)$client['id'];
    if ($id === null && is_numeric($clientOrId)) $id = (int)$clientOrId;

    // fallback id to 0 if not available
    if (!$id) $id = 0;

    // Get business initial
    if (!$pdo && isset($GLOBALS['pdo'])) $pdo = $GLOBALS['pdo'];
    $initial = 'I';
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
            $row = $stmt->fetch();
            if (!empty($row['business_name'])) {
                $initial = strtoupper(substr(trim($row['business_name']),0,1));
                if (!preg_match('/[A-Z]/', $initial)) $initial = 'I';
            }
        } catch (Throwable $e) {
            // ignore and use default
        }
    }

    // zero-pad id to 5 characters (so id=6 => 00006) and prefix initial => F00006
    $num = str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    return $initial . $num;
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