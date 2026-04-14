<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_from   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $exp_to     = $_GET['date_to']   ?? date('Y-m-d');
    $exp_method = $_GET['method']    ?? '';
    $exp_search = $_GET['search']    ?? '';
    $exp_status = $_GET['status']    ?? '';

    $q = "SELECT p.*, c.full_name, c.phone, c.account_number
          FROM payments p
          LEFT JOIN clients c ON p.client_id = c.id
          WHERE p.tenant_id = ? AND DATE(p.payment_date) BETWEEN ? AND ?";
    $qp = [$tenant_id, $exp_from, $exp_to];

    if ($exp_search) {
        $q  .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR p.transaction_id LIKE ?)";
        $t   = "%$exp_search%";
        $qp  = array_merge($qp, [$t, $t, $t]);
    }
    if ($exp_method && $exp_method !== 'All Methods') {
        $mv  = strtolower($exp_method) === 'm-pesa' ? 'mpesa' : strtolower($exp_method);
        $q  .= " AND p.payment_method = ?"; $qp[] = $mv;
    }
    if ($exp_status && $exp_status !== 'All') {
        $q  .= " AND p.status = ?"; $qp[] = strtolower($exp_status);
    }
    $q .= " ORDER BY p.payment_date DESC";

    $s = $pdo->prepare($q); $s->execute($qp);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payments_export_' . $exp_from . '_to_' . $exp_to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Customer','Phone','Account No','Amount (KES)','Method','Transaction ID','Status','Date','Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['full_name']     ?? 'Unknown',
            $r['phone']         ?? '',
            $r['account_number']?? '',
            number_format($r['amount'] ?? 0, 2, '.', ''),
            strtoupper($r['payment_method'] ?? ''),
            $r['transaction_id']?? '',
            $r['status']        ?? '',
            $r['payment_date']  ?? '',
            $r['notes']         ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Calculate stats from payments table
try {
    // Total Revenue Today
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $revenue_today = (float)$stmt->fetchColumn();
    
    // Confirmed Transactions (overall)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $confirmed_transactions = (int)$stmt->fetchColumn();
    
    // Pending Payments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND tenant_id = ?");
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

// Build Query - Fetch real payments (join mpesa_transactions for confirmation status)
$query = "
    SELECT p.*, c.full_name, c.phone,
           mt.result_code AS mpesa_result_code
    FROM payments p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN mpesa_transactions mt
           ON mt.checkout_request_id = p.transaction_id AND mt.result_code = '0'
    WHERE p.tenant_id = ? AND DATE(p.payment_date) BETWEEN ? AND ?
";
$params = [$tenant_id, $date_from, $date_to];

if ($search) {
    $query .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR p.transaction_id LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

if ($filter_method && $filter_method !== 'All Methods') {
    $query .= " AND p.payment_method = ?";
    // Clean string (e.g. from UI 'Cash', 'M-Pesa')
    $filterVal = strtolower($filter_method);
    if ($filterVal == 'm-pesa') $filterVal = 'mpesa';
    $params[] = $filterVal;
}

$query .= " ORDER BY p.payment_date DESC, p.created_at DESC LIMIT 100";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $transactions = [];
}

// Get Clients for Dropdown
try {
    $stmt = $pdo->prepare("SELECT id, full_name, phone, subscription_plan FROM clients WHERE tenant_id = ? ORDER BY full_name ASC");
    $stmt->execute([$tenant_id]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper { background: #141414 !important; justify-content: flex-start !important; }
    /* Override sidebar max-width constraint — fill full content area */
    .main-content-wrapper > div.payments-container {
        max-width: 100% !important; margin: 0 !important; padding: 24px 32px !important; box-sizing: border-box !important;
    }
    .payments-container { width: 100%; box-sizing: border-box; }
    .payments-title { font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px 0; }
    .payments-subtitle { font-size: 14px; color: #9a9a95; margin: 0 0 24px 0; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: #222221; border-radius: 12px; padding: 20px; border: 1px solid rgba(255,255,255,.06); box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
    .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .stat-icon.revenue   { background: rgba(16,185,129,.15);  color: #6ee7b7; }
    .stat-icon.confirmed { background: rgba(59,130,246,.15);  color: #93c5fd; }
    .stat-icon.pending   { background: rgba(245,158,11,.15);  color: #fcd34d; }
    .stat-icon.failed    { background: rgba(239,68,68,.15);   color: #fca5a5; }
    .stat-value { font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 4px; }
    .stat-label { font-size: 13px; color: #9a9a95; font-weight: 500; }
    .stat-change { font-size: 12px; margin-top: 8px; }
    .stat-change.positive { color: #34d399; }

    /* Filters */
    .filters-section { background: #222221; border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,.06); }
    .filters-title { font-size: 16px; font-weight: 600; color: #e2e2e0; margin-bottom: 16px; }
    .filters-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto auto; gap: 12px; align-items: end; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-label { font-size: 12px; font-weight: 500; color: #9a9a95; }
    .filter-input, .filter-select {
        padding: 8px 12px; border: 1px solid rgba(255,255,255,.08); border-radius: 6px;
        font-size: 14px; background: #1a1a19; color: #e2e2e0;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,.4), inset -1px -1px 3px rgba(255,255,255,.04);
    }
    .filter-btn { padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.05); color: #e2e2e0; }
    .filter-btn.primary { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; border: none; }

    /* Transactions table */
    .transactions-section { background: #222221; border-radius: 12px; border: 1px solid rgba(255,255,255,.06); overflow: hidden; box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
    .transactions-header { padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; align-items: center; justify-content: space-between; }
    .transactions-title { font-size: 16px; font-weight: 600; color: #e2e2e0; }
    .transactions-actions { display: flex; gap: 8px; }
    .action-link { padding: 6px 12px; border-radius: 6px; font-size: 13px; text-decoration: none; display: flex; align-items: center; gap: 6px; }
    .action-link.refresh { color: #9a9a95; }
    .action-link.manual { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; }
    .transactions-table { width: 100%; border-collapse: collapse; }
    .transactions-table thead { background: rgba(255,255,255,.04); border-bottom: 1px solid rgba(255,255,255,.07); }
    .transactions-table th { padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #9a9a95; text-transform: uppercase; letter-spacing: 0.05em; }
    .transactions-table td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 14px; color: #e2e2e0; }
    .transactions-table tbody tr:hover { background: rgba(255,255,255,.04); }
    .customer-name { font-weight: 500; color: #fff; }
    .customer-id   { font-size: 12px; color: #9a9a95; }
    .transaction-id { font-family: monospace; font-size: 13px; color: #9a9a95; }

    /* Method badges */
    .payment-method { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; }
    .payment-method.mpesa { background: rgba(16,185,129,.15); color: #6ee7b7; }
    .payment-method.cash  { background: rgba(59,130,246,.15);  color: #93c5fd; }

    /* Status badges */
    .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
    .status-badge.completed { background: rgba(16,185,129,.15);  color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
    .status-badge.pending   { background: rgba(245,158,11,.15);  color: #fcd34d; border: 1px solid rgba(245,158,11,.25); }
    .status-badge.failed    { background: rgba(239,68,68,.15);   color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

    /* Action icons */
    .action-icons { display: flex; gap: 8px; }
    .action-icon { width: 28px; height: 28px; border-radius: 6px; border: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.05); color: #9a9a95; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; transition: all .18s; }
    .action-icon:hover { background: var(--primary-color,#3B6EA5); border-color: transparent; color: #fff; }

    /* Payment modal shared input style */
    .pay-input {
        width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,.08);border-radius:8px;
        font-size:14px;background:#1a1a19;color:#e2e2e0;box-sizing:border-box;
        box-shadow:inset 3px 3px 7px rgba(0,0,0,.35),inset -1px -1px 3px rgba(255,255,255,.03);
        transition:border-color .2s;font-family:inherit;
    }
    .pay-input:focus { outline:none;border-color:var(--primary-color,#3B6EA5);box-shadow:inset 3px 3px 7px rgba(0,0,0,.35),0 0 0 3px rgba(59,110,165,.18); }
    .pay-input::placeholder { color:rgba(255,255,255,.25); }
    .pay-input option { background:#1a1a19;color:#e2e2e0; }
    .pay-method-label {
        display:flex;align-items:center;gap:8px;cursor:pointer;
        padding:10px 14px;border:1px solid rgba(255,255,255,.1);border-radius:8px;
        flex:1;justify-content:center;background:rgba(255,255,255,.04);
        font-size:14px;font-weight:500;color:rgba(255,255,255,.6);transition:all .2s;
    }
    .pay-method-label.pay-method-active {
        border:2px solid var(--primary-color,#3B6EA5);background:rgba(59,110,165,.15);
        color:var(--primary-light,#93c5fd);font-weight:600;
    }
    .pay-submit-btn {
        width:100%;padding:13px;
        background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);
        color:white;border:none;border-radius:8px;cursor:pointer;
        font-weight:700;font-size:15px;letter-spacing:.02em;transition:opacity .2s,transform .15s;
    }
    .pay-submit-btn:hover { opacity:.9;transform:translateY(-1px); }
    /* viewModal dark content styles */
    .vm-grid { display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px; }
    .vm-label { font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px; }
    .vm-value { font-size:15px;font-weight:600;color:#e2e2e0; }
    .vm-sub { font-size:13px;color:rgba(255,255,255,.4); }
    .vm-detail-box { background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:16px;margin-bottom:20px; }
    .vm-row { display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:13px; }
    .vm-row:last-child { margin-bottom:0; }
    .vm-row-label { color:rgba(255,255,255,.4); }
    .vm-row-val { font-weight:600;color:#e2e2e0;font-family:monospace; }
    /* Sandbox warning banner */
    #sandboxBanner { display:none;margin-bottom:14px;padding:11px 14px;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);border-radius:8px;font-size:13px;color:#fcd34d; }

    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .transactions-table { min-width: 700px; }

    @media (max-width: 1024px) { .filters-grid { grid-template-columns: 1fr; } }
    @media (max-width: 640px) { .payments-container { padding: 16px; } }

    #paymentForm button[type="submit"]:hover { filter: brightness(110%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.5); }
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
            <div class="transactions-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h3 class="transactions-title" style="margin:0;">Recent Transactions</h3>
                <div class="transactions-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="filter-btn" onclick="exportPaymentsCSV()" style="display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-download"></i> Export CSV
                    </button>
                    <button class="filter-btn" onclick="openImportModal('payments')" style="display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-upload"></i> Import CSV
                    </button>
                    <button class="action-link manual filter-btn primary" onclick="openPaymentModal()">
                        <i class="fas fa-plus"></i> New Payment
                    </button>
                </div>
            </div>

          <div class="table-scroll">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>CUSTOMER / PHONE</th>
                        <th>AMOUNT</th>
                        <th>METHOD</th>
                        <th>TRANSACTION ID</th>
                        <th>STATUS</th>
                        <th>DATE</th>
                        <th style="text-align:center;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:20px;">No transactions found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($transactions as $tx):
                        $status = $tx['status'] ?? 'pending';
                        $method = strtolower($tx['payment_method'] ?? 'cash');
                        // Determine display status: only "Confirmed" when result_code='0' in mpesa_transactions
                        $isConfirmed = ($tx['mpesa_result_code'] ?? null) === '0';
                        if ($isConfirmed) {
                            $badgeClass = 'completed'; $badgeLabel = 'Confirmed';
                        } elseif ($status === 'completed') {
                            $badgeClass = 'completed'; $badgeLabel = 'Completed';
                        } elseif ($status === 'failed') {
                            $badgeClass = 'failed'; $badgeLabel = 'Failed';
                        } else {
                            $badgeClass = 'pending'; $badgeLabel = 'Pending';
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="customer-name"><?php echo htmlspecialchars($tx['full_name'] ?? 'Unknown'); ?></div>
                            <div class="customer-id"><?php echo htmlspecialchars($tx['phone'] ?? 'N/A'); ?></div>
                        </td>
                        <td>
                            <strong>KES <?php echo number_format($tx['amount'] ?? 0, 2); ?></strong>
                        </td>
                        <td>
                            <span class="payment-method <?php echo $method === 'mpesa' ? 'mpesa' : 'cash'; ?>">
                                <i class="fas fa-<?php echo $method === 'mpesa' ? 'mobile-alt' : 'money-bill'; ?>"></i>
                                <?php echo ucfirst($method); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                                $displayId = $tx['transaction_id'] ?? 'N/A';
                                if ($status === 'pending' && strpos($displayId, 'ws_') === 0) {
                                    echo '<span style="font-style:italic; color:#9CA3AF;">Processing...</span>';
                                } else {
                                    echo '<span class="transaction-id">' . htmlspecialchars($displayId) . '</span>';
                                }
                            ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $badgeClass; ?>">
                                <span class="status-dot"></span>
                                <?php echo $badgeLabel; ?>
                            </span>
                        </td>
                        <td><?php echo date('d/m/Y H:i', strtotime($tx['payment_date'] ?? $tx['created_at'])); ?></td>
                        <td style="text-align:center;">
                            <div class="action-icons" style="justify-content: center;">
                                <button class="action-icon" title="View" onclick='openViewModal(<?php echo htmlspecialchars(json_encode($tx), ENT_QUOTES, "UTF-8"); ?>)'><i class="fas fa-eye"></i></button>
                                <button class="action-icon" title="Print Receipt" onclick='printReceipt(<?php echo htmlspecialchars(json_encode($tx), ENT_QUOTES, "UTF-8"); ?>)'><i class="fas fa-print"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
          </div><!-- end table-scroll -->
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:1050;overflow-y:auto;-webkit-overflow-scrolling:touch;align-items:flex-start;justify-content:center;padding:40px 16px 24px;box-sizing:border-box;">
    <div style="background:#1e1e1d;border:1px solid rgba(255,255,255,.08);width:100%;max-width:620px;max-height:calc(100vh - 80px);border-radius:16px;box-shadow:0 32px 80px rgba(0,0,0,.8);display:flex;flex-direction:column;overflow:hidden;margin:auto 0;">

        <!-- Modal Header -->
        <div style="padding:20px 24px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div>
                <h2 style="font-size:18px;font-weight:700;color:#e2e2e0;margin:0 0 2px 0;">Payment Entry</h2>
                <p style="font-size:13px;color:rgba(255,255,255,.4);margin:0;">Initiate or record a customer payment</p>
            </div>
            <button onclick="closePaymentModal()" style="width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">&times;</button>
        </div>

        <!-- Tab Navigation -->
        <div style="display:flex;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0;background:rgba(255,255,255,.02);">
            <button id="tabBtnNew" class="tab-button active" onclick="showPaymentTab('newPaymentTab')"
                style="flex:1;padding:12px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:600;color:var(--primary-light,#93c5fd);border-bottom:2px solid var(--primary-light,#93c5fd);transition:all .2s;">
                <i class="fas fa-bolt" style="margin-right:6px;"></i>New Payment
            </button>
            <button id="tabBtnPast" class="tab-button" onclick="showPaymentTab('recordPastTab')"
                style="flex:1;padding:12px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:rgba(255,255,255,.4);border-bottom:2px solid transparent;transition:all .2s;">
                <i class="fas fa-history" style="margin-right:6px;"></i>Record Past Transaction
            </button>
        </div>

        <!-- Scrollable Body -->
        <div style="overflow-y:auto;flex:1;padding:24px;">

            <!-- Tab 1: New Payment (STK Push / Manual Cash) -->
            <div id="newPaymentTab" class="tab-content">
                <div id="sandboxBanner"><i class="fas fa-flask" style="margin-right:7px;"></i><strong>Sandbox Mode Active</strong> — STK pushes are accepted by Safaricom but no real prompt appears on any phone. Go to <strong>Settings → Payments</strong> and set your M-Pesa gateway to <strong>Production</strong>.</div>
                <form id="newPaymentForm" onsubmit="handlePaymentSubmit(event)">
                    <!-- Section: Customer -->
                    <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <p style="font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin:0 0 12px 0;">Customer</p>
                        <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Select Customer</label>
                        <select name="client_id" id="payClient" required class="pay-input"
                            onchange="updatePhone(this)">
                            <option value="">Select a customer...</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" data-phone="<?php echo htmlspecialchars($c['phone']); ?>">
                                <?php echo htmlspecialchars($c['full_name']); ?> — <?php echo htmlspecialchars($c['phone']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Section: Payment Details -->
                    <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <p style="font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin:0 0 12px 0;">Payment Details</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div>
                                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Phone (M-Pesa)</label>
                                <input type="text" name="phone" id="payPhone" required placeholder="2547XXXXXXXX" class="pay-input">
                            </div>
                            <div>
                                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Amount (KES)</label>
                                <input type="number" name="amount" id="payAmount" required placeholder="0.00" min="1" step="0.01" class="pay-input">
                            </div>
                        </div>
                        <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:8px;">Payment Method</label>
                        <div style="display:flex;gap:10px;">
                            <label id="radioMpesa" class="pay-method-label pay-method-active">
                                <input type="radio" name="method" value="mpesa" checked onchange="highlightMethodRadio()"> <i class="fas fa-mobile-alt"></i> M-Pesa STK
                            </label>
                            <label id="radioCash" class="pay-method-label">
                                <input type="radio" name="method" value="cash" onchange="highlightMethodRadio()"> <i class="fas fa-money-bill"></i> Manual Cash
                            </label>
                        </div>
                    </div>
                    <button type="submit" id="newPayBtn" class="pay-submit-btn">
                        <i class="fas fa-paper-plane" style="margin-right:8px;"></i>Process Payment
                    </button>
                </form>
            </div>

            <!-- Tab 2: Record Past Transaction -->
            <div id="recordPastTab" class="tab-content" style="display:none;">
                <form id="recordPastForm" onsubmit="handleRecordPastSubmit(event)">

                    <!-- Section: Transaction Details -->
                    <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <p style="font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin:0 0 12px 0;">Transaction Details</p>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Customer <span style="color:#f87171;">*</span></label>
                            <select name="client_id" id="recordClient" required class="pay-input">
                                <option value="">Select a customer...</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['full_name']); ?> — <?php echo htmlspecialchars($c['phone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                            <div>
                                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Amount (KES) <span style="color:#f87171;">*</span></label>
                                <input type="number" name="amount" required placeholder="0.00" min="1" step="0.01" class="pay-input">
                            </div>
                            <div>
                                <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Payment Method <span style="color:#f87171;">*</span></label>
                                <select name="method" required class="pay-input">
                                    <option value="">Select...</option>
                                    <option value="M-Pesa">M-Pesa</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Reference / Confirmation Code <span style="color:#f87171;">*</span></label>
                            <input type="text" name="reference_code" required
                                placeholder="e.g. QJK3X7A1P2 — M-Pesa code, bank ref, receipt #"
                                class="pay-input" style="font-family:monospace;letter-spacing:.04em;"
                                oninput="this.value=this.value.toUpperCase()">
                        </div>
                    </div>

                    <!-- Section: Date & Verification -->
                    <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <p style="font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;margin:0 0 12px 0;">Date &amp; Verification</p>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Date &amp; Time of Payment <span style="color:#f87171;">*</span></label>
                            <input type="datetime-local" name="transaction_date" required value="<?php echo date('Y-m-d\TH:i'); ?>" class="pay-input">
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="display:block;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);margin-bottom:6px;">Notes (optional)</label>
                            <input type="text" name="notes" placeholder="e.g. Customer called to confirm payment" class="pay-input">
                        </div>

                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:14px 16px;background:rgba(52,211,153,.06);border:1px solid rgba(52,211,153,.2);border-radius:8px;">
                            <input type="checkbox" name="is_verified" value="1" checked
                                style="width:16px;height:16px;accent-color:#34d399;flex-shrink:0;margin-top:1px;">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#6ee7b7;">Mark as Verified / Confirmed</div>
                                <div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;">Records as "Confirmed" — use only when you have verified the payment reference is genuine.</div>
                            </div>
                        </label>
                    </div>

                    <button type="submit" id="recordPastBtn" class="pay-submit-btn">
                        <i class="fas fa-save" style="margin-right:8px;"></i>Record Transaction
                    </button>
                </form>
            </div>

        </div><!-- end scrollable body -->
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:1000;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
    <div style="background:#1e1e1d;border:1px solid rgba(255,255,255,.08);width:100%;max-width:500px;border-radius:16px;padding:32px;position:relative;box-shadow:0 32px 80px rgba(0,0,0,.8);">
        <button onclick="closeViewModal()" style="position:absolute;top:20px;right:20px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;color:rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;">&times;</button>
        <h2 style="font-size:20px;font-weight:600;color:#e2e2e0;margin:0 0 24px 0;">Transaction Details</h2>
        <div id="viewModalContent"></div>
        <div style="margin-top:24px;text-align:right;">
            <button onclick="printCurrentReceipt()" style="padding:10px 20px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Print Receipt</button>
        </div>
    </div>
</div>

<script>
function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
    showPaymentTab('newPaymentTab'); // Default to 'New Payment' tab
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    document.getElementById('newPaymentForm').reset(); // Reset new payment form
    document.getElementById('recordPastForm').reset(); // Reset record past form
}

function showPaymentTab(tabId) {
    document.querySelectorAll('#paymentModal .tab-content').forEach(t => t.style.display = 'none');
    document.getElementById(tabId).style.display = 'block';

    const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary-light').trim() || '#93c5fd';
    ['tabBtnNew','tabBtnPast'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.style.color = 'rgba(255,255,255,.4)';
        btn.style.borderBottom = '2px solid transparent';
        btn.style.fontWeight = '500';
    });
    const activeId = tabId === 'newPaymentTab' ? 'tabBtnNew' : 'tabBtnPast';
    const active = document.getElementById(activeId);
    if (active) {
        active.style.color = primary;
        active.style.borderBottom = '2px solid ' + primary;
        active.style.fontWeight = '600';
    }
}

function highlightMethodRadio() {
    const mpesa = document.querySelector('input[name="method"][value="mpesa"]');
    const lMpesa = document.getElementById('radioMpesa');
    const lCash  = document.getElementById('radioCash');
    if (!lMpesa || !lCash) return;
    if (mpesa && mpesa.checked) {
        lMpesa.classList.add('pay-method-active');
        lCash.classList.remove('pay-method-active');
    } else {
        lCash.classList.add('pay-method-active');
        lMpesa.classList.remove('pay-method-active');
    }
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
        url = 'api/payments/record_cash.php';
    }
    
    const btn = document.getElementById('newPayBtn') || form.querySelector('button[type="submit"]');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Processing...';
    btn.disabled = true;
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) throw new Error('Server returned ' + r.status);
        return r.json();
    })
    .then(data => {
        if (data.success) {
            if (method === 'cash') {
                showFnToast('Cash payment recorded successfully', 'success');
                closePaymentModal();
                setTimeout(() => location.reload(), 1800);
            } else if (data.sandbox) {
                // Sandbox — show inline warning, don't close modal
                const banner = document.getElementById('sandboxBanner');
                if (banner) banner.style.display = 'block';
                showFnToast('⚠️ SANDBOX: No phone prompt sent. Switch to Production in Settings → Payments.', 'warning');
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            } else {
                const baseMsg = data.using_platform
                    ? 'STK request accepted via FortuNett paybill. If no phone prompt appears within 30s, the shortcode may not be enrolled for LNM Online — contact your admin.'
                    : (data.CustomerMessage || 'STK Push sent — check your phone.');
                showFnToast(baseMsg, 'success');
                closePaymentModal();
                setTimeout(() => location.reload(), 1800);
            }
        } else {
            const errMsg = data.message || data.errorMessage || 'Unknown error';
            showFnToast(errMsg, 'error');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        showFnToast('Connection error. Check your network.', 'error');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    });
}

function handleRecordPastSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    const btn = document.getElementById('recordPastBtn') || form.querySelector('button[type="submit"]');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Saving...';
    btn.disabled = true;

    fetch('api/payments/record_manual.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showFnToast('✅ Transaction recorded: ' + data.transaction_id, 'success');
            closePaymentModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showFnToast('Error: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(() => showFnToast('Connection error. Please try again.', 'error'))
    .finally(() => { btn.innerHTML = originalHTML; btn.disabled = false; });
}

function showFnToast(message, type) {
    const existing = document.getElementById('fnToast');
    if (existing) existing.remove();
    const bg = type === 'success' ? 'rgba(16,185,129,.9)' : type === 'warning' ? 'rgba(217,119,6,.9)' : 'rgba(239,68,68,.9)';
    const icon = type === 'success' ? 'fa-check-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle';
    const toast = document.createElement('div');
    toast.id = 'fnToast';
    toast.style.cssText = `position:fixed;top:20px;right:20px;z-index:9999;background:${bg};backdrop-filter:blur(8px);color:white;padding:14px 20px;border-radius:10px;font-size:13px;font-weight:600;display:flex;align-items:flex-start;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.5);max-width:420px;line-height:1.4;`;
    toast.innerHTML = `<i class="fas ${icon}" style="flex-shrink:0;margin-top:1px;"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), type === 'warning' ? 8000 : 4000);
}

let currentViewTx = null;

function openViewModal(tx) {
    currentViewTx = tx;
    const content = document.getElementById('viewModalContent');
    // Check for mpesa_receipt_number or transaction_id to determine method
    const isMpesa = tx.mpesa_receipt_number || (tx.transaction_id && tx.transaction_id.toLowerCase().includes('mpesa'));
    const method = isMpesa ? 'M-Pesa' : (tx.method || 'Cash'); // Use tx.method if available, else default to Cash
    
    // Determine status based on result_code for M-Pesa or 'status' for recorded transactions
    let status = 'pending';
    if (tx.result_code === '0') {
        status = 'completed';
    } else if (tx.result_code && tx.result_code !== '0') {
        status = 'failed';
    } else if (tx.status) { // For manually recorded transactions
        status = tx.status;
    }

    const statusClass = status === 'completed' ? 'color: #059669; background: #D1FAE5;' : 
                        (status === 'failed' ? 'color: #DC2626; background: #FEE2E2;' : 'color: #D97706; background: #FEF3C7;');
    
    const statusCls = status === 'completed'
        ? 'background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);'
        : status === 'failed'
        ? 'background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.25);'
        : 'background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.25);';
    content.innerHTML = `
        <div class="vm-grid">
            <div>
                <div class="vm-label">Customer</div>
                <div class="vm-value">${tx.full_name || 'Unknown'}</div>
                <div class="vm-sub">${tx.phone || 'N/A'}</div>
            </div>
            <div>
                <div class="vm-label">Amount</div>
                <div class="vm-value" style="font-size:22px;">KSh ${parseFloat(tx.amount).toLocaleString()}</div>
            </div>
        </div>
        <div class="vm-detail-box">
            <div class="vm-row"><span class="vm-row-label">Transaction ID</span><span class="vm-row-val">${tx.mpesa_receipt_number || tx.transaction_id || 'N/A'}</span></div>
            <div class="vm-row"><span class="vm-row-label">Date</span><span class="vm-row-val" style="font-family:inherit;">${new Date(tx.created_at || tx.payment_date).toLocaleString()}</span></div>
            <div class="vm-row"><span class="vm-row-label">Method</span><span class="vm-row-val" style="font-family:inherit;">${method}</span></div>
            <div class="vm-row"><span class="vm-row-label">Status</span><span style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;${statusCls}">${status.toUpperCase()}</span></div>
        </div>
    `;
    
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
    currentViewTx = null;
}

// Backdrop click-to-close
document.getElementById('paymentModal').addEventListener('click', function(e) { if (e.target === this) closePaymentModal(); });
document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) closeViewModal(); });

// Auto-open payment modal when navigated from quick actions
if (new URLSearchParams(window.location.search).get('open_modal') === '1') {
    openPaymentModal();
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
            <title>Receipt - ${tx.mpesa_receipt_number || tx.transaction_id || tx.id}</title>
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
                Date: ${new Date(tx.created_at || tx.payment_date).toLocaleString()}
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
                    <span>${tx.mpesa_receipt_number || tx.transaction_id || '-'}</span>
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

function exportPaymentsCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'payments.php?' + params.toString();
}

// ── CSV Import modal ─────────────────────────────────────────────────────────
function openImportModal(type) {
    const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
        ? '/fortunett_technologies_' : '';
    const endpoints = {
        payments: _base + '/api/import/payments.php',
        clients:  _base + '/api/import/customers.php',
        packages: _base + '/api/import/packages.php',
    };
    const templates = {
        payments: 'client_phone,amount,payment_method,transaction_id,payment_date,status,notes\n0712345678,500,cash,CASH001,2026-04-01 10:00:00,completed,Manual entry',
        clients:  'full_name,phone,email,address,username,package_name,connection_type,status,expiry_date\nJohn Doe,0712345678,john@example.com,,john.doe,Basic Hotspot,hotspot,active,2026-12-31',
        packages: 'name,type,price,download_speed,upload_speed,validity_value,validity_unit,data_limit,device_limit,description,status\nBasic Hotspot,hotspot,500,10,5,30,days,0,1,Entry level,active',
    };
    const titles = { payments: 'Import Transactions', clients: 'Import Customers', packages: 'Import Packages' };
    document.getElementById('importModalTitle').textContent  = titles[type] || 'Import CSV';
    document.getElementById('importModalForm').action        = endpoints[type];
    document.getElementById('importTemplateLink').href       = 'data:text/csv;charset=utf-8,' + encodeURIComponent(templates[type] || '');
    document.getElementById('importTemplateLink').download   = type + '_template.csv';
    document.getElementById('importModalResult').innerHTML   = '';
    document.getElementById('importCsvFile').value           = '';
    document.getElementById('importModal').style.display     = 'flex';
}
function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }
function showImportResult(html, type) {
    const el = document.getElementById('importModalResult');
    el.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:12px;'
        + (type === 'error'
            ? 'border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.1);'
            : 'border:1px solid rgba(16,185,129,.3);background:rgba(16,185,129,.1);');
    el.innerHTML = html;
}
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('importModalForm');
    if (!form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const file = document.getElementById('importCsvFile').files[0];
        if (!file) { showImportResult('Please select a CSV file.', 'error'); return; }
        const btn = document.getElementById('importSubmitBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';
        try {
            const fd = new FormData(); fd.append('csv_file', file);
            const res  = await fetch(form.action, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                let html = '<div style="color:#6ee7b7"><i class="fas fa-check-circle"></i> ' + (data.message || 'Done') + '</div>';
                if (data.errors && data.errors.length) {
                    html += '<div style="margin-top:8px;color:#fca5a5;font-size:12px"><strong>Errors:</strong><ul style="margin:4px 0 0 16px;padding:0">'
                        + data.errors.map(e => '<li>' + e + '</li>').join('') + '</ul></div>';
                }
                showImportResult(html, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showImportResult(data.message || 'Import failed.', 'error');
            }
        } catch { showImportResult('Connection error. Please try again.', 'error'); }
        finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Import'; }
    });
    document.getElementById('importModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeImportModal();
    });
});
</script>

<!-- ── Import CSV Modal ─────────────────────────────────────────────────────── -->
<div id="importModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:28px 32px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 id="importModalTitle" style="margin:0;color:#e2e2e0;font-size:18px;font-weight:600;">Import CSV</h3>
            <button onclick="closeImportModal()" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:20px;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <form id="importModalForm" method="post" enctype="multipart/form-data">
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:13px;color:rgba(255,255,255,.55);margin-bottom:6px;">CSV File <span style="color:#fca5a5">*</span></label>
                <input id="importCsvFile" type="file" name="csv_file" accept=".csv,text/csv"
                    style="width:100%;padding:9px 12px;background:#1a1a19;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e2e2e0;font-size:13px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:20px;font-size:13px;color:rgba(255,255,255,.4);">
                <i class="fas fa-info-circle" style="color:#93c5fd;margin-right:4px;"></i>
                First row must be the header row.
                <a id="importTemplateLink" href="#" download style="color:#93c5fd;text-decoration:none;margin-left:4px;">
                    <i class="fas fa-download"></i> Download template
                </a>
            </div>
            <div id="importModalResult" style="display:none;"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                <button type="button" onclick="closeImportModal()"
                    style="padding:9px 20px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:rgba(255,255,255,.7);font-size:14px;cursor:pointer;">
                    Cancel
                </button>
                <button id="importSubmitBtn" type="submit"
                    style="padding:9px 20px;background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-upload"></i> Import
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>