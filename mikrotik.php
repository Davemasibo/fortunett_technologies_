<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/mpesa.php';

// Extract Base URL from M-Pesa Config for Provisioning
// We need to go up 3 levels from .../api/mpesa/callback.php to get PROJECT_ROOT
$ngrok_url = defined('MPESA_CALLBACK_URL') ? dirname(dirname(dirname(MPESA_CALLBACK_URL))) : 'http://localhost/fortunett_technologies_';

redirectIfNotLoggedIn();

$action_result = null;

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Fetch Tenant Info
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle DELETE Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_router'])) {
    $router_id = $_POST['router_id'] ?? 0;
    try {
        // Ensure tenant owns this router before deleting
        $stmt = $pdo->prepare("DELETE FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$router_id, $tenant_id]);
        if ($stmt->rowCount() > 0) {
            $action_result = 'success|Router deleted successfully';
        } else {
            $action_result = 'error|Router not found or access denied';
        }
    } catch (PDOException $e) {
        $action_result = 'error|Failed to delete router: ' . $e->getMessage();
    }
}


// Handle Filters
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? 'All Status';
$filter_location = $_GET['location'] ?? 'All Locations'; // Assuming location column or just ignoring for now if not in DB

// Build Query
$query = "SELECT * FROM mikrotik_routers WHERE tenant_id = ?";
$params = [$tenant_id];

if ($search) {
    $query .= " AND (name LIKE ? OR ip_address LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
}

if ($filter_status !== 'All Status') {
    // Map UI status to DB status if needed, assuming lowercase in DB
    $query .= " AND status = ?";
    $params[] = strtolower($filter_status);
}

// Execute Query
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $routers = [];
}

// Calculate stats (Real data)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mikrotik_routers WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $total_routers = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mikrotik_routers WHERE status = 'online' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $online_routers = (int)$stmt->fetchColumn();
    
    $offline_routers = $total_routers - $online_routers;
    
    // Bandwidth/Connections are hard to sum without active monitoring table, keeping placeholder or 0
    $total_bandwidth = 0; 
    $active_connections = 0;
} catch (Exception $e) {
    $total_routers = 0;
    $online_routers = 0;
    $offline_routers = 0;
    $total_bandwidth = 0; 
    $active_connections = 0;
}

include 'includes/header.php';
include 'includes/sidebar.php';

// Handle Form Submissions
$action_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_router') {
        $router_ip = $_POST['router_ip'] ?? '';
        $router_username = $_POST['router_username'] ?? '';
        $router_password = $_POST['router_password'] ?? '';
        $router_port = $_POST['router_port'] ?? 8728;
        $router_name = $_POST['router_name'] ?? 'Main Router';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO mikrotik_routers (tenant_id, name, ip_address, username, password, api_port, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$tenant_id, $router_name, $router_ip, $router_username, $router_password, $router_port]);
            $action_result = 'success|New router added successfully';
            // Refresh list
            $stmt = $pdo->prepare($query); // Re-run query
            $stmt->execute($params);
            $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }
}
?>

<style>
    :root { --neu-bg:#141414; --neu-surf:#1c1c1b; --neu-s2:#222221; --neu-border:rgba(255,255,255,.06); --neu-card:8px 8px 20px rgba(0,0,0,.45),-4px -4px 10px rgba(255,255,255,.03); }
    .main-content-wrapper { background: var(--neu-bg) !important; }
    .routers-container { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }
    .routers-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .routers-title-section h1 { font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0 0 4px 0; }
    .routers-subtitle { font-size: 14px; color: rgba(255,255,255,.4); margin: 0; }
    .header-actions { display: flex; gap: 12px; }
    .sync-btn, .add-router-btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; text-decoration: none; border: none; }
    .sync-btn { background: var(--neu-s2); border: 1px solid var(--neu-border); color: rgba(255,255,255,.7); }
    .sync-btn:hover { background: rgba(255,255,255,.08); }
    .add-router-btn { background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color: white; }
    .add-router-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.4); }

    /* Stats Cards */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: var(--neu-s2); border-radius: 10px; padding: 20px; border: 1px solid var(--neu-border); box-shadow: var(--neu-card); }
    .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .stat-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .stat-icon.routers { background: rgba(99,102,241,.15); color: #a5b4fc; }
    .stat-icon.online  { background: rgba(52,211,153,.15);  color: #6ee7b7; }
    .stat-icon.offline { background: rgba(248,113,113,.15); color: #fca5a5; }
    .stat-value { font-size: 28px; font-weight: 700; color: #e2e2e0; margin-bottom: 4px; }
    .stat-label { font-size: 12px; color: rgba(255,255,255,.4); font-weight: 500; }

    /* Filters */
    .filters-section { background: var(--neu-s2); border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; border: 1px solid var(--neu-border); box-shadow: var(--neu-card); }
    .filters-title { font-size: 16px; font-weight: 600; color: #e2e2e0; margin-bottom: 16px; }
    .filters-grid { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; align-items: end; }
    .filter-input, .filter-select {
        padding: 8px 12px; border: 1px solid var(--neu-border); border-radius: 6px; font-size: 14px; width: 100%;
        background: var(--neu-surf); color: #d4d4d2;
        box-shadow: inset 3px 3px 7px rgba(0,0,0,.3);
    }
    .filter-input::placeholder { color: rgba(255,255,255,.25); }
    .filter-select option { background: #222221; }
    .filter-btn { padding: 8px 16px; background: var(--neu-surf); border: 1px solid var(--neu-border); border-radius: 6px; font-size: 14px; color: rgba(255,255,255,.6); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .filter-btn.primary { background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color: white; border: none; }

    /* Router Cards */
    .routers-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
    .router-card { background: var(--neu-s2); border-radius: 10px; border: 1px solid var(--neu-border); box-shadow: var(--neu-card); overflow: hidden; }
    .router-card-header { padding: 16px 20px; border-bottom: 1px solid var(--neu-border); display: flex; align-items: center; justify-content: space-between; }
    .router-info { display: flex; align-items: center; gap: 12px; }
    .router-status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px; animation: pulse 2s infinite; }
    .router-status-dot.online  { background: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
    .router-status-dot.offline { background: #EF4444; box-shadow: 0 0 0 3px rgba(239,68,68,.2); animation: none; }
    .router-status-dot.unknown { background: #6B7280; box-shadow: 0 0 0 3px rgba(107,114,128,.2); animation: none; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

    .status-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; gap: 5px; }
    .status-badge.online  { background: rgba(52,211,153,.15); color: #6ee7b7; }
    .status-badge.offline { background: rgba(248,113,113,.15); color: #fca5a5; }
    .status-badge.unknown { background: rgba(255,255,255,.07); color: rgba(255,255,255,.45); }

    .router-name { font-weight: 600; font-size: 14px; color: #e2e2e0; }
    .router-ip   { font-size: 12px; color: rgba(255,255,255,.4); }
    .router-card-body { padding: 20px; }
    .router-card-footer { padding: 12px 20px; border-top: 1px solid var(--neu-border); display: flex; align-items: center; justify-content: space-between; }
    .footer-info { font-size: 12px; color: rgba(255,255,255,.3); }

    /* Router Metrics */
    .router-metric { margin-bottom: 16px; }
    .router-metric:last-child { margin-bottom: 0; }
    .metric-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .metric-label { font-size: 12px; color: rgba(255,255,255,.4); display: flex; align-items: center; gap: 6px; }
    .metric-value { font-size: 14px; font-weight: 600; color: #d4d4d2; }
    .progress-bar { height: 6px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
    .progress-fill.good    { background: linear-gradient(90deg, #10B981 0%, #059669 100%); }
    .progress-fill.warning { background: linear-gradient(90deg, #F59E0B 0%, #D97706 100%); }
    .progress-fill.danger  { background: linear-gradient(90deg, #EF4444 0%, #DC2626 100%); }

    /* Footer action buttons */
    .footer-btn { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all .2s; border: 1px solid var(--neu-border); background: rgba(255,255,255,.06); color: rgba(255,255,255,.65); }
    .footer-btn:hover { background: rgba(255,255,255,.12); }
    .footer-btn.danger { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.3); }
    .footer-btn.danger:hover { background: rgba(239,68,68,.25); }

    /* Wizard Modal */
    #wizardModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.65); z-index: 1000; align-items: center; justify-content: center; }
    .wizard-content { background: var(--neu-s2); width: 100%; max-width: 800px; border-radius: 12px; border: 1px solid var(--neu-border); box-shadow: 0 24px 60px rgba(0,0,0,.6); padding: 0; position: relative; overflow: hidden; max-height: 90vh; display: flex; flex-direction: column; }
    .wizard-header { padding: 24px 32px; border-bottom: 1px solid var(--neu-border); }
    .wizard-title { font-size: 20px; font-weight: 600; color: #e2e2e0; margin: 0 0 8px 0; }
    .wizard-subtitle { font-size: 14px; color: rgba(255,255,255,.4); margin: 0; }
    .wizard-steps { display: flex; padding: 20px 32px; background: rgba(0,0,0,.2); border-bottom: 1px solid var(--neu-border); justify-content: space-between; }
    .step-item { display: flex; align-items: center; gap: 12px; opacity: 0.4; }
    .step-item.active { opacity: 1; }
    .step-number { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,.08); color: rgba(255,255,255,.5); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
    .step-item.active .step-number { background: var(--primary-color,#3B6EA5); color: white; }
    .step-text { font-size: 14px; font-weight: 500; color: rgba(255,255,255,.6); }
    .step-item.active .step-text { color: #e2e2e0; }
    .step-line { flex: 1; height: 2px; background: rgba(255,255,255,.08); margin: 0 16px; align-self: center; }
    .wizard-body { padding: 32px; flex: 1; overflow-y: auto; color: #d4d4d2; }
    .wizard-body label { display: block; font-weight: 500; margin-bottom: 8px; color: rgba(255,255,255,.6); font-size: 13px; }
    .wizard-body input[type=text], .wizard-body input[type=password], .wizard-body input[type=number] {
        width: 100%; padding: 10px; border: 1px solid var(--neu-border); border-radius: 6px;
        background: var(--neu-surf); color: #e2e2e0; font-size: 14px;
        box-shadow: inset 3px 3px 7px rgba(0,0,0,.3); box-sizing: border-box;
    }
    .wizard-body input::placeholder { color: rgba(255,255,255,.2); }
    .wizard-body p { color: rgba(255,255,255,.5); font-size: 13px; margin-top: 4px; }
    .wizard-footer { padding: 20px 32px; border-top: 1px solid var(--neu-border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,.15); }
    .wizard-btn { padding: 10px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; border: none; font-size: 14px; }
    .wizard-btn.prev { background: rgba(255,255,255,.07); border: 1px solid var(--neu-border); color: rgba(255,255,255,.65); }
    .wizard-btn.next { background: var(--primary-color,#3B6EA5); color: white; }

    /* Command box */
    .command-box { background: #0f0f0e; padding: 16px; border-radius: 8px; margin: 16px 0; position: relative; border: 1px solid rgba(255,255,255,.07); }
    .command-text { color: #a5f3fc; font-family: monospace; font-size: 13px; word-break: break-all; line-height: 1.6; }
    .copy-btn { position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,.1); color: rgba(255,255,255,.7); border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; }
    .copy-btn:hover { background: rgba(255,255,255,.2); }
</style>

<div class="main-content-wrapper">
    <div class="routers-container">
        <!-- Header -->
        <div class="routers-header">
            <div class="routers-title-section">
                <h1 style="font-size:28px; font-weight:600; color:#e2e2e0; margin:0 0 4px 0;">Router Management</h1>
                <p class="routers-subtitle">Monitor and manage MikroTik routers, servers, and network locations</p>
            </div>
            <div class="header-actions">
                <button class="sync-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Sync All
                </button>
                <button class="add-router-btn" onclick="openWizard()">
                    <i class="fas fa-plus"></i> Add Router
                </button>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon routers"><i class="fas fa-server"></i></div></div>
                <div class="stat-value"><?php echo $total_routers; ?></div>
                <div class="stat-label">Total Routers</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon online"><i class="fas fa-check-circle"></i></div></div>
                <div class="stat-value"><?php echo $online_routers; ?></div>
                <div class="stat-label">Online</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><div class="stat-icon offline"><i class="fas fa-times-circle"></i></div></div>
                <div class="stat-value"><?php echo $offline_routers; ?></div>
                <div class="stat-label">Offline</div>
            </div>
        </div>

        <!-- Manual Router Configuration (Moved from Settings) -->
        <details class="mb-4">
            <summary class="btn btn-outline-secondary mb-3"><i class="fas fa-plus-circle me-2"></i>Advanced: Manual Router Configuration</summary>
            <div class="card border-0 bg-light mt-3">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">Add Router Manually</h6>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_router">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Router Name</label>
                                <input type="text" name="router_name" placeholder="e.g., Main HQ Router" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">IP Address</label>
                                <input type="text" name="router_ip" placeholder="e.g., 192.168.88.1" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">API Port</label>
                                <input type="number" name="router_port" value="8728" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Username</label>
                                <input type="text" name="router_username" placeholder="admin" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Password</label>
                                <input type="password" name="router_password" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Router</button>
                    </form>
                </div>
            </div>
        </details>

        <!-- Filters -->
        <div class="filters-section">
            <h3 class="filters-title">Filter Routers</h3>
            <form method="GET" class="filters-grid">
                <input type="text" name="search" class="filter-input" placeholder="Search by name or IP..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option>All Status</option>
                    <option <?php echo $filter_status == 'Online' ? 'selected' : ''; ?>>Online</option>
                    <option <?php echo $filter_status == 'Offline' ? 'selected' : ''; ?>>Offline</option>
                </select>
                <select name="location" class="filter-select">
                    <option>All Locations</option>
                    <option>Main Gateway</option>
                </select>
                <div style="display:flex; gap:10px;">
                    <a href="mikrotik.php" class="filter-btn" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">Reset</a>
                    <button type="submit" class="filter-btn primary">Apply</button>
                </div>
            </form>
        </div>

        <!-- Router Cards -->
        <div class="routers-grid">
            <?php foreach ($routers as $router): 
                $status = strtolower($router['status'] ?? 'offline');
                // Mock usage stats if not in DB
                $active_users = rand(10, 100); 
                $bandwidth = rand(10, 95);
                $bwClass = $bandwidth > 90 ? 'danger' : ($bandwidth > 70 ? 'warning' : 'good');
            ?>
            <div class="router-card">
                <div class="router-card-header">
                    <div class="router-info">
                        <div class="router-status-dot <?php echo $status; ?>"></div>
                        <div>
                            <div class="router-name"><?php echo htmlspecialchars($router['name']); ?></div>
                            <div class="router-ip"><?php echo htmlspecialchars($router['ip_address'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                    <span class="status-badge <?php echo $status; ?>">
                        <i class="fas fa-<?php echo $status === 'online' ? 'check-circle' : ($status === 'offline' ? 'times-circle' : 'question-circle'); ?>"></i>
                        <?php echo ucfirst($status); ?>
                    </span>
                </div>
                <div class="router-card-body">
                    <div class="router-metric">
                        <div class="metric-header"><span class="metric-label"><i class="fas fa-users"></i>Active Users</span><span class="metric-value"><?php echo $active_users; ?></span></div>
                    </div>
                    <div class="router-metric">
                        <div class="metric-header"><span class="metric-label"><i class="fas fa-chart-line"></i>Bandwidth Usage</span><span class="metric-value"><?php echo $bandwidth; ?>%</span></div>
                        <div class="progress-bar"><div class="progress-fill <?php echo $bwClass; ?>" style="width: <?php echo $bandwidth; ?>%"></div></div>
                    </div>
                    <div class="router-metric">
                        <div class="metric-header"><span class="metric-label"><i class="fas fa-clock"></i>Uptime</span><span class="metric-value">Unknown</span></div>
                    </div>
                </div>
                <div class="router-card-footer">
                    <div class="footer-info">Last Seen: <?php echo $router['last_seen'] ?? 'Never'; ?></div>
                    <div class="footer-actions">
                        <button class="footer-btn secondary" onclick="testConnection(<?php echo $router['id']; ?>, this)"><i class="fas fa-plug"></i> Test</button>
                        <button class="footer-btn secondary" onclick="editRouter(<?php echo htmlspecialchars(json_encode($router)); ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <button class="footer-btn danger" onclick="confirmDeleteRouter(<?php echo $router['id']; ?>, '<?php echo htmlspecialchars($router['name']); ?>')"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<?php if ($action_result): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1100">
  <div id="liveToast" class="toast align-items-center text-white <?php echo strpos($action_result, 'error') !== false ? 'bg-danger' : 'bg-success'; ?> border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
         <i class="fas <?php echo strpos($action_result, 'error') !== false ? 'fa-exclamation-circle' : 'fa-check-circle'; ?> me-2"></i>
         <?php echo explode('|', $action_result)[1]; ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Router Wizard Modal -->
<div id="wizardModal">
    <div class="wizard-content">
        <div class="wizard-header">
            <h2 class="wizard-title">Add Mikrotik Device</h2>
            <p class="wizard-subtitle">Connect your router to enable automated provisioning and management.</p>
        </div>
        
        <div class="wizard-steps">
            <div class="step-item active" id="step1-indicator">
                <div class="step-number">1</div>
                <div class="step-text">Connection</div>
            </div>
            <div class="step-line"></div>
            <div class="step-item" id="step2-indicator">
                <div class="step-number">2</div>
                <div class="step-text">Device Details</div>
            </div>
            <div class="step-line"></div>
            <div class="step-item" id="step3-indicator">
                <div class="step-number">3</div>
                <div class="step-text">Service Setup</div>
            </div>
        </div>
        
        <!-- Step 1: Basic Info -->
        <div class="wizard-body" id="step1">
            <div style="margin-bottom:20px;">
                <label>Mikrotik Identity *</label>
                <input type="text" id="mikrotikName" placeholder="e.g. Router-01 Main">
                <p>The identity name of your Mikrotik device (System → Identity)</p>
            </div>
        </div>

        <!-- Step 2: Provisioning -->
        <div class="wizard-body" id="step2" style="display:none;">

            <!-- LOCALHOST TESTING INFO (shown automatically when on localhost) -->
            <div id="localhostInfoPanel" style="display:none; margin-bottom:20px; border-radius:10px; overflow:hidden; border:1px solid rgba(251,146,60,.25);">
                <div style="background:rgba(251,146,60,.1); padding:14px 18px; border-bottom:1px solid rgba(251,146,60,.25);">
                    <div style="display:flex; align-items:center; gap:10px; font-size:15px; font-weight:700; color:#fb923c;">
                        <i class="fas fa-laptop-code"></i>&nbsp;Localhost Detected — Testing Options
                    </div>
                    <div style="font-size:12px; color:rgba(251,146,60,.7); margin-top:4px;">Your app is running on <strong>localhost</strong>. Your MikroTik router can't reach this from the internet. Use one of the options below to test provisioning:</div>
                </div>
                <div style="background:rgba(0,0,0,.2); padding:16px 18px;">
                    <!-- Option A -->
                    <div style="margin-bottom:12px; padding:12px; background:rgba(52,211,153,.08); border-radius:8px; border-left:4px solid #10B981;">
                        <div style="font-size:13px; font-weight:700; color:#6ee7b7; margin-bottom:4px;"><i class="fas fa-network-wired"></i> Option A — Same Local Network (Simplest)</div>
                        <div style="font-size:12px; color:rgba(255,255,255,.5); line-height:1.7;">
                            If your router and this PC are on the <strong>same WiFi/LAN</strong>, replace <code>localhost</code> in the command with your PC's local IP (e.g., <code>192.168.1.100</code>).<br>
                            <em>Find it:</em> Open Command Prompt → type <code>ipconfig</code> → look for <strong>IPv4 Address</strong>.
                        </div>
                    </div>
                    <!-- Option B -->
                    <div style="margin-bottom:12px; padding:12px; background:rgba(59,130,246,.08); border-radius:8px; border-left:4px solid #3B82F6;">
                        <div style="font-size:13px; font-weight:700; color:#93c5fd; margin-bottom:4px;"><i class="fas fa-cloud"></i> Option B — Ngrok Tunnel (Different network)</div>
                        <div style="font-size:12px; color:rgba(255,255,255,.5); line-height:1.7;">
                            1. Download <a href="https://ngrok.com/download" target="_blank" style="color:#60a5fa; font-weight:600;">ngrok</a> and run: <code style="background:#0f0f0e; color:#a5f3fc; padding:2px 6px; border-radius:4px;">ngrok http 80</code><br>
                            2. Copy the <code>https://xxxxx.ngrok.io</code> URL ngrok provides.<br>
                            3. Update <code>MPESA_CALLBACK_URL</code> in your <code>.env</code> file to use that URL.<br>
                            4. Reload this page — the provisioning command will update automatically.
                        </div>
                    </div>
                    <!-- Option C -->
                    <div style="padding:12px; background:rgba(255,255,255,.04); border-radius:8px; border-left:4px solid rgba(255,255,255,.15);">
                        <div style="font-size:13px; font-weight:700; color:rgba(255,255,255,.6); margin-bottom:4px;"><i class="fas fa-keyboard"></i> Option C — Manual Entry (Skip provisioning)</div>
                        <div style="font-size:12px; color:rgba(255,255,255,.4);">
                            Use the <strong>"Advanced: Manual Router Configuration"</strong> section on this page to add your router's IP, username, and password directly — no script needed.
                        </div>
                    </div>
                </div>
            </div>
            <!-- END LOCALHOST INFO -->

            <p style="margin-bottom:16px; color:rgba(255,255,255,.5);">Run this command in your Mikrotik Terminal to connect:</p>
            <div class="command-box">
                <button class="copy-btn" onclick="copyCommand()">Copy</button>
                <div class="command-text" id="provisionCommand">Generating command...</div>
            </div>
            <div style="display:flex; align-items:center; gap:8px; margin-top:16px; padding:12px; background:rgba(52,211,153,.08); border-radius:6px; color:#6ee7b7; border:1px solid rgba(52,211,153,.2);" id="connectionStatus">
                 <i class="fas fa-spinner fa-spin"></i> Waiting for command execution...
            </div>
        </div>

        <!-- Step 3: Service Setup -->
        <div class="wizard-body" id="step3" style="display:none;">
            <div style="text-align:center; padding:20px;">
                <div style="width:48px; height:48px; background:rgba(52,211,153,.15); color:#6ee7b7; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                    <i class="fas fa-check" style="font-size:24px;"></i>
                </div>
                <h3 style="font-size:18px; font-weight:600; margin-bottom:8px; color:#e2e2e0;">Router Connected Successfully!</h3>
                <p style="color:rgba(255,255,255,.4); margin-bottom:24px;">You can now configure services on this router.</p>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; text-align:left;">
                    <div class="service-card" onclick="selectService('pppoe', this)" style="border:1px solid var(--neu-border); background:var(--neu-surf); padding:16px; border-radius:8px; cursor:pointer; transition:.2s;">
                        <div style="font-weight:600; margin-bottom:4px; color:#e2e2e0;">PPPoE Server</div>
                        <p style="font-size:12px; color:rgba(255,255,255,.35); margin:0;">Deploy PPPoE server on selected interface</p>
                    </div>
                    <div class="service-card" onclick="selectService('hotspot', this)" style="border:1px solid var(--neu-border); background:var(--neu-surf); padding:16px; border-radius:8px; cursor:pointer; transition:.2s;">
                        <div style="font-weight:600; margin-bottom:4px; color:#e2e2e0;">Hotspot Server</div>
                        <p style="font-size:12px; color:rgba(255,255,255,.35); margin:0;">Deploy Hotspot server and walled garden</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="wizard-footer">
            <button class="wizard-btn prev" id="prevBtn" onclick="prevStep()" style="display:none;">Back</button>
            <div style="flex:1;"></div>
            <button class="wizard-btn next" id="nextBtn" onclick="nextStep()">Next Step <i class="fas fa-arrow-right"></i></button>
        </div>
    </div>
</div>

<script>

let currentStep = 1;
const ngrokUrl = "<?php echo $ngrok_url; ?>";
let provisioningTimer = null;
let selectedService = null;

function openWizard() {
    document.getElementById('wizardModal').style.display = 'flex';
    currentStep = 1;
    selectedService = null;
    updateWizard();
    resetSelections();
}

function updateWizard() {
    // Hide all bodies
    document.querySelectorAll('.wizard-body').forEach(el => el.style.display = 'none');
    // Show current
    document.getElementById('step' + currentStep).style.display = 'block';
    
    // Update indicators
    document.querySelectorAll('.step-item').forEach((el, idx) => {
        if (idx + 1 === currentStep) el.classList.add('active');
        else el.classList.remove('active');
    });

    // Buttons
    if (currentStep === 1) {
        document.getElementById('prevBtn').style.display = 'none';
        document.getElementById('nextBtn').textContent = 'Next: Get Command';
        document.getElementById('nextBtn').onclick = nextStep;
        document.getElementById('nextBtn').disabled = false;
    } else if (currentStep === 2) {
        document.getElementById('prevBtn').style.display = 'block';
        document.getElementById('nextBtn').textContent = 'Waiting for Connection...';
        document.getElementById('nextBtn').disabled = true; // Disable manual next until verified
        
        // Start polling
        startPolling();
        
    } else if (currentStep === 3) {
        document.getElementById('prevBtn').style.display = 'none';
        document.getElementById('nextBtn').textContent = 'Finish';
        document.getElementById('nextBtn').disabled = false;
        document.getElementById('nextBtn').onclick = finishWizard;
        
        // Stop polling
        if(provisioningTimer) clearInterval(provisioningTimer);
    }
}

function nextStep() {
    if (currentStep === 1) {
        const name = document.getElementById('mikrotikName').value;
        if (!name) return alert('Please enter a name');
        
        // === PROVISIONING COMMAND GENERATION ===
        // This automatically detects the current server URL from the browser
        // LOCALHOST: Will show http://localhost/fortunett_technologies_/api/routers/provision.php
        // VPS PRODUCTION: Will show http://72.61.147.86/fortunett_technologies_/api/routers/provision.php
        // NO MANUAL CHANGES NEEDED - it adapts automatically!
        
        const token = "<?php echo $tenant['provisioning_token'] ?? ''; ?>";
        let host = window.location.host;        // Auto-detects: localhost OR 72.61.147.86
        let protocol = window.location.protocol; // Auto-detects: http OR https
        
        const endpoint = `${protocol}//${host}/fortunett_technologies_/api/routers/provision.php`;
        
        let cmd = `/tool fetch url="${endpoint}?token=${token}&identity=${encodeURIComponent(name)}&format=rsc" dst-path=provision.rsc; :delay 5s; /import provision.rsc;`;
        
        // Check if host is localhost and warn user + show the info panel
        if (host.includes('localhost') || host.includes('127.0.0.1')) {
            const infoPanel = document.getElementById('localhostInfoPanel');
            if (infoPanel) infoPanel.style.display = 'block';
        }

        document.getElementById('provisionCommand').textContent = cmd;
        
        currentStep = 2;
        updateWizard();
    }
}

function startPolling() {
    if(provisioningTimer) clearInterval(provisioningTimer);
    const name = document.getElementById('mikrotikName').value;
    
    provisioningTimer = setInterval(() => {
        fetch('api/routers/check_status.php?identity=' + encodeURIComponent(name))
        .then(r => r.json())
        .then(data => {
            if(data.connected) {
                clearInterval(provisioningTimer);
                document.getElementById('connectionStatus').innerHTML = '<i class="fas fa-check-circle"></i> Connection Verified!';
                document.getElementById('connectionStatus').style.background = 'rgba(52,211,153,.15)';
                document.getElementById('connectionStatus').style.color = '#6ee7b7';
                
                // Auto advance shortly after success
                setTimeout(() => {
                    currentStep = 3;
                    updateWizard();
                }, 1000);
            }
        });
    }, 3000); // Check every 3 seconds
}

function selectService(service, el) {
    selectedService = service;
    document.querySelectorAll('.service-card').forEach(c => { c.style.borderColor = 'rgba(255,255,255,.07)'; c.style.backgroundColor = '#1c1c1b'; });
    el.style.borderColor = 'var(--primary-color, #3B6EA5)';
    el.style.backgroundColor = 'rgba(59,110,165,.15)';
}

function resetSelections() {
   document.querySelectorAll('.service-card').forEach(c => {
       c.style.borderColor = 'rgba(255,255,255,.07)';
       c.style.backgroundColor = '#1c1c1b';
   });
}

function finishWizard() {
    if(!selectedService) {
        location.reload();
        return;
    }
    
    const btn = document.getElementById('nextBtn');
    btn.textContent = 'Configuring...';
    btn.disabled = true;
    
    const name = document.getElementById('mikrotikName').value;
    
    const formData = new FormData();
    formData.append('identity', name);
    formData.append('service', selectedService);
    
    fetch('api/routers/configure_service.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            // Show result
            document.getElementById('step3').innerHTML = `
                <div style="text-align:center; padding:20px;">
                    <div style="width:48px; height:48px; background:rgba(52,211,153,.15); color:#6ee7b7; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                        <i class="fas fa-check" style="font-size:24px;"></i>
                    </div>
                    <h3 style="font-size:18px; font-weight:600; margin-bottom:8px; color:#e2e2e0;">Configuration Generated</h3>
                    <p style="color:rgba(255,255,255,.4); margin-bottom:16px;">Run this command to finalize the ${selectedService.toUpperCase()} setup:</p>
                    
                    <div class="command-box" style="text-align:left;">
                        <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.textContent).then(()=>alert('Copied'))">Copy</button>
                        <div class="command-text">${data.command}</div>
                    </div>
                    
                    <button onclick="location.reload()" style="margin-top:20px; padding:10px 24px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer;">Done</button>
                </div>
            `;
            btn.style.display = 'none';
        } else {
            alert('Error: ' + data.message);
            btn.textContent = 'Finish';
            btn.disabled = false;
        }
    })
    .catch(e => {
        alert('Error: ' + e);
        btn.textContent = 'Finish';
        btn.disabled = false;
    });
}

function prevStep() {
    if (currentStep > 1) {
        currentStep--;
        updateWizard();
        if(provisioningTimer) clearInterval(provisioningTimer);
    }
}

function copyCommand() {
    const text = document.getElementById('provisionCommand').textContent;
    navigator.clipboard.writeText(text).then(() => alert('Copied!'));
}

window.onclick = function(event) {
    if (event.target == document.getElementById('wizardModal')) {
        document.getElementById('wizardModal').style.display = "none";
        if(provisioningTimer) clearInterval(provisioningTimer);
    }
}
</script>

<!-- Edit Router Modal -->
<div id="editRouterModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#222221; border:1px solid rgba(255,255,255,.07); border-radius:12px; padding:24px; width:100%; max-width:500px; box-shadow:0 24px 60px rgba(0,0,0,.6);">
        <h3 style="margin-top:0; margin-bottom:16px; color:#e2e2e0;">Edit Router</h3>
        <form id="editRouterForm" onsubmit="saveRouter(event)">
            <input type="hidden" name="id" id="edit_id">
            <div style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-weight:500; color:rgba(255,255,255,.6); font-size:13px;">Name</label>
                <input type="text" name="name" id="edit_name" required style="width:100%; padding:8px; border:1px solid rgba(255,255,255,.07); border-radius:6px; background:#1c1c1b; color:#e2e2e0; box-shadow:inset 3px 3px 7px rgba(0,0,0,.3); box-sizing:border-box;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-weight:500; color:rgba(255,255,255,.6); font-size:13px;">IP Address</label>
                <input type="text" name="ip_address" id="edit_ip" required style="width:100%; padding:8px; border:1px solid rgba(255,255,255,.07); border-radius:6px; background:#1c1c1b; color:#e2e2e0; box-shadow:inset 3px 3px 7px rgba(0,0,0,.3); box-sizing:border-box;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-weight:500; color:rgba(255,255,255,.6); font-size:13px;">Username</label>
                <input type="text" name="username" id="edit_username" style="width:100%; padding:8px; border:1px solid rgba(255,255,255,.07); border-radius:6px; background:#1c1c1b; color:#e2e2e0; box-shadow:inset 3px 3px 7px rgba(0,0,0,.3); box-sizing:border-box;">
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:8px; font-weight:500; color:rgba(255,255,255,.6); font-size:13px;">Password</label>
                <input type="password" name="password" id="edit_password" placeholder="Leave blank to keep unchanged" style="width:100%; padding:8px; border:1px solid rgba(255,255,255,.07); border-radius:6px; background:#1c1c1b; color:#e2e2e0; box-shadow:inset 3px 3px 7px rgba(0,0,0,.3); box-sizing:border-box;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="document.getElementById('editRouterModal').style.display='none'" style="padding:8px 16px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.07); border-radius:6px; cursor:pointer; color:rgba(255,255,255,.6);">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editRouter(router) {
    document.getElementById('edit_id').value = router.id;
    document.getElementById('edit_name').value = router.name;
    document.getElementById('edit_ip').value = router.ip_address;
    document.getElementById('edit_username').value = router.username || 'admin';
    document.getElementById('edit_password').value = ''; // Don't show password
    document.getElementById('editRouterModal').style.display = 'flex';
}

function saveRouter(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    fetch('api/routers/update.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Copied to clipboard!');
    });
}

function testConnection(id, btn) {

    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('id', id);
    
    fetch('api/routers/test_connection.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check"></i> Connected';
            btn.style.color = 'green';
            setTimeout(() => { btn.innerHTML = originalText; btn.disabled = false; btn.style.color = ''; }, 3000);
        } else {
            alert('Failed: ' + data.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(e => {
        alert('Error: ' + e);
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

function confirmDeleteRouter(routerId, routerName) {
    if (confirm(`Are you sure you want to delete router "${routerName}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_router" value="1">
            <input type="hidden" name="router_id" value="${routerId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

</script>
<?php include 'includes/footer.php'; ?>
