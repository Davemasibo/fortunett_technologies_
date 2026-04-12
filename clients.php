<?php
require_once __DIR__ . '/includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Export CSV Logic
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $database = new Database();
    $db = $database->getConnection();
    
    // Build Query with Filters (Same logic as display)
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $package = $_GET['package'] ?? '';
    
    $query = "SELECT c.*, 
              COALESCE((SELECT name FROM packages WHERE id = c.package_id LIMIT 1), c.subscription_plan) AS package_name,
              COALESCE((SELECT price FROM packages WHERE id = c.package_id LIMIT 1), 0) AS package_price
              FROM clients c WHERE c.tenant_id = ?";
    $params = [$tenant_id];
    
    
    if (!empty($search)) {
        $query .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.account_number LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }
    
    if (!empty($status)) {
        $query .= " AND c.status = ?";
        $params[] = $status;
    }
    
    if (!empty($package)) {
        $query .= " AND c.package_id = ?";
        $params[] = $package;
    }
    
    $query .= " ORDER BY c.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Account Number', 'Name', 'Phone', 'Email', 'Address', 'Package', 'Price', 'Connection Type', 'Username', 'Status', 'Expiry Date']);
    
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['account_number'],
            $row['full_name'],
            $row['phone'],
            $row['email'],
            $row['address'],
            $row['package_name'],
            $row['package_price'],
            $row['connection_type'],
            $row['mikrotik_username'],
            $row['status'],
            $row['expiry_date']
        ]);
    }
    fclose($output);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Calculate stats
try {
    // Total Customers
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $total_customers = (int)$stmt->fetchColumn();
    
    // Active Services
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status = 'active' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $active_services = (int)$stmt->fetchColumn();
    
    // Expired Services
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE (expiry_date < NOW() OR status = 'inactive') AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $expired_services = (int)$stmt->fetchColumn();
    
    // Expiring Soon (next 7 days)
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status = 'active' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $expiring_soon = (int)$stmt->fetchColumn();
    
} catch (Exception $e) {
    $total_customers = 0;
    $active_services = 0;
    $expired_services = 0;
    $expiring_soon = 0;
}

// Get all customers with Filters
try {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $package_filter = $_GET['package'] ?? '';
    
    $query = "SELECT c.*, 
              COALESCE((SELECT name FROM packages WHERE id = c.package_id LIMIT 1), c.subscription_plan) AS package_name,
              COALESCE((SELECT price FROM packages WHERE id = c.package_id LIMIT 1), 0) AS package_price,
              (SELECT COUNT(*) FROM mpesa_transactions WHERE client_id = c.id) AS payments_count
              FROM clients c WHERE c.tenant_id = ?";
              
    $params = [$tenant_id];
    
    if (!empty($search)) {
        $query .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.account_number LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }
    
    if (!empty($status_filter)) {
        $query .= " AND c.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($package_filter)) {
        $query .= " AND c.package_id = ?";
        $params[] = $package_filter;
    }
    
    $query .= " ORDER BY c.created_at DESC";
              
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
}

// Get Packages for Dropdown
try {
    $stmt = $db->prepare("SELECT id, name, price, COALESCE(type, connection_type, 'pppoe') AS type FROM packages WHERE tenant_id = ? ORDER BY price ASC");
    $stmt->execute([$tenant_id]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $packages = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    /* ── Dark neumorphism tokens ──────────────────────────────── */
    :root {
        --neu-bg:     #141414;
        --neu-surf:   #1c1c1b;
        --neu-s2:     #222221;
        --neu-border: rgba(255,255,255,.06);
        --neu-card:   8px 8px 20px rgba(0,0,0,.45), -4px -4px 10px rgba(255,255,255,.03);
    }

    .main-content-wrapper { background: var(--neu-bg) !important; }

    .customers-container { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }

    .customers-header   { margin-bottom: 24px; }
    .customers-title    { font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px 0; }
    .customers-subtitle { font-size: 14px; color: rgba(255,255,255,.45); margin: 0; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: var(--neu-s2); border-radius: 12px; padding: 20px; border: 1px solid var(--neu-border); box-shadow: var(--neu-card); position: relative; overflow: hidden; }
    .stat-card::before { content:''; position:absolute; top:0; right:0; width:60px; height:60px; background:linear-gradient(135deg,rgba(255,255,255,.04) 0%,transparent 100%); border-radius:0 0 0 60px; }
    .stat-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .stat-icon { width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:18px; }
    .stat-icon.total   { background:rgba(96,165,250,.15);  color:#93c5fd; }
    .stat-icon.active  { background:rgba(52,211,153,.15);  color:#6ee7b7; }
    .stat-icon.expired { background:rgba(248,113,113,.15); color:#fca5a5; }
    .stat-icon.warning { background:rgba(251,191,36,.15);  color:#fcd34d; }
    .stat-value  { font-size:32px; font-weight:700; color:#e2e2e0; margin-bottom:4px; }
    .stat-label  { font-size:13px; color:rgba(255,255,255,.5); font-weight:500; }
    .stat-change { font-size:12px; margin-top:8px; color:rgba(255,255,255,.35); }
    .stat-change.positive { color:#34d399; }
    .stat-change.negative { color:#f87171; }

    /* Filters */
    .filters-bar { background:var(--neu-s2); border-radius:10px; padding:20px 24px; margin-bottom:20px; border:1px solid var(--neu-border); box-shadow:var(--neu-card); }
    .filters-grid { display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:16px; align-items:end; }
    .filter-group { display:flex; flex-direction:column; gap:8px; }
    .filter-label { font-size:13px; font-weight:500; color:rgba(255,255,255,.55); }
    .filter-input, .filter-select { padding:10px 14px; background:var(--neu-surf); border:1px solid var(--neu-border); border-radius:8px; font-size:14px; color:#e2e2e0; box-shadow:inset 3px 3px 7px rgba(0,0,0,.35),inset -2px -2px 5px rgba(255,255,255,.03); transition:border-color .18s,box-shadow .18s; }
    .filter-input::placeholder { color:rgba(255,255,255,.25); }
    .filter-input:focus, .filter-select:focus { outline:none; border-color:var(--primary-color,#3B6EA5); box-shadow:inset 3px 3px 7px rgba(0,0,0,.35),0 0 0 3px rgba(59,110,165,.2); }
    .filter-select option { background:#222221; color:#e2e2e0; }
    .clear-filters-btn { padding:10px 16px; background:var(--neu-surf); border:1px solid var(--neu-border); border-radius:8px; color:rgba(255,255,255,.5); font-size:14px; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .clear-filters-btn:hover { background:rgba(255,255,255,.07); color:#e2e2e0; }

    /* Table */
    .table-container { background:var(--neu-s2); border-radius:12px; border:1px solid var(--neu-border); box-shadow:var(--neu-card); overflow:hidden; }
    .table-header { padding:16px 24px; border-bottom:1px solid var(--neu-border); display:flex; align-items:center; justify-content:space-between; }
    .table-info { font-size:14px; color:rgba(255,255,255,.45); }
    .table-actions { display:flex; gap:12px; }
    .export-btn, .add-customer-btn { padding:10px 20px; border-radius:8px; font-size:14px; font-weight:500; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:8px; text-decoration:none; }
    .export-btn { background:var(--neu-surf); border:1px solid var(--neu-border); color:rgba(255,255,255,.7); }
    .export-btn:hover { background:rgba(255,255,255,.07); color:#e2e2e0; }
    .add-customer-btn { background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary-color) 100%); border:none; color:white; }
    .add-customer-btn:hover { opacity:.9; transform:translateY(-1px); color:white; }

    .customer-table { width:100%; border-collapse:collapse; }
    .customer-table thead { background:rgba(255,255,255,.04); border-bottom:1px solid var(--neu-border); }
    .customer-table th { padding:12px 16px; text-align:left; font-size:12px; font-weight:600; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.05em; }
    .customer-table td { padding:16px; border-bottom:1px solid rgba(255,255,255,.04); font-size:14px; color:#d4d4d2; }
    .customer-table tbody tr { transition:background .15s; }
    .customer-table tbody tr:hover { background:rgba(255,255,255,.03); }

    .customer-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--primary-color,#3B6EA5) 0%,var(--primary-dark,#2C5282) 100%); display:flex; align-items:center; justify-content:center; color:white; font-weight:600; font-size:14px; }
    .customer-info { display:flex; align-items:center; gap:12px; }
    .customer-name { font-weight:600; color:#e2e2e0; margin-bottom:2px; }
    .customer-id   { font-size:12px; color:rgba(255,255,255,.35); }
    .contact-info  { display:flex; flex-direction:column; gap:2px; }
    .contact-phone { font-weight:500; color:#d4d4d2; }
    .contact-email { font-size:12px; color:rgba(255,255,255,.4); }

    .status-badge { padding:4px 12px; border-radius:12px; font-size:12px; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
    .status-badge.active    { background:rgba(52,211,153,.15);  color:#6ee7b7; border:1px solid rgba(52,211,153,.25); }
    .status-badge.expired   { background:rgba(248,113,113,.15); color:#fca5a5; border:1px solid rgba(248,113,113,.25); }
    .status-badge.suspended { background:rgba(251,191,36,.15);  color:#fcd34d; border:1px solid rgba(251,191,36,.25); }
    .status-badge.inactive  { background:rgba(156,163,175,.12); color:rgba(255,255,255,.45); border:1px solid rgba(156,163,175,.2); }
    .status-dot { width:6px; height:6px; border-radius:50%; background:currentColor; }

    .expiry-date    { font-size:13px; color:#d4d4d2; }
    .expiry-warning { color:#f87171; }
    .payment-amount { font-weight:600; color:#e2e2e0; }
    .payment-period { font-size:12px; color:rgba(255,255,255,.4); }

    .action-buttons { display:flex; gap:8px; }
    .action-btn { width:32px; height:32px; border-radius:6px; border:1px solid var(--neu-border); background:var(--neu-surf); color:rgba(255,255,255,.5); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; transition:all .2s; text-decoration:none; box-shadow:3px 3px 7px rgba(0,0,0,.3),-2px -2px 5px rgba(255,255,255,.03); }
    .action-btn:hover { background:rgba(255,255,255,.08); border-color:var(--primary-color,#3B6EA5); color:var(--primary-color,#3B6EA5); }

    .row-action-wrap { display:flex; gap:6px; align-items:center; }
    .row-action-dropdown { position:relative; }
    .row-dd-menu { display:none; position:absolute; right:0; top:calc(100% + 4px); width:180px; background:#2a2a29; border:1px solid var(--neu-border); border-radius:8px; box-shadow:0 12px 32px rgba(0,0,0,.55); z-index:50; }
    .row-action-dropdown.open .row-dd-menu { display:block; }
    .row-dd-menu a { display:flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; color:rgba(255,255,255,.75); text-decoration:none; }
    .row-dd-menu a i { width:14px; color:rgba(255,255,255,.4); }
    .row-dd-menu a:hover { background:rgba(255,255,255,.06); color:#e2e2e0; }
    .row-dd-menu a.danger { color:#f87171; }
    .row-dd-menu a.danger i { color:#f87171; }
    .row-dd-divider { border-top:1px solid var(--neu-border); margin:3px 0; }

    #userModal > div, #clientModal > div, #customerFormModal > div { background:#222221 !important; border:1px solid var(--neu-border) !important; color:#e2e2e0 !important; }
    /* Dark inputs inside the add/edit customer modal */
    #customerFormModal input, #customerFormModal textarea {
        background: #1c1c1b !important;
        border-color: rgba(255,255,255,.1) !important;
        color: #e2e2e0 !important;
    }
    #customerFormModal input::placeholder, #customerFormModal textarea::placeholder { color: rgba(255,255,255,.3) !important; }
    #customerFormModal label[style*="color:#374151"], #customerFormModal label { color: rgba(255,255,255,.65) !important; }
    /* Section divider borders inside modal */
    #customerFormModal [style*="border-bottom:1px solid #F3F4F6"] { border-bottom-color: rgba(255,255,255,.07) !important; }
    /* Modal footer */
    #customerFormModal > div > div:last-child { background: #222221 !important; border-top-color: rgba(255,255,255,.07) !important; }

    .modal-tabs { display:flex; gap:0; border-bottom:1px solid var(--neu-border); background:#222221; flex-shrink:0; }
    .modal-tab-btn { padding:10px 16px; font-size:13px; font-weight:500; color:rgba(255,255,255,.45); background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; white-space:nowrap; transition:all .15s; }
    .modal-tab-btn.active { color:var(--primary-color,#3B6EA5); border-bottom-color:var(--primary-color,#3B6EA5); }
    .modal-tab-btn:hover:not(.active) { color:rgba(255,255,255,.7); background:rgba(255,255,255,.04); }
    .modal-tab-panel { display:none; }
    .modal-tab-panel.active { display:block; }

    .report-stat { background:var(--neu-surf); border:1px solid var(--neu-border); border-radius:8px; padding:12px 14px; }
    .report-stat-label { font-size:10px; font-weight:600; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
    .report-stat-value { font-size:20px; font-weight:700; color:#e2e2e0; }
    .report-stat-sub { font-size:11px; color:rgba(255,255,255,.4); margin-top:2px; }

    .modal-data-table { width:100%; border-collapse:collapse; font-size:12px; }
    .modal-data-table th { padding:8px 10px; text-align:left; font-size:10px; font-weight:600; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--neu-border); }
    .modal-data-table td { padding:9px 10px; border-bottom:1px solid rgba(255,255,255,.04); color:rgba(255,255,255,.65); }
    .modal-data-table tr:last-child td { border-bottom:none; }
    .modal-data-table tr:hover td { background:rgba(255,255,255,.03); }

    .pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; }
    .pill.success   { background:rgba(52,211,153,.15);  color:#6ee7b7; }
    .pill.pending   { background:rgba(251,191,36,.15);  color:#fcd34d; }
    .pill.failed    { background:rgba(248,113,113,.15); color:#fca5a5; }
    .pill.sent      { background:rgba(96,165,250,.15);  color:#93c5fd; }
    .pill.delivered { background:rgba(52,211,153,.15);  color:#6ee7b7; }

    .expiry-quick-btn { padding:6px 12px; font-size:12px; font-weight:500; color:var(--primary-color,#3B6EA5); background:rgba(59,110,165,.12); border:1px solid var(--primary-color,#3B6EA5); border-radius:6px; cursor:pointer; transition:all .15s; }
    .expiry-quick-btn:hover { background:var(--primary-color,#3B6EA5); color:white; }

    @keyframes pulseDot { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.5; transform:scale(1.3); } }

    /* ── Override all inline white backgrounds inside modals ── */
    #userModal [style*="background:white"], #userModal [style*="background: white"],
    #userModal [style*="background:#fff"],  #userModal [style*="background: #fff"],
    #clientModal [style*="background:white"], #clientModal [style*="background: white"],
    #clientModal [style*="background:#fff"],  #clientModal [style*="background: #fff"],
    #customerFormModal [style*="background:white"], #customerFormModal [style*="background: white"] {
        background: var(--neu-surf) !important;
        border-color: var(--neu-border) !important;
        color: #d4d4d2 !important;
    }
    /* Override inline color:#374151 / #111827 text inside modals */
    #userModal [style*="color:#374151"], #userModal [style*="color: #374151"],
    #userModal [style*="color:#111827"], #userModal [style*="color: #111827"],
    #clientModal [style*="color:#374151"], #clientModal [style*="color:#111827"] { color: #d4d4d2 !important; }
    /* Inline button with white bg */
    #userModal button[style*="background:white"], #userModal button[style*="background: white"],
    #userModal button[style*="background:#fff"] { background: var(--neu-surf) !important; border-color: var(--neu-border) !important; color: rgba(255,255,255,.7) !important; }
    /* Clear filters inline button */
    .clear-filters-btn[style*="background:#fff"] { background: var(--neu-surf) !important; border-color: var(--neu-border) !important; color: rgba(255,255,255,.5) !important; }
    /* Actions dropdown menu inside modal */
    #actionsMenu[style*="background:white"] { background: #2a2a29 !important; border-color: var(--neu-border) !important; }
    /* Record payment form */
    #recordPaymentForm { background: var(--neu-surf) !important; border-color: var(--neu-border) !important; }
    /* Payment / SMS table wraps inside modal */
    #paymentsTableWrap, #smsTableWrap { background: var(--neu-surf) !important; border-color: var(--neu-border) !important; }
    /* ── Online status indicator ── */
    .online-dot { display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:4px;vertical-align:middle;box-shadow:0 0 6px #10b981;animation:pulseDot 2s infinite; }
    .tr-online .customer-name::before { content:''; display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;margin-right:5px;vertical-align:middle;box-shadow:0 0 5px #10b981; }

    @media (max-width:1024px) { .filters-grid { grid-template-columns:1fr 1fr; } }
    @media (max-width:768px)  { .customers-container { padding:16px; } .table-header { flex-direction:column; gap:12px; align-items:flex-start; } .table-actions { width:100%; } .export-btn,.add-customer-btn { flex:1; justify-content:center; } }
    @media (max-width:640px)  { .filters-grid { grid-template-columns:1fr; } #userModal > div { max-width:100% !important; margin:0 !important; border-radius:10px !important; } #customerFormModal > div { max-width:100% !important; margin:0 !important; border-radius:10px !important; } }
</style>

<div class="main-content-wrapper">
    <div class="customers-container">
        <!-- Header -->
        <div class="customers-header">
            <h1 class="customers-title">Customer Management</h1>
            <p class="customers-subtitle">Manage customer accounts, services, and billing operations</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon total">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_customers; ?></div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-change">
                    <span class="metric-period">Total Database</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon active">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $active_services; ?></div>
                <div class="stat-label">Active Services</div>
                <div class="stat-change">
                    <span class="metric-period">Current Active</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon expired">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $expired_services; ?></div>
                <div class="stat-label">Expired Services</div>
                <div class="stat-change">
                    <span class="metric-period">Total Expired</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $expiring_soon; ?></div>
                <div class="stat-label">Expiring Soon</div>
                <div class="stat-change">
                    <i class="fas fa-clock"></i> Next 7 days
                </div>
            </div>
        </div>

        <!-- Filters Bar -->
        <div class="filters-bar">
            <form id="filterForm" method="GET" action="clients.php" class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">Search by name, phone, email, or customer ID...</label>
                    <input type="text" name="search" class="filter-input" placeholder="Type to search..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">All Packages</label>
                    <select name="package" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Packages</option>
                        <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['id']; ?>" <?php echo (isset($_GET['package']) && $_GET['package'] == $pkg['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pkg['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">All Status</label>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?php echo (isset($_GET['status']) && $_GET['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo (isset($_GET['status']) && $_GET['status'] == 'expired') ? 'selected' : ''; ?>>Expired</option>
                        <option value="inactive" <?php echo (isset($_GET['status']) && $_GET['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <button type="button" onclick="window.location.href='clients.php'" class="clear-filters-btn" style="border:1px solid #ddd; background:#fff; padding:8px 12px; border-radius:6px; cursor:pointer;">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
            </form>
        </div>

        <!-- Customer Table -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-info">
                    <i class="fas fa-users"></i> Showing <?php echo count($customers); ?> customers
                </div>
                <div class="table-actions">
                    <button class="export-btn" id="syncRouterBtn" onclick="syncOnlineStatus()" title="Fetch live session data from MikroTik">
                        <i class="fas fa-satellite-dish" id="syncIcon"></i>
                        <span id="syncLabel">Sync Router</span>
                    </button>
                    <button class="export-btn" onclick="exportCSV()">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </button>
                    <button class="add-customer-btn" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        Add Customer
                    </button>
                </div>
            </div>

            <table class="customer-table">
                <thead>
                    <tr>
                        <th>CUSTOMER</th>
                        <th>CONTACT</th>
                        <th>PACKAGE</th>
                        <th>STATUS</th>
                        <th>ONLINE</th>
                        <th>EXPIRY</th>
                        <th>PAYMENTS</th>
                        <th>VIEW</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer):
                        $initials = '';
                        if (!empty($customer['full_name'])) {
                            $names = explode(' ', $customer['full_name']);
                            $initials = strtoupper(substr($names[0], 0, 1));
                            if (count($names) > 1) {
                                $initials .= strtoupper(substr($names[count($names)-1], 0, 1));
                            }
                        }
                        
                        // DB subscription status only — no MikroTik logic here (done client-side)
                        $dbStatus    = strtolower($customer['status'] ?? 'inactive');
                        $expiry_date = $customer['expiry_date'] ?? null;
                        $is_expired  = $expiry_date && strtotime($expiry_date) < time();
                        $dispStatus  = $is_expired ? 'expired' : $dbStatus;

                        $statusMeta = [
                            'active'    => ['bg'=>'#D1FAE5','color'=>'#065F46','label'=>'Active'],
                            'inactive'  => ['bg'=>'#F3F4F6','color'=>'#6B7280','label'=>'Inactive'],
                            'suspended' => ['bg'=>'#FEF3C7','color'=>'#92400E','label'=>'Suspended'],
                            'expired'   => ['bg'=>'#FEE2E2','color'=>'#991B1B','label'=>'Expired'],
                        ];
                        $sm = $statusMeta[$dispStatus] ?? $statusMeta['inactive'];

                        // Prepare JSON for JS
                        $customerJson = htmlspecialchars(json_encode($customer), ENT_QUOTES, 'UTF-8');
                        $mikrotikUser = htmlspecialchars($customer['mikrotik_username'] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr onclick='viewCustomer(<?php echo $customerJson; ?>)' style="cursor:pointer;" data-username="<?php echo $mikrotikUser; ?>" data-client-id="<?php echo $customer['id']; ?>">
                        <td>
                            <div class="customer-info">
                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                <div>
                                    <div class="customer-name"><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></div>
                                    <div class="customer-id"><?php
                                        if (!empty($customer['account_number'])) {
                                            echo htmlspecialchars($customer['account_number']);
                                        } else {
                                            $pfx = strtoupper(substr($customer['mikrotik_username'] ?? $customer['full_name'] ?? 'C', 0, 1));
                                            echo $pfx . str_pad($customer['id'], 3, '0', STR_PAD_LEFT);
                                        }
                                    ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="contact-info">
                                <div class="contact-phone"><?php echo htmlspecialchars($customer['phone'] ?? '—'); ?></div>
                                <div class="contact-email"><?php echo htmlspecialchars($customer['email'] ?? ''); ?></div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($customer['package_name'] ?? $customer['subscription_plan'] ?? '—'); ?></td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>;">
                                <?php if ($dispStatus === 'active'): ?><span style="width:6px;height:6px;border-radius:50%;background:<?php echo $sm['color']; ?>;flex-shrink:0;"></span><?php endif; ?>
                                <?php echo $sm['label']; ?>
                            </span>
                        </td>
                        <!-- ONLINE column — filled by JS after MikroTik fetch -->
                        <td class="online-badge-cell">
                            <span class="online-badge" title="Checking…">
                                <span style="font-size:11px;color:#D1D5DB;">—</span>
                            </span>
                        </td>
                        <td>
                            <div class="expiry-date <?php echo $is_expired ? 'expiry-warning' : ''; ?>">
                                <?php echo $expiry_date ? date('d/m/Y', strtotime($expiry_date)) : '—'; ?>
                                <?php if ($is_expired): ?><br><small style="color:#EF4444;">Expired</small><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="payment-amount">KES <?php echo number_format($customer['package_price'] ?? 0, 0); ?></div>
                            <div class="payment-period"><?php echo $customer['payments_count'] ?? 0; ?> payments</div>
                        </td>
                        <!-- VIEW column — just eye icon, all other actions inside the modal -->
                        <td onclick="event.stopPropagation()">
                            <button onclick='viewCustomer(<?php echo $customerJson; ?>)' class="action-btn" title="View Customer" style="color:var(--primary-color,#3B6EA5);">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- ═══════════════════════════════════════════════════════════════
     CUSTOMER DETAIL MODAL  (5 tabs)
════════════════════════════════════════════════════════════════ -->
<div id="userModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1000;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#1e1e1d;width:100%;max-width:820px;border-radius:16px;max-height:92vh;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.07);display:flex;flex-direction:column;">

    <!-- ── HEADER ─────────────────────────────────────────── -->
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-shrink:0;background:rgba(255,255,255,.02);">
        <div style="display:flex;gap:12px;align-items:center;min-width:0;">
            <div id="modalAvatar" style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:white;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.4);"></div>
            <div style="min-width:0;">
                <div style="font-size:16px;font-weight:700;color:#e2e2e0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="modalUserName"></div>
                <div style="display:flex;align-items:center;gap:8px;margin-top:3px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:700;color:var(--primary-light,#93c5fd);font-family:monospace;" id="modalAcctNum"></span>
                    <span id="modalStatusBadge"></span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-shrink:0;">
            <button onclick="openExpiryModal()" style="padding:6px 10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:12px;font-weight:500;color:#d4d4d2;cursor:pointer;white-space:nowrap;transition:background .15s;">
                <i class="fas fa-calendar-alt" style="color:var(--primary-light,#93c5fd);"></i> Change Expiry
            </button>
            <button id="pauseSubBtn" onclick="pauseSubscription()" title="Pause subscription" style="padding:6px 10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:12px;font-weight:500;color:#d4d4d2;cursor:pointer;white-space:nowrap;transition:background .15s;">
                <i class="fas fa-pause" style="color:#fbbf24;"></i> Pause
            </button>
            <div style="position:relative;">
                <button style="padding:6px 10px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;" onclick="toggleActionsMenu()">
                    Actions <i class="fas fa-chevron-down" style="font-size:10px;"></i>
                </button>
                <div id="actionsMenu" style="display:none;position:absolute;top:100%;right:0;width:195px;background:#2a2a29;border:1px solid rgba(255,255,255,.08);border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.6);margin-top:4px;z-index:20;overflow:hidden;">
                    <a href="#" onclick="editUser();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-edit" style="color:rgba(255,255,255,.4);width:14px;"></i> Edit Details</a>
                    <a href="#" onclick="promptPayment();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-mobile-alt" style="color:#34d399;width:14px;"></i> Payment Prompt</a>
                    <a href="#" onclick="switchToTab('sms');openSMSModal(currentCustomer);return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-comment" style="color:#60a5fa;width:14px;"></i> Send SMS</a>
                    <div style="border-top:1px solid rgba(255,255,255,.06);margin:3px 0;"></div>
                    <a href="#" onclick="confirmDelete(currentCustomer.id,currentCustomer.full_name||currentCustomer.name);return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#f87171;text-decoration:none;font-size:13px;"><i class="fas fa-trash" style="width:14px;"></i> Delete</a>
                </div>
            </div>
            <button onclick="closeModal()" style="padding:4px 8px;background:transparent;border:none;font-size:22px;cursor:pointer;color:rgba(255,255,255,.4);line-height:1;">&times;</button>
        </div>
    </div>

    <!-- ── PACKAGE INFO BAR ───────────────────────────────── -->
    <div style="padding:7px 20px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;color:rgba(255,255,255,.45);flex-shrink:0;" id="modalPackageInfo"></div>

    <!-- ── TABS ──────────────────────────────────────────── -->
    <div class="modal-tabs" style="padding:0 20px;background:#1e1e1d;border-bottom:1px solid rgba(255,255,255,.06);">
        <button class="modal-tab-btn active" onclick="switchToTab('general')">General</button>
        <button class="modal-tab-btn" onclick="switchToTab('reports')">Reports</button>
        <button class="modal-tab-btn" onclick="switchToTab('payments')">Payments</button>
        <button class="modal-tab-btn" onclick="switchToTab('sms')">SMS</button>
        <button class="modal-tab-btn" onclick="switchToTab('fup')"><i class="fas fa-tachometer-alt" style="font-size:10px;margin-right:4px;"></i>FUP</button>
    </div>

    <!-- ── TAB CONTENT ────────────────────────────────────── -->
    <div style="overflow-y:auto;flex:1;background:#181817;">

        <!-- ── GENERAL TAB ─────────────────────────────── -->
        <div class="modal-tab-panel active" id="tab-general" style="padding:16px 20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Account Number</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:700;color:#e2e2e0;font-family:monospace;font-size:15px;" id="infoId"></span>
                        <button onclick="copyField('infoId')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Full Name</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoName"></span>
                        <button onclick="copyField('infoName')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Username</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-size:13px;font-family:monospace;" id="infoUsername"></span>
                        <button onclick="copyField('infoUsername')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Password</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#e2e2e0;font-family:monospace;">
                            <span id="pwdHidden">••••••••</span>
                            <span id="pwdValue" style="display:none;font-size:13px;"></span>
                        </span>
                        <div style="display:flex;gap:4px;flex-shrink:0;">
                            <button onclick="togglePwd()" style="color:rgba(255,255,255,.4);background:none;border:none;cursor:pointer;padding:2px 4px;" title="Show/Hide"><i class="fas fa-eye" id="pwdEye" style="font-size:13px;"></i></button>
                            <button onclick="copyField('pwdValue')" style="font-size:10px;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);padding:2px 7px;border-radius:4px;cursor:pointer;">Copy</button>
                        </div>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Package</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoPackage"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Status</div>
                    <div id="infoStatus"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Phone Number</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoPhone"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Connection Type</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoType"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Connectivity</div>
                    <div id="infoOnlineStatus" style="font-weight:600;font-size:13px;">
                        <span style="color:rgba(255,255,255,.3);font-size:12px;">Checking…</span>
                    </div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Address</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoAddress"></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);box-shadow:4px 4px 10px rgba(0,0,0,.3),-2px -2px 6px rgba(255,255,255,.02);grid-column:span 2;">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Expiry / Time Remaining</div>
                    <div style="font-weight:600;color:#e2e2e0;font-size:13px;" id="infoTime"></div>
                </div>
            </div>
        </div>

        <!-- ── REPORTS TAB ──────────────────────────────── -->
        <div class="modal-tab-panel" id="tab-reports" style="padding:16px 20px;">
            <div id="reportsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;">
                <i class="fas fa-spinner fa-spin"></i> Loading analytics…
            </div>
            <div id="reportsContent" style="display:none;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;" id="reportStats1"></div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;" id="reportStats2"></div>
                <div style="background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:14px;margin-bottom:12px;">
                    <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.55);margin-bottom:10px;">Monthly Payments (Last 6 Months)</div>
                    <div style="height:180px;"><canvas id="clientPaymentChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- ── PAYMENTS TAB ─────────────────────────────── -->
        <div class="modal-tab-panel" id="tab-payments" style="padding:16px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:13px;font-weight:600;color:#e2e2e0;" id="paymentsTabTitle">Payments</span>
                <button onclick="openRecordPaymentForm()" style="padding:7px 14px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;">
                    <i class="fas fa-plus"></i> Record Payment
                </button>
            </div>
            <div id="recordPaymentForm" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:14px;margin-bottom:12px;">
                <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,.55);margin-bottom:10px;">Record a Payment</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Amount (KES) *</label>
                        <input type="number" id="rpAmount" placeholder="e.g. 1500" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Reference / Code *</label>
                        <input type="text" id="rpReference" placeholder="e.g. QAB123456" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Method</label>
                        <select id="rpMethod" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;background:#1c1c1b;color:#e2e2e0;">
                            <option value="M-Pesa">M-Pesa</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Date</label>
                        <input type="datetime-local" id="rpDate" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;">
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;">Notes (optional)</label>
                    <input type="text" id="rpNotes" placeholder="Optional note" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;">
                </div>
                <div style="display:flex;gap:8px;">
                    <button onclick="submitRecordPayment()" style="padding:7px 16px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;">Save Payment</button>
                    <button onclick="document.getElementById('recordPaymentForm').style.display='none'" style="padding:7px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:12px;cursor:pointer;color:#d4d4d2;">Cancel</button>
                </div>
            </div>
            <div id="paymentsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
            <div id="paymentsTableWrap" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;overflow:hidden;">
                <table class="modal-data-table">
                    <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Phone</th><th>Ref / Code</th><th>Confirmed</th></tr></thead>
                    <tbody id="paymentsTableBody"></tbody>
                </table>
                <div id="paymentsEmpty" style="display:none;padding:24px;text-align:center;color:rgba(255,255,255,.3);font-size:13px;">No payments recorded yet.</div>
            </div>
        </div>

        <!-- ── SMS TAB ───────────────────────────────────── -->
        <div class="modal-tab-panel" id="tab-sms" style="padding:16px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:13px;font-weight:600;color:#e2e2e0;">SMS History</span>
                <button onclick="openSMSModal(currentCustomer)" style="padding:7px 14px;background:linear-gradient(135deg,var(--primary-dark,#2C5282),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;">
                    <i class="fas fa-paper-plane"></i> Send SMS
                </button>
            </div>
            <div id="smsLoading" style="text-align:center;padding:30px;color:rgba(255,255,255,.35);font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
            <div id="smsTableWrap" style="display:none;background:#222221;border:1px solid rgba(255,255,255,.06);border-radius:8px;overflow:hidden;">
                <table class="modal-data-table">
                    <thead><tr><th>Date</th><th>Phone</th><th>Message</th><th>Status</th></tr></thead>
                    <tbody id="smsTableBody"></tbody>
                </table>
                <div id="smsEmpty" style="display:none;padding:24px;text-align:center;color:rgba(255,255,255,.3);font-size:13px;">No SMS messages sent yet.</div>
            </div>
        </div>

        <!-- ── FUP TAB ───────────────────────────────────── -->
        <div class="modal-tab-panel" id="tab-fup" style="padding:16px 20px;">
            <!-- Section title -->
            <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:7px;">
                <i class="fas fa-tachometer-alt" style="color:#a5b4fc;"></i> Bandwidth Policy Details
            </div>

            <!-- Current State banner -->
            <div style="background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.18);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-check-circle" style="color:#34d399;font-size:16px;"></i>
                <div>
                    <div style="font-size:12px;font-weight:700;color:#34d399;">Current State: Normal</div>
                    <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:2px;">No bandwidth policy is currently active for this user.</div>
                </div>
            </div>

            <!-- Two-column grid of FUP fields -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">FUP Started</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not set</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Why FUP Started</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">—</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Window Start</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not set</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Window End</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not set</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Used vs Limit</div>
                    <div style="font-size:13px;font-weight:600;color:#e2e2e0;">0 B <span style="color:rgba(255,255,255,.3);font-size:11px;font-weight:400;">of Not set</span></div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Current Action</div>
                    <div style="font-size:13px;font-weight:600;color:#34d399;">No action required</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Last Reconciled</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not reconciled</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Manual Override</div>
                    <div style="font-size:13px;font-weight:600;color:#e2e2e0;">Inherited package policy</div>
                </div>
            </div>

            <!-- Package Policy Snapshot -->
            <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.06);">
                Package Policy Snapshot
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Quota</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not set</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Enforcement Mode</div>
                    <div style="font-size:13px;font-weight:600;color:#e2e2e0;">Inherit</div>
                </div>
                <div style="background:#222221;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.06);">
                    <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Throttle Rate</div>
                    <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,.35);font-style:italic;">Not applicable</div>
                </div>
            </div>
        </div>

    </div><!-- end scroll area -->
</div><!-- end modal card -->
</div><!-- end overlay -->

<!-- ═══════════════════════════════════════════════════════════════
     CHANGE EXPIRY MODAL
════════════════════════════════════════════════════════════════ -->
<div id="expiryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:1050;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#1e1e1d;width:100%;max-width:500px;border-radius:16px;padding:0;box-shadow:0 32px 80px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.07);overflow:hidden;">
    <!-- Header -->
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;background:#222221;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(251,191,36,.12);display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-calendar-alt" style="color:#fbbf24;font-size:14px;"></i>
            </div>
            <div style="font-size:15px;font-weight:700;color:#e2e2e0;">Change Expiry</div>
        </div>
        <button onclick="closeExpiryModal()" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:7px;width:28px;height:28px;font-size:16px;cursor:pointer;color:#9ca3af;display:flex;align-items:center;justify-content:center;line-height:1;">&times;</button>
    </div>
    <div style="padding:20px;">
        <!-- Quick add buttons -->
        <div style="margin-bottom:18px;">
            <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Quick Extend</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap;">
                <button onclick="applyQuickExpiry(60)" class="expiry-quick-btn">+1 Hour</button>
                <button onclick="applyQuickExpiry(720)" class="expiry-quick-btn">+12 Hours</button>
                <button onclick="applyQuickExpiry(1440)" class="expiry-quick-btn">+1 Day</button>
                <button onclick="applyQuickExpiry(10080)" class="expiry-quick-btn">+7 Days</button>
                <button onclick="applyQuickExpiry(43200)" class="expiry-quick-btn">+1 Month</button>
                <button onclick="applyQuickExpiry(129600)" class="expiry-quick-btn">+3 Months</button>
            </div>
        </div>
        <!-- Set specific date -->
        <div style="margin-bottom:16px;">
            <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Set Specific Date</div>
            <div style="display:flex;gap:8px;">
                <input type="datetime-local" id="expiryDateInput" style="flex:1;padding:8px 10px;background:#1c1c1b;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;color:#e2e2e0;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;box-sizing:border-box;">
                <button onclick="applySetDate()" style="padding:8px 14px;background:var(--primary-color,#3B6EA5);color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Set Date</button>
            </div>
        </div>
        <!-- Change package -->
        <div style="margin-bottom:16px;">
            <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Change Package</div>
            <div style="display:flex;gap:8px;">
                <select id="expiryPackageSelect" style="flex:1;padding:8px 10px;background:#1c1c1b;border:1px solid rgba(255,255,255,.08);border-radius:7px;font-size:13px;color:#e2e2e0;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;">
                    <option value="" style="background:#1c1c1b;">— Keep current package —</option>
                    <?php foreach ($packages as $pkg): ?>
                    <option value="<?php echo $pkg['id']; ?>" data-type="<?php echo htmlspecialchars($pkg['type']); ?>" style="background:#1c1c1b;"><?php echo htmlspecialchars($pkg['name']); ?> — KES <?php echo number_format($pkg['price']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="applyChangePackage()" style="padding:8px 14px;background:#059669;color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Apply</button>
            </div>
        </div>
        <!-- Grace period -->
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px;">
            <div style="font-size:10px;font-weight:600;color:rgba(251,191,36,.9);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Grace Period (added on top)</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <input type="number" id="graceHoursInput" min="0" max="720" value="0" style="width:80px;padding:7px;background:#1c1c1b;border:1px solid rgba(251,191,36,.25);border-radius:6px;font-size:13px;text-align:center;color:#fcd34d;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;">
                <span style="font-size:13px;color:rgba(251,191,36,.85);">hours of grace period</span>
            </div>
        </div>
        <!-- Current expiry info -->
        <div style="margin-top:14px;padding:10px 12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:7px;font-size:12px;color:rgba(255,255,255,.6);">
            Current expiry: <strong id="currentExpiryDisplay" style="color:#e2e2e0;"></strong>
        </div>
    </div>
</div>
</div>

<!-- Add/Edit form Modal -->
<div id="customerFormModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1001;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:white;width:100%;max-width:680px;border-radius:14px;max-height:92vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;">
    <!-- Header -->
    <div style="padding:16px 20px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
        <div>
            <div id="formModalTitle" style="font-size:16px;font-weight:700;color:#111827;">Add Customer</div>
            <div style="font-size:12px;color:#9CA3AF;margin-top:2px;">Fill in the details below</div>
        </div>
        <button onclick="closeFormModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;padding:4px;">&times;</button>
    </div>
    <!-- Scrollable body -->
    <div style="overflow-y:auto;flex:1;padding:20px;">
        <form id="customerForm" onsubmit="handleFormSubmit(event)">
            <input type="hidden" name="id" id="formId">

            <!-- Section: Personal Info -->
            <div style="font-size:11px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.07);">Personal Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Full Name *</label>
                    <input type="text" name="name" id="formName" required style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;transition:border .15s;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Phone Number *</label>
                    <input type="text" name="phone" id="formPhone" required style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Email Address</label>
                    <input type="email" name="email" id="formEmail" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Physical Address</label>
                    <input type="text" name="address" id="formAddress" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                </div>
            </div>

            <!-- Section: Service Details -->
            <div style="font-size:11px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.07);margin-top:6px;">Service Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Package *</label>
                    <select name="package_id" id="formPackageId" required style="width:100%;padding:9px 11px;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;background:#1c1c1b;color:#e2e2e0;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='rgba(255,255,255,.1)'">
                        <option value="">Select Package</option>
                        <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['id']; ?>" data-type="<?php echo htmlspecialchars($pkg['type']); ?>"><?php echo htmlspecialchars($pkg['name']); ?> — KES <?php echo number_format($pkg['price']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Connection Type</label>
                    <select name="connection_type" id="formConnectionType" style="width:100%;padding:9px 11px;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;background:#1c1c1b;color:#e2e2e0;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='rgba(255,255,255,.1)'">
                        <option value="pppoe">PPPoE</option>
                        <option value="hotspot">Hotspot</option>
                        <option value="static">Static IP</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Expiry Date <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                    <input type="date" name="expiry_date" id="formExpiryDate" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;">Leave blank for default package duration</div>
                </div>
            </div>

            <!-- Section: Access Credentials -->
            <div style="font-size:11px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.07);margin-top:6px;">Access Credentials</div>
            <div style="background:var(--neu-surf,#1c1c1b);border:1px solid var(--neu-border,rgba(255,255,255,.06));border-radius:8px;padding:14px;margin-bottom:6px;">
                <div style="font-size:11px;color:rgba(255,255,255,.5);margin-bottom:12px;">Used for both Router (PPPoE/Hotspot) and Customer Portal login. Same credentials work on both.</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Username *</label>
                        <input type="text" name="mikrotik_username" id="formMikrotikUsername" required style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;background:white;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Password</label>
                        <input type="password" name="mikrotik_password" id="formMikrotikPassword" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;background:white;" placeholder="Leave blank to keep current" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                    </div>
                </div>
            </div>
        </form>
    </div>
    <!-- Footer -->
    <div style="padding:14px 20px;border-top:1px solid #E5E7EB;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;background:white;">
        <button type="button" onclick="closeFormModal()" style="padding:9px 18px;background:white;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;color:#374151;">Cancel</button>
        <button type="submit" form="customerForm" style="padding:9px 22px;background:linear-gradient(135deg,var(--primary-dark,#2a5a8f) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Save Customer</button>
    </div>
</div>
</div>

<!-- SMS Modal -->
<div id="smsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1002;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:white;width:100%;max-width:500px;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;">
    <!-- Header -->
    <div style="padding:16px 20px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
        <div>
            <div style="font-size:15px;font-weight:700;color:#111827;">Send SMS</div>
            <div style="font-size:12px;color:#9CA3AF;margin-top:2px;">To: <span id="smsCustomerName" style="color:#374151;font-weight:500;"></span></div>
        </div>
        <button onclick="closeSMSModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;padding:4px;">&times;</button>
    </div>
    <!-- Body -->
    <div style="padding:20px;">
        <form onsubmit="handleSendSMS(event)" id="smsForm">
            <input type="hidden" name="client_id" id="smsClientId">
            <input type="hidden" name="phone" id="smsClientPhone">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Template <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                <select id="smsTemplate" onchange="applyTemplate()" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:white;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                    <option value="">— Select a Template —</option>
                    <option value="credentials">Login Credentials</option>
                    <option value="payment">Payment Details</option>
                    <option value="alert">Service Alert</option>
                    <option value="promo">Promotional Message</option>
                </select>
            </div>
            <div style="margin-bottom:6px;">
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Message *</label>
                <textarea name="message" id="smsMessage" rows="5" required style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;" placeholder="Type your message here…" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'"></textarea>
                <div style="font-size:11px;color:#9CA3AF;margin-top:3px;text-align:right;" id="smsCharCount">0 characters</div>
            </div>
        </form>
    </div>
    <!-- Footer -->
    <div style="padding:14px 20px;border-top:1px solid #E5E7EB;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;background:white;">
        <button type="button" onclick="closeSMSModal()" style="padding:9px 18px;background:white;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;color:#374151;">Cancel</button>
        <button type="submit" form="smsForm" style="padding:9px 20px;background:linear-gradient(135deg,#1D4ED8 0%,#3B82F6 100%);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-paper-plane"></i> Send SMS</button>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
let currentCustomer = null;
let clientPaymentChart = null;

function viewCustomer(customerJson) {
    if (typeof customerJson === 'string') {
        currentCustomer = JSON.parse(customerJson);
    } else {
        currentCustomer = customerJson;
    }

    const fullName = currentCustomer.full_name || currentCustomer.name || 'Unknown';
    const _prefix  = ((currentCustomer.mikrotik_username || currentCustomer.username || '').charAt(0) || 'C').toUpperCase();
    const acctNum  = currentCustomer.account_number || (_prefix + String(currentCustomer.id).padStart(3, '0'));
    const status   = (currentCustomer.status || 'inactive').toLowerCase();

    // Avatar initials
    const avatarEl = document.getElementById('modalAvatar');
    if (avatarEl) {
        const parts = fullName.trim().split(' ');
        avatarEl.textContent = (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }

    document.getElementById('modalUserName').textContent = fullName;
    document.getElementById('modalAcctNum').textContent  = acctNum;

    // Status badge
    const badgeEl = document.getElementById('modalStatusBadge');
    if (badgeEl) {
        const badgeStyles = {
            active:    'background:#D1FAE5; color:#065F46;',
            inactive:  'background:#F3F4F6; color:#6B7280;',
            suspended: 'background:#FEF3C7; color:#92400E;',
            expired:   'background:#FEE2E2; color:#991B1B;'
        };
        badgeEl.style.cssText = 'padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; ' + (badgeStyles[status] || badgeStyles.inactive);
        badgeEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    }

    // Core fields
    document.getElementById('infoId').textContent       = acctNum;
    document.getElementById('infoName').textContent     = fullName;
    document.getElementById('infoUsername').textContent = currentCustomer.mikrotik_username || currentCustomer.username || '—';

    // Password — show plain mikrotik_password (what the router uses)
    const pwd = currentCustomer.mikrotik_password || '';
    document.getElementById('pwdValue').textContent = pwd || '(hidden)';
    document.getElementById('pwdHidden').style.display = 'inline';
    document.getElementById('pwdValue').style.display  = 'none';
    document.getElementById('pwdEye').className = 'fas fa-eye';

    document.getElementById('infoPhone').textContent = currentCustomer.phone || '—';
    document.getElementById('infoAddress').textContent = currentCustomer.address || currentCustomer.location || '—';
    document.getElementById('infoPackage').textContent = (currentCustomer.package_name || currentCustomer.subscription_plan || 'N/A');
    document.getElementById('infoType').textContent    = (currentCustomer.connection_type || 'PPPoE').toUpperCase();

    // Status badge inside grid
    const statusGrid = document.getElementById('infoStatus');
    if (statusGrid) {
        const sc = { active:'#D1FAE5|#065F46', inactive:'#F3F4F6|#6B7280', suspended:'#FEF3C7|#92400E', expired:'#FEE2E2|#991B1B' };
        const [bg, fg] = (sc[status] || sc.inactive).split('|');
        statusGrid.innerHTML = `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:${bg};color:${fg};">
            <span style="width:6px;height:6px;border-radius:50%;background:${fg};"></span>${status.charAt(0).toUpperCase()+status.slice(1)}</span>`;
    }

    // Expiry with time remaining
    const timeEl = document.getElementById('infoTime');
    if (currentCustomer.expiry_date) {
        const timeLeft = calculateTimeLeft(currentCustomer.expiry_date);
        const expired  = new Date(currentCustomer.expiry_date) < new Date();
        timeEl.innerHTML = `${formatDate(currentCustomer.expiry_date)} &nbsp;<span style="color:${expired?'#EF4444':'#059669'};font-size:12px;">(${timeLeft})</span>`;
    } else {
        timeEl.textContent = '—';
    }

    document.getElementById('modalPackageInfo').textContent =
        'Package: ' + (currentCustomer.package_name || 'N/A') +
        ' · KES ' + (parseFloat(currentCustomer.package_price||0)).toLocaleString() +
        ' · Expires: ' + formatDate(currentCustomer.expiry_date);

    // Connectivity status from cached MikroTik data
    const onlineEl = document.getElementById('infoOnlineStatus');
    if (onlineEl) {
        const uname = (currentCustomer.mikrotik_username || '').toLowerCase();
        if (!uname) {
            onlineEl.innerHTML = '<span style="color:#9CA3AF;font-size:12px;">No username configured</span>';
        } else if (onlineStatusCache.set && onlineStatusCache.set.size > 0) {
            updateModalOnlineStatus(onlineStatusCache.set, onlineStatusCache.details);
        } else {
            onlineEl.innerHTML = '<span style="color:#D1D5DB;font-size:12px;">Checking…</span>';
            loadOnlineStatus(); // Trigger fresh fetch
        }
    }

    updatePauseBtn(status);
    document.getElementById('userModal').style.display = 'flex';
}

/* ── Tab switching ─────────────────────────────────────────── */
function switchToTab(name) {
    document.querySelectorAll('.modal-tab-btn').forEach((b,i) => {
        const tabs = ['general','reports','payments','sms','fup'];
        b.classList.toggle('active', tabs[i] === name);
    });
    document.querySelectorAll('.modal-tab-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');

    if (name === 'reports'  && currentCustomer) loadReports(currentCustomer.id);
    if (name === 'payments' && currentCustomer) loadPayments(currentCustomer.id);
    if (name === 'sms'      && currentCustomer) loadSMSHistory(currentCustomer.id);
}

/* ── Sync online status from MikroTik ─────────────────────── */
function syncOnlineStatus() {
    const btn   = document.getElementById('syncRouterBtn');
    const icon  = document.getElementById('syncIcon');
    const label = document.getElementById('syncLabel');
    if (!btn) return;
    btn.disabled = true;
    icon.className  = 'fas fa-spinner fa-spin';
    label.textContent = 'Syncing…';

    fetch('api/clients/online_status.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const online = (data.online || []).map(u => u.toLowerCase());
                // Mark rows
                document.querySelectorAll('tr[data-username]').forEach(tr => {
                    const uname = (tr.getAttribute('data-username') || '').toLowerCase();
                    if (online.includes(uname)) {
                        tr.classList.add('tr-online');
                    } else {
                        tr.classList.remove('tr-online');
                    }
                });
                showToast(`Router synced — ${online.length} session${online.length !== 1 ? 's' : ''} online`, 'success');
            } else {
                showToast('Sync failed: ' + (data.message || 'Router unreachable'), 'error');
            }
        })
        .catch(() => showToast('Could not reach router — check connectivity', 'error'))
        .finally(() => {
            btn.disabled   = false;
            icon.className = 'fas fa-satellite-dish';
            label.textContent = 'Sync Router';
        });
}

/* ── Modal open/close ──────────────────────────────────────── */
function closeModal() {
    document.getElementById('userModal').style.display = 'none';
    document.getElementById('actionsMenu').style.display = 'none';
    switchToTab('general');
}

function closeFormModal() {
    document.getElementById('customerFormModal').style.display = 'none';
}

function closeSMSModal() {
    document.getElementById('smsModal').style.display = 'none';
}

function toggleActionsMenu() {
    const menu = document.getElementById('actionsMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

/* ── Expiry modal ──────────────────────────────────────────── */
function openExpiryModal() {
    if (!currentCustomer) return;
    const expEl = document.getElementById('currentExpiryDisplay');
    if (expEl) expEl.textContent = currentCustomer.expiry_date ? formatDate(currentCustomer.expiry_date) : 'Not set';
    filterExpiryPackagesByType(currentCustomer.connection_type || 'pppoe');
    updatePauseBtn(currentCustomer.status);
    document.getElementById('expiryModal').style.display = 'flex';
}

function closeExpiryModal() {
    document.getElementById('expiryModal').style.display = 'none';
}

function applyQuickExpiry(minutes) {
    if (!currentCustomer) return;
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('action', 'add_minutes');
    fd.append('minutes', minutes);
    fd.append('grace_hours', grace);
    submitExpiryChange(fd, '+' + (minutes >= 43200 ? Math.round(minutes/43200)+'mo' : minutes >= 1440 ? Math.round(minutes/1440)+'d' : minutes >= 60 ? Math.round(minutes/60)+'h' : minutes+'m'));
}

function applySetDate() {
    if (!currentCustomer) return;
    const dateVal = document.getElementById('expiryDateInput').value;
    if (!dateVal) { showToast('Please select a date.', 'warning'); return; }
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('action', 'set_date');
    fd.append('expiry_date', dateVal);
    fd.append('grace_hours', grace);
    submitExpiryChange(fd, 'specific date');
}

function applyChangePackage() {
    if (!currentCustomer) return;
    const pkgId = document.getElementById('expiryPackageSelect').value;
    if (!pkgId) { showToast('Please select a package.', 'warning'); return; }
    const grace = parseInt(document.getElementById('graceHoursInput').value) || 0;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('action', 'change_package');
    fd.append('package_id', pkgId);
    fd.append('grace_hours', grace);
    submitExpiryChange(fd, 'package change');
}

function submitExpiryChange(fd, label) {
    fetch('api/clients/change_expiry.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Expiry updated (' + label + ').', 'success');
                currentCustomer.expiry_date = d.new_expiry;
                document.getElementById('currentExpiryDisplay').textContent = formatDate(d.new_expiry);
                // Refresh time display in general tab
                const timeEl = document.getElementById('infoTime');
                if (timeEl) {
                    const tl = calculateTimeLeft(d.new_expiry);
                    const exp = new Date(d.new_expiry) < new Date();
                    timeEl.innerHTML = formatDate(d.new_expiry) + ' &nbsp;<span style="color:' + (exp?'#EF4444':'#059669') + ';font-size:12px;">(' + tl + ')</span>';
                }
                document.getElementById('modalPackageInfo').textContent =
                    'Package: ' + (currentCustomer.package_name || 'N/A') +
                    ' · KES ' + parseFloat(currentCustomer.package_price||0).toLocaleString() +
                    ' · Expires: ' + formatDate(d.new_expiry);
                setTimeout(() => closeExpiryModal(), 1200);
            } else {
                showToast('Error: ' + d.message, 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

function togglePwd() {
    const h = document.getElementById('pwdHidden');
    const v = document.getElementById('pwdValue');
    const eye = document.getElementById('pwdEye');
    
    if (v.style.display === 'none') {
        v.style.display = 'inline';
        h.style.display = 'none';
        eye.className = 'fas fa-eye-slash';
    } else {
        v.style.display = 'none';
        h.style.display = 'inline';
        eye.className = 'fas fa-eye';
    }
}

function copyField(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = el.textContent.trim();
    if (!text || text === '(hidden)') return;
    navigator.clipboard.writeText(text)
        .then(() => showToast('Copied!', 'success', 1800))
        .catch(() => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            showToast('Copied!', 'success', 1800);
        });
}

function copyText(text) { // kept for any legacy calls
    if (!text) return;
    navigator.clipboard.writeText(text)
        .then(() => showToast('Copied!', 'success', 1800))
        .catch(() => { showToast('Copy failed', 'error'); });
}

function promptPayment() {
    if (!currentCustomer) return;
    const amount = currentCustomer.package_price || '1000';
    const phone  = currentCustomer.phone || '';
    if (!phone) { showToast('No phone number on file for this customer.', 'warning'); return; }

    if (!confirm(`Send M-Pesa STK Push to ${phone} for KES ${amount}?`)) return;

    showToast('Initiating STK Push…', 'info');
    const formData = new FormData();
    formData.append('client_id', currentCustomer.id);
    formData.append('phone', phone);
    formData.append('amount', amount);

    fetch('api/mpesa/stk_push.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.Success || data.success) {
                showToast('STK Push sent! Customer should see a prompt on their phone.', 'success');
            } else {
                showToast('STK Push failed: ' + (data.message || data.errorMessage || 'Unknown error'), 'error');
            }
        })
        .catch(() => showToast('Network error initiating STK Push.', 'error'));
}

function sendEmail() {
     showToast('Email feature coming soon.', 'info');
}

function editUser() {
    closeModal(); // Ensure Detail modal is closed
    setTimeout(() => { // Small delay to ensure clean transition if needed
        openEditModal(currentCustomer); 
    }, 50);
}

function openAddModal() {
    document.getElementById('formModalTitle').textContent = 'Add New Customer';
    document.getElementById('customerForm').reset();
    document.getElementById('formId').value = '';
    filterPackagesByType('pppoe');
    document.getElementById('customerFormModal').style.display = 'flex';
}

function openEditModal(customer) {
    document.getElementById('formModalTitle').textContent = 'Edit Customer';
    document.getElementById('customerForm').reset();
    
    document.getElementById('formId').value = customer.id;
    document.getElementById('formName').value = customer.full_name || customer.name;
    document.getElementById('formPhone').value = customer.phone;
    document.getElementById('formEmail').value = customer.email;
    document.getElementById('formAddress').value = customer.address || customer.location;
    
    filterPackagesByType(customer.connection_type || 'pppoe');
    document.getElementById('formPackageId').value = customer.package_id;
    document.getElementById('formConnectionType').value = customer.connection_type || 'pppoe';
    document.getElementById('formMikrotikUsername').value = customer.mikrotik_username || '';
    document.getElementById('formMikrotikPassword').placeholder = 'Leave blank to keep current';
    
    // expiry
    if (customer.expiry_date) {
        document.getElementById('formExpiryDate').value = customer.expiry_date.split(' ')[0];
    } else {
        document.getElementById('formExpiryDate').value = '';
    }
    
    // Portal (Merged) - Logic no longer needed as fields are removed
    // We just ensure mikrotik_username is populated (done above)
    
    document.getElementById('customerFormModal').style.display = 'flex';
}

function handleFormSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const id = formData.get('id');
    const url = id ? 'api/customers/update.php' : 'api/customers/create.php';
    
    // Button is outside the <form> (linked via form="customerForm"), so we must query the document
    const btn = document.querySelector('button[form="customerForm"][type="submit"]') || form.querySelector('button[type="submit"]');
    const originalText = btn ? btn.textContent : '';
    if (btn) { btn.textContent = 'Saving...'; btn.disabled = true; }
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Customer saved successfully.', 'success');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
    })
    .finally(() => {
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
    });
}

function confirmDelete(id, name) {
    if (confirm('Delete customer "' + name + '"?\n\nThis will remove them from the system and the router.')) {
        const formData = new FormData();
        formData.append('id', id);
        fetch('api/customers/delete.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    showToast('Customer deleted.', 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast('Error: ' + d.message, 'error');
                }
            })
            .catch(() => showToast('Network error. Please try again.', 'error'));
    }
}

function openSMSModal(customer) {
    if (!customer) return;
    document.getElementById('smsClientId').value = customer.id;
    document.getElementById('smsClientPhone').value = customer.phone;
    document.getElementById('smsCustomerName').textContent = customer.full_name || customer.name;
    document.getElementById('smsMessage').value = '';
    document.getElementById('smsTemplate').value = '';
    document.getElementById('smsCharCount').textContent = '0 characters';
    document.getElementById('smsModal').style.display = 'flex';
}

function applyTemplate() {
    const template = document.getElementById('smsTemplate').value;
    const msgBox = document.getElementById('smsMessage');
    
    if (!currentCustomer) return;
    
    let text = '';
    const name = currentCustomer.full_name || currentCustomer.name || 'Customer';
    const username = currentCustomer.mikrotik_username || currentCustomer.username || '[Username]';
    const password = currentCustomer.mikrotik_password || currentCustomer.password || '[Password]';
    const expiry = currentCustomer.expiry_date ? formatDate(currentCustomer.expiry_date) : '[Date]';
    const account = currentCustomer.account_number || currentCustomer.id;
    const price = currentCustomer.package_price || '0';
    
    switch(template) {
        case 'credentials':
            text = `Hello ${name}, your internet login details are:\nUsername: ${username}\nPassword: ${password}\nExpires: ${expiry}\nThank you for choosing Fortunnet.`;
            break;
        case 'payment':
            text = `Dear ${name}, kindly make your payment of KES ${price} to Paybill: 247247, Account: ${account}.\nTo avoid disconnection, please pay before ${expiry}.`;
            break;
        case 'alert':
            text = `Dear ${name}, this is a reminder that your internet subscription is expiring soon (${expiry}). Please renew to ensure uninterrupted service.`;
            break;
        case 'promo':
            text = `Hello ${name}, check out our new high-speed fibre packages! Upgrade today and get 2x speed for the same price. Call us on 0700000000.`;
            break;
    }
    
    if (text) msgBox.value = text;
}

function handleSendSMS(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const original = btn.textContent;
    btn.textContent = 'Sending...';
    btn.disabled = true;
    
    // Placeholder for actual SMS API
    setTimeout(() => {
        showToast('SMS sent successfully.', 'success');
        closeSMSModal();
        btn.textContent = original;
        btn.disabled = false;
    }, 1000);
}

function deleteUser() {
    if (currentCustomer) {
        closeModal(); // Close the detailed modal first
        confirmDelete(currentCustomer.id, currentCustomer.full_name || currentCustomer.name);
    }
}

/* ── Reports tab ───────────────────────────────────────────── */
function loadReports(clientId) {
    const loading = document.getElementById('reportsLoading');
    const content = document.getElementById('reportsContent');
    if (!loading || !content) return;
    loading.style.display = 'block';
    content.style.display = 'none';

    fetch('api/clients/reports.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed to load reports.</span>'; loading.style.display='block'; return; }
            content.style.display = 'block';

            const churnColor = { Low: '#10B981', Medium: '#F59E0B', High: '#EF4444' };
            const churnBg    = { Low: '#D1FAE5', Medium: '#FEF3C7', High: '#FEE2E2' };
            const cr = d.churn_risk || 'Low';
            const reliabilityColor = d.payment_reliability >= 80 ? '#10B981' : d.payment_reliability >= 50 ? '#F59E0B' : '#EF4444';

            document.getElementById('reportStats1').innerHTML = `
                <div class="report-stat">
                    <div class="report-stat-label">Lifetime Value</div>
                    <div class="report-stat-value">KES ${(d.lifetime_value||0).toLocaleString()}</div>
                    <div class="report-stat-sub">${d.total_payments||0} payments total</div>
                </div>
                <div class="report-stat">
                    <div class="report-stat-label">Avg Monthly</div>
                    <div class="report-stat-value">KES ${(d.avg_monthly||0).toLocaleString()}</div>
                    <div class="report-stat-sub">Last 6 months</div>
                </div>
                <div class="report-stat">
                    <div class="report-stat-label">Payment Reliability</div>
                    <div class="report-stat-value" style="color:${reliabilityColor}">${d.payment_reliability||0}%</div>
                    <div class="report-stat-sub">Months with payment / 6</div>
                </div>`;

            const rankLabel = d.value_rank ? `#${d.value_rank} of ${d.total_clients}` : 'Unranked';
            const daysExp = d.days_to_expiry !== null ? (d.days_to_expiry < 0 ? Math.abs(d.days_to_expiry)+'d overdue' : d.days_to_expiry+'d remaining') : '—';
            document.getElementById('reportStats2').innerHTML = `
                <div class="report-stat">
                    <div class="report-stat-label">Value Rank</div>
                    <div class="report-stat-value">${rankLabel}</div>
                    <div class="report-stat-sub">By total spend</div>
                </div>
                <div class="report-stat">
                    <div class="report-stat-label">Churn Risk</div>
                    <div class="report-stat-value" style="color:${churnColor[cr]}">${cr}</div>
                    <div class="report-stat-sub" style="background:${churnBg[cr]};color:${churnColor[cr]};border-radius:4px;padding:1px 6px;display:inline-block;">${d.days_since_payment !== null ? d.days_since_payment+'d since last payment' : 'No payments'}</div>
                </div>
                <div class="report-stat">
                    <div class="report-stat-label">Expiry</div>
                    <div class="report-stat-value" style="font-size:15px;">${daysExp}</div>
                    <div class="report-stat-sub">Account age: ${d.account_age_days||0}d</div>
                </div>`;

            // Monthly payment chart
            const ctx = document.getElementById('clientPaymentChart');
            if (ctx) {
                if (clientPaymentChart) clientPaymentChart.destroy();
                const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#3B6EA5';
                clientPaymentChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.monthly_labels || [],
                        datasets: [{ label: 'KES', data: d.monthly_data || [], backgroundColor: primary, borderRadius: 4 }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES '+v.toLocaleString() } } }
                    }
                });
            }
        })
        .catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error loading reports.</span>'; loading.style.display='block'; });
}

/* ── Payments tab ──────────────────────────────────────────── */
function loadPayments(clientId) {
    const loading = document.getElementById('paymentsLoading');
    const wrap    = document.getElementById('paymentsTableWrap');
    const body    = document.getElementById('paymentsTableBody');
    const empty   = document.getElementById('paymentsEmpty');
    if (!loading||!wrap||!body) return;
    loading.style.display = 'block'; wrap.style.display = 'none';

    fetch('api/clients/payments.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed to load payments.</span>'; loading.style.display='block'; return; }
            wrap.style.display = 'block';
            const payments = d.payments || [];
            document.getElementById('paymentsTabTitle').textContent = 'Payments (' + payments.length + ')';
            if (!payments.length) { body.innerHTML = ''; empty.style.display = 'block'; return; }
            empty.style.display = 'none';
            body.innerHTML = payments.map(p => {
                const conf = p.confirmed
                    ? '<span class="pill success"><i class="fas fa-check"></i> Confirmed</span>'
                    : '<span class="pill pending">Pending</span>';
                const methodMap = { mpesa:'M-Pesa', cash:'Cash', bank_transfer:'Bank', 'M-Pesa':'M-Pesa' };
                return `<tr>
                    <td>${fmtShortDate(p.paid_at)}</td>
                    <td>${methodMap[p.method] || p.method || '—'}</td>
                    <td style="font-weight:600;">KES ${parseFloat(p.amount||0).toLocaleString()}</td>
                    <td>${p.phone || '—'}</td>
                    <td style="font-family:monospace;font-size:11px;">${p.mpesa_code || p.reference || '—'}</td>
                    <td>${conf}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error loading payments.</span>'; loading.style.display='block'; });
}

function openRecordPaymentForm() {
    const form = document.getElementById('recordPaymentForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    if (form.style.display === 'block') {
        // Default date to now
        const now = new Date();
        const local = now.toISOString().slice(0,16);
        document.getElementById('rpDate').value = local;
    }
}

function submitRecordPayment() {
    if (!currentCustomer) return;
    const amount = document.getElementById('rpAmount').value;
    const ref    = document.getElementById('rpReference').value;
    const method = document.getElementById('rpMethod').value;
    const date   = document.getElementById('rpDate').value;
    const notes  = document.getElementById('rpNotes').value;
    if (!amount || !ref) { showToast('Amount and reference code are required.', 'warning'); return; }

    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('amount', amount);
    fd.append('reference_code', ref);
    fd.append('method', method);
    fd.append('transaction_date', date || new Date().toISOString().slice(0,16));
    fd.append('is_verified', '1');
    fd.append('notes', notes);

    fetch('api/payments/record_manual.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Payment recorded.', 'success');
                document.getElementById('recordPaymentForm').style.display = 'none';
                document.getElementById('rpAmount').value = '';
                document.getElementById('rpReference').value = '';
                loadPayments(currentCustomer.id);
            } else {
                showToast('Error: ' + d.message, 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

/* ── SMS tab ───────────────────────────────────────────────── */
function loadSMSHistory(clientId) {
    const loading = document.getElementById('smsLoading');
    const wrap    = document.getElementById('smsTableWrap');
    const body    = document.getElementById('smsTableBody');
    const empty   = document.getElementById('smsEmpty');
    if (!loading||!wrap||!body) return;
    loading.style.display = 'block'; wrap.style.display = 'none';

    fetch('api/clients/sms_history.php?client_id=' + clientId)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success) { loading.innerHTML = '<span style="color:#EF4444;">Failed to load SMS history.</span>'; loading.style.display='block'; return; }
            wrap.style.display = 'block';
            const msgs = d.messages || [];
            if (!msgs.length) { body.innerHTML = ''; empty.style.display = 'block'; return; }
            empty.style.display = 'none';
            const pillClass = { sent:'sent', delivered:'delivered', failed:'failed', pending:'pending' };
            body.innerHTML = msgs.map(m => `<tr>
                <td>${fmtShortDate(m.sent_at)}</td>
                <td>${m.phone || '—'}</td>
                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(m.message)}">${escHtml(m.message)}</td>
                <td><span class="pill ${pillClass[m.status]||'pending'}">${ucFirst(m.status||'pending')}</span></td>
            </tr>`).join('');
        })
        .catch(() => { loading.innerHTML = '<span style="color:#EF4444;">Error loading SMS history.</span>'; loading.style.display='block'; });
}

/* ── Row-level actions dropdown ────────────────────────────── */
document.addEventListener('click', e => {
    // Toggle row dropdown
    const toggleBtn = e.target.closest('.row-dd-toggle');
    if (toggleBtn) {
        e.stopPropagation();
        const wrap = toggleBtn.closest('.row-action-dropdown');
        const wasOpen = wrap.classList.contains('open');
        document.querySelectorAll('.row-action-dropdown.open').forEach(d => d.classList.remove('open'));
        if (!wasOpen) wrap.classList.add('open');
        return;
    }
    // Close all row dropdowns on outside click
    document.querySelectorAll('.row-action-dropdown.open').forEach(d => d.classList.remove('open'));
    // Close modal actions menu on outside click
    if (!e.target.closest('#actionsMenu') && !e.target.closest('[onclick*="toggleActionsMenu"]')) {
        const am = document.getElementById('actionsMenu');
        if (am) am.style.display = 'none';
    }
});

function rowPromptPayment(customerJson) {
    currentCustomer = typeof customerJson === 'string' ? JSON.parse(customerJson) : customerJson;
    promptPayment();
}

/* ── Helpers ───────────────────────────────────────────────── */
function fmtShortDate(ds) {
    if (!ds) return '—';
    const d = new Date(ds);
    return d.toLocaleDateString('en-KE', { day:'2-digit', month:'short', year:'numeric' }) + ' ' +
           d.toLocaleTimeString('en-KE', { hour:'2-digit', minute:'2-digit' });
}
function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

/* ── Package type filtering ────────────────────────────────── */
function filterPackagesByType(connType) {
    const ct = (connType || 'pppoe').toLowerCase();
    // Form modal package select
    document.querySelectorAll('#formPackageId option[data-type]').forEach(opt => {
        const t = (opt.getAttribute('data-type') || 'pppoe').toLowerCase();
        opt.hidden = (ct !== 'static' && t !== ct);
    });
    // If current value is now hidden, reset
    const formSel = document.getElementById('formPackageId');
    if (formSel) {
        const selOpt = formSel.options[formSel.selectedIndex];
        if (selOpt && selOpt.hidden) formSel.value = '';
    }
}

function filterExpiryPackagesByType(connType) {
    const ct = (connType || 'pppoe').toLowerCase();
    document.querySelectorAll('#expiryPackageSelect option[data-type]').forEach(opt => {
        const t = (opt.getAttribute('data-type') || 'pppoe').toLowerCase();
        opt.hidden = (ct !== 'static' && t !== ct);
    });
}

/* ── Pause / Resume Subscription ──────────────────────────── */
function pauseSubscription() {
    if (!currentCustomer) return;
    const status = (currentCustomer.status || '').toLowerCase();
    const isPaused = status === 'suspended';
    const action = isPaused ? 'resume' : 'pause';
    const label  = isPaused ? 'Resume this subscription?' : 'Pause this subscription?\n\nThis will suspend the customer\'s service without changing their expiry date.';
    if (!confirm(label)) return;

    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    fd.append('action', action);

    fetch('api/clients/pause_subscription.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                currentCustomer.status = d.new_status;
                showToast(d.message, 'success');
                updatePauseBtn(d.new_status);
                // Refresh status badges inline
                const newStatus = d.new_status.toLowerCase();
                const badgeStyles = {
                    active:    'background:#D1FAE5; color:#065F46;',
                    inactive:  'background:#F3F4F6; color:#6B7280;',
                    suspended: 'background:#FEF3C7; color:#92400E;',
                    expired:   'background:#FEE2E2; color:#991B1B;'
                };
                const badgeEl = document.getElementById('modalStatusBadge');
                if (badgeEl) {
                    badgeEl.style.cssText = 'padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; ' + (badgeStyles[newStatus] || badgeStyles.inactive);
                    badgeEl.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                }
                const sc = { active:'#D1FAE5|#065F46', inactive:'#F3F4F6|#6B7280', suspended:'#FEF3C7|#92400E', expired:'#FEE2E2|#991B1B' };
                const [bg, fg] = (sc[newStatus] || sc.inactive).split('|');
                const statusGrid = document.getElementById('infoStatus');
                if (statusGrid) statusGrid.innerHTML = `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:${bg};color:${fg};"><span style="width:6px;height:6px;border-radius:50%;background:${fg};"></span>${newStatus.charAt(0).toUpperCase()+newStatus.slice(1)}</span>`;
            } else {
                showToast('Error: ' + d.message, 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

function updatePauseBtn(status) {
    const btn = document.getElementById('pauseSubBtn');
    if (!btn) return;
    const isPaused = (status || '').toLowerCase() === 'suspended';
    btn.innerHTML = isPaused
        ? '<i class="fas fa-play" style="color:#34d399;"></i> Resume'
        : '<i class="fas fa-pause" style="color:#fbbf24;"></i> Pause';
    btn.title = isPaused ? 'Resume subscription' : 'Pause subscription';
}

function calculateTimeLeft(expiryDate) {
    if (!expiryDate) return 'N/A';
    const diff = new Date(expiryDate) - new Date();
    if (diff < 0) return 'Expired';
    const days  = Math.floor(diff / 86400000);
    const hours = Math.floor((diff % 86400000) / 3600000);
    if (days > 0) return days + 'd ' + hours + 'h remaining';
    if (hours > 0) return hours + 'h remaining';
    return 'Expiring soon';
}

function formatDate(dateString) {
    if (!dateString) return "N/A";
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}

// Close expiry modal on backdrop click
document.getElementById('expiryModal').addEventListener('click', function(e) {
    if (e.target === this) closeExpiryModal();
});
// Close user modal on backdrop click
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
// Close form modal on backdrop click
document.getElementById('customerFormModal').addEventListener('click', function(e) {
    if (e.target === this) closeFormModal();
});
// Close SMS modal on backdrop click
document.getElementById('smsModal').addEventListener('click', function(e) {
    if (e.target === this) closeSMSModal();
});

/* ── Filter packages when connection type changes ──────────── */
document.getElementById('formConnectionType').addEventListener('change', function() {
    filterPackagesByType(this.value);
});

/* ── SMS char counter ──────────────────────────────────────── */
document.getElementById('smsMessage').addEventListener('input', function() {
    const cc = document.getElementById('smsCharCount');
    if (cc) cc.textContent = this.value.length + ' characters';
});

/* ── Online status ─────────────────────────────────────────── */
let onlineStatusCache = { set: new Set(), details: {} };

function loadOnlineStatus() {
    fetch('api/clients/online_status.php')
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            const onlineSet = new Set((d.online || []).map(u => u.toLowerCase()));
            onlineStatusCache = { set: onlineSet, details: d.details || {} };

            // Update ONLINE column cells in table
            document.querySelectorAll('tr[data-username]').forEach(row => {
                const uname = (row.getAttribute('data-username') || '').toLowerCase();
                const badge = row.querySelector('.online-badge');
                if (!badge) return;
                if (uname && onlineSet.has(uname)) {
                    badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:#D1FAE5;color:#065F46;font-size:11px;font-weight:600;">' +
                        '<span style="width:6px;height:6px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulseDot 1.5s ease-in-out infinite;"></span>Online</span>';
                } else if (uname) {
                    badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:#F3F4F6;color:#9CA3AF;font-size:11px;font-weight:500;">Offline</span>';
                } else {
                    badge.innerHTML = '<span style="font-size:11px;color:#D1D5DB;">—</span>';
                }
            });

            // If modal is open, update connectivity field
            if (currentCustomer && document.getElementById('userModal').style.display !== 'none') {
                updateModalOnlineStatus(onlineSet, d.details || {});
            }
        })
        .catch(() => {}); // Silent fail — routers may be unreachable
}

function updateModalOnlineStatus(onlineSet, details) {
    const el = document.getElementById('infoOnlineStatus');
    if (!el) return;
    const uname = (currentCustomer.mikrotik_username || '').toLowerCase();
    if (!uname) {
        el.innerHTML = '<span style="color:#9CA3AF;font-size:12px;">No username</span>';
        return;
    }
    if (onlineSet.has(uname)) {
        const det = details[currentCustomer.mikrotik_username] || details[uname] || {};
        let extra = '';
        if (det.uptime) extra += ' · ' + det.uptime;
        if (det.address) extra += ' · IP: ' + det.address;
        el.innerHTML = '<span style="display:inline-flex;align-items:center;gap:5px;">' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulseDot 1.5s ease-in-out infinite;"></span>' +
            '<span style="color:#065F46;font-weight:600;font-size:13px;">Online Now</span>' +
            '<span style="font-size:11px;color:#6B7280;">' + escHtml(extra) + '</span></span>';
        // Also update the header status badge
        const badgeEl = document.getElementById('modalStatusBadge');
        if (badgeEl) {
            badgeEl.style.cssText = 'padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:#D1FAE5;color:#065F46;display:inline-flex;align-items:center;gap:4px;';
            badgeEl.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulseDot 1.5s ease-in-out infinite;"></span>Online';
        }
    } else {
        el.innerHTML = '<span style="display:inline-flex;align-items:center;gap:5px;">' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#D1D5DB;flex-shrink:0;"></span>' +
            '<span style="color:#6B7280;font-size:13px;">Offline</span></span>';
    }
}

// Load online status on page load, then every 45 seconds
loadOnlineStatus();
setInterval(loadOnlineStatus, 45000);
</script>