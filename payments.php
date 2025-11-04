<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

// Tab/filter handling and safe counts
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'checked';

// Counts
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'completed'");
    $stmt->execute(); 
    $checkedCount = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status <> 'completed' OR status IS NULL");
    $stmt->execute(); 
    $uncheckedCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $checkedCount = $uncheckedCount = 0;
}

// Build query based on filter
$where = "WHERE p.status = 'completed'";
if ($filter === 'unchecked') {
    $where = "WHERE p.status <> 'completed' OR p.status IS NULL";
}

try {
    $payments = $pdo->query("
        SELECT p.*, c.full_name, c.phone, c.id AS client_id
        FROM payments p
        LEFT JOIN clients c ON p.client_id = c.id
        $where
        ORDER BY p.payment_date DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payments = [];
}

// If a payment has no client_id but has a phone, try to resolve to an existing client by phone
if (!empty($payments)) {
    $findClientByPhone = $pdo->prepare("SELECT id, full_name FROM clients WHERE phone = ? LIMIT 1");
    foreach ($payments as &$p) {
        if (empty($p['client_id']) && !empty($p['phone'])) {
            try {
                $findClientByPhone->execute([$p['phone']]);
                $c = $findClientByPhone->fetch(PDO::FETCH_ASSOC);
                if ($c) {
                    $p['client_id'] = $c['id'];
                    $p['full_name'] = $c['full_name'];
                }
            } catch (Exception $ignored) {}
        }
    }
    unset($p);
}

// Get stats
$stats = $pdo->query("
    SELECT 
        SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN amount ELSE 0 END) as daily_amount,
        SUM(CASE WHEN YEARWEEK(payment_date) = YEARWEEK(CURDATE()) THEN amount ELSE 0 END) as weekly_amount,
        SUM(CASE WHEN MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) THEN amount ELSE 0 END) as monthly_amount
    FROM payments
")->fetch(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">

        <!-- Page title - simplified to match target design -->
        <div style="margin-bottom: 30px;">
            <h1 style="margin: 0 0 8px 0; font-size: 28px; color: #222; font-weight: 700;">Payments</h1>
        </div>

        <!-- Stats cards - simplified teal design -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: #48d1cc; padding: 20px; border-radius: 8px; color: #fff;">
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Daily Earnings</div>
                <div style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Ksh <?php echo number_format($stats['daily_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Total earnings today</div>
            </div>
            <div style="background: #48d1cc; padding: 20px; border-radius: 8px; color: #fff;">
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Weekly Earnings</div>
                <div style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Ksh <?php echo number_format($stats['weekly_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Total earnings this week</div>
            </div>
            <div style="background: #48d1cc; padding: 20px; border-radius: 8px; color: #fff;">
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Monthly Earnings</div>
                <div style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Ksh <?php echo number_format($stats['monthly_amount'] ?? 0, 2); ?></div>
                <div style="font-size: 12px; opacity: 0.9;">Total earnings this month</div>
            </div>
        </div>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">

        <!-- Section header for checked payments -->
        <div style="margin-bottom: 20px;">
            <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #222; font-weight: 600;">Checked payments</h2>
        </div>

        <!-- Payments Table - simplified to match target design -->
        <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            
            <!-- Table Header -->
            <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 12px; padding: 15px 20px; background: #f8f9fa; border-bottom: 1px solid #e9ecef; font-weight: 600; color: #495057; font-size: 14px;">
                <div>User</div>
                <div>Phone</div>
                <div>Receipt No.</div>
                <div style="text-align: right;">Amount</div>
                <div>Checked</div>
                <div>Paid At</div>
                <div>Disbursement</div>
            </div>

            <!-- Table Body -->
            <div id="paymentsBody">
                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $p): ?>
                        <div class="payment-row" 
                             style="display: grid; grid-template-columns: 1.2fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #f8f9fa; align-items: center; font-size: 14px; color: #495057;">
                            
                            <!-- User Column -->
                            <div>
                                <?php if (!empty($p['client_id'])): ?>
                                    <div style="font-weight: 600; color: #495057;">
                                        <?php echo htmlspecialchars($p['full_name'] ?? 'Unknown'); ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-weight: 600; color: #495057;">
                                        <?php echo htmlspecialchars($p['full_name'] ?? 'Unknown'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Phone Column -->
                            <div>
                                <?php if (!empty($p['phone'])): ?>
                                    <?php echo htmlspecialchars($p['phone']); ?>
                                <?php else: ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </div>

                            <!-- Receipt No. Column -->
                            <div>
                                <?php 
                                    $receipt = $p['mpesa_code'] ?? $p['transaction_id'] ?? $p['receipt_no'] ?? '-';
                                ?>
                                <span style="font-family: monospace; color: #495057;"><?php echo htmlspecialchars($receipt); ?></span>
                            </div>

                            <!-- Amount Column -->
                            <div style="text-align: right; font-weight: 600; font-family: monospace;">
                                Ksh <?php echo number_format((float)($p['amount'] ?? 0), 2); ?>
                            </div>

                            <!-- Checked Column -->
                            <div>
                                <?php if (isset($p['status']) && $p['status'] === 'completed'): ?>
                                    <span style="color: #28a745; font-weight: 600;">Yes</span>
                                <?php else: ?>
                                    <span style="color: #dc3545; font-weight: 600;">No</span>
                                <?php endif; ?>
                            </div>

                            <!-- Paid At Column -->
                            <div style="font-family: monospace;">
                                <?php echo !empty($p['payment_date']) ? date('d.m.Y H:i', strtotime($p['payment_date'])) : '-'; ?>
                            </div>

                            <!-- Disbursement Column -->
                            <div>
                                <span style="color: #007bff;">[<?php echo htmlspecialchars($p['disbursement_method'] ?? $p['method'] ?? 'Direct'); ?>]</span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #6c757d;">
                        No payments found for this filter.
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Simple search functionality -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('paymentsSearch');
            const rows = Array.from(document.querySelectorAll('.payment-row'));
            
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase().trim();
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(query) ? 'grid' : 'none';
                    });
                });
            }
        });
        </script>

    </div>
</div>

<?php include 'includes/footer.php'; ?>

<style>
.main-content-wrapper { 
    margin-left: 260px; 
    background: #f8f9fa; 
    min-height: 100vh; 
}

@media (max-width: 900px) { 
    .main-content-wrapper {
        margin-left: 0; 
    } 
}

.payment-row:hover {
    background-color: #f8f9fa;
}

/* Ensure proper alignment for all table cells */
.payment-row > div {
    display: flex;
    align-items: center;
}
</style>