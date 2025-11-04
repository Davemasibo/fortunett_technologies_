<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$servers = $pdo->query("SELECT * FROM mikrotik_servers ORDER BY created_at DESC")->fetchAll();

// Save handler
$save_message = $save_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mikrotik'])) {
    $host = trim($_POST['mk_host'] ?? '');
    $port = (int)($_POST['mk_port'] ?? 8728) ?: 8728;
    $user = trim($_POST['mk_user'] ?? '');
    $pass = trim($_POST['mk_pass'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mikrotik_settings WHERE id = 1");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO mikrotik_settings (id, host, username, password, port, updated_at) VALUES (1, ?, ?, ?, ?, NOW())");
            $ins->execute([$host, $user, $pass, $port]);
        } else {
            $upd = $pdo->prepare("UPDATE mikrotik_settings SET host = ?, username = ?, password = ?, port = ?, updated_at = NOW() WHERE id = 1");
            $upd->execute([$host, $user, $pass, $port]);
        }
        // reload to display saved values
        header('Location: mikrotik.php?saved=1');
        exit;
    } catch (Exception $e) {
        $save_error = "Failed to save MikroTik settings: " . $e->getMessage();
        error_log($save_error);
    }
}

// Load existing settings
$mk_settings = null;
try {
    $stmt = $pdo->query("SELECT * FROM mikrotik_settings WHERE id = 1 LIMIT 1");
    if ($stmt) $mk_settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mk_settings = null;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 20px;">
            <h2 style="margin:0 0 8px 0;">MikroTik Settings</h2>
            <p style="margin:0;color:#666;">Configure credentials used by the dashboard to fetch live RouterOS metrics. For production, consider encrypting credentials or using environment variables.</p>
        </div>

        <?php if (!empty($_GET['saved'])): ?>
            <div style="padding:12px;background:#d4edda;color:#155724;border-radius:8px;margin-bottom:12px;">Settings saved successfully.</div>
        <?php endif; ?>

        <?php if (!empty($save_error)): ?>
            <div style="padding:12px;background:#f8d7da;color:#721c24;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($save_error); ?></div>
        <?php endif; ?>

        <form method="POST" style="max-width:540px;">
            <div style="margin-bottom:12px;">
                <label class="form-label">Host / IP</label>
                <input name="mk_host" class="form-control" value="<?php echo htmlspecialchars($mk_settings['host'] ?? ''); ?>" placeholder="192.168.88.1">
            </div>
            <div style="margin-bottom:12px;">
                <label class="form-label">Port</label>
                <input name="mk_port" class="form-control" value="<?php echo htmlspecialchars($mk_settings['port'] ?? 8728); ?>" placeholder="8728">
            </div>
            <div style="margin-bottom:12px;">
                <label class="form-label">Username</label>
                <input name="mk_user" class="form-control" value="<?php echo htmlspecialchars($mk_settings['username'] ?? ''); ?>">
            </div>
            <div style="margin-bottom:12px;">
                <label class="form-label">Password</label>
                <input name="mk_pass" type="password" class="form-control" value="<?php echo htmlspecialchars($mk_settings['password'] ?? ''); ?>">
            </div>
            <div style="margin-top:16px; display:flex; gap:10px;">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <button type="submit" name="save_mikrotik" class="btn btn-primary">Save MikroTik Settings</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>


