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
    $stmt = $db->prepare("SELECT id, name, price FROM packages WHERE tenant_id = ? ORDER BY price ASC");
    $stmt->execute([$tenant_id]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $packages = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .customers-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Header */
    .customers-header {
        margin-bottom: 24px;
    }
    
    .customers-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .customers-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0;
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
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, rgba(59, 110, 165, 0.1) 0%, rgba(59, 110, 165, 0.05) 100%);
        border-radius: 0 0 0 60px;
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
    
    .stat-icon.total {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .stat-icon.active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .stat-icon.expired {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    .stat-icon.warning {
        background: #FEF3C7;
        color: #92400E;
    }
    
    .stat-value {
        font-size: 32px;
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
    
    .stat-change.negative {
        color: #DC2626;
    }
    
    /* Filters Bar */
    .filters-bar {
        background: white;
        border-radius: 10px;
        padding: 20px 24px;
        margin-bottom: 20px;
        border: 1px solid #E5E7EB;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto;
        gap: 16px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .filter-label {
        font-size: 13px;
        font-weight: 500;
        color: #374151;
    }
    
    .filter-input,
    .filter-select {
        padding: 10px 14px;
        border: 1px solid #D1D5DB;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
    }
    
    .filter-input:focus,
    .filter-select:focus {
        outline: none;
        border-color: #3B6EA5;
        box-shadow: 0 0 0 3px rgba(59, 110, 165, 0.1);
    }
    
    .clear-filters-btn {
        padding: 10px 16px;
        background: transparent;
        border: 1px solid #D1D5DB;
        border-radius: 8px;
        color: #6B7280;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .clear-filters-btn:hover {
        background: #F3F4F6;
        border-color: #9CA3AF;
    }
    
    /* Table Container */
    .table-container {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .table-header {
        padding: 16px 24px;
        border-bottom: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .table-info {
        font-size: 14px;
        color: #6B7280;
    }
    
    .table-actions {
        display: flex;
        gap: 12px;
    }
    
    .export-btn,
    .add-customer-btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .export-btn {
        background: white;
        border: 1px solid #D1D5DB;
        color: #374151;
    }
    
    .export-btn:hover {
        background: #F9FAFB;
        border-color: #9CA3AF;
    }
    
    .add-customer-btn {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        border: none;
        color: white;
    }
    
    .add-customer-btn:hover {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-light) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(var(--primary-color), 0.3);
        color: white;
    }
    
    /* Customer Table */
    .customer-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .customer-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .customer-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .customer-table td {
        padding: 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .customer-table tbody tr {
        transition: background 0.2s;
    }
    
    .customer-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .customer-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3B6EA5 0%, #2C5282 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 14px;
    }
    
    .customer-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .customer-name {
        font-weight: 600;
        color: #111827;
        margin-bottom: 2px;
    }
    
    .customer-id {
        font-size: 12px;
        color: #9CA3AF;
    }
    
    .contact-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .contact-phone {
        font-weight: 500;
    }
    
    .contact-email {
        font-size: 12px;
        color: #6B7280;
    }
    
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .status-badge.active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .status-badge.expired {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    .status-badge.suspended {
        background: #FEF3C7;
        color: #92400E;
    }
    
    .status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }
    
    .expiry-date {
        font-size: 13px;
    }
    
    .expiry-warning {
        color: #DC2626;
    }
    
    .payment-amount {
        font-weight: 600;
        color: #111827;
    }
    
    .payment-period {
        font-size: 12px;
        color: #6B7280;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #E5E7EB;
        background: white;
        color: #6B7280;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }
    
    .action-btn:hover {
        background: #F3F4F6;
        border-color: #3B6EA5;
        color: #3B6EA5;
    }
    
    @media (max-width: 1024px) {
        .filters-grid {
            grid-template-columns: 1fr;
        }
    }
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
                        <th>EXPIRY</th>
                        <th>PAYMENTS</th>
                        <th>ACTIONS</th>
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
                        
                        $status = strtolower($customer['status'] ?? 'active');
                        $expiry_date = $customer['expiry_date'] ?? null;
                        $is_expired = $expiry_date && strtotime($expiry_date) < time();
                        if ($is_expired) $status = 'expired';
                        
                        // Prepare JSON for JS
                        $customerJson = htmlspecialchars(json_encode($customer), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr onclick='viewCustomer(<?php echo $customerJson; ?>)' style="cursor: pointer;">
                        <td>
                            <div class="customer-info">
                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                <div>
                                    <div class="customer-name"><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></div>
                                    <div class="customer-id">ID: <?php echo htmlspecialchars($customer['account_number'] ?? $customer['id']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="contact-info">
                                <div class="contact-phone"><?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?></div>
                                <div class="contact-email"><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($customer['package_name'] ?? $customer['subscription_plan'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="status-badge <?php echo $status; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td>
                            <div class="expiry-date <?php echo $is_expired ? 'expiry-warning' : ''; ?>">
                                <?php 
                                if ($expiry_date) {
                                    echo date('d/m/Y', strtotime($expiry_date));
                                    if ($is_expired) {
                                        echo '<br><small>Expired</small>';
                                    }
                                } else {
                                    echo '—';
                                }
                                ?>
                            </div>
                        </td>
                        <td>
                            <div class="payment-amount">KES <?php echo number_format($customer['package_price'] ?? 0, 0); ?></div>
                            <div class="payment-period"><?php echo $customer['payments_count'] ?? 0; ?> payments</div>
                        </td>
                        <td>
                            <div class="action-buttons" onclick="event.stopPropagation()">
                                <button onclick='viewCustomer(<?php echo json_encode($customer); ?>)' class="action-btn" title="View Customer Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick='openEditModal(<?php echo json_encode($customer); ?>)' class="action-btn" title="Edit Customer">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick='openSMSModal(<?php echo json_encode($customer); ?>)' class="action-btn" title="Send SMS">
                                    <i class="fas fa-comment"></i>
                                </button>
                                <button class="action-btn" title="Delete" onclick="confirmDelete(<?php echo $customer['id']; ?>, '<?php echo addslashes($customer['full_name']); ?>')">
                                    <i class="fas fa-trash-alt" style="color: #EF4444;"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Detailed User Modal (Replaces previous modal) -->
<div id="userModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 900px; border-radius: 12px; padding: 0; position: relative; max-height: 90vh; overflow-y: auto; display: flex; flex-direction: column;">
        
        <!-- Header -->
        <div style="padding: 24px; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: flex-start;">
            <div style="display: flex; gap: 16px; align-items: center;">
                 <div style="font-size: 24px; font-weight: 700; color: #111827;" id="modalUserName">Gabu503 (Gabu)</div>
                 <div style="padding: 4px 12px; background: #FEF3C7; color: #92400E; border-radius: 12px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 6px;" id="modalUserStatus">
                     <span style="width: 6px; height: 6px; border-radius: 50%; background:currentColor;"></span> Currently Offline
                 </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button onclick="openExpiryModal()" style="padding: 8px 16px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 14px; font-weight: 500; color: #374151; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.2s;">
                    <i class="fas fa-calendar-alt" style="color: #3B6EA5;"></i> Change Expiry
                </button>
                <div style="position: relative;">
                    <button class="action-btn-primary" style="padding: 8px 16px; background: #3B6EA5; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px;" onclick="toggleActionsMenu()">
                        Actions <i class="fas fa-chevron-down"></i>
                    </button>
                    <!-- Dropdown -->
                    <div id="actionsMenu" style="display: none; position: absolute; top: 100%; right: 0; width: 220px; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-top: 4px; z-index: 10;">
                        <a href="#" onclick="promptPayment(); return false;" style="display: block; padding: 10px 16px; color: #374151; text-decoration: none; font-size: 14px; hover:bg-gray-50;">
                            <i class="fas fa-mobile-alt" style="margin-right: 8px; color: #10B981;"></i> Send Payment Prompt
                        </a>
                        <a href="#" onclick="openSMSModal(currentCustomer); return false;" style="display: block; padding: 10px 16px; color: #374151; text-decoration: none; font-size: 14px;">
                            <i class="fas fa-comment" style="margin-right: 8px; color: #3B82F6;"></i> Send SMS
                        </a>
                        <div style="border-top: 1px solid #E5E7EB; margin: 4px 0;"></div>
                         <a href="#" onclick="editUser(); return false;" style="display: block; padding: 10px 16px; color: #374151; text-decoration: none; font-size: 14px;">
                            <i class="fas fa-edit" style="margin-right: 8px; color: #6B7280;"></i> Edit Details
                        </a>
                        <a href="#" onclick="confirmDelete(currentCustomer.id, currentCustomer.full_name || currentCustomer.name); return false;" style="display: block; padding: 10px 16px; color: #EF4444; text-decoration: none; font-size: 14px;">
                            <i class="fas fa-trash" style="margin-right: 8px;"></i> Delete User
                        </a>
                    </div>
                </div>
                <button onclick="closeModal()" style="padding: 8px; background: transparent; border: none; font-size: 20px; cursor: pointer; color: #9CA3AF;">&times;</button>
            </div>
        </div>
        
        <div style="padding: 0 24px 24px 24px; border-bottom: 1px solid #E5E7EB;">
            <div style="font-size: 13px; color: #6B7280; margin-top: 4px;" id="modalPackageInfo">Package: pppoe 6Mbps | Expires: January 17, 2026 12:00 AM</div>
            
            <!-- Tabs -->
            <div style="display: flex; gap: 24px; margin-top: 24px; border-bottom: 1px solid #E5E7EB;">
                <button class="modal-tab active" style="padding: 8px 0; border-bottom: 2px solid #3B6EA5; color: #3B6EA5; font-weight: 500; font-size: 14px; background: none; cursor:pointer;">General Information</button>
                <button class="modal-tab" style="padding: 8px 0; border-bottom: 2px solid transparent; color: #6B7280; font-weight: 500; font-size: 14px; background: none; cursor:pointer;">Reports</button>
                <button class="modal-tab" style="padding: 8px 0; border-bottom: 2px solid transparent; color: #6B7280; font-weight: 500; font-size: 14px; background: none; cursor:pointer;">Payments</button>
                <button class="modal-tab" style="padding: 8px 0; border-bottom: 2px solid transparent; color: #6B7280; font-weight: 500; font-size: 14px; background: none; cursor:pointer;">SMS</button>
            </div>
        </div>

        <!-- Content -->
        <div style="padding: 24px; background: #F9FAFB; flex: 1;">
            <div style="background: white; border-radius: 8px; border: 1px solid #E5E7EB; padding: 20px;">
                <h3 style="font-size: 16px; font-weight: 600; color: #111827; margin: 0 0 20px 0; border-left: 3px solid #3B6EA5; padding-left: 12px;">Account Information</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <!-- Row 1 -->
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Account Number</label>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #111827;" id="infoId">E63992</span>
                            <button style="font-size: 11px; color: #6B7280; border: 1px solid #E5E7EB; background: whitem; padding: 2px 6px; border-radius: 4px; cursor: pointer;">Copy</button>
                        </div>
                    </div>
                     <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Full Name</label>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #111827;" id="infoName">Gabu</span>
                            <button style="font-size: 11px; color: #6B7280; border: 1px solid #E5E7EB; background: whitem; padding: 2px 6px; border-radius: 4px; cursor: pointer;">Copy</button>
                        </div>
                    </div>
                    
                    <!-- Row 2 -->
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Username</label>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #111827;" id="infoUsername">Gabu503</span>
                            <button onclick="copyText(document.getElementById('infoUsername').textContent)" style="font-size: 11px; color: #6B7280; border: 1px solid #E5E7EB; background: whitem; padding: 2px 6px; border-radius: 4px; cursor: pointer;">Copy</button>
                        </div>
                    </div>
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Password</label>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #111827;">
                                <span id="pwdHidden">********</span>
                                <span id="pwdValue" style="display:none;"></span>
                            </span>
                            <div style="display: flex; gap: 4px;">
                                <button onclick="togglePwd()" style="color: #6B7280; background: none; border: none; cursor: pointer;" title="Show/Hide">
                                    <i class="fas fa-eye" id="pwdEye"></i>
                                </button>
                                <button onclick="copyText(document.getElementById('pwdValue').textContent)" style="font-size: 11px; color: #6B7280; border: 1px solid #E5E7EB; background: whitem; padding: 2px 6px; border-radius: 4px; cursor: pointer;">Copy</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Row 3 -->
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Package</label>
                        <div style="font-weight: 600; color: #111827;" id="infoPackage">pppoe 6Mbps</div>
                    </div>
                     <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Status</label>
                        <div style="font-weight: 600; color: #111827;" id="infoStatus">Active</div>
                    </div>
                    
                    <!-- Row 4 -->
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">User Type</label>
                        <div style="font-weight: 600; color: #111827;" id="infoType">PPPoE</div>
                    </div>
                     <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Phone Number</label>
                        <div style="font-weight: 600; color: #111827;" id="infoPhone">Not provided</div>
                    </div>
                    
                    <!-- Row 5 -->
                    <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Address</label>
                        <div style="font-weight: 600; color: #111827;" id="infoAddress">Not provided</div>
                    </div>
                     <div class="info-group" style="background: #F9FAFB; padding: 12px; border-radius: 6px; border: 1px solid #F3F4F6;">
                        <label style="display: block; font-size: 11px; font-weight: 600; color: #9CA3AF; text-transform: uppercase; margin-bottom: 4px;">Time Remaining</label>
                        <div style="font-weight: 600; color: #111827;" id="infoTime">2 weeks 13 hours</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit form Modal (Hidden by default, can be toggled if 'Update Details' clicked) -->
<div id="customerFormModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 700px; border-radius: 12px; padding: 32px; position: relative; max-height: 90vh; overflow-y: auto;">
         <button onclick="document.getElementById('customerFormModal').style.display='none'" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 20px; cursor: pointer; color: #6B7280;">&times;</button>
         <h2 id="formModalTitle" style="font-size: 20px; font-weight: 600; margin: 0 0 24px 0;">Add/Edit Customer</h2>
         
         <form id="customerForm" onsubmit="handleFormSubmit(event)">
             <input type="hidden" name="id" id="formId">
             
             <!-- Personal Info -->
             <h3 style="font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 16px; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Personal Information</h3>
             <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Full Name *</label>
                    <input type="text" name="name" id="formName" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Phone Number *</label>
                    <input type="text" name="phone" id="formPhone" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
             </div>
             
             <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Email Address</label>
                    <input type="email" name="email" id="formEmail" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Physical Address</label>
                    <input type="text" name="address" id="formAddress" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
             </div>

             <!-- Service Details -->
             <h3 style="font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 16px; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Service Details</h3>
             <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Package *</label>
                    <select name="package_id" id="formPackageId" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                        <option value="">Select Package</option>
                        <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?> - <?php echo number_format($pkg['price']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                     <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Connection Type</label>
                     <select name="connection_type" id="formConnectionType" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                        <option value="pppoe">PPPoE</option>
                        <option value="hotspot">Hotspot</option>
                        <option value="static">Static IP</option>
                     </select>
                </div>
             </div>
             
             <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Expiry Date (Optional)</label>
                    <input type="date" name="expiry_date" id="formExpiryDate" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;" placeholder="Select Date">
                    <small style="color: #6B7280; font-size: 11px;">Leave blank for default package duration</small>
                </div>
             </div>
             
             <!-- Access Credentials (Merged) -->
             <div style="background: #F9FAFB; padding: 16px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
                 <h4 style="font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase; margin: 0 0 12px 0;">Access Credentials (Portal & Internet)</h4>
                 <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Username *</label>
                        <input type="text" name="mikrotik_username" id="formMikrotikUsername" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                        <small style="color: #6B7280; font-size: 11px;">Used for both Router PPPoE/Hotspot and Customer Portal login</small>
                    </div>
                    <div class="form-group">
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Password</label>
                        <input type="password" name="mikrotik_password" id="formMikrotikPassword" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;" placeholder="Leave blank to keep current">
                    </div>
                 </div>
             </div>
             
             <!-- Portal Fields Removed (Merged with above) -->

             <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #E5E7EB; padding-top: 20px;">
                <button type="button" onclick="document.getElementById('customerFormModal').style.display='none'" style="padding: 10px 20px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 10px 24px; background: #3B6EA5; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">Save Customer</button>
             </div>
         </form>
    </div>
</div>

<!-- SMS Modal (Added back) -->
<div id="smsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1002; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 500px; border-radius: 12px; padding: 24px; position: relative;">
        <button onclick="document.getElementById('smsModal').style.display='none'" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 20px; cursor: pointer; color: #6B7280;">&times;</button>
        <h3 style="margin: 0 0 16px 0;">Send SMS to <span id="smsCustomerName"></span></h3>
        <form onsubmit="handleSendSMS(event)">
            <input type="hidden" name="client_id" id="smsClientId">
            <input type="hidden" name="phone" id="smsClientPhone">
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Template</label>
                <select id="smsTemplate" onchange="applyTemplate()" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px; background: white;">
                    <option value="">-- Select a Template --</option>
                    <option value="credentials">Login Credentials</option>
                    <option value="payment">Payment Details</option>
                    <option value="alert">Service Alert</option>
                    <option value="promo">Promotional Message</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Message</label>
                <textarea name="message" id="smsMessage" rows="4" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-family: inherit;" placeholder="Type your message here..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="document.getElementById('smsModal').style.display='none'" style="padding: 8px 16px; border: 1px solid #D1D5DB; background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 8px 24px; background: #3B82F6; color: white; border: none; border-radius: 6px; cursor: pointer;">Send SMS</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentCustomer = null;

function viewCustomer(customerJson) {
    if (typeof customerJson === 'string') {
        currentCustomer = JSON.parse(customerJson);
    } else {
        currentCustomer = customerJson;
    }
    
    // Populate Modal
    document.getElementById('modalUserName').textContent = (currentCustomer.mikrotik_username || currentCustomer.username) + " (" + (currentCustomer.full_name || currentCustomer.name) + ")";
    document.getElementById('infoId').textContent = currentCustomer.id; // Or Account Number if exists
    document.getElementById('infoName').textContent = currentCustomer.full_name || currentCustomer.name;
    document.getElementById('infoUsername').textContent = currentCustomer.mikrotik_username || currentCustomer.username;
    
    // Password handling
    const pwd = currentCustomer.mikrotik_password || currentCustomer.password || '';
    document.getElementById('pwdValue').textContent = pwd;
    // Reset toggle
    document.getElementById('pwdHidden').style.display = 'inline';
    document.getElementById('pwdValue').style.display = 'none';
    document.getElementById('pwdEye').className = 'fas fa-eye';

    document.getElementById('infoPhone').textContent = currentCustomer.phone;
    document.getElementById('infoAddress').textContent = currentCustomer.address || currentCustomer.location || 'Not provided';
    document.getElementById('infoPackage').textContent = (currentCustomer.package_name || currentCustomer.subscription_plan) + " | " + (currentCustomer.connection_type || 'PPPoE');
    document.getElementById('infoType').textContent = (currentCustomer.connection_type || 'PPPoE').toUpperCase();
    document.getElementById('infoStatus').textContent = currentCustomer.status;
    document.getElementById('infoTime').textContent = calculateTimeLeft(currentCustomer.expiry_date);
    document.getElementById('modalPackageInfo').textContent = "Package: " + (currentCustomer.package_name || 'N/A') + " | Expires: " + formatDate(currentCustomer.expiry_date);
    
    document.getElementById('userModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('userModal').style.display = 'none';
    document.getElementById('actionsMenu').style.display = 'none';
}

function toggleActionsMenu() {
    const menu = document.getElementById('actionsMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
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

function copyText(text) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        alert("Copied to clipboard!");
    }).catch(err => {
        console.error('Failed to copy: ', err);
        // Fallback
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand("Copy");
        textArea.remove();
        alert("Copied!");
    });
}

function promptPayment() {
    // Open Payment Modal (Simple version)
    // We can reuse the one from payments.php if we copy it, or build a simple specific one here.
    // For now, let's use a prompt logic
    
    if (!currentCustomer) return;
    
    const amount = prompt("Enter Amount to Request (KES):", currentCustomer.package_price || "1000");
    if (amount) {
        const phone = prompt("Confirm Phone Number for M-Pesa:", currentCustomer.phone);
        if (phone) {
             const formData = new FormData();
             formData.append('client_id', currentCustomer.id);
             formData.append('phone', phone);
             formData.append('amount', amount);
             
             // Show loading?
             alert("Initiating STK Push...");
             
             fetch('api/mpesa/stk_push.php', {
                 method: 'POST',
                 body: formData
             })
             .then(r => r.json())
             .then(data => {
                 if (data.Success || data.success) {
                     alert("STK Push Sent! Check customer phone.");
                 } else {
                     alert("Failed: " + (data.message || data.errorMessage));
                 }
             });
        }
    }
}

function sendEmail() {
     alert("Email Feature Placeholder");
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
    
    // Reset displays - Portal is always enabled now, no UI elements to toggle
    
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
    
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.textContent;
    btn.textContent = 'Saving...';
    btn.disabled = true;
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Success! Customer saved.');
            location.reload();
        } else {
            alert('Error: ' + data.message);
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

function confirmDelete(id, name) {
    if(confirm("Are you sure you want to delete client " + name + "?\n\nThis will remove them from the system and the Router.")) {
         const formData = new FormData();
         formData.append('id', id);
         fetch('api/customers/delete.php', { method: 'POST', body: formData })
         .then(r => r.json())
         .then(d => {
             if(d.success) {
                 alert("Customer deleted successfully.");
                 location.reload();
             }
             else {
                 alert("Error deleting customer: " + d.message);
             }
         })
         .catch(err => {
             alert("Error connecting to server.");
             console.error(err);
         });
    }
}

function openSMSModal(customer) {
    if (!customer) return;
    document.getElementById('smsClientId').value = customer.id;
    document.getElementById('smsClientPhone').value = customer.phone;
    document.getElementById('smsCustomerName').textContent = customer.full_name || customer.name;
    document.getElementById('smsMessage').value = '';
    document.getElementById('smsTemplate').value = ''; // Reset template
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
        alert('SMS sent successfully (Simulation).');
        document.getElementById('smsModal').style.display = 'none';
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

function calculateTimeLeft(expiryDate) {
    if (!expiryDate) return "N/A";
    const now = new Date();
    const expiry = new Date(expiryDate);
    const diff = expiry - now;
    
    if (diff < 0) return "Expired";
    
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    return days + " days remaining";
}

function formatDate(dateString) {
    if (!dateString) return "N/A";
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}

// Global click to close menu
window.onclick = function(event) {
  if (!event.target.matches('.action-btn-primary') && !event.target.matches('.action-btn-primary *')) {
    var dropdowns = document.getElementsByClassName("dropdown-content");
    document.getElementById('actionsMenu').style.display = "none";
  }
}
</script>