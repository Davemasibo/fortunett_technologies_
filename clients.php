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

// Lazy-add last_seen column (silent if already exists)
try { $db->exec("ALTER TABLE clients ADD COLUMN last_seen DATETIME NULL DEFAULT NULL"); } catch (Exception $_e) {}

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
    $type_filter = $_GET['type'] ?? '';
    if (!in_array($type_filter, ['pppoe', 'hotspot'])) $type_filter = '';

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

    if (!empty($status_filter)) {
        $query .= " AND c.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($package_filter)) {
        $query .= " AND c.package_id = ?";
        $params[] = $package_filter;
    }

    if (!empty($type_filter)) {
        $query .= " AND COALESCE(NULLIF(c.connection_type,''), 'hotspot') = ?";
        $params[] = $type_filter;
    }

    $query .= " ORDER BY c.created_at DESC";
              
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
}

// Connection type counts for tabs
try {
    $cntStmt = $db->prepare("SELECT COALESCE(NULLIF(connection_type,''), 'hotspot') AS ct, COUNT(*) AS n FROM clients WHERE tenant_id = ? GROUP BY ct");
    $cntStmt->execute([$tenant_id]);
    $typeCountsRaw = $cntStmt->fetchAll(PDO::FETCH_ASSOC);
    $typeCounts = ['all' => $total_customers, 'pppoe' => 0, 'hotspot' => 0];
    foreach ($typeCountsRaw as $r) { if (isset($typeCounts[$r['ct']])) $typeCounts[$r['ct']] = (int)$r['n']; }
} catch (Exception $_e) {
    $typeCounts = ['all' => $total_customers, 'pppoe' => 0, 'hotspot' => 0];
}

// Get Packages for Dropdown
try {
    $stmt = $db->prepare("SELECT id, name, price, COALESCE(NULLIF(type,''), 'hotspot') AS type FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY price ASC");
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

    /* Connection type tabs */
    .conn-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
    .conn-tab { padding:7px 18px; border-radius:20px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid var(--neu-border); background:var(--neu-s2); color:rgba(255,255,255,.5); text-decoration:none; transition:all .18s; display:inline-flex; align-items:center; gap:6px; }
    .conn-tab:hover { color:#e2e2e0; background:rgba(255,255,255,.06); }
    .conn-tab.active { background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%); border-color:var(--primary-color,#3B6EA5); color:#fff; }
    .conn-tab .ct-badge { font-size:10px; font-weight:700; background:rgba(255,255,255,.18); padding:1px 6px; border-radius:10px; }
    .conn-tab.active .ct-badge { background:rgba(255,255,255,.25); }

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

    .customer-table { width:100%; min-width:820px; border-collapse:collapse; }
    .customer-table thead { background:rgba(255,255,255,.03); border-bottom:1px solid var(--neu-border); }
    .customer-table th { padding:10px 14px; text-align:left; font-size:10px; font-weight:700; color:rgba(255,255,255,.3); text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
    .customer-table td { padding:13px 14px; border-bottom:1px solid rgba(255,255,255,.04); font-size:13px; color:rgba(255,255,255,.7); vertical-align:middle; }
    .customer-table tbody tr { transition:background .15s; }
    .customer-table tbody tr:hover { background:rgba(255,255,255,.025); }
    .customer-table tbody tr:last-child td { border-bottom:none; }

    /* Connection type pill — matches online_customers.php .oc-type */
    .conn-type { display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-top:3px; }
    .conn-type.pppoe   { background:rgba(96,165,250,.12);  color:#93c5fd;  border:1px solid rgba(96,165,250,.2); }
    .conn-type.hotspot { background:rgba(167,139,250,.12); color:#c4b5fd;  border:1px solid rgba(167,139,250,.2); }
    .conn-type.unknown { background:rgba(156,163,175,.1);  color:rgba(255,255,255,.4); border:1px solid rgba(156,163,175,.15); }

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

    .modal-data-table { width:100%; min-width:520px; border-collapse:collapse; font-size:12px; }
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

    .expiry-quick-btn { padding:6px 12px; font-size:12px; font-weight:600; color:#93c5fd; background:rgba(59,110,165,.18); border:1px solid rgba(147,197,253,.3); border-radius:6px; cursor:pointer; transition:all .15s; }
    .expiry-quick-btn:hover { background:var(--primary-color,#3B6EA5); color:#fff; border-color:var(--primary-color,#3B6EA5); }

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

        <!-- Connection Type Tabs -->
        <div class="conn-tabs">
            <?php
            $baseParams = array_filter(['search' => $search, 'status' => $status_filter, 'package' => $package_filter]);
            $mkTabUrl = fn($t) => 'clients.php?' . http_build_query(array_merge($baseParams, $t ? ['type' => $t] : []));
            ?>
            <a href="<?php echo htmlspecialchars($mkTabUrl('')); ?>" class="conn-tab <?php echo $type_filter === '' ? 'active' : ''; ?>">
                All <span class="ct-badge"><?php echo $typeCounts['all']; ?></span>
            </a>
            <a href="<?php echo htmlspecialchars($mkTabUrl('pppoe')); ?>" class="conn-tab <?php echo $type_filter === 'pppoe' ? 'active' : ''; ?>">
                <i class="fas fa-network-wired" style="font-size:11px;"></i> PPPoE <span class="ct-badge"><?php echo $typeCounts['pppoe']; ?></span>
            </a>
            <a href="<?php echo htmlspecialchars($mkTabUrl('hotspot')); ?>" class="conn-tab <?php echo $type_filter === 'hotspot' ? 'active' : ''; ?>">
                <i class="fas fa-wifi" style="font-size:11px;"></i> Hotspot <span class="ct-badge"><?php echo $typeCounts['hotspot']; ?></span>
            </a>
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
                <?php if ($type_filter): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                <button type="button" onclick="window.location.href='clients.php<?php echo $type_filter ? '?type='.htmlspecialchars($type_filter) : ''; ?>'" class="clear-filters-btn" style="border:1px solid #ddd; background:#fff; padding:8px 12px; border-radius:6px; cursor:pointer;">
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
                    <button class="export-btn" onclick="openImportOptionsModal()">
                        <i class="fas fa-file-import"></i>
                        Import
                    </button>
                    <button class="add-customer-btn" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        Add Customer
                    </button>
                </div>
            </div>

            <!-- Bulk Operations Bar (hidden until rows are selected) -->
            <div id="bulkOpsBar" style="display:none;padding:10px 16px;background:rgba(59,110,165,.15);border-bottom:1px solid rgba(59,110,165,.3);display:none;align-items:center;gap:10px;flex-wrap:wrap;">
                <span id="bulkCount" style="font-size:13px;font-weight:600;color:#93c5fd;white-space:nowrap;"></span>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto;">
                    <button onclick="openBulkSMSModal()" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(96,165,250,.3);background:rgba(96,165,250,.1);color:#93c5fd;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-comment-dots"></i> Send SMS</button>
                    <button onclick="openBulkPackageModal()" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(167,139,250,.3);background:rgba(167,139,250,.1);color:#c4b5fd;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-box"></i> Change Package</button>
                    <button onclick="bulkExport()" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(52,211,153,.3);background:rgba(52,211,153,.1);color:#6ee7b7;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-download"></i> Export</button>
                    <button onclick="bulkDelete()" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(248,113,113,.3);background:rgba(248,113,113,.1);color:#fca5a5;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-trash"></i> Delete</button>
                    <button onclick="deselectAll()" style="padding:7px 12px;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.5);font-size:12px;cursor:pointer;"><i class="fas fa-times"></i> Deselect All</button>
                </div>
            </div>

            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
            <table class="customer-table">
                <thead>
                    <tr>
                        <th style="width:38px;padding:10px 8px 10px 16px;"><input type="checkbox" id="selectAllCheck" onclick="toggleSelectAll(this)" style="cursor:pointer;accent-color:var(--primary-color,#3B6EA5);width:15px;height:15px;"></th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Package</th>
                        <th>Online</th>
                        <th>Last Seen</th>
                        <th>Expiry</th>
                        <th>Actions</th>
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

                        $statusLabels = [
                            'active'    => 'Active',
                            'inactive'  => 'Inactive',
                            'suspended' => 'Suspended',
                            'expired'   => 'Expired',
                        ];
                        $statusLabel = $statusLabels[$dispStatus] ?? ucfirst($dispStatus);
                        $connType    = strtolower($customer['connection_type'] ?? 'pppoe');

                        // Prepare JSON for JS
                        $customerJson = htmlspecialchars(json_encode($customer), ENT_QUOTES, 'UTF-8');
                        $mikrotikUser = htmlspecialchars($customer['mikrotik_username'] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <?php
                        $lastSeen = $customer['last_seen'] ?? null;
                        $lastSeenFmt = $lastSeen ? date('M d, g:ia', strtotime($lastSeen)) : '';
                    ?>
                    <tr onclick='viewCustomer(<?php echo $customerJson; ?>)' style="cursor:pointer;" data-username="<?php echo $mikrotikUser; ?>" data-client-id="<?php echo $customer['id']; ?>">
                        <!-- Checkbox -->
                        <td onclick="event.stopPropagation()" style="padding:13px 8px 13px 16px;">
                            <input type="checkbox" class="row-check" value="<?php echo $customer['id']; ?>"
                                   onclick="updateBulkBar()" style="cursor:pointer;accent-color:var(--primary-color,#3B6EA5);width:15px;height:15px;">
                        </td>
                        <td>
                            <div class="customer-info">
                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                <div>
                                    <div class="customer-name"><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></div>
                                    <div class="customer-id" style="font-family:monospace;">
                                    <?php
                                        if (!empty($customer['account_number'])) {
                                            echo htmlspecialchars($customer['account_number']);
                                        } else {
                                            $pfx = strtoupper(substr($customer['mikrotik_username'] ?? $customer['full_name'] ?? 'C', 0, 1));
                                            echo $pfx . str_pad($customer['id'], 3, '0', STR_PAD_LEFT);
                                        }
                                    ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="contact-phone"><?php echo htmlspecialchars($customer['phone'] ?? '—'); ?></div>
                            <div class="contact-email"><?php echo htmlspecialchars($customer['email'] ?? ''); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;color:#e2e2e0;"><?php echo htmlspecialchars($customer['package_name'] ?? $customer['subscription_plan'] ?? '—'); ?></div>
                            <span class="conn-type <?php echo in_array($connType, ['pppoe','hotspot']) ? $connType : 'unknown'; ?>">
                                <i class="fas fa-<?php echo $connType === 'pppoe' ? 'plug' : 'wifi'; ?>" style="font-size:8px;"></i>
                                <?php echo strtoupper($connType); ?>
                            </span>
                        </td>
                        <!-- ONLINE column — filled by JS after MikroTik fetch -->
                        <td class="online-badge-cell">
                            <span class="online-badge" title="Checking…">
                                <span style="font-size:11px;color:#D1D5DB;">—</span>
                            </span>
                        </td>
                        <!-- LAST SEEN column — updated by JS on online sync -->
                        <td class="last-seen-cell" data-ts="<?php echo htmlspecialchars($lastSeen ?? ''); ?>" data-online="0">
                            <?php if ($lastSeen): ?>
                                <span style="font-size:12px;color:rgba(255,255,255,.55);"><?php echo $lastSeenFmt; ?></span>
                            <?php else: ?>
                                <span style="font-size:11px;color:rgba(255,255,255,.25);">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="expiry-date <?php echo $is_expired ? 'expiry-warning' : ''; ?>" style="font-variant-numeric:tabular-nums;">
                                <?php echo $expiry_date ? date('M d, Y', strtotime($expiry_date)) : '—'; ?>
                                <?php if ($is_expired): ?><div style="font-size:10px;color:#f87171;margin-top:2px;">Expired</div><?php endif; ?>
                            </div>
                        </td>
                        <!-- Actions column -->
                        <td onclick="event.stopPropagation()" style="white-space:nowrap;">
                            <div style="display:flex;gap:6px;align-items:center;">
                                <button onclick='viewCustomer(<?php echo $customerJson; ?>)' class="action-btn" title="View Customer">
                                    <i class="fas fa-eye" style="font-size:13px;color:rgba(255,255,255,.55);"></i>
                                </button>
                                <button onclick='confirmDelete(<?php echo $customer["id"]; ?>,<?php echo json_encode($customer["full_name"] ?? $customer["name"] ?? ""); ?>)' class="action-btn" title="Delete Customer">
                                    <i class="fas fa-trash" style="font-size:12px;color:#f87171;"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /overflow-x:auto -->
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
                    <a href="#" onclick="provisionToRouter();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-network-wired" style="color:#a78bfa;width:14px;"></i> Provision to Router</a>
                    <a href="#" onclick="verifyOnRouter();return false;" style="display:flex;align-items:center;gap:8px;padding:9px 14px;color:#d4d4d2;text-decoration:none;font-size:13px;"><i class="fas fa-clipboard-check" style="color:#fcd34d;width:14px;"></i> Verify on Router</a>
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
                        <input type="datetime-local" id="rpDate" step="1" style="width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,.08);border-radius:6px;font-size:13px;box-sizing:border-box;background:#1c1c1b;color:#e2e2e0;">
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
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="modal-data-table">
                    <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Phone</th><th>Ref / Code</th><th>Confirmed</th></tr></thead>
                    <tbody id="paymentsTableBody"></tbody>
                </table>
                </div>
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
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="modal-data-table">
                    <thead><tr><th>Date</th><th>Phone</th><th>Message</th><th>Status</th></tr></thead>
                    <tbody id="smsTableBody"></tbody>
                </table>
                </div>
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
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Quick Extend</div>
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
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Set Specific Date</div>
            <div style="display:flex;gap:8px;">
                <input type="datetime-local" id="expiryDateInput" style="flex:1;padding:8px 10px;background:#1c1c1b;border:1px solid rgba(255,255,255,.15);border-radius:7px;font-size:13px;color:#e2e2e0;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;box-sizing:border-box;color-scheme:dark;">
                <button onclick="applySetDate()" style="padding:8px 14px;background:var(--primary-color,#3B6EA5);color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Set Date</button>
            </div>
        </div>
        <!-- Change package -->
        <div style="margin-bottom:16px;">
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Change Package</div>
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
            <div style="font-size:10px;font-weight:700;color:#fcd34d;text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Grace Period (added on top)</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <input type="number" id="graceHoursInput" min="0" max="720" value="0" style="width:80px;padding:7px;background:#1c1c1b;border:1px solid rgba(251,191,36,.25);border-radius:6px;font-size:13px;text-align:center;color:#fcd34d;box-shadow:inset 2px 2px 5px rgba(0,0,0,.3);outline:none;">
                <span style="font-size:13px;font-weight:500;color:#fcd34d;">hours of grace period</span>
            </div>
        </div>
        <!-- Current expiry info -->
        <div style="margin-top:14px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:7px;font-size:12px;color:rgba(255,255,255,.75);">
            Current expiry: <strong id="currentExpiryDisplay" style="color:#e2e2e0;font-size:13px;"></strong>
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
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Username <span style="font-weight:400;opacity:.55;">(auto-generated if blank)</span></label>
                        <input type="text" name="mikrotik_username" id="formMikrotikUsername" placeholder="Leave blank to auto-generate" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;background:white;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Password <span style="font-weight:400;opacity:.55;">(auto-generated if blank)</span></label>
                        <input type="text" name="mikrotik_password" id="formMikrotikPassword" placeholder="Leave blank to auto-generate" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;background:white;" onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='#D1D5DB'">
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

<!-- Import Router Users Modal -->
<div id="importRouterModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1002;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#222221;width:100%;max-width:420px;border-radius:14px;border:1px solid rgba(255,255,255,.07);box-shadow:0 20px 60px rgba(0,0,0,.5);overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-size:15px;font-weight:700;color:#e2e2e0;">Import Router Users</div>
            <div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;">Pull existing users from MikroTik into the dashboard</div>
        </div>
        <button onclick="closeImportRouterModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:rgba(255,255,255,.4);line-height:1;padding:4px;">&times;</button>
    </div>
    <div style="padding:20px;">
        <div style="background:rgba(59,110,165,.12);border:1px solid rgba(59,110,165,.3);border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:rgba(255,255,255,.65);line-height:1.5;">
            <i class="fas fa-info-circle" style="color:#93c5fd;margin-right:6px;"></i>
            Users already in the dashboard are skipped. Imported users will have their username as the login name — you can set their password and package afterwards.
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.55);margin-bottom:6px;">Connection Type</label>
            <select id="importRouterConnType" style="width:100%;padding:9px 11px;background:#1c1c1b;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:14px;color:#e2e2e0;">
                <option value="pppoe">PPPoE (ppp/secret)</option>
                <option value="hotspot">Hotspot (ip/hotspot/user)</option>
            </select>
        </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);display:flex;justify-content:flex-end;gap:10px;">
        <button onclick="closeImportRouterModal()" style="padding:9px 18px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:rgba(255,255,255,.6);cursor:pointer;">Cancel</button>
        <button id="importRouterRunBtn" onclick="runImportRouterUsers()" style="padding:9px 22px;background:linear-gradient(135deg,var(--primary-dark,#2a5a8f) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Import</button>
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

/* ── Import Router Users ────────────────────────────────────── */
function openImportRouterModal() {
    document.getElementById('importRouterModal').style.display = 'flex';
}
function closeImportRouterModal() {
    document.getElementById('importRouterModal').style.display = 'none';
}
function runImportRouterUsers() {
    const connType = document.getElementById('importRouterConnType').value;
    const btn = document.getElementById('importRouterRunBtn');
    btn.textContent = 'Importing…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('connection_type', connType);
    fetch('api/mikrotik/import_users.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeImportRouterModal();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Import failed: ' + data.message, 'error');
            }
        })
        .catch(() => showToast('Network error during import', 'error'))
        .finally(() => { btn.textContent = 'Import'; btn.disabled = false; });
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

let _stkPollTimer = null;
let _stkPollCount = 0;

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

    fetch('api/payment/stk_push.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.sandbox) {
                    showToast('SANDBOX: No real phone prompt sent. Showing demo payment confirmed.', 'warning');
                    return;
                }
                _openStkModal(phone, amount, data.checkout_request_id, currentCustomer.id);
            } else {
                showToast('STK Push failed: ' + (data.message || data.errorMessage || 'Unknown error'), 'error');
            }
        })
        .catch(() => showToast('Network error initiating STK Push.', 'error'));
}

function _openStkModal(phone, amount, checkoutId, clientId) {
    const m = document.getElementById('stkPollModal');
    document.getElementById('stkPollPhone').textContent  = phone;
    document.getElementById('stkPollAmount').textContent = 'KES ' + parseFloat(amount).toLocaleString();
    document.getElementById('stkPollStatus').textContent = 'Waiting for approval on customer\'s phone…';
    document.getElementById('stkPollSpinner').style.display = 'block';
    document.getElementById('stkPollIconOk').style.display  = 'none';
    document.getElementById('stkPollIconFail').style.display = 'none';
    document.getElementById('stkPollDismiss').style.display  = 'none';
    m.style.display = 'flex';

    clearInterval(_stkPollTimer);
    _stkPollCount = 0;

    _stkPollTimer = setInterval(function() {
        _stkPollCount++;
        // 3s × 60 = 3 min timeout
        if (_stkPollCount > 60) {
            clearInterval(_stkPollTimer);
            _stkModalFail('Timed out waiting for payment. If the customer was charged, it will auto-process within minutes.');
            return;
        }

        fetch('api/payment/hotspot_payment_status.php?checkout_request_id=' + encodeURIComponent(checkoutId) + '&client_id=' + encodeURIComponent(clientId))
            .then(r => r.json())
            .then(d => {
                if (d.status === 'completed') {
                    clearInterval(_stkPollTimer);
                    _stkModalSuccess();
                } else if (d.status === 'failed') {
                    clearInterval(_stkPollTimer);
                    _stkModalFail(d.message || 'Payment failed or was cancelled by the customer.');
                }
                // 'pending' → keep polling
            })
            .catch(() => {}); // network blip — retry next tick
    }, 3000);
}

function _stkModalSuccess() {
    document.getElementById('stkPollSpinner').style.display  = 'none';
    document.getElementById('stkPollIconOk').style.display   = 'flex';
    document.getElementById('stkPollStatus').textContent     = 'Payment confirmed! Account activated.';
    document.getElementById('stkPollDismiss').style.display  = 'block';
    document.getElementById('stkPollDismiss').textContent    = 'Done';
}

function _stkModalFail(msg) {
    document.getElementById('stkPollSpinner').style.display   = 'none';
    document.getElementById('stkPollIconFail').style.display  = 'flex';
    document.getElementById('stkPollStatus').textContent      = msg;
    document.getElementById('stkPollDismiss').style.display   = 'block';
    document.getElementById('stkPollDismiss').textContent     = 'Close';
}

function _closeStkModal() {
    clearInterval(_stkPollTimer);
    document.getElementById('stkPollModal').style.display = 'none';
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
    
    // Set connection type first, then filter packages, then select the customer's package
    const connType = (customer.connection_type || 'pppoe').toLowerCase();
    document.getElementById('formConnectionType').value = connType;
    filterPackagesByType(connType);
    // Set package AFTER filtering (so the reset-if-hidden logic doesn't clear it)
    if (customer.package_id) {
        document.getElementById('formPackageId').value = customer.package_id;
        // If it still didn't select (package hidden or missing), force-show that option
        const sel = document.getElementById('formPackageId');
        if (sel.value != customer.package_id) {
            const opt = sel.querySelector('option[value="' + customer.package_id + '"]');
            if (opt) { opt.hidden = false; sel.value = customer.package_id; }
        }
    }
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
            const isNew = !formData.get('id');
            if (isNew && (data.auto_gen_username || data.auto_gen_password)) {
                // Show credentials modal so admin can copy before page reloads
                showCredentialsResult(data);
            } else if (isNew) {
                if (data.mikrotik_synced) {
                    showToast(`Customer saved & pushed to router (${data.router_ip}) · Profile: ${data.profile_used}`, 'success');
                } else if (data.no_router) {
                    showToast('Customer saved — no active router configured, provision manually later.', 'warning');
                } else {
                    showToast(`Customer saved but router sync failed: ${data.mikrotik_error || 'unknown'}`, 'warning');
                }
                setTimeout(() => location.reload(), 1800);
            } else {
                showToast('Customer updated successfully.', 'success');
                setTimeout(() => location.reload(), 1800);
            }
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

function showCredentialsResult(data) {
    const synced    = data.mikrotik_synced;
    const routerTxt = synced ? `Router: <strong style="color:#a5f3fc">${data.router_ip}</strong> &nbsp;·&nbsp; Profile: <strong style="color:#a5f3fc">${data.profile_used}</strong>` : (data.no_router ? 'No active router — provision manually later.' : `Router sync failed: ${data.mikrotik_error || 'unknown'}`);
    const el = document.createElement('div');
    el.id = 'credModal';
    el.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:3000;display:flex;align-items:center;justify-content:center;';
    el.innerHTML = `
        <div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:28px 32px;max-width:440px;width:92%;box-shadow:0 24px 60px rgba(0,0,0,.7);">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                <div style="width:36px;height:36px;border-radius:8px;background:${synced?'rgba(52,211,153,.15)':'rgba(251,191,36,.12)'};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-${synced?'check':'exclamation-triangle'}" style="color:${synced?'#6ee7b7':'#fbbf24'};font-size:15px;"></i>
                </div>
                <h3 style="margin:0;font-size:16px;font-weight:600;color:#e2e2e0;">Customer Created${synced?' & Provisioned':''}</h3>
            </div>
            <p style="font-size:12px;color:rgba(255,255,255,.4);margin:0 0 18px;">${routerTxt}</p>
            <div style="background:#111110;border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:16px;margin-bottom:20px;">
                <div style="font-size:10px;color:rgba(255,255,255,.3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">Auto-Generated Credentials — share with customer</div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <span style="font-size:12px;color:rgba(255,255,255,.45);width:70px;">Username</span>
                    <div style="display:flex;align-items:center;gap:7px;flex:1;justify-content:flex-end;">
                        <code style="background:#0d0d0c;padding:5px 11px;border-radius:5px;font-size:13px;color:#a5f3fc;letter-spacing:.03em;">${data.username}</code>
                        <button onclick="navigator.clipboard.writeText('${data.username}');this.innerHTML='<i class=\\'fas fa-check\\'></i>'" style="padding:4px 9px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:4px;color:rgba(255,255,255,.5);font-size:11px;cursor:pointer;"><i class="fas fa-copy"></i></button>
                    </div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:12px;color:rgba(255,255,255,.45);width:70px;">Password</span>
                    <div style="display:flex;align-items:center;gap:7px;flex:1;justify-content:flex-end;">
                        <code style="background:#0d0d0c;padding:5px 11px;border-radius:5px;font-size:13px;color:#fbbf24;letter-spacing:.03em;">${data.password}</code>
                        <button onclick="navigator.clipboard.writeText('${data.password}');this.innerHTML='<i class=\\'fas fa-check\\'></i>'" style="padding:4px 9px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:4px;color:rgba(255,255,255,.5);font-size:11px;cursor:pointer;"><i class="fas fa-copy"></i></button>
                    </div>
                </div>
            </div>
            <button onclick="document.getElementById('credModal').remove();location.reload();" style="width:100%;padding:10px;background:linear-gradient(135deg,var(--primary-dark,#1e3a5f) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;">Done</button>
        </div>`;
    document.body.appendChild(el);
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

function provisionToRouter() {
    if (!currentCustomer) return;
    const name = currentCustomer.full_name || currentCustomer.name || 'this customer';
    if (!confirm('Provision "' + name + '" to the router?\n\nThis creates/updates their PPPoE secret or hotspot user and uploads the branded login page.')) return;
    const fd = new FormData();
    fd.append('client_id', currentCustomer.id);
    showToast('Provisioning…', 'info');
    fetch('api/customers/provision.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Provisioned: ' + (d.username || '') + ' via ' + (d.service || '') + ' on ' + (d.router || ''), 'success');
            } else {
                showToast('Provision failed: ' + d.message, 'error');
            }
        })
        .catch(e => showToast('Provisioning request failed: ' + e.message, 'error'));
}

function verifyOnRouter() {
    if (!currentCustomer) return;
    document.getElementById('actionsMenu').style.display = 'none';
    showToast('Checking router…', 'info');

    fetch('api/mikrotik/verify_user.php?client_id=' + currentCustomer.id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { showToast('Verify error: ' + d.message, 'error'); return; }

            // Build a readable report
            const lines = [];
            lines.push('DB: ' + (d.db_ok ? '✓ Found' : '✗ Missing') + ' · Status: ' + d.db_status);
            lines.push('Username: ' + (d.db_username || '(none)') + ' · Type: ' + d.db_connection);

            if (d.router_error) {
                lines.push('Router: ✗ ' + d.router_error);
            } else {
                lines.push('Router (' + d.router_ip + '): ' + (d.router_ok ? '✓ User exists' : '✗ User NOT found'));
                if (d.router_ok) {
                    lines.push('Profile on router: ' + (d.router_profile || 'unknown'));
                    lines.push('Expected profile: ' + d.expected_profile);
                    lines.push('Profile match: ' + (d.profile_match ? '✓ Yes' : '✗ Mismatch — re-provision to fix'));
                    if (d.is_online) {
                        const s = d.session;
                        lines.push('🟢 ONLINE — IP: ' + s.address + ' · Uptime: ' + s.uptime + ' · ↓' + s.rx_mb + 'MB ↑' + s.tx_mb + 'MB');
                    } else {
                        lines.push('⚫ Not currently connected');
                    }
                }
            }

            const type = (d.db_ok && d.router_ok && d.profile_match) ? 'success'
                       : (d.db_ok && !d.router_ok) ? 'warning'
                       : 'error';
            // Show the full report as an alert so all lines are readable
            alert('Router Verification — ' + (d.db_username || 'unknown') + '\n\n' + lines.join('\n'));
            if (d.is_online) showToast('Customer is ONLINE right now.', 'success');
        })
        .catch(() => showToast('Network error during verification.', 'error'));
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
        // Default date to local time (not UTC ISO) with seconds
        const now = new Date();
        const pad = n => String(n).padStart(2,'0');
        const local = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())+'T'+pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
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
    const _now = new Date(), _p = n => String(n).padStart(2,'0');
    const _localNow = _now.getFullYear()+'-'+_p(_now.getMonth()+1)+'-'+_p(_now.getDate())+'T'+_p(_now.getHours())+':'+_p(_now.getMinutes())+':'+_p(_now.getSeconds());
    fd.append('transaction_date', date || _localNow);
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
    const ct = (connType || '').toLowerCase();
    const opts = document.querySelectorAll('#formPackageId option[data-type]');

    // If no connection type known, show all packages
    if (!ct) {
        opts.forEach(opt => { opt.hidden = false; });
        return;
    }

    let visibleCount = 0;
    opts.forEach(opt => {
        const raw = (opt.getAttribute('data-type') || '').toLowerCase().trim();
        // Treat blank/unknown data-type as matching any connection type
        const matches = !raw || raw === ct || ct === 'static';
        opt.hidden = !matches;
        if (!opt.hidden) visibleCount++;
    });

    // If filtering left nothing visible, show all packages (data-type not set correctly in DB)
    if (visibleCount === 0) {
        opts.forEach(opt => { opt.hidden = false; });
    }

    // If current value is now hidden, reset selection
    const formSel = document.getElementById('formPackageId');
    if (formSel) {
        const selOpt = formSel.options[formSel.selectedIndex];
        if (selOpt && selOpt.hidden) formSel.value = '';
    }
}

function filterExpiryPackagesByType(connType) {
    const ct = (connType || '').toLowerCase();
    const opts = document.querySelectorAll('#expiryPackageSelect option[data-type]');

    if (!ct) { opts.forEach(opt => { opt.hidden = false; }); return; }

    let visibleCount = 0;
    opts.forEach(opt => {
        const raw = (opt.getAttribute('data-type') || '').toLowerCase().trim();
        const matches = !raw || raw === ct || ct === 'static';
        opt.hidden = !matches;
        if (!opt.hidden) visibleCount++;
    });

    // Fall back to showing all if nothing matched
    if (visibleCount === 0) {
        opts.forEach(opt => { opt.hidden = false; });
    }
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

            // Update ONLINE + LAST SEEN columns
            const nowStr = new Date().toLocaleString('en-KE', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' });
            document.querySelectorAll('tr[data-username]').forEach(row => {
                const uname = (row.getAttribute('data-username') || '').toLowerCase();
                const badge = row.querySelector('.online-badge');
                const lsCell = row.querySelector('.last-seen-cell');
                if (!badge) return;
                if (uname && onlineSet.has(uname)) {
                    badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:#D1FAE5;color:#065F46;font-size:11px;font-weight:600;">' +
                        '<span style="width:6px;height:6px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulseDot 1.5s ease-in-out infinite;"></span>Online</span>';
                    if (lsCell) {
                        lsCell.dataset.online = '1';
                        lsCell.dataset.ts = new Date().toISOString().replace('T',' ').slice(0,19);
                        lsCell.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#10b981;font-weight:600;"><span style="width:6px;height:6px;border-radius:50%;background:#10b981;animation:pulseDot 1.5s ease-in-out infinite;"></span>Now</span>';
                    }
                } else if (uname) {
                    badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;background:#F3F4F6;color:#9CA3AF;font-size:11px;font-weight:500;">Offline</span>';
                    if (lsCell) { lsCell.dataset.online = '0'; _lsRefreshCell(lsCell); }
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

        // Format bytes to human-readable
        function fmtBytes(b) {
            b = parseInt(b) || 0;
            if (b >= 1073741824) return (b/1073741824).toFixed(2) + ' GB';
            if (b >= 1048576)    return (b/1048576).toFixed(1)    + ' MB';
            if (b >= 1024)       return (b/1024).toFixed(1)       + ' KB';
            return b + ' B';
        }

        const bIn  = det.bytes_in  || det['bytes-in']  || 0;
        const bOut = det.bytes_out || det['bytes-out'] || 0;

        let statRows = '<div style="display:flex;align-items:center;gap:5px;margin-bottom:7px;">' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#10B981;flex-shrink:0;animation:pulseDot 1.5s ease-in-out infinite;"></span>' +
            '<span style="color:#065F46;font-weight:700;font-size:13px;">Online Now</span></div>';

        statRows += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">';
        if (det.address)  statRows += '<div style="background:rgba(255,255,255,.04);border-radius:6px;padding:6px 8px;"><div style="font-size:10px;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.04em;">IP Address</div><div style="font-size:12px;font-weight:600;color:#e2e2e0;font-family:monospace;">' + escHtml(det.address) + '</div></div>';
        if (det.uptime)   statRows += '<div style="background:rgba(255,255,255,.04);border-radius:6px;padding:6px 8px;"><div style="font-size:10px;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.04em;">Uptime</div><div style="font-size:12px;font-weight:600;color:#e2e2e0;">' + escHtml(det.uptime) + '</div></div>';
        if (bIn)          statRows += '<div style="background:rgba(255,255,255,.04);border-radius:6px;padding:6px 8px;"><div style="font-size:10px;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.04em;">↓ Download</div><div style="font-size:12px;font-weight:600;color:#34d399;">' + fmtBytes(bIn) + '</div></div>';
        if (bOut)         statRows += '<div style="background:rgba(255,255,255,.04);border-radius:6px;padding:6px 8px;"><div style="font-size:10px;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.04em;">↑ Upload</div><div style="font-size:12px;font-weight:600;color:#60a5fa;">' + fmtBytes(bOut) + '</div></div>';
        statRows += '</div>';

        el.innerHTML = statRows;
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

// ── Last-seen relative time helpers ──────────────────────────────────────────
function _lsRelText(ts) {
    if (!ts) return '<span style="font-size:11px;color:rgba(255,255,255,.25);">Never</span>';
    const diff = Math.floor((Date.now() - new Date(ts.replace(' ','T')).getTime()) / 1000);
    if (diff <  10)   return '<span style="font-size:12px;color:#10b981;font-weight:600;">Just now</span>';
    if (diff <  60)   return '<span style="font-size:12px;color:rgba(255,255,255,.6);">'  + diff                  + 's ago</span>';
    if (diff <  3600) return '<span style="font-size:12px;color:rgba(255,255,255,.55);">' + Math.floor(diff/60)  + ' min ago</span>';
    if (diff < 86400) return '<span style="font-size:12px;color:rgba(255,255,255,.4);">'  + Math.floor(diff/3600)+ ' hr ago</span>';
    return                   '<span style="font-size:12px;color:rgba(255,255,255,.3);">'  + Math.floor(diff/86400)+ ' d ago</span>';
}
function _lsRefreshCell(cell) {
    cell.innerHTML = _lsRelText(cell.dataset.ts || '');
}
function refreshLastSeenCells() {
    document.querySelectorAll('.last-seen-cell[data-online="0"]').forEach(_lsRefreshCell);
}

// Load online status on page load, then every 45 seconds
loadOnlineStatus();
setInterval(loadOnlineStatus, 45000);
// Refresh relative timestamps every 60s; initial run after online-status settles
setTimeout(refreshLastSeenCells, 2000);
setInterval(refreshLastSeenCells, 60000);

// Auto-open add customer modal when navigated from quick actions
if (new URLSearchParams(window.location.search).get('open_modal') === '1') {
    openAddModal();
}

/* ── Bulk selection ────────────────────────────────────────── */
function toggleSelectAll(masterCb) {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = masterCb.checked; });
    updateBulkBar();
}

function deselectAll() {
    document.querySelectorAll('.row-check, #selectAllCheck').forEach(cb => { cb.checked = false; });
    updateBulkBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}

function updateBulkBar() {
    const ids = getSelectedIds();
    const bar = document.getElementById('bulkOpsBar');
    const cnt = document.getElementById('bulkCount');
    if (ids.length > 0) {
        bar.style.display = 'flex';
        cnt.textContent = ids.length + ' customer' + (ids.length !== 1 ? 's' : '') + ' selected';
    } else {
        bar.style.display = 'none';
    }
    // Sync master checkbox
    const all  = document.querySelectorAll('.row-check').length;
    const chk  = document.getElementById('selectAllCheck');
    if (chk) { chk.checked = (ids.length > 0 && ids.length === all); chk.indeterminate = (ids.length > 0 && ids.length < all); }
}

/* ── Bulk delete ───────────────────────────────────────────── */
function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' selected customer(s)?\n\nThis is permanent and cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    ids.forEach(id => fd.append('ids[]', id));
    fetch('api/clients/bulk_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
            else showToast('Error: ' + d.message, 'error');
        })
        .catch(() => showToast('Network error.', 'error'));
}

/* ── Bulk SMS ──────────────────────────────────────────────── */
function openBulkSMSModal() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    document.getElementById('bulkSmsCount').textContent = ids.length + ' customer(s)';
    document.getElementById('bulkSmsMsg').value = '';
    document.getElementById('bulkSmsModal').style.display = 'flex';
}
function closeBulkSMSModal() { document.getElementById('bulkSmsModal').style.display = 'none'; }
function submitBulkSMS() {
    const msg = document.getElementById('bulkSmsMsg').value.trim();
    if (!msg) { showToast('Please enter a message.', 'warning'); return; }
    const ids = getSelectedIds();
    const fd  = new FormData();
    fd.append('action', 'send_sms');
    fd.append('message', msg);
    ids.forEach(id => fd.append('ids[]', id));
    const btn = document.getElementById('bulkSmsSendBtn');
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('api/clients/bulk_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showToast(d.message, 'success'); closeBulkSMSModal(); }
            else showToast('Error: ' + d.message, 'error');
        })
        .catch(() => showToast('Network error.', 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = 'Send SMS'; });
}

/* ── Bulk change package ───────────────────────────────────── */
function openBulkPackageModal() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    document.getElementById('bulkPkgCount').textContent = ids.length + ' customer(s)';
    document.getElementById('bulkPkgModal').style.display = 'flex';
}
function closeBulkPackageModal() { document.getElementById('bulkPkgModal').style.display = 'none'; }
function submitBulkPackage() {
    const pkgId = document.getElementById('bulkPkgSelect').value;
    if (!pkgId) { showToast('Please select a package.', 'warning'); return; }
    const ids = getSelectedIds();
    const fd  = new FormData();
    fd.append('action', 'change_package');
    fd.append('package_id', pkgId);
    ids.forEach(id => fd.append('ids[]', id));
    const btn = document.getElementById('bulkPkgApplyBtn');
    btn.disabled = true; btn.textContent = 'Applying…';
    fetch('api/clients/bulk_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showToast(d.message, 'success'); closeBulkPackageModal(); setTimeout(() => location.reload(), 800); }
            else showToast('Error: ' + d.message, 'error');
        })
        .catch(() => showToast('Network error.', 'error'))
        .finally(() => { btn.disabled = false; btn.textContent = 'Apply'; });
}

/* ── Bulk export ───────────────────────────────────────────── */
function bulkExport() {
    const ids = getSelectedIds();
    if (!ids.length) { exportCSV(); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/clients/bulk_action.php';
    form.style.display = 'none';
    const addInput = (name, val) => { const i = document.createElement('input'); i.type='hidden'; i.name=name; i.value=val; form.appendChild(i); };
    addInput('action', 'export');
    ids.forEach(id => addInput('ids[]', id));
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

/* ── Import options modal ──────────────────────────────────── */
function openImportOptionsModal() {
    document.getElementById('importOptionsModal').style.display = 'flex';
}
function closeImportOptionsModal() {
    document.getElementById('importOptionsModal').style.display = 'none';
}

function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'clients.php?' + params.toString();
}

// ── CSV Import modal ─────────────────────────────────────────────────────────
function openImportModal(type) {
    const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
        ? '/fortunett_technologies_' : '';
    const endpoints = {
        clients:  _base + '/api/import/customers.php',
        payments: _base + '/api/import/payments.php',
        packages: _base + '/api/import/packages.php',
    };
    const templates = {
        clients:  'full_name,phone,email,address,username,package_name,connection_type,status,expiry_date\nJohn Doe,0712345678,john@example.com,,john.doe,Basic Hotspot,hotspot,active,2026-12-31',
        payments: 'client_phone,amount,payment_method,transaction_id,payment_date,status,notes\n0712345678,500,cash,CASH001,2026-04-01 10:00:00,completed,Manual entry',
        packages: 'name,type,price,download_speed,upload_speed,validity_value,validity_unit,data_limit,device_limit,description,status\nBasic Hotspot,hotspot,500,10,5,30,days,0,1,Entry level,active',
    };
    const titles = { clients: 'Import Customers', payments: 'Import Transactions', packages: 'Import Packages' };
    document.getElementById('importModalTitle').textContent  = titles[type] || 'Import CSV';
    document.getElementById('importModalForm').action        = endpoints[type];
    document.getElementById('importTemplateLink').href       = 'data:text/csv;charset=utf-8,' + encodeURIComponent(templates[type] || '');
    document.getElementById('importTemplateLink').download   = type + '_template.csv';
    document.getElementById('importModalResult').innerHTML   = '';
    document.getElementById('importCsvFile').value           = '';
    document.getElementById('importConnTypeRow').style.display = (type === 'clients') ? 'block' : 'none';
    document.getElementById('importModal').style.display     = 'flex';
}
function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}
(function () {
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
})();
function showImportResult(html, type) {
    const el = document.getElementById('importModalResult');
    el.style.cssText = 'display:block;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:12px;'
        + (type === 'error'
            ? 'border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.1);'
            : 'border:1px solid rgba(16,185,129,.3);background:rgba(16,185,129,.1);');
    el.innerHTML = html;
}
</script>

<!-- ── STK Push Polling Modal ────────────────────────────────────────────────── -->
<div id="stkPollModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10002;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
  <div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:32px 28px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.6);text-align:center;">
    <div id="stkPollSpinner" style="margin:0 auto 20px;width:52px;height:52px;border:3px solid rgba(255,255,255,.1);border-top-color:var(--primary-color,#3B6EA5);border-radius:50%;animation:spin .8s linear infinite;"></div>
    <div id="stkPollIconOk" style="display:none;margin:0 auto 20px;width:52px;height:52px;border-radius:50%;background:rgba(16,185,129,.15);border:2px solid rgba(16,185,129,.4);align-items:center;justify-content:center;font-size:26px;">✓</div>
    <div id="stkPollIconFail" style="display:none;margin:0 auto 20px;width:52px;height:52px;border-radius:50%;background:rgba(239,68,68,.15);border:2px solid rgba(239,68,68,.4);align-items:center;justify-content:center;font-size:26px;">✕</div>
    <div style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:4px;">STK Push sent to</div>
    <div id="stkPollPhone" style="font-size:16px;font-weight:700;color:#e2e2e0;margin-bottom:4px;"></div>
    <div id="stkPollAmount" style="font-size:22px;font-weight:800;color:var(--primary-color,#3B6EA5);margin-bottom:18px;"></div>
    <p id="stkPollStatus" style="font-size:13px;color:rgba(255,255,255,.6);line-height:1.55;margin:0 0 22px;"></p>
    <button id="stkPollDismiss" onclick="_closeStkModal()" style="display:none;width:100%;padding:11px;border-radius:10px;border:none;background:var(--primary-color,#3B6EA5);color:#fff;font-weight:700;font-size:14px;cursor:pointer;">Done</button>
  </div>
</div>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>

<!-- ── Import Options Modal ──────────────────────────────────────────────────── -->
<div id="importOptionsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h3 style="margin:0;color:#e2e2e0;font-size:17px;font-weight:600;">Import Customers</h3>
        <button onclick="closeImportOptionsModal()" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <p style="color:rgba(255,255,255,.45);font-size:13px;margin:0 0 18px;">Choose how you want to import customers:</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div onclick="closeImportOptionsModal();openImportModal('clients');" style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.2);border-radius:12px;padding:20px 16px;cursor:pointer;transition:all .2s;text-align:center;" onmouseover="this.style.background='rgba(96,165,250,.15)'" onmouseout="this.style.background='rgba(96,165,250,.08)'">
            <div style="font-size:28px;margin-bottom:10px;">📄</div>
            <div style="font-size:14px;font-weight:700;color:#93c5fd;margin-bottom:4px;">From CSV</div>
            <div style="font-size:12px;color:rgba(255,255,255,.4);line-height:1.4;">Upload a spreadsheet file with customer data</div>
        </div>
        <div onclick="closeImportOptionsModal();openImportRouterModal();" style="background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.2);border-radius:12px;padding:20px 16px;cursor:pointer;transition:all .2s;text-align:center;" onmouseover="this.style.background='rgba(167,139,250,.15)'" onmouseout="this.style.background='rgba(167,139,250,.08)'">
            <div style="font-size:28px;margin-bottom:10px;">📡</div>
            <div style="font-size:14px;font-weight:700;color:#c4b5fd;margin-bottom:4px;">From MikroTik</div>
            <div style="font-size:12px;color:rgba(255,255,255,.4);line-height:1.4;">Pull existing users from your router directly</div>
        </div>
    </div>
</div>
</div>

<!-- ── Bulk SMS Modal ──────────────────────────────────────────────────────── -->
<div id="bulkSmsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:24px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h3 style="margin:0;color:#e2e2e0;font-size:16px;font-weight:600;">Send Bulk SMS</h3>
        <button onclick="closeBulkSMSModal()" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <p style="color:#93c5fd;font-size:13px;margin:0 0 14px;">Sending to: <strong id="bulkSmsCount"></strong></p>
    <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.55);margin-bottom:6px;">Message *</label>
        <textarea id="bulkSmsMsg" rows="4" style="width:100%;padding:10px 12px;background:#1c1c1b;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:#e2e2e0;font-family:inherit;resize:vertical;box-sizing:border-box;" placeholder="Type your message here…"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button onclick="closeBulkSMSModal()" style="padding:9px 18px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:rgba(255,255,255,.6);cursor:pointer;">Cancel</button>
        <button id="bulkSmsSendBtn" onclick="submitBulkSMS()" style="padding:9px 22px;background:linear-gradient(135deg,var(--primary-dark,#2a5a8f),var(--primary-color,#3B6EA5));color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Send SMS</button>
    </div>
</div>
</div>

<!-- ── Bulk Change Package Modal ───────────────────────────────────────────── -->
<div id="bulkPkgModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
<div style="background:#222221;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:24px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.6);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h3 style="margin:0;color:#e2e2e0;font-size:16px;font-weight:600;">Change Package</h3>
        <button onclick="closeBulkPackageModal()" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <p style="color:#93c5fd;font-size:13px;margin:0 0 14px;">Changing package for: <strong id="bulkPkgCount"></strong></p>
    <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.55);margin-bottom:6px;">Select New Package *</label>
        <select id="bulkPkgSelect" style="width:100%;padding:10px 12px;background:#1c1c1b;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:#e2e2e0;box-sizing:border-box;">
            <option value="">— Select Package —</option>
            <?php foreach ($packages as $pkg): ?>
            <option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?> — KES <?php echo number_format($pkg['price']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;padding:10px 12px;margin-bottom:16px;font-size:12px;color:#fcd34d;">
        <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
        This changes the package assignment only. Expiry and router profile changes are not applied automatically.
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button onclick="closeBulkPackageModal()" style="padding:9px 18px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:13px;color:rgba(255,255,255,.6);cursor:pointer;">Cancel</button>
        <button id="bulkPkgApplyBtn" onclick="submitBulkPackage()" style="padding:9px 22px;background:#059669;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Apply</button>
    </div>
</div>
</div>

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
            <!-- Default connection type — shown only when importing customers -->
            <div id="importConnTypeRow" style="display:none;margin-bottom:16px;">
                <label style="display:block;font-size:13px;color:rgba(255,255,255,.55);margin-bottom:6px;">Default Connection Type <span style="font-size:11px;color:rgba(255,255,255,.3);">(used when CSV has no connection_type column)</span></label>
                <select name="default_connection_type" style="width:100%;padding:9px 12px;background:#1a1a19;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e2e2e0;font-size:13px;box-sizing:border-box;">
                    <option value="hotspot">Hotspot</option>
                    <option value="pppoe">PPPoE</option>
                </select>
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