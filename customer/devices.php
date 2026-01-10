<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

// Fetch active sessions for this client
$stmt = $pdo->prepare("
    SELECT * FROM customer_sessions 
    WHERE client_id = ? 
    ORDER BY last_activity DESC
");
$stmt->execute([$customer['id']]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="dashboard-container">
    <div class="section-header">
        <h2><i class="fas fa-laptop"></i> Connected Devices</h2>
        <p>Manage devices connected to your account</p>
    </div>
    
    <div class="devices-card">
        <?php if (count($sessions) > 0): ?>
            <div class="table-responsive">
                <table class="devices-table">
                    <thead>
                        <tr>
                            <th>Device / IP</th>
                            <th>MAC Address</th>
                            <th>Status</th>
                            <th>Last Seen</th>
                            <th>Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): 
                            $isCurrent = ($session['session_token'] === ($_COOKIE['customer_session'] ?? ''));
                            $isActive = strtotime($session['expires_at']) > time();
                        ?>
                        <tr class="<?php echo $isCurrent ? 'current-device' : ''; ?>">
                            <td>
                                <div class="device-info">
                                    <div class="device-icon">
                                        <i class="fas fa-<?php echo $isCurrent ? 'mobile-alt' : 'desktop'; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="device-ip"><?php echo htmlspecialchars($session['ip_address'] ?? 'Unknown IP'); ?></div>
                                        <?php if ($isCurrent): ?>
                                            <span class="badge-current">Current Device</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="mac-address"><?php echo htmlspecialchars($session['mac_address'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="status-badge active">Active</span>
                                <?php else: ?>
                                    <span class="status-badge expired">Expired</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, H:i', strtotime($session['last_activity'])); ?></td>
                            <td><?php echo date('M d, H:i', strtotime($session['expires_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-laptop-medical"></i>
                <h3>No Active Devices</h3>
                <p>No devices are currently connected to this account.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.section-header {
    margin-bottom: 24px;
}
.section-header h2 {
    font-size: 24px;
    font-weight: 700;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 12px;
}
.section-header p {
    color: var(--gray-500);
    margin-top: 4px;
}

.devices-card {
    background: white;
    border-radius: 12px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
}

.table-responsive {
    overflow-x: auto;
}

.devices-table {
    width: 100%;
    border-collapse: collapse;
}

.devices-table th {
    text-align: left;
    padding: 16px 24px;
    background: var(--gray-50);
    color: var(--gray-500);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    border-bottom: 1px solid var(--gray-200);
}

.devices-table td {
    padding: 16px 24px;
    border-bottom: 1px solid var(--gray-100);
    color: var(--gray-700);
    font-size: 14px;
}

.devices-table tr:last-child td {
    border-bottom: none;
}

.device-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.device-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--gray-100);
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.current-device .device-icon {
    background: #EFF6FF;
    color: var(--primary);
}

.badge-current {
    font-size: 11px;
    color: var(--primary);
    background: #EFF6FF;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 500;
}

.mac-address {
    font-family: monospace;
    color: var(--gray-600);
}

.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.expired {
    background: #FEE2E2;
    color: #991B1B;
}

.current-device {
    background-color: #F8FAFC;
}
</style>

<?php include 'includes/footer.php'; ?>
