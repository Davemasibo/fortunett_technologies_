<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$pdo = $database->getConnection();

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Calculate stats
$total_packages = 0;
$active_packages = 0;
$total_customers = 0;
$monthly_revenue = 0;

try {
    // Total Packages
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $total_packages = (int)$stmt->fetchColumn();
} catch (Exception $e) { error_log("Stats Error (Total Pkgs): " . $e->getMessage()); }

try {
    // Active Packages
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE status = 'active' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $active_packages = (int)$stmt->fetchColumn();
} catch (Exception $e) { error_log("Stats Error (Active Pkgs): " . $e->getMessage()); }

try {
    // Total Customers
    // Fixed: changed client_id to id
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM clients WHERE package_id IS NOT NULL AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $total_customers = (int)$stmt->fetchColumn();
} catch (Exception $e) { error_log("Stats Error (Total Cust): " . $e->getMessage()); }

try {
    // Monthly Revenue (Real Collections)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid' AND tenant_id = ? AND MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE())");
    $stmt->execute([$tenant_id]);
    $monthly_revenue = (float)$stmt->fetchColumn();
} catch (Exception $e) { error_log("Stats Error (Revenue): " . $e->getMessage()); }

// Get all packages
// Get filters
$search = $_GET['search'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Build Query
$query = "SELECT * FROM packages WHERE tenant_id = ?";
$params = [$tenant_id];

if ($search) {
    $query .= " AND (name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_type && $filter_type !== 'All Types') {
    $query .= " AND (type = ? OR connection_type = ?)";
    $params[] = strtolower($filter_type);
    $params[] = strtolower($filter_type);
}

if ($filter_status && $filter_status !== 'All Status') {
    $query .= " AND status = ?";
    $params[] = strtolower($filter_status);
}

$query .= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $packages = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper { background: #141414 !important; justify-content: flex-start !important; }
    /* Override sidebar max-width constraint — fill full content area */
    .main-content-wrapper > div.packages-container {
        max-width: 100% !important; margin: 0 !important; padding: 24px 32px !important; box-sizing: border-box !important;
    }
    .packages-container { width: 100%; box-sizing: border-box; }
    .packages-title { font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px 0; }
    .packages-subtitle { font-size: 14px; color: #9a9a95; margin: 0 0 24px 0; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: #222221; border-radius: 12px; padding: 20px; border: 1px solid rgba(255,255,255,.06); box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
    .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .stat-icon.packages  { background: rgba(99,102,241,.15); color: #a5b4fc; }
    .stat-icon.active    { background: rgba(16,185,129,.15);  color: #6ee7b7; }
    .stat-icon.customers { background: rgba(59,130,246,.15);  color: #93c5fd; }
    .stat-icon.revenue   { background: rgba(245,158,11,.15);  color: #fcd34d; }
    .stat-value { font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 4px; }
    .stat-label { font-size: 13px; color: #9a9a95; font-weight: 500; }

    /* Filters */
    .filters-section { background: #222221; border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,.06); }
    .filters-title { font-size: 16px; font-weight: 600; color: #e2e2e0; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .filters-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-input, .filter-select {
        padding: 8px 12px; border: 1px solid rgba(255,255,255,.08); border-radius: 6px;
        font-size: 14px; background: #1a1a19; color: #e2e2e0;
        box-shadow: inset 2px 2px 5px rgba(0,0,0,.4), inset -1px -1px 3px rgba(255,255,255,.04);
    }
    .create-btn { padding: 10px 20px; background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s ease; }
    .create-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.5); }

    /* Table */
    .packages-section { background: #222221; border-radius: 12px; border: 1px solid rgba(255,255,255,.06); overflow: hidden; box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03); }
    .packages-table { width: 100%; border-collapse: collapse; }
    .packages-table thead { background: rgba(255,255,255,.04); border-bottom: 1px solid rgba(255,255,255,.07); }
    .packages-table th { padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #9a9a95; text-transform: uppercase; letter-spacing: 0.05em; }
    .packages-table td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 14px; color: #e2e2e0; }
    .packages-table tbody tr:hover { background: rgba(255,255,255,.04); }
    .package-icon { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
    .package-icon.pppoe   { background: rgba(99,102,241,.15); color: #a5b4fc; }
    .package-icon.hotspot { background: rgba(59,130,246,.15);  color: #93c5fd; }
    .package-name { font-weight: 600; color: #fff; }
    .package-type { font-size: 12px; color: #9a9a95; }

    /* Status badges */
    .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
    .status-badge.active   { background: rgba(16,185,129,.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
    .status-badge.inactive { background: rgba(107,114,128,.15); color: #9ca3af; border: 1px solid rgba(107,114,128,.25); }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

    /* Action icons */
    .action-icons { display: flex; gap: 8px; }
    .action-icon { width: 28px; height: 28px; border-radius: 6px; border: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.05); color: #9a9a95; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; transition: all .18s; }
    .action-icon:hover { background: var(--primary-color,#3B6EA5); border-color: transparent; color: #fff; }

    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .packages-table { min-width: 720px; }

    @media (max-width: 1024px) { .filters-grid { grid-template-columns: 1fr; } }
    @media (max-width: 640px) {
        .packages-container { padding: 16px; }
        #packageModal > div { max-width: 100% !important; border-radius: 10px !important; }
    }
</style>

<div class="main-content-wrapper">
    <div class="packages-container">
        <!-- Header -->
        <div class="packages-header">
            <h1 class="packages-title">Package Management</h1>
            <p class="packages-subtitle">Configure internet service packages and MikroTik integration</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon packages">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_packages; ?></div>
                <div class="stat-label">Total Packages</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon active">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $active_packages; ?></div>
                <div class="stat-label">Active Packages</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon customers">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_customers; ?></div>
                <div class="stat-label">Total Customers</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon revenue">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="stat-value">KES <?php echo number_format($monthly_revenue, 0); ?></div>
                <div class="stat-label">Monthly Revenue</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <h3 class="filters-title">
                <i class="fas fa-filter"></i>
                Filter Packages
            </h3>

            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <input type="text" name="search" class="filter-input" placeholder="Search packages..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <select name="type" class="filter-select" onchange="this.form.submit()">
                        <option>All Types</option>
                        <option <?php echo $filter_type == 'PPPoE' ? 'selected' : ''; ?>>PPPoE</option>
                        <option <?php echo $filter_type == 'Hotspot' ? 'selected' : ''; ?>>Hotspot</option>
                    </select>
                </div>
                <div class="filter-group">
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option>All Status</option>
                        <option <?php echo $filter_status == 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option <?php echo $filter_status == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                     <!-- Spacer or additional filter -->
                </div>
                <div style="display:flex; gap: 10px; align-items:center;">
                    <a href="packages.php" style="color:#6B7280; text-decoration:none; font-size:14px; margin-right:10px;">Clear</a>
                    <button type="button" class="create-btn" onclick="openAddPackageModal()">
                        <i class="fas fa-plus"></i>
                        Create Package
                    </button>
                    <button type="submit" style="display:none;"></button>
                </div>
            </form>
        </div>

        <!-- Packages Table -->
        <div class="packages-section">
          <div class="table-scroll">
            <table class="packages-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Speed</th>
                        <th>Time</th>
                        <th>Data Limit</th>
                        <th>Devices</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $pkg): 
                        $type = strtolower($pkg['type'] ?? 'pppoe');
                        $status = strtolower($pkg['status'] ?? 'active');
                        $download = (int)($pkg['download_speed'] ?? 0);
                        $upload = (int)($pkg['upload_speed'] ?? 0);
                        $data_cap = (int)($pkg['data_limit'] ?? 0);
                        $validityValue = $pkg['validity_value'] ?? 30;
                        $validityUnit = $pkg['validity_unit'] ?? 'days';
                        // Respect user's choice (Singular/Plural)
                        $validityText = $validityValue . ' ' . ucfirst($validityUnit);
                        
                        // Optional: Smart fix ONLY if they mismatch (e.g. "1 Hours" -> "1 Hour", "2 Hour" -> "2 Hours")
                        // But user asked to "create both", so we trust their input. 
                        // However, standard grammar is better. Let's auto-fix grammar but allow the singular options to be selected in UI.
                        if ($validityValue == 1 && substr($validityUnit, -1) === 's') {
                             $validityText = $validityValue . ' ' . ucfirst(substr($validityUnit, 0, -1));
                        } elseif ($validityValue > 1 && substr($validityUnit, -1) !== 's') {
                             $validityText = $validityValue . ' ' . ucfirst($validityUnit) . 's';
                        }

                        $validity = $validityText;
                        $devices = $pkg['device_limit'] ?? 1;
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="package-icon <?php echo $type; ?>">
                                    <i class="fas fa-<?php echo $type === 'pppoe' ? 'network-wired' : 'wifi'; ?>"></i>
                                </div>
                                <div>
                                    <div class="package-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                    <div class="package-type"><?php echo htmlspecialchars($pkg['description'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo strtoupper($type); ?></td>
                        <td><?php echo $download; ?>/<?php echo $upload; ?> Mbps</td>
                        <td><?php echo $validity; ?></td>
                        <td><?php echo $data_cap > 0 ? number_format($data_cap / 1073741824, 0) . ' GB' : 'Unlimited'; ?></td>
                        <td><?php echo $devices; ?></td>
                        <td><strong>KES <?php echo number_format($pkg['price'], 0); ?></strong></td>
                        <td>
                            <span class="status-badge <?php echo $status; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-icons">
                                <button class="action-icon" title="Edit" onclick='openEditPackageModal(<?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES, "UTF-8"); ?>)'><i class="fas fa-edit"></i></button>
                                <button class="action-icon" title="Delete" onclick="deletePackage(<?php echo $pkg['id']; ?>)"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
          </div><!-- end table-scroll -->
        </div>
    </div>
</div>

<!-- Package Modal CSS -->
<style>
.pkg-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 1050;
    overflow-y: auto;           /* overlay scrolls when modal is tall */
    -webkit-overflow-scrolling: touch;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px 24px;
    box-sizing: border-box;
}
.pkg-modal {
    background: #1e1e1d;
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 18px;
    width: 100%; max-width: 660px;
    /* No max-height — overlay scrolls instead, so nothing gets clipped */
    display: flex; flex-direction: column;
    box-shadow: 0 40px 100px rgba(0,0,0,.85),
                inset 0 1px 0 rgba(255,255,255,.07);
    overflow: hidden;
    /* Keep modal body scrollable but don't let the whole modal overflow */
    max-height: calc(100vh - 80px);
    margin: auto 0;             /* vertically center when there's room */
}
.pkg-modal-head {
    padding: 20px 24px 16px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-shrink: 0;
    background: linear-gradient(135deg, rgba(255,255,255,.04) 0%, transparent 100%);
}
.pkg-modal-title { font-size: 17px; font-weight: 700; color: #f0f0ee; margin-bottom: 3px; }
.pkg-modal-sub   { font-size: 12px; color: rgba(255,255,255,.35); }
.pkg-close-btn {
    background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px; width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: rgba(255,255,255,.5); font-size: 16px;
    transition: all .15s; flex-shrink: 0;
}
.pkg-close-btn:hover { background: rgba(255,255,255,.14); color: #fff; }
.pkg-modal-body { overflow-y: auto; flex: 1; padding: 24px; }
.pkg-modal-body::-webkit-scrollbar { width: 5px; }
.pkg-modal-body::-webkit-scrollbar-track { background: transparent; }
.pkg-modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }
.pkg-modal-foot {
    padding: 14px 24px;
    border-top: 1px solid rgba(255,255,255,.07);
    display: flex; justify-content: flex-end; gap: 10px;
    flex-shrink: 0; background: rgba(255,255,255,.015);
}

/* Section labels */
.pkg-section {
    font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,.3);
    text-transform: uppercase; letter-spacing: .08em;
    margin: 0 0 14px; padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    display: flex; align-items: center; gap: 7px;
}
.pkg-section i { color: var(--primary-color, #3B6EA5); font-size: 11px; }

/* Field grid helpers */
.pkg-row   { display: grid; gap: 14px; margin-bottom: 18px; }
.pkg-col-2 { grid-template-columns: 1fr 1fr; }
.pkg-col-3 { grid-template-columns: 1fr 1fr 1fr; }

/* Dark inputs */
.pkg-label {
    display: block; font-size: 11.5px; font-weight: 600;
    color: rgba(255,255,255,.45); margin-bottom: 6px;
    text-transform: uppercase; letter-spacing: .04em;
}
.pkg-input, .pkg-select, .pkg-textarea {
    width: 100%; padding: 10px 12px;
    background: rgb(27,27,26);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 9px; color: #e2e2e0; font-size: 13.5px;
    box-shadow: inset 3px 3px 8px rgba(0,0,0,.45), inset -2px -2px 5px rgba(255,255,255,.04);
    transition: border-color .15s, box-shadow .15s;
    box-sizing: border-box; font-family: inherit;
    -webkit-appearance: none; appearance: none;
}
.pkg-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,.35)'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 28px; }
.pkg-textarea { resize: vertical; min-height: 68px; }
.pkg-input::placeholder, .pkg-textarea::placeholder { color: rgba(255,255,255,.2); }
.pkg-input:focus, .pkg-select:focus, .pkg-textarea:focus {
    outline: none;
    border-color: var(--primary-color, #3B6EA5);
    box-shadow: inset 3px 3px 8px rgba(0,0,0,.45), 0 0 0 3px rgba(59,110,165,.2);
}
.pkg-input-hint { font-size: 11px; color: rgba(255,255,255,.25); margin-top: 4px; }

/* Rate limit preview badge */
.rate-preview {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(59,110,165,.12); border: 1px solid rgba(59,110,165,.2);
    border-radius: 6px; padding: 4px 10px; font-size: 12px;
    color: #93c5fd; font-family: monospace; margin-top: 6px;
}

/* Validity pair */
.validity-pair { display: grid; grid-template-columns: 90px 1fr; gap: 8px; }

/* Connection type toggle pills */
.conn-pills { display: flex; gap: 8px; }
.conn-pill {
    flex: 1; padding: 9px 8px; border-radius: 9px; text-align: center;
    border: 1.5px solid rgba(255,255,255,.08); background: rgba(255,255,255,.04);
    cursor: pointer; transition: all .18s; font-size: 13px; font-weight: 600;
    color: rgba(255,255,255,.45);
}
.conn-pill.active {
    border-color: var(--primary-color, #3B6EA5);
    background: rgba(59,110,165,.18); color: #e2e2e0;
    box-shadow: 0 0 0 3px rgba(59,110,165,.15);
}
.conn-pill i { margin-right: 5px; font-size: 12px; }

/* Footer buttons */
.pkg-btn-cancel {
    padding: 10px 20px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1); border-radius: 9px;
    font-size: 13.5px; color: rgba(255,255,255,.6); cursor: pointer;
    transition: all .15s;
}
.pkg-btn-cancel:hover { background: rgba(255,255,255,.12); color: #e2e2e0; }
.pkg-btn-save {
    padding: 10px 26px;
    background: linear-gradient(135deg, var(--primary-dark,#2C5282) 0%, var(--primary-color,#3B6EA5) 100%);
    color: #fff; border: none; border-radius: 9px;
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 14px rgba(59,110,165,.35);
    transition: all .18s; display: flex; align-items: center; gap: 7px;
}
.pkg-btn-save:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(59,110,165,.45); }
.pkg-btn-save:disabled { opacity: .6; transform: none; cursor: not-allowed; }

@media(max-width:600px) {
    .pkg-overlay { padding: 16px 10px 32px; align-items: flex-start; }
    .pkg-modal {
        max-height: none;   /* let overlay scroll — no clipping on mobile */
        border-radius: 14px;
        margin: 0 auto;
    }
    .pkg-modal-body { padding: 18px 16px; }
    .pkg-modal-head, .pkg-modal-foot { padding-left: 16px; padding-right: 16px; }
    .pkg-col-2, .pkg-col-3 { grid-template-columns: 1fr !important; }
    .validity-pair { grid-template-columns: 80px 1fr; }
    .conn-pills { flex-direction: row; }
}
</style>

<!-- Add/Edit Package Modal -->
<div id="packageModal" class="pkg-overlay">
<div class="pkg-modal">
    <div class="pkg-modal-head">
        <div>
            <div class="pkg-modal-title" id="pkgModalTitle">Create Package</div>
            <div class="pkg-modal-sub">Configure service package &amp; MikroTik profile</div>
        </div>
        <button class="pkg-close-btn" onclick="closePackageModal()"><i class="fas fa-times"></i></button>
    </div>

    <div class="pkg-modal-body">
        <form id="packageForm" onsubmit="handlePackageSubmit(event)">
            <input type="hidden" name="id" id="packageId">
            <input type="hidden" name="connection_type" id="pkgConnType" value="pppoe">

            <!-- Basic Info -->
            <div class="pkg-section"><i class="fas fa-tag"></i> Basic Info</div>
            <div class="pkg-row pkg-col-2">
                <div>
                    <label class="pkg-label">Package Name <span style="color:#f87171;">*</span></label>
                    <input type="text" name="name" id="pkgName" class="pkg-input" required placeholder="e.g. Home 10Mbps">
                </div>
                <div>
                    <label class="pkg-label">Price (KES) <span style="color:#f87171;">*</span></label>
                    <input type="number" name="price" id="pkgPrice" class="pkg-input" required placeholder="e.g. 2500" min="0" step="0.01">
                </div>
            </div>

            <!-- Connection Type -->
            <div class="pkg-section" style="margin-top:4px;"><i class="fas fa-plug"></i> Connection Type</div>
            <div class="pkg-row" style="grid-template-columns:1fr;margin-bottom:18px;">
                <div>
                    <div class="conn-pills">
                        <div class="conn-pill active" id="pill-pppoe" onclick="setConnType('pppoe')">
                            <i class="fas fa-network-wired"></i> PPPoE
                        </div>
                        <div class="conn-pill" id="pill-hotspot" onclick="setConnType('hotspot')">
                            <i class="fas fa-wifi"></i> Hotspot
                        </div>
                    </div>
                </div>
            </div>

            <!-- Speed & Data -->
            <div class="pkg-section"><i class="fas fa-tachometer-alt"></i> Speed &amp; Data</div>
            <div class="pkg-row pkg-col-3">
                <div>
                    <label class="pkg-label">Download (Mbps) <span style="color:#f87171;">*</span></label>
                    <input type="number" name="download_speed" id="pkgDownload" class="pkg-input" required placeholder="10" min="0" oninput="updateRatePreview()">
                </div>
                <div>
                    <label class="pkg-label">Upload (Mbps) <span style="color:#f87171;">*</span></label>
                    <input type="number" name="upload_speed" id="pkgUpload" class="pkg-input" required placeholder="5" min="0" oninput="updateRatePreview()">
                </div>
                <div>
                    <label class="pkg-label">Data Cap (GB)</label>
                    <input type="number" name="data_limit" id="pkgDataLimit" class="pkg-input" placeholder="0 = Unlimited" min="0">
                    <div class="pkg-input-hint">0 or blank = Unlimited</div>
                </div>
            </div>
            <div id="ratePreviewWrap" style="margin-top:-8px;margin-bottom:18px;">
                <span class="rate-preview"><i class="fas fa-exchange-alt"></i> MikroTik rate: <strong id="ratePreviewVal">—</strong></span>
            </div>

            <!-- Validity & Limits -->
            <div class="pkg-section"><i class="fas fa-clock"></i> Validity &amp; Limits</div>
            <div class="pkg-row pkg-col-2">
                <div>
                    <label class="pkg-label">Validity Period</label>
                    <div class="validity-pair">
                        <input type="number" name="validity_value" id="pkgValidityValue" class="pkg-input" value="1" min="1" required placeholder="1">
                        <select name="validity_unit" id="pkgValidityUnit" class="pkg-select">
                            <option value="hours">Hours</option>
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months" selected>Months</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="pkg-label">Device Limit</label>
                    <input type="number" name="device_limit" id="pkgDeviceLimit" class="pkg-input" value="1" min="1" required placeholder="1">
                    <div class="pkg-input-hint">Max simultaneous connections</div>
                </div>
            </div>

            <!-- Description -->
            <div class="pkg-section" style="margin-top:4px;"><i class="fas fa-align-left"></i> Description</div>
            <div class="pkg-row" style="grid-template-columns:1fr;">
                <div>
                    <textarea name="description" id="pkgDesc" class="pkg-textarea" placeholder="Optional — shown to customers on their portal"></textarea>
                </div>
            </div>
        </form>
    </div>

    <div class="pkg-modal-foot">
        <button type="button" class="pkg-btn-cancel" onclick="closePackageModal()">Cancel</button>
        <button type="submit" form="packageForm" class="pkg-btn-save" id="pkgSaveBtn">
            <i class="fas fa-save"></i> Save Package
        </button>
    </div>
</div>
</div>

<script>
/* ── Connection type pills ── */
function setConnType(type) {
    document.getElementById('pkgConnType').value = type;
    document.getElementById('pill-pppoe').classList.toggle('active', type === 'pppoe');
    document.getElementById('pill-hotspot').classList.toggle('active', type === 'hotspot');
}

/* ── Rate limit preview ── */
function updateRatePreview() {
    const dl = parseInt(document.getElementById('pkgDownload').value) || 0;
    const ul = parseInt(document.getElementById('pkgUpload').value) || 0;
    document.getElementById('ratePreviewVal').textContent = dl || ul ? ul + 'M/' + dl + 'M' : '—';
}

/* ── Open / close ── */
function openAddPackageModal() {
    document.getElementById('pkgModalTitle').textContent = 'Create Package';
    document.getElementById('packageForm').reset();
    document.getElementById('packageId').value = '';
    document.getElementById('pkgValidityValue').value = '1';
    document.getElementById('pkgValidityUnit').value = 'months';
    document.getElementById('pkgDeviceLimit').value = '1';
    setConnType('pppoe');
    updateRatePreview();
    document.getElementById('packageModal').style.display = 'flex';
}

function openEditPackageModal(pkg) {
    document.getElementById('pkgModalTitle').textContent = 'Edit Package';
    document.getElementById('packageId').value = pkg.id;
    document.getElementById('pkgName').value = pkg.name || '';
    document.getElementById('pkgPrice').value = pkg.price || '';
    document.getElementById('pkgDownload').value = pkg.download_speed || '';
    document.getElementById('pkgUpload').value = pkg.upload_speed || '';
    document.getElementById('pkgDataLimit').value = pkg.data_limit || '';
    document.getElementById('pkgDesc').value = pkg.description || '';
    document.getElementById('pkgDeviceLimit').value = pkg.device_limit || '1';
    document.getElementById('pkgValidityValue').value = pkg.validity_value || '1';
    const unit = (pkg.validity_unit || 'months').replace(/s$/, '') + 's';
    document.getElementById('pkgValidityUnit').value = ['hours','days','weeks','months'].includes(unit) ? unit : (pkg.validity_unit || 'months');
    setConnType(pkg.connection_type || pkg.type || 'pppoe');
    updateRatePreview();
    document.getElementById('packageModal').style.display = 'flex';
}

function closePackageModal() {
    document.getElementById('packageModal').style.display = 'none';
}

document.getElementById('packageModal').addEventListener('click', function(e) {
    if (e.target === this) closePackageModal();
});

/* ── Submit ── */
function handlePackageSubmit(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('packageForm'));
    const id = formData.get('id');
    const url = id ? 'api/packages/update.php' : 'api/packages/create.php';
    const btn = document.getElementById('pkgSaveBtn');
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;

    fetch(url, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Package saved successfully.', 'success');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast('Error: ' + (data.message || 'Unknown error'), 'error');
                btn.innerHTML = origHTML; btn.disabled = false;
            }
        })
        .catch(() => { showToast('Network error. Please try again.', 'error'); btn.innerHTML = origHTML; btn.disabled = false; });
}

/* ── Delete ── */
function deletePackage(id) {
    if (confirm('Delete this package? This cannot be undone.')) {
        const fd = new FormData();
        fd.append('id', id);
        fetch('api/packages/delete.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) { showToast('Package deleted.', 'success'); setTimeout(() => location.reload(), 700); }
                else showToast('Error: ' + data.message, 'error');
            })
            .catch(() => showToast('Network error.', 'error'));
    }
}

// Auto-open create package modal when navigated from quick actions
if (new URLSearchParams(window.location.search).get('open_modal') === '1') {
    openAddPackageModal();
}
</script>

<?php include 'includes/footer.php'; ?>
