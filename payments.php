<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Calculate stats
try {
    // Total Revenue Today
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_today = (float)$stmt->fetchColumn();
    
    // Confirmed Transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $confirmed_transactions = (int)$stmt->fetchColumn();
    
    // Pending Payments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE (status != 'completed' OR status IS NULL) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $pending_payments = (int)$stmt->fetchColumn();
    
    // Failed Transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'failed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $failed_transactions = (int)$stmt->fetchColumn();
    
} catch (Exception $e) {
    $revenue_today = 0;
    $confirmed_transactions = 0;
    $pending_payments = 0;
    $failed_transactions = 0;
}

// Get filters
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_method = $_GET['method'] ?? '';

// Build Query
$query = "
    SELECT p.*, c.full_name, c.phone 
    FROM payments p
    LEFT JOIN clients c ON p.client_id = c.id
    WHERE DATE(p.payment_date) BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if ($search) {
    $query .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR p.transaction_id LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if ($filter_method && $filter_method !== 'All Methods') {
    if ($filter_method === 'M-Pesa') {
        // Assume non-empty ID is M-Pesa if not explicitly 'CASH' (simplified logic)
        $query .= " AND p.transaction_id IS NOT NULL AND p.transaction_id NOT LIKE 'CASH%'";
    } elseif ($filter_method === 'Cash') {
        $query .= " AND (p.transaction_id IS NULL OR p.transaction_id LIKE 'CASH%')";
    }
}

$query .= " ORDER BY p.payment_date DESC LIMIT 100";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $transactions = [];
}

// Get Clients for Dropdown
try {
    $stmt = $pdo->prepare("SELECT id, full_name, phone, subscription_plan FROM clients WHERE status = 'active' AND tenant_id = ? ORDER BY full_name ASC");
    $stmt->execute([$tenant_id]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .payments-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .payments-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .payments-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0 0 24px 0;
    }
    
    /* Stats Cards */
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }
    
    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        border: 1px solid #E5E7EB;
    }
    
    .stat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    
    .stat-icon.revenue {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .stat-icon.confirmed {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .stat-icon.pending {
        background: #FEF3C7;
        color: #92400E;
    }
    
    .stat-icon.failed {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .stat-label {
        font-size: 13px;
        color: #6B7280;
        font-weight: 500;
    }
    
    .stat-change {
        font-size: 12px;
        margin-top: 8px;
    }
    
    .stat-change.positive {
        color: #059669;
    }
    
    /* Filters */
    .filters-section {
        background: white;
        border-radius: 10px;
        padding: 20px 24px;
        margin-bottom: 20px;
        border: 1px solid #E5E7EB;
    }
    
    .filters-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 16px;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
        gap: 12px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-label {
        font-size: 12px;
        font-weight: 500;
        color: #6B7280;
    }
    
    .filter-input,
    .filter-select {
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .filter-btn {
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: 1px solid #D1D5DB;
        background: white;
        color: #374151;
    }
    
    .filter-btn.primary {
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
    }
    
    /* Transactions Table */
    .transactions-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .transactions-header {
        padding: 16px 24px;
        border-bottom: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .transactions-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
    }
    
    .transactions-actions {
        display: flex;
        gap: 8px;
    }
    
    .action-link {
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 13px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .action-link.refresh {
        color: #6B7280;
    }
    
    .action-link.manual {
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
    }
    
    .transactions-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .transactions-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .transactions-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .transactions-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .transactions-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .customer-name {
        font-weight: 500;
        color: #111827;
    }
    
    .customer-id {
        font-size: 12px;
        color: #9CA3AF;
    }
    
    .payment-method {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .payment-method.mpesa {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .payment-method.cash {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .transaction-id {
        font-family: monospace;
        font-size: 13px;
        color: #6B7280;
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
    
    .status-badge.pending {
        background: #FEF3C7;
        color: #92400E;
    }
    
    .status-badge.failed {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    .status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }
    
    .action-icons {
        display: flex;
        gap: 8px;
    }
    
    .action-icon {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid #E5E7EB;
        background: white;
        color: #6B7280;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 12px;
    }
    
    .action-icon:hover {
        background: #F3F4F6;
        color: #3B6EA5;
    }
    
    @media (max-width: 1024px) {
        .filters-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-content-wrapper">
    <div class="payments-container">
        <!-- Header -->
        <div class="payments-header">
            <h1 class="payments-title">Payment Processing</h1>
            <p class="payments-subtitle">Manage M-Pesa transactions, manual payments, and financial reconciliation</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon revenue">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-value">KSh <?php echo number_format($revenue_today, 2); ?></div>
                <div class="stat-label">Total Revenue Today</div>
                <div class="stat-change">
                    <span class="metric-period">Total Today</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon confirmed">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $confirmed_transactions; ?></div>
                <div class="stat-label">Confirmed Transactions</div>
                <div class="stat-change">
                    <span class="metric-period">Total Today</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $pending_payments; ?></div>
                <div class="stat-label">Pending Payments</div>
                <div class="stat-change">
                    <span class="metric-period">Awaiting Completion</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon failed">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $failed_transactions; ?></div>
                <div class="stat-label">Failed Transactions</div>
                <div class="stat-change">
                    <span class="metric-period">Needs Attention</span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <h3 class="filters-title">Filter Transactions</h3>
            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input type="text" name="search" class="filter-input" placeholder="Name, Phone, Trans ID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Date From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Date To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Payment Method</label>
                    <select name="method" class="filter-select">
                        <option>All Methods</option>
                        <option <?php echo $filter_method == 'M-Pesa' ? 'selected' : ''; ?>>M-Pesa</option>
                        <option <?php echo $filter_method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                    </select>
                </div>
                <button type="button" class="filter-btn" onclick="window.location.href='payments.php'">Reset Filters</button>
                <button type="submit" class="filter-btn primary">Apply Filters</button>
            </form>
        </div>

        <!-- Recent Transactions -->
        <div class="transactions-section">
            <div class="transactions-header">
                <h3 class="transactions-title">Recent Transactions</h3>
                <div class="transactions-actions">
                    <a href="#" class="action-link refresh">
                        <i class="fas fa-sync-alt"></i> Auto-refresh: 30s
                    </a>
                    <button class="action-link manual" onclick="openPaymentModal()">
                        <i class="fas fa-plus"></i> New Payment
                    </button>
                </div>
            </div>

            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>CUSTOMER ID</th>
                        <th>AMOUNT</th>
                        <th>PAYMENT METHOD</th>
                        <th>TRANSACTION ID</th>
                        <th>STATUS</th>
                        <th>TIMESTAMP</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): 
                        $status = strtolower($tx['status'] ?? 'pending');
                        // Infer method from transaction ID format
                        $txId = $tx['transaction_id'] ?? '';
                        $isMpesa = !empty($txId) && stripos($txId, 'CASH') === false; 
                        $method = $isMpesa ? 'mpesa' : 'cash';
                    ?>
                    <tr>
                        <td>
                            <div class="customer-name"><?php echo htmlspecialchars($tx['full_name'] ?? 'Unknown'); ?></div>
                            <div class="customer-id"><?php echo htmlspecialchars($tx['phone'] ?? 'N/A'); ?></div>
                        </td>
                        <td>
                            <strong>KSh <?php echo number_format($tx['amount'] ?? 0, 2); ?></strong>
                        </td>
                        <td>
                            <span class="payment-method <?php echo $method; ?>">
                                <i class="fas fa-<?php echo $method === 'mpesa' ? 'mobile-alt' : 'money-bill'; ?>"></i>
                                <?php echo $method === 'mpesa' ? 'M-Pesa C2B' : 'Manual - Cash'; ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                                $displayId = $tx['transaction_id'] ?? 'N/A';
                                // Hide CheckoutRequestID (long string starting with ws_) for pending/processing
                                if ($status === 'pending' || strpos($displayId, 'ws_') === 0) {
                                    echo '<span style="font-style:italic; color:#9CA3AF;">Processing...</span>';
                                } else {
                                    echo '<span class="transaction-id">' . htmlspecialchars($displayId) . '</span>';
                                }
                            ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $status; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td><?php echo date('d/m/Y, H:i', strtotime($tx['payment_date'] ?? 'now')); ?></td>
                        <td>
                        <td>
                            <div class="action-icons" style="justify-content: center;">
                                <button class="action-icon" title="View" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($tx), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-eye"></i></button>
                                <button class="action-icon" title="Print Receipt" onclick="printReceipt(<?php echo htmlspecialchars(json_encode($tx), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-print"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 500px; border-radius: 12px; padding: 32px; position: relative;">
        <button onclick="closePaymentModal()" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 20px; cursor: pointer; color: #6B7280;">&times;</button>
        
        <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0 0 24px 0;">New Payment</h2>
        
        <form id="paymentForm" onsubmit="handlePaymentSubmit(event)">
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Select Customer</label>
                <select name="client_id" id="payClient" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;" onchange="updatePhone(this)">
                    <option value="">Select a Customer</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?php echo $c['id']; ?>" data-phone="<?php echo htmlspecialchars($c['phone']); ?>">
                        <?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['subscription_plan']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Phone Number (M-Pesa)</label>
                <input type="text" name="phone" id="payPhone" required placeholder="2547..." style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Amount (KES)</label>
                <input type="number" name="amount" id="payAmount" required placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
            </div>
            
            <div class="form-group" style="margin-bottom: 24px;">
                 <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Payment Method</label>
                 <div style="display: flex; gap: 12px;">
                     <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px; flex: 1; justify-content: center;">
                         <input type="radio" name="method" value="mpesa" checked> M-Pesa STK
                     </label>
                     <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px; flex: 1; justify-content: center;">
                         <input type="radio" name="method" value="cash"> Manual Cash
                     </label>
                 </div>
            </div>

            <button type="submit" style="width: 100%; padding: 12px; background: #10B981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 16px;">
                Process Payment
            </button>
        </form>
    </div>
</div>

<script>
function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function updatePhone(select) {
    const option = select.options[select.selectedIndex];
    if (option.dataset.phone) {
        document.getElementById('payPhone').value = option.dataset.phone;
    }
}

function handlePaymentSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const method = formData.get('method');
    
    // Determine URL based on method
    let url = 'api/mpesa/stk_push.php';
    if (method === 'cash') {
        // Implement cash endpoint later, or use dummy
        alert('Manual cash entry not implemented yet. Using M-Pesa flow for demo.');
        // For now, let's just proceed with STK push logic which requires phone.
    }
    
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.textContent;
    btn.textContent = 'Processing...';
    btn.disabled = true;
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.Success || data.success) { // STK push returns Success logic?
             if (data.ResponseCode === '0') {
                 alert('STK Push Initiated. Check phone.');
             } else {
                 alert('Request Sent: ' + (data.CustomerMessage || data.message));
             }
             closePaymentModal();
             // Maybe refresh table?
             location.reload();
        } else {
            alert('Error: ' + (data.errorMessage || data.message));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error connecting to server');
    })
    .finally(() => {
        btn.textContent = originalText;
        btn.disabled = false;
    });
}
let currentViewTx = null;

function openViewModal(tx) {
    currentViewTx = tx;
    const content = document.getElementById('viewModalContent');
    const isMpesa = tx.transaction_id && !tx.transaction_id.toLowerCase().includes('cash');
    const method = isMpesa ? 'M-Pesa' : 'Cash';
    const statusClass = tx.status === 'completed' ? 'color: #059669; background: #D1FAE5;' : 'color: #D97706; background: #FEF3C7;';
    
    content.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase; margin-bottom: 4px;">Customer</label>
                <div style="font-size: 15px; font-weight: 600; color: #111827;">${tx.full_name || 'Unknown'}</div>
                <div style="font-size: 13px; color: #6B7280;">${tx.phone || 'N/A'}</div>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase; margin-bottom: 4px;">Amount</label>
                <div style="font-size: 24px; font-weight: 700; color: #111827;">KSh ${parseFloat(tx.amount).toLocaleString()}</div>
            </div>
        </div>
        
        <div style="background: #F3F4F6; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 13px; color: #6B7280;">Transaction ID</span>
                <span style="font-size: 13px; font-weight: 600; font-family: monospace;">${tx.transaction_id || 'N/A'}</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 13px; color: #6B7280;">Date</span>
                <span style="font-size: 13px; font-weight: 600;">${new Date(tx.payment_date).toLocaleString()}</span>
            </div>
             <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 13px; color: #6B7280;">Method</span>
                <span style="font-size: 13px; font-weight: 600;">${method}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 13px; color: #6B7280;">Status</span>
                <span style="padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; ${statusClass}">${tx.status.toUpperCase()}</span>
            </div>
        </div>
    `;
    
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
    currentViewTx = null;
}

function printCurrentReceipt() {
    if (currentViewTx) printReceipt(currentViewTx);
}

function printReceipt(tx) {
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    if (!printWindow) { return alert('Please allow popups to print receipts.'); }
    
    const html = `
        <html>
        <head>
            <title>Receipt - ${tx.transaction_id || tx.id}</title>
            <style>
                body { font-family: 'Courier New', Courier, monospace; padding: 20px; text-align: center; }
                .header { margin-bottom: 20px; border-bottom: 1px dashed #000; padding-bottom: 10px; }
                .company { font-size: 18px; font-weight: bold; }
                .meta { font-size: 12px; color: #555; margin-bottom: 20px; }
                .details { text-align: left; margin-bottom: 20px; }
                .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                .total { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0; font-weight: bold; font-size: 16px; margin: 20px 0; }
                .footer { font-size: 10px; color: #777; margin-top: 30px; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company">FORTUNNET TECHNOLOGIES</div>
                <div>Internet Service Provider</div>
                <div>Tel: 0700 000 000</div>
            </div>
            
            <div class="meta">
                Receipt #: ${tx.id}<br>
                Date: ${new Date(tx.payment_date).toLocaleString()}
            </div>
            
            <div class="details">
                <div class="row">
                    <span>Customer:</span>
                    <span>${tx.full_name || 'Customer'}</span>
                </div>
                <!-- Remove Payment Method and Ref to execute user.receipt request cleanly -->
                <div class="row">
                    <span>Account:</span>
                    <span>${tx.phone}</span>
                </div>
                <div class="row">
                    <span>Ref:</span>
                    <span>${tx.transaction_id || '-'}</span>
                </div>
            </div>
            
            <div class="total">
                <div class="row">
                    <span>TOTAL PAID:</span>
                    <span>KES ${parseFloat(tx.amount).toLocaleString()}</span>
                </div>
            </div>
            
            <div class="footer">
                Thank you for your business!<br>
                This is a computer generated receipt.
            </div>
            <script>
                window.onload = function() { window.print(); window.close(); }
            <\/script>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
}
</script>

<?php include 'includes/footer.php'; ?>