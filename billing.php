<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get billing data
try {
    // Create billing table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing_expenses (
        id INT PRIMARY KEY AUTO_INCREMENT,
        amount DECIMAL(10,2),
        message TEXT,
        payment_date DATE,
        invoice_number VARCHAR(100),
        status VARCHAR(20) DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Get all expenses
    $expenses = $pdo->query("SELECT * FROM billing_expenses ORDER BY payment_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $total_expenses = array_sum(array_column($expenses, 'amount'));
    $current_month_expenses = 0;
    
    foreach ($expenses as $expense) {
        if (date('Y-m', strtotime($expense['payment_date'])) === date('Y-m')) {
            $current_month_expenses += $expense['amount'];
        }
    }
    
} catch (Exception $e) {
    $expenses = [];
    $total_expenses = 0;
    $current_month_expenses = 0;
}

// Get subscription info (mock data - replace with actual subscription logic)
$subscription_expires = '2026-01-06';
$subscription_amount = 625.00;
$subscription_status = 'active';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .billing-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .billing-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .billing-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0 0 24px 0;
    }
    
    /* License Alert */
    .license-alert {
        background: white;
        border-radius: 10px;
        padding: 20px 24px;
        margin-bottom: 24px;
        border: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .alert-content {
        flex: 1;
    }
    
    .alert-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .alert-message {
        font-size: 14px;
        color: #6B7280;
    }
    
    .view-invoice-btn {
        padding: 10px 20px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    /* Search Bar */
    .search-bar {
        background: white;
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 20px;
        border: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .search-input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .filter-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #D1D5DB;
        background: white;
        color: #6B7280;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
    
    /* Expenses Table */
    .expenses-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .expenses-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .expenses-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .expenses-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .expenses-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .expenses-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .status-badge.completed {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .action-btn {
        color: #6B7280;
        font-size: 18px;
        cursor: pointer;
        border: none;
        background: none;
    }
    
    .pagination {
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-top: 1px solid #E5E7EB;
    }
    
    .pagination-info {
        font-size: 13px;
        color: #6B7280;
    }
    
    .pagination-controls {
        display: flex;
        gap: 8px;
    }
    
    .page-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #E5E7EB;
        background: white;
        color: #374151;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 14px;
    }
    
    .page-btn.active {
        background: #F59E0B;
        color: white;
        border-color: #F59E0B;
    }
</style>

<div class="main-content-wrapper">
    <div class="billing-container">
        <!-- Header -->
        <div>
            <h1 class="billing-title">Fortunnet Licence</h1>
            <p class="billing-subtitle">Manage your subscription and view billing history</p>
        </div>

        <!-- License Alert -->
        <div class="license-alert">
            <div class="alert-content">
                <div class="alert-title">Fortunnet Licence</div>
                <div class="alert-message">
                    Your subscription expires on <?php echo date('d.m.Y', strtotime($subscription_expires)); ?> at 09:22 AM. 
                    Please renew your subscription before it expires.
                </div>
            </div>
            <a href="#" class="view-invoice-btn" onclick="viewInvoice(); return false;">
                <i class="fas fa-file-invoice"></i>
                View Invoice & Payment Details
            </a>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search" style="color: #9CA3AF;"></i>
            <input type="text" class="search-input" placeholder="Search">
            <button class="filter-icon">
                <i class="fas fa-th"></i>
            </button>
        </div>

        <!-- Expenses Table -->
        <div class="expenses-section">
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>AMOUNT</th>
                        <th>MESSAGE</th>
                        <th>PAYMENT DATE</th>
                        <th>INVOICE</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                    <!-- Sample data if no expenses exist -->
                    <tr>
                        <td>KES 625.00</td>
                        <td>The service request is processed successfully.</td>
                        <td>01.12.2025 13:27</td>
                        <td>INV-ecolandattic-20251206</td>
                        <td>
                            <button class="action-btn" title="More options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>KES 705.00</td>
                        <td>The service request is processed successfully.</td>
                        <td>04.11.2025 09:35</td>
                        <td>INV-ecolandattic-20251106</td>
                        <td>
                            <button class="action-btn" title="More options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>KES 500.00</td>
                        <td>The service request is processed successfully.</td>
                        <td>06.10.2025 15:26</td>
                        <td>INV-ecolandattic-20251006</td>
                        <td>
                            <button class="action-btn" title="More options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td>KES <?php echo number_format($expense['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($expense['message']); ?></td>
                        <td><?php echo date('d.m.Y H:i', strtotime($expense['payment_date'])); ?></td>
                        <td><?php echo htmlspecialchars($expense['invoice_number']); ?></td>
                        <td>
                            <button class="action-btn" title="More options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <div class="pagination-info">
                    Showing 1 to 3 of 3 results
                </div>
                <div class="pagination-controls">
                    <select style="padding: 6px 10px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
                        <option>Per page: 10</option>
                        <option>Per page: 25</option>
                        <option>Per page: 50</option>
                    </select>
                    <button class="page-btn active">1</button>
                    <button class="page-btn">2</button>
                    <button class="page-btn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div id="invoiceModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 10px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <!-- Modal Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #E5E7EB; display: flex; align-items: center; justify-content: space-between;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">View Invoice & Payment Details</h3>
            <button onclick="closeInvoice()" style="width: 32px; height: 32px; border-radius: 6px; border: none; background: #F3F4F6; color: #6B7280; cursor: pointer; font-size: 20px;">&times;</button>
        </div>
        
        <!-- Invoice Content -->
        <div style="padding: 32px 40px;">
            <!-- Header Section -->
            <div style="display: flex; justify-content: space-between; margin-bottom: 32px;">
                <div>
                    <h2 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #F59E0B;">Fortunnet Technologies Ltd.</h2>
                    <p style="margin: 0; font-size: 13px; color: #6B7280;">sales@fortunnet.com</p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;">+254 712 234 193</p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;">2nd Ngong Avenue, I & M Bank Building, 5th Floor</p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;">Upper Hill, Nairobi, Kenya</p>
                </div>
                <div style="text-align: right;">
                    <h1 style="margin: 0 0 12px 0; font-size: 32px; font-weight: 300; color: #111827;">INVOICE</h1>
                    <p style="margin: 0; font-size: 13px; color: #6B7280;"><strong>Invoice #:</strong> INV-ecolandattic-<?php echo date('Ymd'); ?></p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;"><strong>Date:</strong> <?php echo date('M d, Y'); ?></p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;"><strong>Due Date:</strong> <?php echo date('M d, Y', strtotime('+30 days')); ?></p>
                </div>
            </div>
            
            <!-- Bill To & Status -->
            <div style="display: flex; justify-content: space-between; margin-bottom: 32px;">
                <div>
                    <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Bill To</h4>
                    <p style="margin: 0; font-size: 14px; font-weight: 600; color: #111827;">EcolandAttic</p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;">kelvinmuruts82@gmail.com</p>
                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #6B7280;">+254791082822</p>
                </div>
                <div style="text-align: right;">
                    <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Status</h4>
                    <span style="background: #FEF3C7; color: #92400E; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 600;">PENDING</span>
                </div>
            </div>
            
            <!-- Invoice Items Table -->
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
                <thead>
                    <tr style="border-bottom: 2px solid #E5E7EB;">
                        <th style="padding: 12px 0; text-align: left; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Description</th>
                        <th style="padding: 12px 0; text-align: right; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Price</th>
                        <th style="padding: 12px 0; text-align: center; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Quantity</th>
                        <th style="padding: 12px 0; text-align: right; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid #F3F4F6;">
                        <td style="padding: 16px 0; font-size: 14px; color: #111827;">PPPoE Users: 20</td>
                        <td style="padding: 16px 0; text-align: right; font-size: 14px; color: #111827;">Ksh 500</td>
                        <td style="padding: 16px 0; text-align: center; font-size: 14px; color: #111827;">1</td>
                        <td style="padding: 16px 0; text-align: right; font-size: 14px; color: #111827;">Ksh 500</td>
                    </tr>
                    <tr style="border-bottom: 1px solid #F3F4F6;">
                        <td style="padding: 16px 0; font-size: 14px; color: #111827;">Hotspot Service Fee (3% of Ksh120 revenue)</td>
                        <td style="padding: 16px 0; text-align: right; font-size: 14px; color: #111827;">Ksh 33</td>
                        <td style="padding: 16px 0; text-align: center; font-size: 14px; color: #111827;">1</td>
                        <td style="padding: 16px 0; text-align: right; font-size: 14px; color: #111827;">Ksh 33</td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Totals -->
            <div style="display: flex; justify-content: flex-end; margin-bottom: 32px;">
                <div style="width: 300px;">
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #F3F4F6;">
                        <span style="font-size: 14px; color: #6B7280;">Service Subtotal:</span>
                        <span style="font-size: 14px; font-weight: 600; color: #111827;">Ksh 533</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-top: 2px solid #E5E7EB;">
                        <span style="font-size: 16px; font-weight: 700; color: #F59E0B;">Total Due:</span>
                        <span style="font-size: 16px; font-weight: 700; color: #F59E0B;">Ksh 533</span>
                    </div>
                </div>
            </div>
            
            <!-- Payment Methods -->
            <div style="margin-bottom: 32px;">
                <h4 style="margin: 0 0 16px 0; font-size: 12px; font-weight: 700; color: #F59E0B; text-transform: uppercase;">Payment Methods</h4>
                <div style="background: linear-gradient(135deg, #06B6D4 0%, #0891B2 100%); border-radius: 8px; padding: 20px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px;">
                            <i class="fas fa-shield-alt" style="font-size: 24px; color: white;"></i>
                        </div>
                        <div>
                            <h5 style="margin: 0 0 4px 0; font-size: 16px; font-weight: 600; color: white;">Pay Securely with Paystack</h5>
                            <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.9);">M-Pesa • Visa • Mastercard • Bank Transfer</p>
                        </div>
                    </div>
                    <button style="background: white; color: #0891B2; border: none; padding: 10px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
                        Pay Now
                    </button>
                </div>
                <div style="display: flex; align-items: center; justify-content: center; gap: 24px; margin-top: 16px;">
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #10B981;">
                        <i class="fas fa-check-circle"></i>
                        <span>SSL Encrypted</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #10B981;">
                        <i class="fas fa-check-circle"></i>
                        <span>Bank-Level Security</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #10B981;">
                        <i class="fas fa-check-circle"></i>
                        <span>Instant Processing</span>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div style="text-align: center; padding-top: 24px; border-top: 1px solid #E5E7EB;">
                <p style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #F59E0B;">Thank you for your business!</p>
                <p style="margin: 0; font-size: 12px; color: #6B7280;">For billing inquiries, please contact sales@fortunnet.com</p>
                <p style="margin: 4px 0 0 0; font-size: 12px; color: #6B7280;">Access your account and manage your services by logging in at: https://ecolandattic.fortunnet.com/login</p>
                <p style="margin: 8px 0 0 0; font-size: 11px; color: #9CA3AF;">© 2025 Fortunnet Technologies Ltd. All rights reserved.</p>
            </div>
        </div>
    </div>
</div>

<script>
function viewInvoice() {
    document.getElementById('invoiceModal').style.display = 'flex';
}

function closeInvoice() {
    document.getElementById('invoiceModal').style.display = 'none';
}

function downloadInvoice() {
    alert('Invoice download functionality will be implemented soon!');
}

// Close modal when clicking outside
document.getElementById('invoiceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeInvoice();
    }
});
</script>

<?php include 'includes/footer.php'; ?>
