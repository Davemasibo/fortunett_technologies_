<?php
/**
 * Super Admin Password Reset Tool
 * Delete this file immediately after use.
 */
require_once __DIR__ . '/../includes/db_master.php';

// Simple security: require a key in the URL — change this to something private
$SECRET_KEY = 'fortunett_reset_2026';
if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    die('<h2>Access Denied</h2><p>Add <code>?key=fortunett_reset_2026</code> to the URL.</p>');
}

$message = '';
$msgType = '';

// Fetch existing super admins
$admins = [];
try {
    $admins = $pdo->query("SELECT id, username, email, is_super_admin, tenant_id FROM users WHERE is_super_admin = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = 'DB error: ' . $e->getMessage();
    $msgType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_password') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $newPass  = trim($_POST['new_password'] ?? '');
        $confirm  = trim($_POST['confirm_password'] ?? '');

        if (!$uid || !$newPass) {
            $message = 'User and password are required.';
            $msgType = 'error';
        } elseif ($newPass !== $confirm) {
            $message = 'Passwords do not match.';
            $msgType = 'error';
        } elseif (strlen($newPass) < 8) {
            $message = 'Password must be at least 8 characters.';
            $msgType = 'error';
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND is_super_admin = 1");
                $stmt->execute([$hash, $uid]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Password updated successfully. You can now login.';
                    $msgType = 'success';
                } else {
                    $message = 'No super admin with that ID found.';
                    $msgType = 'error';
                }
            } catch (Exception $e) {
                $message = 'DB error: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
        // Refresh list
        try { $admins = $pdo->query("SELECT id, username, email, is_super_admin, tenant_id FROM users WHERE is_super_admin = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    }

    if ($action === 'create_admin') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $confirm  = trim($_POST['confirm'] ?? '');

        if (!$username || !$email || !$password) {
            $message = 'All fields required.';
            $msgType = 'error';
        } elseif ($password !== $confirm) {
            $message = 'Passwords do not match.';
            $msgType = 'error';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters.';
            $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email.';
            $msgType = 'error';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_super_admin, tenant_id, created_at) VALUES (?, ?, ?, 'superadmin', 1, NULL, NOW())");
                $stmt->execute([$username, $email, $hash]);
                $message = "Super admin '$username' created. Login at <a href='login.php'>login.php</a>.";
                $msgType = 'success';
                $admins = $pdo->query("SELECT id, username, email, is_super_admin, tenant_id FROM users WHERE is_super_admin = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $message = 'DB error: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin Reset — FortuNett</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.wrap { width:100%; max-width:560px; }
.card { background:#1c1c1b; border-radius:16px; border:1px solid rgba(255,255,255,.07); box-shadow:0 24px 64px rgba(0,0,0,.5); overflow:hidden; margin-bottom:20px; }
.card-header { background:linear-gradient(135deg,#1a1a2e,#0f3460); padding:24px 28px; color:#fff; display:flex; align-items:center; gap:12px; }
.card-header .badge { background:rgba(233,69,96,.25); color:#fca5a5; font-size:11px; padding:3px 10px; border-radius:20px; }
.card-header h2 { font-size:18px; font-weight:700; }
.card-body { padding:24px 28px; }
.warn { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25); border-radius:8px; padding:12px 16px; color:#fca5a5; font-size:13px; margin-bottom:20px; display:flex; gap:10px; align-items:flex-start; }
.msg.success { background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.25); border-radius:8px; padding:12px 16px; color:#6ee7b7; font-size:13px; margin-bottom:20px; }
.msg.error   { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25); border-radius:8px; padding:12px 16px; color:#fca5a5; font-size:13px; margin-bottom:20px; }
.admin-list { border:1px solid rgba(255,255,255,.07); border-radius:8px; overflow:hidden; margin-bottom:20px; }
.admin-row { display:flex; align-items:center; gap:14px; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,.05); background:rgba(255,255,255,.02); }
.admin-row:last-child { border-bottom:none; }
.admin-avatar { width:36px; height:36px; background:rgba(59,110,165,.3); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#93c5fd; font-weight:700; font-size:15px; flex-shrink:0; }
.admin-info { flex:1; }
.admin-info .name { font-size:14px; font-weight:600; color:#e2e2e0; }
.admin-info .email { font-size:12px; color:#9a9a95; }
.admin-id { font-size:12px; color:#6b7280; background:rgba(255,255,255,.06); padding:2px 8px; border-radius:4px; }
.section-title { font-size:13px; font-weight:700; color:#9a9a95; text-transform:uppercase; letter-spacing:.05em; margin-bottom:14px; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:12px; font-weight:600; color:#9a9a95; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em; }
.form-group input, .form-group select { width:100%; padding:10px 12px; background:#1a1a19; border:1px solid rgba(255,255,255,.08); border-radius:8px; color:#e2e2e0; font-size:13px; box-shadow:inset 3px 3px 7px rgba(0,0,0,.3); }
.form-group input:focus { outline:none; border-color:#3B6EA5; }
.btn { width:100%; padding:12px; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; transition:opacity .2s; }
.btn-primary { background:linear-gradient(135deg,#1a1a2e,#0f3460); color:#fff; }
.btn-primary:hover { opacity:.9; }
.btn-danger { background:linear-gradient(135deg,#b91c1c,#ef4444); color:#fff; }
.divider { border:none; border-top:1px solid rgba(255,255,255,.07); margin:20px 0; }
</style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <div class="card-header">
            <i class="fas fa-shield-alt" style="font-size:20px;color:#93c5fd;"></i>
            <div>
                <div class="badge">SECURITY TOOL</div>
                <h2>Super Admin Reset</h2>
            </div>
        </div>
        <div class="card-body">
            <div class="warn">
                <i class="fas fa-exclamation-triangle" style="margin-top:1px;flex-shrink:0;"></i>
                <span><strong>Delete this file after use!</strong> It bypasses normal authentication. Path: <code>super_admin/reset_admin.php</code></span>
            </div>

            <?php if ($message): ?>
            <div class="msg <?= $msgType ?>"><?= $message ?></div>
            <?php endif; ?>

            <!-- Existing super admins -->
            <div class="section-title"><i class="fas fa-users" style="margin-right:6px;"></i>Existing Super Admins (<?= count($admins) ?>)</div>
            <?php if ($admins): ?>
            <div class="admin-list">
                <?php foreach ($admins as $a): ?>
                <div class="admin-row">
                    <div class="admin-avatar"><?= strtoupper(substr($a['username'],0,1)) ?></div>
                    <div class="admin-info">
                        <div class="name"><?= htmlspecialchars($a['username']) ?></div>
                        <div class="email"><?= htmlspecialchars($a['email']) ?></div>
                    </div>
                    <div class="admin-id">ID: <?= $a['id'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:#9a9a95;font-size:13px;margin-bottom:16px;">No super admin accounts found.</p>
            <?php endif; ?>

            <!-- Reset password form -->
            <?php if ($admins): ?>
            <hr class="divider">
            <div class="section-title"><i class="fas fa-key" style="margin-right:6px;"></i>Reset Password</div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <div class="form-group">
                    <label>Select Admin</label>
                    <select name="user_id" required>
                        <?php foreach ($admins as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['username']) ?> (<?= htmlspecialchars($a['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>New Password (min 8 chars)</label>
                    <input type="password" name="new_password" required placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-lock" style="margin-right:6px;"></i>Reset Password</button>
            </form>
            <?php endif; ?>

            <!-- Create new super admin -->
            <hr class="divider">
            <div class="section-title"><i class="fas fa-user-plus" style="margin-right:6px;"></i>Create New Super Admin</div>
            <form method="POST">
                <input type="hidden" name="action" value="create_admin">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="e.g. platform_admin">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required placeholder="admin@fortunetttech.site">
                </div>
                <div class="form-group">
                    <label>Password (min 8 chars)</label>
                    <input type="password" name="password" required placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-danger"><i class="fas fa-user-shield" style="margin-right:6px;"></i>Create Super Admin</button>
            </form>

            <hr class="divider">
            <a href="login.php" style="display:block;text-align:center;color:#93c5fd;font-size:14px;text-decoration:none;font-weight:600;"><i class="fas fa-arrow-left" style="margin-right:6px;"></i>Go to Super Admin Login</a>
        </div>
    </div>

</div>
</body>
</html>
