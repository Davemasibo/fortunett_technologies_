<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get tenant context
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// --- 1. Auto-Calculate Bill for Current Month ---
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// Calculate Revenue
$revStmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE tenant_id = ? AND payment_date BETWEEN ? AND ?");
$revStmt->execute([$tenant_id, $currentMonthStart . ' 00:00:00', $currentMonthEnd . ' 23:59:59']);
$currentRevenue = $revStmt->fetchColumn() ?: 0.00;

// Calculate Fees
$baseFee = 500.00;
$commissionRate = 0.10; // 10%
$commission = $currentRevenue * $commissionRate;
$totalDue = $baseFee + $commission;

// Upsert into tenant_bills
// Check if bill exists for this month
$checkBill = $pdo->prepare("SELECT id FROM tenant_bills WHERE tenant_id = ? AND billing_period = ?");
$checkBill->execute([$tenant_id, $currentMonthStart]);
$billId = $checkBill->fetchColumn();

if ($billId) {
    // Update existing
    $upd = $pdo->prepare("UPDATE tenant_bills SET total_collections = ?, commission_amount = ? WHERE id = ? AND status = 'pending'");
    $upd->execute([$currentRevenue, $commission, $billId]);
} else {
    // Insert new
    $ins = $pdo->prepare("INSERT INTO tenant_bills (tenant_id, billing_period, total_collections, base_fee, commission_rate, commission_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $ins->execute([$tenant_id, $currentMonthStart, $currentRevenue, $baseFee, $commissionRate * 100, $commission]);
}

// --- 2. Fetch All Bills for Display ---
$billsStmt = $pdo->prepare("SELECT * FROM tenant_bills WHERE tenant_id = ? ORDER BY billing_period DESC");
$billsStmt->execute([$tenant_id]);
$bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current pending bill for the alert
$currentBill = null;
foreach ($bills as $b) {
    if ($b['billing_period'] === $currentMonthStart) {
        $currentBill = $b;
        break;
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div class="container-fluid">
        <!-- Header -->
        <div class="mb-4">
            <h2 class="mb-1 text-dark fw-bold">Billing & Invoices</h2>
            <p class="text-muted mb-0">Manage your subscription and view monthly statements.</p>
        </div>

        <!-- Current Bill Card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                        <i class="fas fa-file-invoice-dollar fa-2x"></i>
                    </div>
                    <div>
                        <h5 class="mb-1 fw-bold text-dark">Current Month's Bill (Estimate)</h5>
                        <p class="mb-0 text-secondary">
                            Your estimated bill for <strong><?php echo date('F Y'); ?></strong> is 
                            <span class="text-primary fw-bold">KES <?php echo number_format($totalDue, 2); ?></span>.
                            <br><small class="text-muted">Based on collected revenue of KES <?php echo number_format($currentRevenue, 2); ?>.</small>
                        </p>
                    </div>
                </div>
                <?php if ($currentBill): ?>
                <button onclick='openInvoiceModal(<?php echo json_encode($currentBill); ?>)' class="btn btn-primary d-flex align-items-center gap-2">
                    <i class="fas fa-eye"></i> View Details
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                <h5 class="card-title mb-0">Invoice History</h5>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="py-3">Period</th>
                                <th class="py-3 text-end">Revenue</th>
                                <th class="py-3 text-end">Commission (10%)</th>
                                <th class="py-3 text-end">Base Fee</th>
                                <th class="py-3 text-end">Total Due</th>
                                <th class="py-3 text-center">Status</th>
                                <th class="py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $bill): 
                                $total = $bill['base_fee'] + $bill['commission_amount'];
                            ?>
                            <tr>
                                <td class="fw-bold text-dark"><?php echo date('F Y', strtotime($bill['billing_period'])); ?></td>
                                <td class="text-end">KES <?php echo number_format($bill['total_collections'], 2); ?></td>
                                <td class="text-end text-danger">- KES <?php echo number_format($bill['commission_amount'], 2); ?></td>
                                <td class="text-end text-danger">- KES <?php echo number_format($bill['base_fee'], 2); ?></td>
                                <td class="text-end fw-bold text-dark">KES <?php echo number_format($total, 2); ?></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill bg-<?php echo $bill['status'] === 'paid' ? 'success' : 'warning text-dark'; ?>">
                                        <?php echo ucfirst($bill['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary" onclick='openInvoiceModal(<?php echo json_encode($bill); ?>)'>
                                        View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Invoice Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <h2 class="fw-bold text-dark mb-0" id="modalTotal">KES 0.00</h2>
                    <span class="badge bg-warning text-dark mt-2" id="modalStatus">Pending</span>
                </div>
                
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Billing Period</span>
                        <span class="fw-bold" id="modalPeriod">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Total Collections</span>
                        <span class="fw-bold" id="modalRevenue">-</span>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small fw-bold mb-3">Breakdown</h6>
                <div class="d-flex justify-content-between mb-2">
                    <span>Base Platform Fee</span>
                    <span id="modalBase">-</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Commission (10%)</span>
                    <span id="modalComm">-</span>
                </div>
                <hr>
                <div class="d-flex justify-content-between fw-bold text-dark fs-5">
                    <span>Total Due</span>
                    <span id="modalTotalBottom">-</span>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="alert('Payment integration coming soon!')">Pay Now</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openInvoiceModal(bill) {
    const revenue = parseFloat(bill.total_collections);
    const base = parseFloat(bill.base_fee);
    const comm = parseFloat(bill.commission_amount);
    const total = base + comm;
    
    // Format Currency Helper
    const fmt = n => 'KES ' + n.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    
    // Set Texts
    document.getElementById('modalTotal').textContent = fmt(total);
    document.getElementById('modalTotalBottom').textContent = fmt(total);
    document.getElementById('modalRevenue').textContent = fmt(revenue);
    document.getElementById('modalBase').textContent = fmt(base);
    document.getElementById('modalComm').textContent = fmt(comm);
    
    // Date
    const date = new Date(bill.billing_period);
    document.getElementById('modalPeriod').textContent = date.toLocaleString('default', { month: 'long', year: 'numeric' });
    
    // Status
    const badge = document.getElementById('modalStatus');
    badge.textContent = bill.status.charAt(0).toUpperCase() + bill.status.slice(1);
    badge.className = bill.status === 'paid' ? 'badge bg-success mt-2' : 'badge bg-warning text-dark mt-2';
    
    // Show Modal
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
}
</script>

<?php include 'includes/footer.php'; ?>
