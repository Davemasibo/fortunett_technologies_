<?php
/**
 * One-time migration runner — delete this file after running.
 * Adds service_types and hotspot_shared_users columns to mikrotik_routers.
 */
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

// Only super-admins or tenant admins should run this
if (!isset($_SESSION['user_id'])) { die('Unauthorized'); }

$results = [];

$statements = [
    "ALTER TABLE `mikrotik_routers` ADD COLUMN `service_types` VARCHAR(20) DEFAULT NULL COMMENT 'Comma-separated: pppoe, hotspot, or pppoe,hotspot'",
    "ALTER TABLE `mikrotik_routers` ADD COLUMN `hotspot_shared_users` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=unlimited sessions, 1=one session per user'",
];

foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['sql' => $sql, 'ok' => true, 'msg' => 'OK'];
    } catch (PDOException $e) {
        // 1060 = Duplicate column — already exists, that's fine
        $ok = ($e->getCode() === '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false);
        $results[] = ['sql' => $sql, 'ok' => $ok, 'msg' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Migration Runner</title>
<style>body{font-family:monospace;background:#111;color:#e2e2e0;padding:30px;}
.ok{color:#34d399;} .err{color:#f87171;} pre{background:#1c1c1b;padding:12px;border-radius:6px;font-size:13px;}
</style></head>
<body>
<h2>Migration: router service config</h2>
<?php foreach ($results as $r): ?>
<p class="<?php echo $r['ok'] ? 'ok' : 'err'; ?>">
    <?php echo $r['ok'] ? '✓' : '✗'; ?> <?php echo htmlspecialchars($r['msg']); ?>
</p>
<pre><?php echo htmlspecialchars($r['sql']); ?></pre>
<?php endforeach; ?>
<p style="color:rgba(255,255,255,.4);margin-top:30px;">Delete this file after migration is complete.</p>
</body>
</html>
