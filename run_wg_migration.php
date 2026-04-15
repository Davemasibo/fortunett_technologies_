<?php
/**
 * Run WireGuard migration + update rb951 VPN IP.
 * DELETE THIS FILE after running.
 */
require_once 'includes/db_master.php';

$results = [];

$steps = [
    'Add vpn_ip column' =>
        "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS vpn_ip VARCHAR(15) DEFAULT NULL",

    'Add wg_public_key column' =>
        "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS wg_public_key VARCHAR(64) DEFAULT NULL",

    'Add wg_private_key column' =>
        "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS wg_private_key VARCHAR(64) DEFAULT NULL",

    'Add provision_id column' =>
        "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS provision_id VARCHAR(36) DEFAULT NULL",

    'Set rb951 VPN IP (id=7)' =>
        "UPDATE mikrotik_routers SET vpn_ip='10.200.200.7', wg_public_key='SSZz2OMQWci5SjUFychc97rugTlqRSKAHC2xzYOhcgk=' WHERE id=7",
];

foreach ($steps as $label => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['ok', $label];
    } catch (PDOException $e) {
        $results[] = ['err', $label . ': ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>WireGuard Migration</title>
<style>
  body { font-family: monospace; background: #111; color: #eee; padding: 30px; }
  .ok  { color: #34d399; } .err { color: #f87171; }
  h2   { color: #60a5fa; }
</style>
</head>
<body>
<h2>WireGuard Migration</h2>
<?php foreach ($results as [$status, $msg]): ?>
  <p class="<?= $status ?>">
    <?= $status === 'ok' ? '✓' : '✗' ?> <?= htmlspecialchars($msg) ?>
  </p>
<?php endforeach; ?>
<hr>
<p style="color:#fbbf24">✓ Done. <strong>Delete this file from the server now.</strong></p>
<p>Next: go to the Mikrotik page → click <strong>Test Connection</strong> on rb951.</p>
</body>
</html>
