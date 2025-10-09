<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$servers = $pdo->query("SELECT * FROM mikrotik_servers ORDER BY created_at DESC")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h1 style="margin: 0; color: #333;"><i class="fas fa-server me-3"></i>MikroTik Servers</h1>
        </div>

        <div style="background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 15px; text-align: left;">Name</th>
                        <th style="padding: 15px; text-align: left;">IP Address</th>
                        <th style="padding: 15px; text-align: left;">Port</th>
                        <th style="padding: 15px; text-align: left;">Status</th>
                        <th style="padding: 15px; text-align: left;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $s): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 15px;"><strong><?php echo htmlspecialchars($s['name']); ?></strong></td>
                        <td style="padding: 15px;"><?php echo htmlspecialchars($s['ip_address']); ?></td>
                        <td style="padding: 15px;"><?php echo (int)$s['port']; ?></td>
                        <td style="padding: 15px;">
                            <span style="padding: 5px 10px; border-radius:4px; font-size:12px; background: <?php echo $s['status']==='active'?'#d4edda':'#f8d7da'; ?>; color: <?php echo $s['status']==='active'?'#155724':'#721c24'; ?>;">
                                <?php echo ucfirst($s['status']); ?>
                            </span>
                        </td>
                        <td style="padding: 15px;">
                            <a href="payments.php" style="text-decoration:none;" class="btn btn-sm btn-outline-success"><i class="fas fa-receipt me-1"></i>View Payments</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($servers)): ?>
                    <tr>
                        <td colspan="5" style="padding: 30px; text-align:center; color:#666;">No MikroTik servers configured.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>


