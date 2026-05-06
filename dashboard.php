<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$db = $pdo; // Alias used throughout this file

// Get current user's tenant_id
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

// Get ISP profile
$profile = getISPProfile($db);

// Calculate metrics
try {
    // Daily Revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $daily_revenue = (float)$stmt->fetchColumn();
    
    // Monthly Revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $monthly_revenue = (float)$stmt->fetchColumn();
    
    // Yearly Revenue
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $yearly_revenue = (float)$stmt->fetchColumn();
    
    // Active subscriptions (initial page-load placeholder — AJAX replaces with live router count)
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status = 'active' AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $active_users = (int)$stmt->fetchColumn();
    
    // Expired Accounts
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE (expiry_date < NOW() OR status = 'inactive') AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $expired_accounts = (int)$stmt->fetchColumn();
    
    // New Registrations (this month)
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $new_registrations = (int)$stmt->fetchColumn();

    // Chart Data: Registrations Last 7 Days
    $reg_labels = [];
    $reg_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $reg_labels[] = date('D', strtotime($date));
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE DATE(created_at) = ? AND tenant_id = ?");
        $stmt->execute([$date, $tenant_id]);
        $reg_data[] = (int)$stmt->fetchColumn();
    }
    $reg_labels_json = json_encode($reg_labels);
    $reg_data_json = json_encode($reg_data);
    
} catch (Exception $e) {
    $daily_revenue = 0;
    $monthly_revenue = 0;
    $yearly_revenue = 0;
    $active_users = 0;
    $expired_accounts = 0;
    $new_registrations = 0;
    $reg_labels_json = '[]';
    $reg_data_json = '[]';
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    /* Override main content wrapper for this page */
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .dashboard-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Header Section */
    .dashboard-header {
        margin-bottom: 24px;
    }
    
    .breadcrumb {
        display:flex;align-items:center;gap:8px;font-size:12px;
        color:rgba(255,255,255,.4);margin-bottom:8px;
    }
    .breadcrumb a { color:var(--primary-light,#5b8fc9);text-decoration:none; }
    .breadcrumb a:hover { text-decoration:underline; }
    .dashboard-title    { font-size:26px;font-weight:700;color:#e2e2e0;margin:0 0 4px; }
    .dashboard-subtitle { font-size:13.5px;color:rgba(255,255,255,.45);margin:0; }

    /* Quick Actions */
    .quick-actions { margin-bottom:32px; }
    .section-title { font-size:15px;font-weight:600;color:#e2e2e0;margin-bottom:16px; }
    
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }
    
    .action-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 14px 20px;
        background: #1c1c1b;
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: 6px 6px 14px rgba(0,0,0,.45), -3px -3px 8px rgba(255,255,255,.04);
        color: rgba(255,255,255,.85);
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .action-btn i {
        font-size: 16px;
        color: var(--primary-light, #5b8fc9);
    }

    .action-btn:hover {
        background: var(--primary-dark, #1e3a5f);
        border-color: var(--primary-color, #3B6EA5);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,.55);
        color: #fff;
    }

    .action-btn:hover i {
        color: #fff;
    }
    
    /* Metrics Grid */
    .metrics-section {
        margin-bottom: 32px;
    }
    
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    /* ── Metric / Revenue / Customer cards ─────────────────────── */
    .metric-card {
        background: #1c1c1b;
        border-radius: 12px;
        padding: 20px;
        border: 1px solid rgba(255,255,255,.06);
        box-shadow: 8px 8px 20px rgba(0,0,0,.45), -4px -4px 10px rgba(255,255,255,.03);
        transition: transform .2s, box-shadow .2s;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 10px 10px 28px rgba(0,0,0,.55), -4px -4px 12px rgba(255,255,255,.04);
    }
    .metric-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    .metric-label {
        font-size: 13px;
        color: rgba(255,255,255,.5);
        font-weight: 500;
    }
    .metric-icon {
        width: 38px;
        height: 38px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
    }
    .metric-icon.revenue {
        background: rgba(96,165,250,.15);
        color: #93c5fd;
    }
    .metric-icon.users {
        background: rgba(129,140,248,.15);
        color: #a5b4fc;
    }
    .metric-icon.growth {
        background: rgba(52,211,153,.15);
        color: #6ee7b7;
    }
    .metric-icon.warning {
        background: rgba(251,191,36,.15);
        color: #fcd34d;
    }
    .metric-value {
        font-size: 26px;
        font-weight: 700;
        color: #e2e2e0;
        margin-bottom: 4px;
    }
    .metric-change { display:flex;align-items:center;gap:4px;font-size:12px;font-weight:500; }
    .metric-change.positive { color: #34d399; }
    .metric-change.negative { color: #f87171; }
    .metric-period { color: rgba(255,255,255,.35); font-size: 12px; }

    /* ── Section titles ─────────────────────────────────────────── */
    .section-title { font-size:15px;font-weight:600;color:#e2e2e0;margin-bottom:16px; }

    /* ── Router Status & System Alerts ─────────────────────────── */
    .two-column-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 32px;
    }
    @media (max-width: 1024px) {
        .two-column-grid { grid-template-columns: 1fr; }
    }
    .status-card {
        background: #1c1c1b;
        border-radius: 12px;
        padding: 24px;
        border: 1px solid rgba(255,255,255,.06);
        box-shadow: 8px 8px 20px rgba(0,0,0,.45), -4px -4px 10px rgba(255,255,255,.03);
    }
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }
    .card-title { font-size:16px;font-weight:600;color:#e2e2e0; }
    .card-subtitle { font-size:12px;color:rgba(255,255,255,.4);margin:4px 0 0 0; }
    .dash-period-select {
        padding:5px 10px;border-radius:6px;font-size:13px;
        background:#1a1a19;color:rgba(255,255,255,.7);
        border:1px solid rgba(255,255,255,.1);
        outline:none;cursor:pointer;
    }
    .dash-period-select:focus { border-color:rgba(255,255,255,.25); }
    .dash-inner-table { width:100%;border-collapse:collapse; }
    .dash-inner-table thead { border-bottom:1px solid rgba(255,255,255,.08); }
    .dash-inner-table th {
        padding:8px 0;text-align:left;font-size:11px;font-weight:600;
        color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;
    }
    .dash-inner-table td { padding:8px 0;font-size:13px;color:rgba(255,255,255,.75); }

    .router-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 0;
        border-bottom: 1px solid rgba(255,255,255,.06);
        gap: 12px;
    }
    .router-item:last-child { border-bottom: none; }
    .router-info { display:flex;align-items:center;gap:12px;flex:1;min-width:0; }
    .router-icon {
        width:38px;height:38px;border-radius:10px;
        display:flex;align-items:center;justify-content:center;
        font-size:15px;flex-shrink:0;
        transition:background .3s,color .3s;
    }
    .router-icon.ri-online  { background:rgba(16,185,129,.15);color:#34d399; }
    .router-icon.ri-offline { background:rgba(239,68,68,.12);color:#f87171; }
    .router-icon.ri-pending { background:rgba(156,163,175,.1);color:#9ca3af; }
    .router-name { font-weight:500;color:#e2e2e0;font-size:14px; }
    .router-ip   { font-size:12px;color:rgba(255,255,255,.4);margin-top:2px; }
    .router-right { display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0; }
    .router-counts { display:flex;gap:12px; }
    .r-count { display:flex;align-items:center;gap:4px;font-size:12px;color:rgba(255,255,255,.4); }
    .r-count i { font-size:10px; }
    .r-count strong { color:#d4d4d2;font-weight:600;font-size:13px; }
    .r-badge {
        display:inline-flex;align-items:center;gap:5px;
        padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;
        letter-spacing:.02em;
    }
    .r-badge.rb-online  { background:rgba(16,185,129,.12);color:#6ee7b7;border:1px solid rgba(16,185,129,.25); }
    .r-badge.rb-offline { background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.2); }
    .r-badge.rb-pending { background:rgba(156,163,175,.1);color:#9ca3af;border:1px solid rgba(156,163,175,.15); }
    .r-badge-dot { width:5px;height:5px;border-radius:50%;background:currentColor; }
    .router-footer {
        margin-top:14px;padding-top:12px;
        border-top:1px solid rgba(255,255,255,.05);
        text-align:right;
    }
    .router-footer a { font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;transition:color .2s; }
    .router-footer a:hover { color:var(--primary-light,#93c5fd); }

    /* ── System Alerts ──────────────────────────────────────────── */
    .alert-item {
        padding: 14px;
        border-radius: 8px;
        margin-bottom: 12px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border-left: 4px solid;
    }
    .alert-item:last-child { margin-bottom:0; }
    .alert-item.warning {
        background: rgba(251,191,36,.08);
        border-color: rgba(251,191,36,.5);
    }
    .alert-item.info {
        background: rgba(96,165,250,.08);
        border-color: rgba(96,165,250,.5);
    }
    .alert-icon {
        width:20px;height:20px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;
        flex-shrink:0;font-size:12px;
    }
    .alert-item.warning .alert-icon { background:rgba(251,191,36,.15);color:#fcd34d; }
    .alert-item.info    .alert-icon { background:rgba(96,165,250,.15); color:#93c5fd; }

    .alert-content { flex:1; }
    .alert-title   { font-weight:600;font-size:13px;color:#e2e2e0;margin-bottom:4px; }
    .alert-message { font-size:12px;color:rgba(255,255,255,.5);line-height:1.5; }
    .alert-time    { font-size:11px;color:rgba(255,255,255,.3);margin-top:4px; }

    /* Dashboard Chart Rows - Responsive */
    .dashboard-chart-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    @media (max-width: 1024px) {
        .dashboard-chart-row {
            grid-template-columns: 1fr;
            gap: 20px;
        }
    }

    /* Mobile Adjustments */
    @media (max-width: 768px) {
        .dashboard-container {
            padding: 16px;
        }

        .dashboard-title {
            font-size: 22px;
        }

        .actions-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .action-btn {
            padding: 12px 16px;
            font-size: 13px;
        }

        .metrics-grid {
            grid-template-columns: 1fr;
        }

        .two-column-grid {
            grid-template-columns: 1fr;
        }

        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .card-header select, .dash-period-select {
            width: 100%;
        }

        .status-card {
            padding: 16px;
        }
    }

    @media (max-width: 480px) {
        .dashboard-container {
            padding: 12px;
        }

        .actions-grid {
            grid-template-columns: 1fr;
        }

        .breadcrumb {
            font-size: 11px;
        }

        .dashboard-title {
            font-size: 20px;
        }

        .metric-value {
            font-size: 24px;
        }

        .section-title {
            font-size: 14px;
        }
    }
</style>

<div class="main-content-wrapper">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span><i class="fas fa-tachometer-alt"></i> Dashboard</span>
            </div>
            <h1 class="dashboard-title">Admin Dashboard</h1>
            <p class="dashboard-subtitle">Welcome back! Here's your ISP operations overview</p>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2 class="section-title">Quick Actions</h2>
            <div class="actions-grid">
                <a href="clients.php?open_modal=1" class="action-btn">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Customer</span>
                </a>
                <a href="packages.php?open_modal=1" class="action-btn">
                    <i class="fas fa-box"></i>
                    <span>Create Package</span>
                </a>
                <a href="payments.php?open_modal=1" class="action-btn">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Process Payment</span>
                </a>
                <a href="mikrotik.php?open_modal=1" class="action-btn">
                    <i class="fas fa-cog"></i>
                    <span>Router Config</span>
                </a>
            </div>
        </div>

        <!-- Revenue Analytics -->
        <div class="metrics-section">
            <h2 class="section-title">Revenue Analytics</h2>
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Daily Revenue</span>
                        <div class="metric-icon revenue">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-daily">KES <?php echo number_format($daily_revenue, 0); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">Today</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Monthly Revenue</span>
                        <div class="metric-icon revenue">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-monthly">KES <?php echo number_format($monthly_revenue, 0); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">This Month</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Yearly Revenue</span>
                        <div class="metric-icon revenue">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-yearly">KES <?php echo number_format($yearly_revenue, 0); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">This Year</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Metrics -->
        <div class="metrics-section">
            <h2 class="section-title">Customer Metrics</h2>
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Live Connections</span>
                        <div class="metric-icon users">
                            <i class="fas fa-wifi"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-active">—</div>
                    <div class="metric-change">
                        <span class="metric-period" id="stat-active-label">loading live sessions…</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Expired Accounts</span>
                        <div class="metric-icon warning">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-expired"><?php echo number_format($expired_accounts); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">total</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">New Registrations</span>
                        <div class="metric-icon growth">
                            <i class="fas fa-user-plus"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-newreg"><?php echo number_format($new_registrations); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">this month</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-label">Routers Online</span>
                        <div class="metric-icon users">
                            <i class="fas fa-server"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-routers-online">—</div>
                    <div class="metric-change">
                        <span class="metric-period" id="stat-routers-label">checking…</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Router Status & System Alerts -->
        <div class="two-column-grid">
            <!-- Router Status -->
            <div class="status-card">
                <div class="card-header">
                    <h3 class="card-title">Router Status</h3>
                    <i class="fas fa-sync-alt" id="dash-refresh-icon" style="color: #9CA3AF; cursor: pointer;" title="Refresh dashboard" onclick="refreshDashboard()"></i>
                </div>
                <div class="router-list" id="router-list">
                    <?php
                    // Fetch configured routers
                    try {
                        $r_stmt = $db->prepare("SELECT * FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
                        $r_stmt->execute([$tenant_id]);
                        $routers = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (count($routers) > 0) {
                            foreach ($routers as $router) {
                                ?>
                                <div class="router-item">
                                    <div class="router-info">
                                        <div class="router-icon ri-pending" id="router-icon-<?php echo $router['id']; ?>">
                                            <i class="fas fa-server"></i>
                                        </div>
                                        <div>
                                            <div class="router-name"><?php echo htmlspecialchars($router['name']); ?></div>
                                            <div class="router-ip"><?php echo htmlspecialchars($router['ip_address']); ?></div>
                                        </div>
                                    </div>
                                    <div class="router-right">
                                        <div class="router-counts">
                                            <div class="r-count" title="PPPoE sessions">
                                                <i class="fas fa-plug"></i>
                                                <strong id="router-pppoe-<?php echo $router['id']; ?>">—</strong>
                                            </div>
                                            <div class="r-count" title="Hotspot sessions">
                                                <i class="fas fa-wifi"></i>
                                                <strong id="router-hs-<?php echo $router['id']; ?>">—</strong>
                                            </div>
                                        </div>
                                        <span class="r-badge rb-pending" id="router-badge-<?php echo $router['id']; ?>">
                                            <span class="r-badge-dot"></span> Checking…
                                        </span>
                                    </div>
                                </div>
                                <?php
                            }
                        } else {
                            echo '<div class="p-3 text-center text-muted">No active routers configured.</div>';
                        }
                    } catch (Exception $ex) {
                        echo '<div class="p-3 text-center text-danger">Error loading routers.</div>';
                    }
                    ?>
                </div>
                <div class="router-footer">
                    <a href="online_customers.php">View online customers <i class="fas fa-arrow-right" style="font-size:10px;"></i></a>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="status-card">
                <div class="card-header">
                    <h3 class="card-title">System Alerts</h3>
                    <i class="fas fa-ellipsis-v" style="color: #9CA3AF; cursor: pointer;"></i>
                </div>
                <div class="alerts-list">
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-check-circle mb-2" style="font-size: 24px; color: #10B981;"></i>
                        <p>No new alerts.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Charts Section -->
        <div style="margin-top: 32px;">
            <h2 class="section-title" style="font-size: 20px; font-weight: 600; margin-bottom: 20px;">Analytics & Insights</h2>
            
            <!-- Row 1: Payments & Active Users -->
            <div class="dashboard-chart-row">
                <!-- Payments Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Payments</h3>
                            <p class="card-subtitle">Payments and expenses trend</p>
                        </div>
                        <select class="dash-period-select">
                            <option>This year</option>
                            <option>This month</option>
                            <option>This week</option>
                        </select>
                    </div>
                    <div style="padding: 20px; height: 250px; display: flex; align-items: center; justify-content: center;">
                        <canvas id="paymentsChart"></canvas>
                    </div>
                </div>

                <!-- Active Users Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Monthly Revenue</h3>
                            <p class="card-subtitle" id="activeUsersSubtitle">Last 6 months revenue trend</p>
                        </div>
                        <select class="dash-period-select">
                            <option>This week</option>
                            <option>This month</option>
                        </select>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="activeUsersChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Row 2: Customer Retention & Data Usage -->
            <div class="dashboard-chart-row">
                <!-- Customer Retention Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Customer retention rate (6 months)</h3>
                            <p class="card-subtitle">How many customers are returning and how many are churning?</p>
                        </div>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="retentionChart"></canvas>
                    </div>
                </div>

                <!-- Data Usage Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Active Users — PPPoE vs Hotspot</h3>
                            <p class="card-subtitle">Active subscriber counts by connection type (last 7 days)</p>
                        </div>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="dataUsageChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Row 3: Package Utilization & Revenue Forecast -->
            <div class="dashboard-chart-row">
                <!-- Package Utilization Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Package Utilization</h3>
                            <p class="card-subtitle">Distribution of packages in use</p>
                        </div>
                    </div>
                    <div style="padding: 20px; height: 300px; display: flex; align-items: center; justify-content: center;">
                        <canvas id="packageChart"></canvas>
                    </div>
                </div>

                <!-- Revenue Forecast Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Revenue Forecast (3 months)</h3>
                            <p class="card-subtitle">How much revenue will you expect to generate in the next 3 months?</p>
                        </div>
                    </div>
                    <div style="padding: 20px; height: 300px;">
                        <canvas id="revenueForecastChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Row 4: SMS Sent & Network Data Usage -->
            <div class="dashboard-chart-row">
                <!-- SMS Sent Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Sent SMS</h3>
                            <p class="card-subtitle">SMS sent from the system</p>
                        </div>
                        <select class="dash-period-select">
                            <option>This week</option>
                            <option>This month</option>
                        </select>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="smsChart"></canvas>
                    </div>
                </div>

                <!-- Network Data Usage Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Network Data Usage</h3>
                            <p class="card-subtitle">Download &amp; Upload (GB) from live PPPoE sessions</p>
                        </div>
                        <select class="dash-period-select">
                            <option>This week</option>
                            <option>This month</option>
                        </select>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="networkDataChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Row 5: User Registrations & Most Active Users -->
            <div class="dashboard-chart-row">
                <!-- User Registrations Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">User Registrations</h3>
                            <p class="card-subtitle">User registrations trend</p>
                        </div>
                        <select class="dash-period-select">
                            <option>This week</option>
                            <option>This month</option>
                        </select>
                    </div>
                    <div style="padding: 20px; height: 250px;">
                        <canvas id="registrationsChart"></canvas>
                    </div>
                </div>

                <!-- Most Active Users Table -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Top Paying Customers</h3>
                            <p class="card-subtitle">Highest revenue customers in the last 30 days</p>
                        </div>
                    </div>
                    <div style="padding: 20px;">
                        <table class="dash-inner-table">
                            <thead>
                                <tr>
                                    <th>Name / Username</th>
                                    <th style="text-align:right;">Revenue (30d)</th>
                                    <th style="text-align:right;">Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="3" class="text-center p-4" style="color:rgba(255,255,255,.35);">No usage data available.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ── Chart instances (kept global for live refresh) ────────────────────────────
let chartPayments, chartRegistrations, chartMonthly, chartPackage, chartSMS,
    chartRetention, chartDataUsage, chartForecast, chartNetwork;

const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#3B6EA5';
const PRIMARY_DARK = getComputedStyle(document.documentElement).getPropertyValue('--primary-dark').trim() || '#2C5282';

function hexAlpha(hex, a) {
    const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${a})`;
}

// ── Build / re-build all charts from stats data ───────────────────────────────
function buildCharts(s) {
    const primary = PRIMARY || '#3B6EA5';

    // Payments (daily last 7 days)
    const pCtx = document.getElementById('paymentsChart');
    if (pCtx) {
        if (chartPayments) chartPayments.destroy();
        chartPayments = new Chart(pCtx, {
            type: 'bar',
            data: {
                labels: s.payments_labels || [],
                datasets: [{ label: 'KES', data: s.payments_data || [], backgroundColor: primary, borderRadius: 5 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES '+v.toLocaleString() } } }
            }
        });
    }

    // Monthly revenue (last 6 months)
    const mCtx = document.getElementById('activeUsersChart');
    if (mCtx) {
        if (chartMonthly) chartMonthly.destroy();
        chartMonthly = new Chart(mCtx, {
            type: 'line',
            data: {
                labels: s.monthly_labels || [],
                datasets: [{
                    label: 'Monthly Revenue (KES)',
                    data: s.monthly_data || [],
                    borderColor: primary,
                    backgroundColor: hexAlpha(primary.replace('var(--primary-color)','#3B6EA5'), 0.12),
                    fill: true, tension: 0.4, pointRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES '+v.toLocaleString() } } }
            }
        });
    }

    // User Registrations (last 7 days)
    const rCtx = document.getElementById('registrationsChart');
    if (rCtx) {
        if (chartRegistrations) chartRegistrations.destroy();
        chartRegistrations = new Chart(rCtx, {
            type: 'bar',
            data: {
                labels: s.reg_labels || [],
                datasets: [{ label: 'New Customers', data: s.reg_data || [], backgroundColor: '#10B981', borderRadius: 5 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    // SMS sent (last 7 days)
    const smsCtx = document.getElementById('smsChart');
    if (smsCtx) {
        if (chartSMS) chartSMS.destroy();
        chartSMS = new Chart(smsCtx, {
            type: 'bar',
            data: {
                labels: s.sms_labels || [],
                datasets: [{ label: 'SMS Sent', data: s.sms_data || [], backgroundColor: '#8B5CF6', borderRadius: 5 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    // Package utilization (doughnut)
    const pkgCtx = document.getElementById('packageChart');
    if (pkgCtx) {
        if (chartPackage) chartPackage.destroy();
        const pkgColors = [primary, '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4'];
        chartPackage = new Chart(pkgCtx, {
            type: 'doughnut',
            data: {
                labels: s.pkg_labels && s.pkg_labels.length ? s.pkg_labels : ['No data'],
                datasets: [{
                    data: s.pkg_data && s.pkg_data.length ? s.pkg_data : [1],
                    backgroundColor: pkgColors.slice(0, Math.max(s.pkg_labels?.length || 1, 1)),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
            }
        });
    }

    // Customer Retention (stacked bar, 6 months)
    const retCtx = document.getElementById('retentionChart');
    if (retCtx) {
        if (chartRetention) chartRetention.destroy();
        chartRetention = new Chart(retCtx, {
            type: 'bar',
            data: {
                labels: s.retention_labels || [],
                datasets: [
                    { label: 'Active',  data: s.retention_active  || [], backgroundColor: '#10B981', borderRadius: 3, stack: 'r' },
                    { label: 'New',     data: s.retention_new     || [], backgroundColor: primary,   borderRadius: 3, stack: 'r' },
                    { label: 'Expired', data: s.retention_churned || [], backgroundColor: '#EF4444', borderRadius: 3, stack: 'c' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, stacked: true }, x: { stacked: true } }
            }
        });
    }

    // Active Users by Type — PPPoE vs Hotspot (last 7 days)
    const duCtx = document.getElementById('dataUsageChart');
    if (duCtx) {
        if (chartDataUsage) chartDataUsage.destroy();
        chartDataUsage = new Chart(duCtx, {
            type: 'bar',
            data: {
                labels: s.du_labels || [],
                datasets: [
                    { label: 'PPPoE',    data: s.du_pppoe   || [], backgroundColor: primary,   borderRadius: 4 },
                    { label: 'Hotspot',  data: s.du_hotspot || [], backgroundColor: '#F59E0B', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    // Revenue Forecast (6 historical + 3 projected)
    const fcCtx = document.getElementById('revenueForecastChart');
    if (fcCtx) {
        if (chartForecast) chartForecast.destroy();
        const safeP = primary.startsWith('var(') ? '#3B6EA5' : primary;
        chartForecast = new Chart(fcCtx, {
            type: 'line',
            data: {
                labels: s.forecast_labels || [],
                datasets: [
                    {
                        label: 'Actual Revenue', data: s.forecast_historical || [],
                        borderColor: safeP, backgroundColor: hexAlpha(safeP, 0.1),
                        fill: true, tension: 0.4, pointRadius: 4, spanGaps: false
                    },
                    {
                        label: '3-Month Forecast', data: s.forecast_projected || [],
                        borderColor: '#F59E0B', backgroundColor: 'rgba(245,158,11,0.08)',
                        borderDash: [6, 4], fill: true, tension: 0.4, pointRadius: 4, spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES '+v.toLocaleString() } } }
            }
        });
    }

    // Network Data Usage — live session bytes (Download / Upload in GB)
    const netCtx = document.getElementById('networkDataChart');
    if (netCtx) {
        if (chartNetwork) chartNetwork.destroy();
        chartNetwork = new Chart(netCtx, {
            type: 'bar',
            data: {
                labels: s.net_labels || [],
                datasets: [
                    { label: 'Download (GB)', data: s.net_download || [], backgroundColor: '#06B6D4', borderRadius: 4 },
                    { label: 'Upload (GB)',   data: s.net_upload   || [], backgroundColor: '#8B5CF6', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => v + ' GB' } } }
            }
        });
    }
}

// ── Update stat cards ─────────────────────────────────────────────────────────
function updateStatCards(s) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('stat-daily',   'KES ' + (s.daily_revenue   || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    set('stat-monthly', 'KES ' + (s.monthly_revenue || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    set('stat-yearly',  'KES ' + (s.yearly_revenue  || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    // Live Connections = actual MikroTik online sessions (PPPoE + Hotspot)
    const liveTotal = s.active_users || 0;
    set('stat-active', liveTotal.toLocaleString());
    const lbl = document.getElementById('stat-active-label');
    if (lbl) {
        if (s.router_online && liveTotal > 0) {
            const pppoe   = s.pppoe_online   || 0;
            const hotspot = s.hotspot_online || 0;
            lbl.textContent = `${pppoe} PPPoE · ${hotspot} Hotspot`;
            lbl.style.color = '#34d399';
        } else if (s.router_online === false) {
            lbl.textContent = 'router offline';
            lbl.style.color = '#f87171';
        } else {
            lbl.textContent = (s.subscribed_users || 0).toLocaleString() + ' active subscriptions';
            lbl.style.color = '';
        }
    }
    set('stat-expired', (s.expired_accounts   || 0).toLocaleString());
    set('stat-newreg',  (s.new_registrations  || 0).toLocaleString());
    // Routers online card
    set('stat-routers-online', (s.routers_online ?? '—').toString());
    const routerLbl = document.getElementById('stat-routers-label');
    if (routerLbl && s.routers_total !== undefined) {
        routerLbl.textContent = `of ${s.routers_total} configured`;
        routerLbl.style.color = (s.routers_online > 0) ? '#34d399' : '#f87171';
    }

    // System Alerts
    if (s.alerts && s.alerts.length) {
        const alertsList = document.querySelector('.alerts-list');
        if (alertsList) {
            alertsList.innerHTML = s.alerts.map(a => `
                <div class="alert-item ${a.type}">
                    <div class="alert-icon">
                        <i class="fas fa-${a.type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
                    </div>
                    <div class="alert-content">
                        <div class="alert-title">${a.title}</div>
                        <div class="alert-message">${a.message}</div>
                        <div class="alert-time">${a.time}</div>
                    </div>
                </div>`).join('');
        }
    }

    // Most Active Users table
    if (s.most_active && s.most_active.length) {
        const tbody = document.querySelector('.dash-inner-table tbody');
        if (tbody) {
            const rows = s.most_active.filter(u => parseFloat(u.total_paid) > 0);
            if (rows.length) {
                tbody.innerHTML = rows.map(u => `
                    <tr>
                        <td>${u.full_name || u.mikrotik_username || '—'}</td>
                        <td style="text-align:right;">KES ${parseFloat(u.total_paid||0).toLocaleString('en-KE',{minimumFractionDigits:0})}</td>
                        <td style="text-align:right;">${u.phone || '—'}</td>
                    </tr>`).join('');
            }
        }
    }
}

// ── Update router status from live MikroTik data ──────────────────────────────
function updateRouterStatus(routers) {
    if (!routers || !routers.length) return;
    routers.forEach(r => {
        const icon  = document.getElementById('router-icon-' + r.id);
        const badge = document.getElementById('router-badge-' + r.id);
        const pppoe = document.getElementById('router-pppoe-' + r.id);
        const hs    = document.getElementById('router-hs-' + r.id);
        if (icon) {
            icon.className = 'router-icon ' + (r.online ? 'ri-online' : 'ri-offline');
        }
        if (badge) {
            badge.className = 'r-badge ' + (r.online ? 'rb-online' : 'rb-offline');
            badge.innerHTML = '<span class="r-badge-dot"></span> ' + (r.online ? 'Online' : 'Offline');
        }
        if (pppoe) pppoe.textContent = r.online ? (r.pppoe_clients ?? 0) : '—';
        if (hs)    hs.textContent    = r.online ? (r.hotspot_clients ?? 0) : '—';
    });
}

// ── Fetch chart/metric stats (fast DB queries only) ───────────────────────────
function refreshDashboard() {
    const refreshIcon = document.getElementById('dash-refresh-icon');
    if (refreshIcon) refreshIcon.classList.add('fa-spin');

    fetch('api/dashboard/stats.php')
        .then(r => r.json())
        .then(s => {
            if (!s.success) { console.warn('Dashboard stats:', s.message); return; }
            updateStatCards(s);
            buildCharts(s);
            if (typeof window.__patchChartsDark === 'function') window.__patchChartsDark();
            // Also apply any router_status the stats API returned (may be stale/offline stubs)
            if (s.router_status && s.router_status.length) updateRouterStatus(s.router_status);
        })
        .catch(err => console.error('Dashboard refresh error:', err))
        .finally(() => {
            if (refreshIcon) refreshIcon.classList.remove('fa-spin');
        });
}

// ── Fetch live router status independently (MikroTik may be slow) ─────────────
function refreshRouterStatus() {
    fetch('api/dashboard/router_status.php')
        .then(r => r.json())
        .then(s => {
            if (!s.success) return;
            updateRouterStatus(s.router_status || []);
            const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            // Update live connections count with authoritative router data
            const liveTotal = s.active_users || 0;
            set('stat-active', liveTotal.toLocaleString());
            const al = document.getElementById('stat-active-label');
            if (al) {
                if (s.router_online && liveTotal > 0) {
                    const pppoe   = s.pppoe_online   || 0;
                    const hotspot = s.hotspot_online || 0;
                    al.textContent = `${pppoe} PPPoE · ${hotspot} Hotspot`;
                    al.style.color = '#34d399';
                } else if (!s.router_online) {
                    al.textContent = 'router offline';
                    al.style.color = '#f87171';
                }
            }
            set('stat-routers-online', (s.routers_online ?? '—').toString());
            const lbl = document.getElementById('stat-routers-label');
            if (lbl && s.routers_total !== undefined) {
                lbl.textContent = `of ${s.routers_total} configured`;
                lbl.style.color = (s.routers_online > 0) ? '#34d399' : '#f87171';
            }
        })
        .catch(err => console.warn('Router status fetch error:', err));
}

// Initial load — stats first, then router status (independent)
document.addEventListener('DOMContentLoaded', () => {
    refreshDashboard();
    refreshRouterStatus();
});
// Auto-refresh every 60 seconds
setInterval(refreshDashboard, 60000);
setInterval(refreshRouterStatus, 60000);

// Keep old updateDashboardStats function working (called by router sync icon)
function updateDashboardStats() { refreshDashboard(); refreshRouterStatus(); }

// All charts are built by refreshDashboard() — no static initialization needed
</script>

<?php include 'includes/footer.php'; ?>