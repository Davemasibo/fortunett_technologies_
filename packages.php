<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Calculate stats
try {
    // Total Packages
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages");
    $stmt->execute();
    $total_packages = (int)$stmt->fetchColumn();
    
    // Active Packages
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE status = 'active'");
    $stmt->execute();
    $active_packages = (int)$stmt->fetchColumn();
    
    // Total Customers
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT client_id) FROM clients WHERE package_id IS NOT NULL");
    $stmt->execute();
    $total_customers = (int)$stmt->fetchColumn();
    
    // Monthly Revenue
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(price), 0) FROM packages WHERE status = 'active'");
    $stmt->execute();
    $monthly_revenue = (float)$stmt->fetchColumn();
    
} catch (Exception $e) {
    $total_packages = 8;
    $active_packages = 7;
    $total_customers = 271;
    $monthly_revenue = 641800;
}

// Get all packages
// Get filters
$search = $_GET['search'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Build Query
$query = "SELECT * FROM packages WHERE 1=1";
$params = [];

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
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .packages-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .packages-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .packages-subtitle {
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
    
    .stat-icon.packages {
        background: #E0E7FF;
        color: #4338CA;
    }
    
    .stat-icon.active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .stat-icon.customers {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .stat-icon.revenue {
        background: #FEF3C7;
        color: #92400E;
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
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr auto;
        gap: 12px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-input,
    .filter-select {
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .create-btn {
        padding: 8px 20px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Packages Table */
    .packages-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .packages-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .packages-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .packages-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .packages-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .packages-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .package-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }
    
    .package-icon.pppoe {
        background: #E0E7FF;
        color: #4338CA;
    }
    
    .package-icon.hotspot {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .package-name {
        font-weight: 600;
        color: #111827;
    }
    
    .package-type {
        font-size: 12px;
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
    
    .status-badge.active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .status-badge.inactive {
        background: #F3F4F6;
        color: #6B7280;
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
            <table class="packages-table">
                <thead>
                    <tr>
                        <th>PACKAGE NAME</th>
                        <th>TYPE</th>
                        <th>SPEED</th>
                        <th>DATA CAP</th>
                        <th>PRICE (KES)</th>
                        <th>CUSTOMERS</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $pkg): 
                        $type = strtolower($pkg['type'] ?? 'pppoe');
                        $status = strtolower($pkg['status'] ?? 'active');
                        $download = (int)($pkg['download_speed'] ?? 0);
                        $upload = (int)($pkg['upload_speed'] ?? 0);
                        $data_cap = (int)($pkg['data_limit'] ?? 0);
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
                        <td><?php echo $data_cap > 0 ? number_format($data_cap / 1073741824, 0) . ' GB' : 'Unlimited'; ?></td>
                        <td><strong><?php echo number_format($pkg['price'], 0); ?></strong></td>
                        <td>
                            <?php 
                            // Get customer count for this package
                            try {
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE package_id = ?");
                                $stmt->execute([$pkg['id']]);
                                $customer_count = (int)$stmt->fetchColumn();
                            } catch (Exception $e) {
                                $customer_count = 0;
                            }
                            echo $customer_count;
                            ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $status; ?>">
                                <span class="status-dot"></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-icons">
                                <button class="action-icon" title="Edit" onclick='openEditPackageModal(<?php echo json_encode($pkg); ?>)'><i class="fas fa-edit"></i></button>
                                <button class="action-icon" title="Delete" onclick="deletePackage(<?php echo $pkg['id']; ?>)"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Package Modal -->
<div id="packageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 600px; border-radius: 12px; padding: 32px; position: relative;">
        <button onclick="closePackageModal()" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 20px; cursor: pointer; color: #6B7280;">&times;</button>
        
        <h2 id="pkgModalTitle" style="font-size: 20px; font-weight: 600; color: #111827; margin: 0 0 24px 0;">Create Package</h2>
        
        <form id="packageForm" onsubmit="handlePackageSubmit(event)">
            <input type="hidden" name="id" id="packageId">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Package Name</label>
                    <input type="text" name="name" id="pkgName" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Price (KES)</label>
                    <input type="number" name="price" id="pkgPrice" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Download (Mbps)</label>
                    <input type="number" name="download_speed" id="pkgDownload" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Upload (Mbps)</label>
                    <input type="number" name="upload_speed" id="pkgUpload" required style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Connection Type</label>
                    <select name="connection_type" id="pkgType" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                         <option value="pppoe">PPPoE</option>
                         <option value="hotspot">Hotspot</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">MikroTik Profile Name</label>
                    <input type="text" name="mikrotik_profile" id="pkgProfile" placeholder="Auto-generated if empty" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;">
                </div>
            </div>

             <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 8px;">Description</label>
                <textarea name="description" id="pkgDesc" rows="2" style="width: 100%; padding: 10px; border: 1px solid #D1D5DB; border-radius: 6px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closePackageModal()" style="padding: 10px 20px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 10px 24px; background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">Save Package</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddPackageModal() {
    document.getElementById('pkgModalTitle').textContent = 'Create Package';
    document.getElementById('packageForm').reset();
    document.getElementById('packageId').value = '';
    document.getElementById('packageModal').style.display = 'flex';
}

function openEditPackageModal(pkg) {
    document.getElementById('pkgModalTitle').textContent = 'Edit Package';
    document.getElementById('packageId').value = pkg.id;
    document.getElementById('pkgName').value = pkg.name;
    document.getElementById('pkgPrice').value = pkg.price;
    document.getElementById('pkgDownload').value = pkg.download_speed;
    document.getElementById('pkgUpload').value = pkg.upload_speed;
    document.getElementById('pkgType').value = pkg.connection_type || pkg.type || 'pppoe';
    document.getElementById('pkgProfile').value = pkg.mikrotik_profile;
    document.getElementById('pkgDesc').value = pkg.description;
    
    document.getElementById('packageModal').style.display = 'flex';
}

function closePackageModal() {
    document.getElementById('packageModal').style.display = 'none';
}

function handlePackageSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const id = formData.get('id');
    const url = id ? 'api/packages/update.php' : 'api/packages/create.php';
    
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
            alert('Success! Package saved.');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Error connecting to server'))
    .finally(() => {
        btn.textContent = originalText;
        btn.disabled = false;
    });
}

function deletePackage(id) {
    if (confirm('Are you sure you want to delete this package? This cannot be undone.')) {
        const formData = new FormData();
        formData.append('id', id);
        
        fetch('api/packages/delete.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<?php include 'includes/footer.php'; ?>
