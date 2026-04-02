<?php
/**
 * POST /api/profile/update.php
 * action=update_info   — update username, email
 * action=change_password — change password (requires current_password)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$action = trim($_POST['action'] ?? '');

try {
    // ── Update Profile Info ──────────────────────────────────────────────
    if ($action === 'update_info') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if (empty($username)) { echo json_encode(['success'=>false,'message'=>'Username is required']); exit; }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'Invalid email address']); exit;
        }

        // Check username uniqueness (excluding self)
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $chk->execute([$username, $user_id]);
        if ($chk->fetch()) { echo json_encode(['success'=>false,'message'=>'Username already taken']); exit; }

        if (!empty($email)) {
            $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chkEmail->execute([$email, $user_id]);
            if ($chkEmail->fetch()) { echo json_encode(['success'=>false,'message'=>'Email already in use']); exit; }
            $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?")->execute([$username, $email, $user_id]);
        } else {
            $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user_id]);
        }

        $_SESSION['username'] = $username;
        echo json_encode(['success'=>true,'message'=>'Profile updated successfully']);
        exit;
    }

    // ── Change Password ──────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password']     ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new)) {
            echo json_encode(['success'=>false,'message'=>'Current and new password are required']); exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['success'=>false,'message'=>'New password must be at least 8 characters']); exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['success'=>false,'message'=>'New passwords do not match']); exit;
        }

        $row = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $row->execute([$user_id]);
        $hash = $row->fetchColumn();

        if (!password_verify($current, $hash)) {
            echo json_encode(['success'=>false,'message'=>'Current password is incorrect']); exit;
        }

        $newHash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $user_id]);
        echo json_encode(['success'=>true,'message'=>'Password changed successfully']);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
