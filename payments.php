<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

$payments = $pdo->query("
    SELECT p.*, c.full_name, c.email 
    FROM payments p 
    LEFT JOIN clients c ON p.client_id = c.id 
    ORDER BY p.payment_date DESC 
    LIMIT 100
")->fetchAll();

$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_payments,
        SUM(amount) as total_amount,
        SUM(CASE WHEN status='completed' THEN amount ELSE 0 END) as completed_amount,
        SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as monthly_amount
    FROM payments
")->fetch();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h1 style="margin: 0; color: #333;"><i class="fas fa-money-bill-wave me-3"></i>Payment History</h1>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;"><i class="fas fa-list me-2"></i>Total Payments</div>
                <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($stats['total_payments']); ?></div>
            </div>
            <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;"><i class="fas fa-dollar-sign me-2"></i>Total Revenue</div>
                <div style="font-size: 32px; font-weight: bold;">KES <?php echo number_format($stats['total_amount'], 2); ?></div>
            </div>
            <div style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;"><i class="fas fa-check-circle me-2"></i>Completed</div>
                <div style="font-size: 32px; font-weight: bold;">KES <?php echo number_format($stats['completed_amount'], 2); ?></div>
            </div>
            <div style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;"><i class="fas fa-calendar me-2"></i>This Month</div>
                <div style="font-size: 32px; font-weight: bold;">KES <?php echo number_format($stats['monthly_amount'], 2); ?></div>
            </div>
        </div>

        <div style="background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">ID</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Client</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Amount</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Method</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Transaction</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Date</th>
                        <th style="padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr style="border-bottom: 1px solid #eee;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                            <td style="padding: 15px;"><strong>#<?php echo $p['id']; ?></strong></td>
                            <td style="padding: 15px;">
                                <div style="font-weight: 600;">&nbsp;<?php echo htmlspecialchars($p['full_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 12px; color: #666;">&nbsp;<?php echo htmlspecialchars($p['email'] ?? ''); ?></div>
                            </td>
                            <td style="padding: 15px; font-weight: 600; color: #28a745;">KES <?php echo number_format($p['amount'], 2); ?></td>
                            <td style="padding: 15px;"><span style="padding: 5px 10px; background: #e7f3ff; color: #004085; border-radius: 4px; font-size: 12px; font-weight: 600;"><?php echo strtoupper($p['payment_method']); ?></span></td>
                            <td style="padding: 15px; color: #666;"><?php echo htmlspecialchars($p['transaction_id'] ?? 'N/A'); ?></td>
                            <td style="padding: 15px;">&nbsp;<?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                            <td style="padding: 15px;">
                                <span style="padding: 5px 10px; background: <?php echo $p['status']=='completed'?'#d4edda':'#fff3cd'; ?>; color: <?php echo $p['status']=='completed'?'#155724':'#856404'; ?>; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                    <?php echo ucfirst($p['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
