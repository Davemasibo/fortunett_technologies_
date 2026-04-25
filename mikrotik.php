<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/mpesa.php';

// Compute the base path for this app dynamically from the current script's URL.
// e.g. on localhost: /fortunett_technologies_, on production: (empty string)
$_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

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
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
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
    .footer-btn.danger    { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.3); }
    .footer-btn.danger:hover { background: rgba(239,68,68,.25); }
    .footer-btn.terminal  { background: rgba(110,231,183,.08); color: #6ee7b7; border-color: rgba(110,231,183,.25); }
    .footer-btn.terminal:hover { background: rgba(110,231,183,.18); }
    .footer-btn.deploy    { background: rgba(251,191,36,.08); color: #fbbf24; border-color: rgba(251,191,36,.25); }
    .footer-btn.deploy:hover { background: rgba(251,191,36,.18); }

    /* ── Terminal Modal ─────────────────────────────── */
    #terminalModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.78); z-index:2000; align-items:center; justify-content:center; backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); }
    .term-window { background:#0d0d0c; border:1px solid rgba(255,255,255,.12); border-radius:10px; width:92vw; max-width:960px; height:82vh; max-height:720px; display:flex; flex-direction:column; box-shadow:0 28px 90px rgba(0,0,0,.85); overflow:hidden; }
    .term-header { background:#181817; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,.07); flex-shrink:0; }
    .term-header-left { display:flex; align-items:center; gap:10px; }
    .term-dots { display:flex; gap:6px; }
    .term-dot { width:12px; height:12px; border-radius:50%; }
    .term-dot.r { background:#FF5F57; } .term-dot.y { background:#FEBC2E; } .term-dot.g { background:#28C840; }
    .term-header-title { font-size:13px; color:rgba(255,255,255,.6); display:flex; align-items:center; gap:8px; }
    .term-header-title i { color:rgba(255,255,255,.35); }
    #termRouterIp { font-size:11px; color:rgba(255,255,255,.3); }
    .term-close { background:none; border:none; color:rgba(255,255,255,.35); font-size:20px; cursor:pointer; padding:0 4px; line-height:1; }
    .term-close:hover { color:rgba(255,255,255,.7); }
    .term-quickbar { background:#111110; padding:7px 14px; display:flex; align-items:center; gap:5px; flex-wrap:wrap; border-bottom:1px solid rgba(255,255,255,.05); flex-shrink:0; }
    .term-ql { font-size:10px; color:rgba(255,255,255,.25); text-transform:uppercase; letter-spacing:.07em; margin-right:4px; white-space:nowrap; }
    .term-qbtn { padding:3px 8px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); border-radius:4px; color:rgba(255,255,255,.5); font-size:11px; font-family:monospace; cursor:pointer; white-space:nowrap; }
    .term-qbtn:hover { background:rgba(255,255,255,.13); color:#e2e2e0; }
    .term-qbtn.clear { background:rgba(239,68,68,.07); color:rgba(239,68,68,.55); border-color:rgba(239,68,68,.15); }
    .term-qbtn.clear:hover { background:rgba(239,68,68,.18); }
    .term-output { flex:1; overflow-y:auto; padding:14px 18px; font-family:'JetBrains Mono','Courier New',monospace; font-size:13px; line-height:1.75; color:#c8c8c4; background:#0d0d0c; }
    .term-output::-webkit-scrollbar { width:5px; }
    .term-output::-webkit-scrollbar-track { background:rgba(255,255,255,.03); }
    .term-output::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12); border-radius:3px; }
    .term-welcome { color:rgba(255,255,255,.3); font-size:12px; margin-bottom:14px; padding-bottom:12px; border-bottom:1px dashed rgba(255,255,255,.07); }
    .term-line-cmd  { color:#a5f3fc; }
    .term-line-prompt { color:#6ee7b7; font-weight:700; user-select:none; }
    .term-line-out  { color:#c8c8c4; white-space:pre-wrap; word-break:break-all; }
    .term-line-err  { color:#fca5a5; }
    .term-line-sep  { color:rgba(255,255,255,.08); margin:6px 0; }
    .term-line-spin { color:rgba(255,255,255,.35); font-size:12px; }
    .term-input-row { background:#141413; border-top:1px solid rgba(255,255,255,.07); padding:9px 14px; display:flex; align-items:center; gap:8px; flex-shrink:0; }
    #termPromptLabel { color:#6ee7b7; font-family:monospace; font-size:13px; white-space:nowrap; flex-shrink:0; }
    #termInput { flex:1; background:transparent; border:none; outline:none; color:#a5f3fc; font-family:'JetBrains Mono','Courier New',monospace; font-size:13px; caret-color:#6ee7b7; }
    #termInput::placeholder { color:rgba(255,255,255,.18); }
    .term-run { padding:5px 14px; background:rgba(110,231,183,.12); border:1px solid rgba(110,231,183,.22); color:#6ee7b7; border-radius:5px; font-size:12px; cursor:pointer; flex-shrink:0; }
    .term-run:hover { background:rgba(110,231,183,.24); }
    @media(max-width:600px) {
        .term-window { width:100%; height:100%; border-radius:0; max-height:100%; }
        .term-quickbar { display:none; }
        .footer-actions { flex-wrap:wrap; gap:6px; }
    }

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

    /* ── Responsive ──────────────────────────────── */
    @media (max-width: 900px) {
        .routers-container { padding: 16px 16px; }
        .routers-grid { grid-template-columns: 1fr; }
        .filters-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 640px) {
        .routers-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        .routers-title-section h1 { font-size: 20px; }
        .header-actions { width: 100%; }
        .header-actions .sync-btn,
        .header-actions .add-router-btn { flex: 1; justify-content: center; padding: 10px 12px; font-size: 13px; }
        .stats-row { grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .stat-value { font-size: 22px; }
        .filters-grid { grid-template-columns: 1fr; }
        .filters-grid > div { display: flex; gap: 8px; }
        .filters-grid > div .filter-btn { flex: 1; }
        .router-card-header { flex-wrap: wrap; gap: 8px; }
        .wizard-content { max-width: 100%; margin: 0 8px; }
        .wizard-header { padding: 16px 18px; }
        .wizard-steps { padding: 12px 16px; overflow-x: auto; gap: 4px; }
        .step-line { min-width: 20px; margin: 0 6px; }
        .step-text { font-size: 11px; }
        .wizard-body { padding: 18px 16px; }
        .wizard-footer { padding: 14px 16px; flex-wrap: wrap; gap: 8px; }
        .wizard-btn { flex: 1; text-align: center; }
        #manualCardBody { padding: 16px 14px; }
    }
    @media (max-width: 420px) {
        .stats-row { grid-template-columns: 1fr 1fr; }
        .routers-container { padding: 12px 10px; }
    }
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

        <!-- Manual Router Configuration -->
        <div id="manualRouterCard" style="background:var(--neu-s2);border:1px solid var(--neu-border);border-radius:12px;box-shadow:var(--neu-card);margin-bottom:20px;overflow:hidden;">
            <!-- Card header — clickable toggle -->
            <div onclick="toggleManualCard()" style="padding:16px 22px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0);transition:border-color .2s;" id="manualCardHeader">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-plug" style="color:#a5b4fc;font-size:14px;"></i>
                    </div>
                    <div>
                        <div style="font-size:14px;font-weight:600;color:#e2e2e0;">Manual Router Setup</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.35);margin-top:1px;">Add a router directly via IP address without the provisioning wizard</div>
                    </div>
                </div>
                <i class="fas fa-chevron-down" id="manualCardChevron" style="color:rgba(255,255,255,.35);font-size:13px;transition:transform .25s;"></i>
            </div>
            <!-- Collapsible body -->
            <div id="manualCardBody" style="display:none;padding:24px 22px;">
                <form method="POST" id="manualRouterForm" onsubmit="return submitManualRouter(event)">
                    <input type="hidden" name="action" value="add_router">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.5);margin-bottom:6px;">Router Name *</label>
                            <input type="text" name="router_name" placeholder="e.g. Main HQ Router" required
                                style="width:100%;padding:9px 12px;border:1px solid var(--neu-border);border-radius:7px;background:var(--neu-surf);color:#e2e2e0;font-size:13px;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);outline:none;box-sizing:border-box;"
                                onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='var(--neu-border)'">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.5);margin-bottom:6px;">IP Address *</label>
                            <input type="text" name="router_ip" placeholder="e.g. 192.168.88.1" required
                                style="width:100%;padding:9px 12px;border:1px solid var(--neu-border);border-radius:7px;background:var(--neu-surf);color:#e2e2e0;font-size:13px;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);outline:none;box-sizing:border-box;"
                                onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='var(--neu-border)'">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.5);margin-bottom:6px;">Username *</label>
                            <input type="text" name="router_username" placeholder="admin" required
                                style="width:100%;padding:9px 12px;border:1px solid var(--neu-border);border-radius:7px;background:var(--neu-surf);color:#e2e2e0;font-size:13px;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);outline:none;box-sizing:border-box;"
                                onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='var(--neu-border)'">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.5);margin-bottom:6px;">Password</label>
                            <input type="password" name="router_password" placeholder="RouterOS API password"
                                style="width:100%;padding:9px 12px;border:1px solid var(--neu-border);border-radius:7px;background:var(--neu-surf);color:#e2e2e0;font-size:13px;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);outline:none;box-sizing:border-box;"
                                onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='var(--neu-border)'">
                        </div>
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:rgba(255,255,255,.5);margin-bottom:6px;">API Port</label>
                        <input type="number" name="router_port" value="8728" min="1" max="65535"
                            style="width:130px;padding:9px 12px;border:1px solid var(--neu-border);border-radius:7px;background:var(--neu-surf);color:#e2e2e0;font-size:13px;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);outline:none;"
                            onfocus="this.style.borderColor='var(--primary-color,#3B6EA5)'" onblur="this.style.borderColor='var(--neu-border)'">
                        <span style="font-size:11px;color:rgba(255,255,255,.3);margin-left:8px;">Default: 8728 (MikroTik RouterOS API)</span>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit" id="manualRouterSaveBtn"
                            style="padding:9px 22px;background:linear-gradient(135deg,var(--primary-dark,#1e3a5f) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px;">
                            <i class="fas fa-save"></i> Add Router
                        </button>
                        <button type="button" onclick="toggleManualCard()"
                            style="padding:9px 16px;background:rgba(255,255,255,.06);border:1px solid var(--neu-border);border-radius:7px;font-size:13px;cursor:pointer;color:rgba(255,255,255,.5);">
                            Cancel
                        </button>
                        <span style="font-size:12px;color:rgba(255,255,255,.25);">Make sure the RouterOS API is enabled on your device (IP → Services → API).</span>
                    </div>
                </form>
            </div>
        </div>

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
                $status    = strtolower($router['status'] ?? 'pending');
                $svcTypes  = array_filter(array_map('trim', explode(',', $router['service_types'] ?? '')));
                $statusIcon = $status === 'active' ? 'check-circle' : ($status === 'inactive' ? 'times-circle' : 'question-circle');
            ?>
            <div class="router-card" id="rcard-<?php echo $router['id']; ?>">
                <div class="router-card-header">
                    <div class="router-info">
                        <div class="router-status-dot <?php echo $status; ?>" id="rdot-<?php echo $router['id']; ?>"></div>
                        <div>
                            <div class="router-name"><?php echo htmlspecialchars($router['name']); ?></div>
                            <div class="router-ip"><?php echo htmlspecialchars($router['ip_address'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                        <span class="status-badge <?php echo $status; ?>" id="rbadge-<?php echo $router['id']; ?>">
                            <i class="fas fa-<?php echo $statusIcon; ?>"></i>
                            <?php echo ucfirst($status); ?>
                        </span>
                        <?php if (!empty($svcTypes)): ?>
                        <div style="display:flex;gap:4px;">
                            <?php foreach ($svcTypes as $svc): ?>
                            <span style="font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600;background:<?php echo $svc === 'pppoe' ? 'rgba(59,130,246,.15)' : 'rgba(16,185,129,.15)'; ?>;color:<?php echo $svc === 'pppoe' ? '#93c5fd' : '#6ee7b7'; ?>;border:1px solid <?php echo $svc === 'pppoe' ? 'rgba(59,130,246,.3)' : 'rgba(16,185,129,.3)'; ?>;">
                                <?php echo strtoupper($svc); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="router-card-body">
                    <div class="router-metric">
                        <div class="metric-header">
                            <span class="metric-label"><i class="fas fa-users"></i> Live Connections</span>
                            <span class="metric-value" id="rstat-active-<?php echo $router['id']; ?>">—</span>
                        </div>
                        <div style="font-size:11px;color:rgba(255,255,255,.25);margin-top:2px;" id="rstat-breakdown-<?php echo $router['id']; ?>"></div>
                    </div>
                    <div class="router-metric">
                        <div class="metric-header">
                            <span class="metric-label"><i class="fas fa-clock"></i> Uptime</span>
                            <span class="metric-value" id="rstat-uptime-<?php echo $router['id']; ?>">—</span>
                        </div>
                    </div>
                    <div class="router-metric">
                        <div class="metric-header">
                            <span class="metric-label"><i class="fas fa-microchip"></i> CPU Load</span>
                            <span class="metric-value" id="rstat-cpu-<?php echo $router['id']; ?>">—</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill good" id="rstat-cpubar-<?php echo $router['id']; ?>" style="width:0%"></div>
                        </div>
                    </div>
                </div>
                <div class="router-card-footer">
                    <div class="footer-info">Last Seen: <?php echo $router['last_seen'] ? date('d M H:i', strtotime($router['last_seen'])) : 'Never'; ?></div>
                    <div class="footer-actions">
                        <button class="footer-btn secondary" id="rbtn-test-<?php echo $router['id']; ?>" onclick="testConnection(<?php echo $router['id']; ?>, this)"><i class="fas fa-plug"></i> Test</button>
                        <button class="footer-btn terminal" onclick="openTerminal(<?php echo $router['id']; ?>, '<?php echo htmlspecialchars(addslashes($router['name'])); ?>', '<?php echo htmlspecialchars($router['ip_address']); ?>')"><i class="fas fa-terminal"></i> Terminal</button>
                        <button class="footer-btn deploy" onclick="deployHotspotLogin(<?php echo $router['id']; ?>, this)" title="Push branded hotspot login page to router"><i class="fas fa-upload"></i> Deploy Login</button>
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
        <div class="wizard-header" style="position:relative;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,var(--primary-dark,#1e3a5f),var(--primary-color,#3B6EA5));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.4);">
                    <i class="fas fa-router" style="color:white;font-size:18px;"></i>
                </div>
                <div>
                    <h2 class="wizard-title">Add MikroTik Device</h2>
                    <p class="wizard-subtitle">Connect your router to enable automated provisioning and management.</p>
                </div>
            </div>
            <button onclick="closeWizard()" style="position:absolute;top:0;right:0;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.08);border-radius:8px;width:32px;height:32px;font-size:18px;cursor:pointer;color:rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;line-height:1;transition:background .15s;" onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.07)'">&times;</button>
        </div>

        <!-- Step indicators -->
        <div class="wizard-steps">
            <div class="step-item active" id="step1-indicator">
                <div class="step-number"><i class="fas fa-plug" style="font-size:12px;"></i></div>
                <div class="step-text">Connection</div>
            </div>
            <div class="step-line"></div>
            <div class="step-item" id="step2-indicator">
                <div class="step-number"><i class="fas fa-terminal" style="font-size:11px;"></i></div>
                <div class="step-text">Provisioning</div>
            </div>
            <div class="step-line"></div>
            <div class="step-item" id="step3-indicator">
                <div class="step-number"><i class="fas fa-check" style="font-size:12px;"></i></div>
                <div class="step-text">Done</div>
            </div>
        </div>
        
        <!-- Step 1: Connection Method -->
        <div class="wizard-body" id="step1">
            <!-- Method selector pills -->
            <div style="display:flex;gap:10px;margin-bottom:24px;">
                <div id="methodProvision" onclick="selectMethod('provision')" style="flex:1;padding:14px 16px;border:2px solid var(--primary-color,#3B6EA5);background:rgba(59,110,165,.12);border-radius:9px;cursor:pointer;transition:.2s;">
                    <div style="display:flex;align-items:center;gap:9px;margin-bottom:5px;">
                        <i class="fas fa-magic" style="color:var(--primary-light,#93c5fd);font-size:15px;"></i>
                        <span style="font-size:13px;font-weight:600;color:#e2e2e0;">Auto Provisioning</span>
                        <span style="font-size:10px;padding:1px 6px;background:rgba(52,211,153,.2);color:#6ee7b7;border-radius:4px;font-weight:600;">RECOMMENDED</span>
                    </div>
                    <p style="font-size:11px;color:rgba(255,255,255,.4);margin:0;line-height:1.5;">Run a script on your router to register it automatically.</p>
                </div>
                <div id="methodDirect" onclick="selectMethod('direct')" style="flex:1;padding:14px 16px;border:1px solid var(--neu-border);background:rgba(255,255,255,.03);border-radius:9px;cursor:pointer;transition:.2s;">
                    <div style="display:flex;align-items:center;gap:9px;margin-bottom:5px;">
                        <i class="fas fa-network-wired" style="color:rgba(255,255,255,.5);font-size:15px;"></i>
                        <span style="font-size:13px;font-weight:600;color:#e2e2e0;">Direct Connect</span>
                    </div>
                    <p style="font-size:11px;color:rgba(255,255,255,.4);margin:0;line-height:1.5;">Enter the router's IP address and credentials directly.</p>
                </div>
            </div>

            <!-- Auto provisioning fields -->
            <div id="provisionFields">
                <label>Router Identity *</label>
                <input type="text" id="mikrotikName" placeholder="e.g. Router-01 Main">
                <p>Found in your router: System → Identity. This name appears in the dashboard.</p>
            </div>

            <!-- Direct connect fields (hidden by default) -->
            <div id="directFields" style="display:none;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label>Router Name *</label>
                        <input type="text" id="directName" placeholder="e.g. Main HQ Router">
                    </div>
                    <div>
                        <label>IP Address *</label>
                        <input type="text" id="directIP" placeholder="e.g. 192.168.88.1">
                    </div>
                    <div>
                        <label>Username *</label>
                        <input type="text" id="directUser" placeholder="admin">
                    </div>
                    <div>
                        <label>Password</label>
                        <input type="password" id="directPass" placeholder="RouterOS API password">
                    </div>
                </div>
                <div>
                    <label>API Port</label>
                    <input type="number" id="directPort" value="8728" style="width:120px;">
                    <p>Default 8728 — must be enabled in IP → Services → API on the router.</p>
                </div>
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
                <p style="color:rgba(255,255,255,.4); margin-bottom:8px;">Select one or both services to enable on this router.</p>
                <p style="font-size:12px; color:rgba(255,255,255,.25); margin-bottom:20px;">You can select both if this router serves PPPoE and hotspot clients.</p>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; text-align:left;">
                    <div class="service-card" id="svc-pppoe" onclick="toggleService('pppoe', this)" style="border:1px solid var(--neu-border); background:var(--neu-surf); padding:16px; border-radius:8px; cursor:pointer; transition:.2s; position:relative;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <i class="fas fa-network-wired" style="color:rgba(255,255,255,.4); font-size:14px;"></i>
                            <span style="font-weight:600; color:#e2e2e0;">PPPoE Server</span>
                        </div>
                        <p style="font-size:12px; color:rgba(255,255,255,.35); margin:0;">For home/business broadband clients</p>
                        <div class="svc-check" style="display:none; position:absolute; top:10px; right:10px; width:18px; height:18px; background:var(--primary-color,#3B6EA5); border-radius:50%; align-items:center; justify-content:center;">
                            <i class="fas fa-check" style="font-size:9px; color:white;"></i>
                        </div>
                    </div>
                    <div class="service-card" id="svc-hotspot" onclick="toggleService('hotspot', this)" style="border:1px solid var(--neu-border); background:var(--neu-surf); padding:16px; border-radius:8px; cursor:pointer; transition:.2s; position:relative;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <i class="fas fa-wifi" style="color:rgba(255,255,255,.4); font-size:14px;"></i>
                            <span style="font-weight:600; color:#e2e2e0;">Hotspot Server</span>
                        </div>
                        <p style="font-size:12px; color:rgba(255,255,255,.35); margin:0;">For voucher / captive-portal clients</p>
                        <div class="svc-check" style="display:none; position:absolute; top:10px; right:10px; width:18px; height:18px; background:var(--primary-color,#3B6EA5); border-radius:50%; align-items:center; justify-content:center;">
                            <i class="fas fa-check" style="font-size:9px; color:white;"></i>
                        </div>
                    </div>
                </div>
                <p id="svcSelectionHint" style="font-size:12px; color:rgba(239,68,68,.7); margin-top:12px; display:none;"><i class="fas fa-exclamation-circle"></i> Please select at least one service.</p>

                <!-- Hotspot settings panel — shown only when hotspot is selected -->
                <div id="hotspotSettingsPanel" style="display:none; margin-top:20px; text-align:left; background:rgba(16,185,129,.06); border:1px solid rgba(16,185,129,.2); border-radius:8px; padding:16px;">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#6ee7b7; margin-bottom:12px;">
                        <i class="fas fa-wifi" style="margin-right:6px;"></i> Hotspot Settings
                    </div>

                    <!-- Session sharing -->
                    <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer; padding:10px; border-radius:6px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.06);">
                        <div style="flex-shrink:0; margin-top:2px;">
                            <input type="checkbox" id="hsNoSharing" style="width:16px;height:16px;accent-color:var(--primary-color,#3B6EA5);cursor:pointer;">
                        </div>
                        <div>
                            <div style="font-weight:600; color:#e2e2e0; font-size:13px;">Prevent session sharing</div>
                            <div style="font-size:11px; color:rgba(255,255,255,.35); margin-top:2px;">
                                Limits each hotspot account to <strong style="color:rgba(255,255,255,.5);">one active device at a time</strong>. If the user connects a second device, the first session is kicked. Recommended for prepaid voucher ISPs.
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <div class="wizard-footer">
            <button class="wizard-btn prev" id="prevBtn" onclick="prevStep()" style="display:none;"><i class="fas fa-arrow-left" style="font-size:11px;margin-right:6px;"></i>Back</button>
            <div style="flex:1;"></div>
            <button class="wizard-btn next" id="nextBtn" onclick="nextStep()">Get Provisioning Script <i class="fas fa-arrow-right" style="font-size:11px;margin-left:6px;"></i></button>
        </div>
    </div>
</div>

<script>

let currentStep = 1;
let provisioningTimer = null;
let selectedServices = new Set(); // supports multi-select: 'pppoe', 'hotspot', or both
let wizardMethod = 'provision'; // 'provision' | 'direct'

function openWizard() {
    document.getElementById('wizardModal').style.display = 'flex';
    currentStep = 1;
    selectedServices = new Set();
    wizardMethod = 'provision';
    selectMethod('provision');
    updateWizard();
    resetSelections();
}

function closeWizard() {
    document.getElementById('wizardModal').style.display = 'none';
    if (provisioningTimer) clearInterval(provisioningTimer);
}

function selectMethod(method) {
    wizardMethod = method;
    const pCard = document.getElementById('methodProvision');
    const dCard = document.getElementById('methodDirect');
    const pFields = document.getElementById('provisionFields');
    const dFields = document.getElementById('directFields');
    if (method === 'provision') {
        pCard.style.border = '2px solid var(--primary-color,#3B6EA5)';
        pCard.style.background = 'rgba(59,110,165,.12)';
        pCard.querySelector('i').style.color = 'var(--primary-light,#93c5fd)';
        dCard.style.border = '1px solid var(--neu-border,rgba(255,255,255,.06))';
        dCard.style.background = 'rgba(255,255,255,.03)';
        dCard.querySelector('i').style.color = 'rgba(255,255,255,.5)';
        pFields.style.display = 'block';
        dFields.style.display = 'none';
    } else {
        dCard.style.border = '2px solid var(--primary-color,#3B6EA5)';
        dCard.style.background = 'rgba(59,110,165,.12)';
        dCard.querySelector('i').style.color = 'var(--primary-light,#93c5fd)';
        pCard.style.border = '1px solid var(--neu-border,rgba(255,255,255,.06))';
        pCard.style.background = 'rgba(255,255,255,.03)';
        pCard.querySelector('i').style.color = 'rgba(255,255,255,.5)';
        dFields.style.display = 'block';
        pFields.style.display = 'none';
    }
    // Update the footer button label
    const nBtn = document.getElementById('nextBtn');
    if (nBtn && currentStep === 1) {
        nBtn.innerHTML = method === 'direct'
            ? 'Connect Router <i class="fas fa-plug" style="font-size:11px;margin-left:6px;"></i>'
            : 'Get Provisioning Script <i class="fas fa-arrow-right" style="font-size:11px;margin-left:6px;"></i>';
    }
}

/* Manual router card toggle */
function toggleManualCard() {
    const body    = document.getElementById('manualCardBody');
    const chevron = document.getElementById('manualCardChevron');
    const header  = document.getElementById('manualCardHeader');
    const open = body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    chevron.style.transform = open ? 'rotate(180deg)' : '';
    header.style.borderBottomColor = open ? 'var(--neu-border,rgba(255,255,255,.06))' : 'rgba(255,255,255,0)';
}

function submitManualRouter(e) {
    e.preventDefault();
    const btn = document.getElementById('manualRouterSaveBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding…';
    btn.disabled = true;
    e.target.submit();
    return false;
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
        document.getElementById('nextBtn').innerHTML = wizardMethod === 'direct'
            ? 'Connect Router <i class="fas fa-plug" style="font-size:11px;"></i>'
            : 'Get Provisioning Script <i class="fas fa-arrow-right" style="font-size:11px;"></i>';
        document.getElementById('nextBtn').onclick = nextStep;
        document.getElementById('nextBtn').disabled = false;
    } else if (currentStep === 2) {
        document.getElementById('prevBtn').style.display = 'block';
        document.getElementById('nextBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Waiting for Connection…';
        document.getElementById('nextBtn').disabled = true;
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
        if (wizardMethod === 'direct') {
            // Direct connect — save router immediately via AJAX
            const name = document.getElementById('directName').value.trim();
            const ip   = document.getElementById('directIP').value.trim();
            const user = document.getElementById('directUser').value.trim();
            const pass = document.getElementById('directPass').value;
            const port = document.getElementById('directPort').value || 8728;
            if (!name || !ip || !user) {
                showWizardError('Router Name, IP Address and Username are required.'); return;
            }
            const btn = document.getElementById('nextBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting…';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'add_router');
            fd.append('router_name', name);
            fd.append('router_ip', ip);
            fd.append('router_username', user);
            fd.append('router_password', pass);
            fd.append('router_port', port);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.text())
                .then(() => {
                    // Page will reload — just show success and reload
                    closeWizard();
                    location.reload();
                })
                .catch(() => {
                    showWizardError('Failed to save router. Please try again.');
                    btn.innerHTML = 'Next: Connect <i class="fas fa-arrow-right"></i>';
                    btn.disabled = false;
                });
            return;
        }

        // Auto provisioning flow
        const name = document.getElementById('mikrotikName').value.trim();
        if (!name) { showWizardError('Please enter the router identity name.'); return; }

        const token    = "<?php echo $tenant['provisioning_token'] ?? ''; ?>";
        const host     = window.location.host;
        const protocol = window.location.protocol;
        const endpoint = `${protocol}//${host}<?php echo $_base_path; ?>/api/routers/provision.php`;
        const cmd      = `/tool fetch url="${endpoint}?token=${token}&identity=${encodeURIComponent(name)}&format=rsc" dst-path=provision.rsc; :delay 5s; /import provision.rsc;`;

        if (host.includes('localhost') || host.includes('127.0.0.1')) {
            const infoPanel = document.getElementById('localhostInfoPanel');
            if (infoPanel) infoPanel.style.display = 'block';
        }
        document.getElementById('provisionCommand').textContent = cmd;
        currentStep = 2;
        updateWizard();
    }
}

function showWizardError(msg) {
    let el = document.getElementById('wizardErrorMsg');
    if (!el) {
        el = document.createElement('div');
        el.id = 'wizardErrorMsg';
        el.style.cssText = 'margin-top:14px;padding:10px 14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:7px;color:#fca5a5;font-size:13px;';
        document.getElementById('step1').appendChild(el);
    }
    el.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + msg;
    el.style.display = 'block';
    setTimeout(() => { if (el) el.style.display = 'none'; }, 4000);
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

function toggleService(service, el) {
    if (selectedServices.has(service)) {
        selectedServices.delete(service);
        el.style.borderColor = 'rgba(255,255,255,.07)';
        el.style.backgroundColor = '#1c1c1b';
        el.querySelector('.svc-check').style.display = 'none';
    } else {
        selectedServices.add(service);
        el.style.borderColor = 'var(--primary-color, #3B6EA5)';
        el.style.backgroundColor = 'rgba(59,110,165,.15)';
        el.querySelector('.svc-check').style.display = 'flex';
    }
    document.getElementById('svcSelectionHint').style.display = 'none';

    // Show hotspot settings panel only while hotspot is selected
    const panel = document.getElementById('hotspotSettingsPanel');
    if (panel) panel.style.display = selectedServices.has('hotspot') ? 'block' : 'none';
}

function resetSelections() {
    selectedServices = new Set();
    document.querySelectorAll('.service-card').forEach(c => {
        c.style.borderColor = 'rgba(255,255,255,.07)';
        c.style.backgroundColor = '#1c1c1b';
        const chk = c.querySelector('.svc-check');
        if (chk) chk.style.display = 'none';
    });
    const hint = document.getElementById('svcSelectionHint');
    if (hint) hint.style.display = 'none';
    const panel = document.getElementById('hotspotSettingsPanel');
    if (panel) panel.style.display = 'none';
    const cb = document.getElementById('hsNoSharing');
    if (cb) cb.checked = false;
}

function finishWizard() {
    if (selectedServices.size === 0) {
        document.getElementById('svcSelectionHint').style.display = 'block';
        return;
    }

    const btn = document.getElementById('nextBtn');
    btn.textContent = 'Configuring...';
    btn.disabled = true;

    const name         = document.getElementById('mikrotikName').value;
    const servicesList = Array.from(selectedServices).join(',');
    const noSharing    = document.getElementById('hsNoSharing')?.checked ? '1' : '0';

    const formData = new FormData();
    formData.append('identity',           name);
    formData.append('services',           servicesList);
    formData.append('hotspot_no_sharing', noSharing);

    fetch('api/routers/configure_service.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            const label = data.services.map(s => s.toUpperCase()).join(' + ');
            const commandBlocks = data.commands.map((cmd, i) => `
                <div style="margin-bottom:${i < data.commands.length - 1 ? '16px' : '0'};">
                    <div style="font-size:11px; font-weight:600; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px;">${data.services[i].toUpperCase()} Command</div>
                    <div class="command-box" style="text-align:left;">
                        <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.textContent)">Copy</button>
                        <div class="command-text">${cmd}</div>
                    </div>
                </div>
            `).join('');

            document.getElementById('step3').innerHTML = `
                <div style="padding:20px;">
                    <div style="text-align:center; margin-bottom:20px;">
                        <div style="width:48px; height:48px; background:rgba(52,211,153,.15); color:#6ee7b7; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 12px;">
                            <i class="fas fa-check" style="font-size:24px;"></i>
                        </div>
                        <h3 style="font-size:18px; font-weight:600; margin-bottom:4px; color:#e2e2e0;">${label} Configured</h3>
                        <p style="color:rgba(255,255,255,.4); font-size:13px;">Run the command(s) below in your MikroTik terminal to finalize setup.</p>
                    </div>
                    ${commandBlocks}
                    <div style="text-align:center; margin-top:20px;">
                        <button onclick="location.reload()" style="padding:10px 24px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer; font-weight:600;">Done</button>
                    </div>
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
    navigator.clipboard.writeText(text).then(() => {
        showRouterToast('Command copied to clipboard!', 'success');
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        showRouterToast('Copied!', 'success');
    });
}

function showRouterToast(msg, type) {
    // Reuse global showToast if available, else simple alert
    if (typeof showToast === 'function') { showToast(msg, type); return; }
    const toast = document.createElement('div');
    toast.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.4);color:white;background:${type==='success'?'#059669':'#dc2626'};`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
}

window.onclick = function(event) {
    if (event.target == document.getElementById('wizardModal')) {
        closeWizard();
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

function updateRouterCard(id, data) {
    const dot   = document.getElementById('rdot-'   + id);
    const badge = document.getElementById('rbadge-' + id);

    if (data.status === 'success') {
        if (dot)   { dot.className = 'router-status-dot active'; }
        if (badge) { badge.className = 'status-badge active'; badge.innerHTML = '<i class="fas fa-check-circle"></i> Active'; }

        const s = data.stats || {};
        const setEl = (elId, val) => { const el = document.getElementById(elId); if (el) el.textContent = val; };

        setEl('rstat-active-'    + id, s.active_total ?? 0);
        setEl('rstat-uptime-'    + id, s.uptime        ?? '—');
        setEl('rstat-cpu-'       + id, (s.cpu_load ?? 0) + '%');

        const bd = document.getElementById('rstat-breakdown-' + id);
        if (bd && s.active_total !== undefined) {
            const parts = [];
            if (s.pppoe_active  > 0) parts.push(s.pppoe_active  + ' PPPoE');
            if (s.hotspot_active > 0) parts.push(s.hotspot_active + ' Hotspot');
            bd.textContent = parts.length ? parts.join(' · ') : 'No active sessions';
        }

        const cpuBar = document.getElementById('rstat-cpubar-' + id);
        if (cpuBar) {
            const cpu = parseInt(s.cpu_load ?? 0);
            cpuBar.style.width = cpu + '%';
            cpuBar.className   = 'progress-fill ' + (cpu > 90 ? 'danger' : (cpu > 70 ? 'warning' : 'good'));
        }
    } else {
        if (dot)   { dot.className = 'router-status-dot inactive'; }
        if (badge) { badge.className = 'status-badge inactive'; badge.innerHTML = '<i class="fas fa-times-circle"></i> Offline'; }
        const setEl = (elId, val) => { const el = document.getElementById(elId); if (el) el.textContent = val; };
        setEl('rstat-active-'    + id, '—');
        setEl('rstat-uptime-'    + id, '—');
        setEl('rstat-cpu-'       + id, '—');
        // Show reason in the breakdown slot
        const bd = document.getElementById('rstat-breakdown-' + id);
        if (bd) {
            const reason = data.reason === 'unreachable'
                ? 'Port 8728 blocked — re-run provisioning script'
                : (data.message || 'Connection failed');
            bd.textContent = reason;
            bd.style.color = 'rgba(239,68,68,.6)';
        }
    }
}

function testConnection(id, btn) {
    const originalText = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...'; btn.disabled = true; }

    const formData = new FormData();
    formData.append('id', id);

    fetch('api/routers/test_connection.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        updateRouterCard(id, data);
        if (btn) {
            if (data.status === 'success') {
                btn.innerHTML = '<i class="fas fa-check"></i> Connected';
                btn.style.color = '#34d399';
                setTimeout(() => { btn.innerHTML = originalText; btn.disabled = false; btn.style.color = ''; }, 3000);
            } else {
                showRouterToast('Failed: ' + data.message, 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    })
    .catch(e => {
        if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
        showRouterToast('Error: ' + e, 'error');
    });
}

// Auto-load live stats for all router cards on page load
document.addEventListener('DOMContentLoaded', () => {
    <?php foreach ($routers as $r): ?>
    testConnection(<?php echo (int)$r['id']; ?>, null);
    <?php endforeach; ?>
});

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

// Auto-open add router wizard when navigated from quick actions
if (new URLSearchParams(window.location.search).get('open_modal') === '1') {
    openWizard();
}

</script>
<!-- ═══════════════════════════════════════════════════════════════
     Remote Terminal Modal
     Uses RouterOS API (port 8728) — not SSH. Commands must start with /
     Example: /ppp/active/print  /system/resource/print  /interface/print
═══════════════════════════════════════════════════════════════ -->
<div id="terminalModal">
    <div class="term-window">

        <!-- Window chrome -->
        <div class="term-header">
            <div class="term-header-left">
                <div class="term-dots">
                    <span class="term-dot r"></span>
                    <span class="term-dot y"></span>
                    <span class="term-dot g"></span>
                </div>
                <div class="term-header-title">
                    <i class="fas fa-terminal"></i>
                    <span id="termRouterName">Router Terminal</span>
                    <span id="termRouterIp"></span>
                </div>
            </div>
            <button class="term-close" onclick="closeTerminal()" title="Close">&times;</button>
        </div>

        <!-- Quick-command shortcuts -->
        <div class="term-quickbar">
            <span class="term-ql">Quick:</span>
            <button class="term-qbtn" onclick="runQuick('/system/resource/print')">sys resource</button>
            <button class="term-qbtn" onclick="runQuick('/ppp/active/print')">ppp active</button>
            <button class="term-qbtn" onclick="runQuick('/ip/hotspot/active/print')">hotspot active</button>
            <button class="term-qbtn" onclick="runQuick('/ppp/secret/print')">ppp secrets</button>
            <button class="term-qbtn" onclick="runQuick('/ip/hotspot/user/print')">hotspot users</button>
            <button class="term-qbtn" onclick="runQuick('/interface/print')">interfaces</button>
            <button class="term-qbtn" onclick="runQuick('/ip/address/print')">ip address</button>
            <button class="term-qbtn" onclick="runQuick('/system/log/print')">log</button>
            <button class="term-qbtn" onclick="runQuick('/system/identity/print')">identity</button>
            <button class="term-qbtn clear" onclick="clearTerminal()"><i class="fas fa-eraser"></i> clear</button>
        </div>

        <!-- Output -->
        <div class="term-output" id="termOutput"></div>

        <!-- Input row -->
        <div class="term-input-row">
            <span id="termPromptLabel">[router] &gt;</span>
            <input id="termInput" type="text" placeholder="e.g. /ppp/active/print" autocomplete="off" spellcheck="false">
            <button class="term-run" onclick="termSubmit()"><i class="fas fa-play" style="font-size:10px;margin-right:4px;"></i>Run</button>
        </div>
    </div>
</div>

<script>
let termRouterId   = null;
let termRouterName = '';
let termHistory    = [];
let termHistIdx    = -1;

function openTerminal(routerId, name, ip) {
    termRouterId   = routerId;
    termRouterName = name;
    document.getElementById('termRouterName').textContent = name;
    document.getElementById('termRouterIp').textContent   = ip ? ' — ' + ip : '';
    document.getElementById('termPromptLabel').textContent = '[' + name + '] >';
    document.getElementById('terminalModal').style.display = 'flex';
    clearTerminal();
    setTimeout(() => document.getElementById('termInput').focus(), 80);
}

function deployHotspotLogin(routerId, btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    const fd = new FormData(); fd.append('router_id', routerId);
    fetch('api/mikrotik/deploy_hotspot_login.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.innerHTML = orig; btn.disabled = false;
            showToast(d.message || (d.success ? 'Deployed.' : 'Failed.'), d.success ? 'success' : 'error');
        })
        .catch(() => { btn.innerHTML = orig; btn.disabled = false; showToast('Network error.', 'error'); });
}

function closeTerminal() {
    document.getElementById('terminalModal').style.display = 'none';
    termRouterId = null;
}

function clearTerminal() {
    document.getElementById('termOutput').innerHTML =
        '<div class="term-welcome">' +
        'Connected to <strong style="color:#a5f3fc;">' + termEsc(termRouterName) + '</strong>. ' +
        'Enter RouterOS API commands and press Enter.<br>' +
        '<span style="opacity:.5;">Tip: commands start with / &nbsp;·&nbsp; use Up/Down for history &nbsp;·&nbsp; Esc closes</span>' +
        '</div>';
}

function runQuick(cmd) {
    document.getElementById('termInput').value = cmd;
    termSubmit();
}

function termAppend(html) {
    const out = document.getElementById('termOutput');
    out.insertAdjacentHTML('beforeend', html);
    out.scrollTop = out.scrollHeight;
}

function termEsc(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function termSubmit() {
    const input = document.getElementById('termInput');
    const cmd   = input.value.trim();
    if (!cmd || !termRouterId) return;
    input.value = '';

    // History
    if (termHistory[0] !== cmd) termHistory.unshift(cmd);
    if (termHistory.length > 60) termHistory.pop();
    termHistIdx = -1;

    // Echo command
    termAppend(
        '<div><span class="term-line-prompt">[' + termEsc(termRouterName) + '] &gt; </span>' +
        '<span class="term-line-cmd">' + termEsc(cmd) + '</span></div>'
    );

    // Spinner
    const spinId = 'sp' + Date.now();
    termAppend('<div id="' + spinId + '" class="term-line-spin"><i class="fas fa-circle-notch fa-spin"></i> executing…</div>');

    const fd = new FormData();
    fd.append('router_id', termRouterId);
    fd.append('command',   cmd);

    fetch('api/mikrotik/terminal.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById(spinId)?.remove();
            if (data.status === 'success') {
                termAppend('<div class="term-line-out">' + termEsc(data.output) + '</div>');
            } else {
                termAppend('<div class="term-line-err">' + termEsc(data.message || data.output || 'Unknown error') + '</div>');
            }
            termAppend('<div class="term-line-sep">─────────────────────────────────────────</div>');
            document.getElementById('termOutput').scrollTop = 9e9;
        })
        .catch(err => {
            document.getElementById(spinId)?.remove();
            termAppend('<div class="term-line-err">network error: ' + termEsc(err.message) + '</div>');
        });
}

// Keyboard nav
document.getElementById('termInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        termSubmit();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (termHistIdx < termHistory.length - 1) {
            termHistIdx++;
            this.value = termHistory[termHistIdx];
            this.setSelectionRange(9999,9999);
        }
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (termHistIdx > 0) { termHistIdx--; this.value = termHistory[termHistIdx]; }
        else if (termHistIdx === 0) { termHistIdx = -1; this.value = ''; }
    } else if (e.key === 'Escape') {
        closeTerminal();
    }
});

// Close on backdrop click
document.getElementById('terminalModal').addEventListener('click', function(e) {
    if (e.target === this) closeTerminal();
});
</script>

<?php include 'includes/footer.php'; ?>
