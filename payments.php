<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

// Get payment statistics
$stats = $pdo->query("
    SELECT 
        SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as daily_amount,
        SUM(CASE WHEN YEARWEEK(payment_date) = YEARWEEK(CURDATE()) THEN amount ELSE 0 END) as weekly_amount,
        SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) THEN amount ELSE 0 END) as monthly_amount
    FROM payments
    WHERE status = 'completed'
")->fetch();

// Get checked payments (assuming 'checked' means 'completed' in your system)
$payments = $pdo->query("
    SELECT p.*, c.full_name, c.phone 
    FROM payments p 
    LEFT JOIN clients c ON p.client_id = c.id 
    WHERE p.status = 'completed'
    ORDER BY p.payment_date DESC 
    LIMIT 100
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h1 style="margin: 0; color: #333;"><i class="fas fa-money-bill-wave me-3"></i>Payments</h1>
        </div>

        <!-- Daily, Weekly, Monthly Earnings Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #333;">Daily Earnings</h2>
                <div style="font-size: 24px; font-weight: bold; color: #333;">Ksh <?php echo number_format($stats['daily_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">Total earnings today</div>
            </div>
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #333;">Weekly Earnings</h2>
                <div style="font-size: 24px; font-weight: bold; color: #333;">Ksh <?php echo number_format($stats['weekly_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">Total earnings this week</div>
            </div>
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #333;">Monthly Earnings</h2>
                <div style="font-size: 24px; font-weight: bold; color: #333;">Ksh <?php echo number_format($stats['monthly_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">Total earnings this month</div>
            </div>
        </div>

        <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

        <!-- Checked Payments Section -->
        <div style="margin-bottom: 20px;">
            <h2 style="margin: 0; color: #333; font-size: 20px;">Checked payments</h2>
        </div>

        <!-- Checkbox and Table Headers -->
        <div style="display: grid; grid-template-columns: 40px 1fr 1fr 1fr 1fr 1fr 1fr; gap: 10px; padding: 10px 15px; background: #f8f9fa; border-radius: 5px 5px 0 0; font-weight: 600; color: #333;">
            <div style="display: flex; align-items: center;">
                <input type="checkbox" style="margin: 0;">
            </div>
            <div>User</div>
            <div>Phone</div>
            <div>Receipt No.</div>
            <div>Amount</div>
            <div>Checked</div>
            <div>Paid At</div>
            <div>Disbursement</div>
        </div>

        <!-- Payments Table -->
        <div style="background: white; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
            <?php if (count($payments) > 0): ?>
                <?php foreach ($payments as $p): ?>
                    <div style="display: grid; grid-template-columns: 40px 1fr 1fr 1fr 1fr 1fr 1fr; gap: 10px; padding: 15px; border-bottom: 1px solid #eee; align-items: center;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                        <div style="display: flex; align-items: center;">
                            <input type="checkbox" style="margin: 0;">
                        </div>
                        <div><?php echo htmlspecialchars($p['full_name'] ?? 'Unknown'); ?></div>
                        <div><?php echo htmlspecialchars($p['phone'] ?? 'N/A'); ?></div>
                        <div><?php echo htmlspecialchars($p['transaction_id'] ?? 'N/A'); ?></div>
                        <div style="font-weight: 600;">Ksh <?php echo number_format($p['amount'], 2); ?></div>
                        <div>
                            <span style="padding: 3px 8px; background: #d4edda; color: #155724; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                Yes
                            </span>
                        </div>
                        <div><?php echo date('d.m.Y H:i', strtotime($p['payment_date'])); ?></div>
                        <div>
                            <span style="padding: 3px 8px; background: #e7f3ff; color: #004085; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                Direct
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 30px; text-align: center; color: #666;">
                    No completed payments found.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>