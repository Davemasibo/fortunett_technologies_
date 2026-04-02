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
    
    // Active Users
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
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #6B7280;
        margin-bottom: 8px;
    }
    
    .breadcrumb a {
        color: #3B6EA5;
        text-decoration: none;
    }
    
    .breadcrumb a:hover {
        text-decoration: underline;
    }
    
    .dashboard-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .dashboard-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0;
    }
    
    /* Quick Actions */
    .quick-actions {
        margin-bottom: 32px;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 16px;
    }
    
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
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }
    
    .action-btn:hover {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-dark) 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(44, 82, 130, 0.3);
        color: white;
    }
    
    .action-btn i {
        font-size: 16px;
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
    
    .metric-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        border: 1px solid #E5E7EB;
        transition: all 0.2s;
    }
    
    .metric-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
    }
    
    .metric-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    
    .metric-label {
        font-size: 13px;
        color: #6B7280;
        font-weight: 500;
    }
    
    .metric-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    
    .metric-icon.revenue {
        background: #DBEAFE;
        color: #1E40AF;
    }
    
    .metric-icon.users {
        background: #E0E7FF;
        color: #4338CA;
    }
    
    .metric-icon.growth {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .metric-icon.warning {
        background: #FEF3C7;
        color: #92400E;
    }
    
    .metric-value {
        font-size: 28px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .metric-change {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .metric-change.positive {
        color: #059669;
    }
    
    .metric-change.negative {
        color: #DC2626;
    }
    
    .metric-period {
        color: #9CA3AF;
        font-size: 12px;
    }
    
    /* Router Status & System Alerts */
    .two-column-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 32px;
    }
    
    @media (max-width: 1024px) {
        .two-column-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .status-card {
        background: white;
        border-radius: 10px;
        padding: 24px;
        border: 1px solid #E5E7EB;
    }
    
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }
    
    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
    }
    
    .router-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 0;
        border-bottom: 1px solid #F3F4F6;
    }
    
    .router-item:last-child {
        border-bottom: none;
    }
    
    .router-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .router-status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #10B981;
    }
    
    .router-name {
        font-weight: 500;
        color: #111827;
        font-size: 14px;
    }
    
    .router-ip {
        font-size: 12px;
        color: #6B7280;
    }
    
    .router-clients {
        font-size: 13px;
        color: #6B7280;
    }
    
    .router-clients strong {
        color: #111827;
        font-weight: 600;
    }
    
    /* System Alerts */
    .alert-item {
        padding: 14px;
        border-radius: 8px;
        margin-bottom: 12px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border-left: 4px solid;
    }
    
    .alert-item:last-child {
        margin-bottom: 0;
    }
    
    .alert-item.warning {
        background: #FFFBEB;
        border-color: #F59E0B;
    }
    
    .alert-item.info {
        background: #EFF6FF;
        border-color: #3B82F6;
    }
    
    .alert-icon {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 12px;
    }
    
    .alert-item.warning .alert-icon {
        background: #FEF3C7;
        color: #F59E0B;
    }
    
    .alert-item.info .alert-icon {
        background: #DBEAFE;
        color: #3B82F6;
    }
    
    .alert-content {
        flex: 1;
    }
    
    .alert-title {
        font-weight: 600;
        font-size: 13px;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .alert-message {
        font-size: 12px;
        color: #6B7280;
        line-height: 1.5;
    }
    
    .alert-time {
        font-size: 11px;
        color: #9CA3AF;
        margin-top: 4px;
    }

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

        .card-header select {
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
                <a href="clients.php?action=add" class="action-btn">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Customer</span>
                </a>
                <a href="packages.php?action=add" class="action-btn">
                    <i class="fas fa-box"></i>
                    <span>Create Package</span>
                </a>
                <a href="payments.php" class="action-btn">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Process Payment</span>
                </a>
                <a href="mikrotik.php" class="action-btn">
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
                        <span class="metric-label">Active Users</span>
                        <div class="metric-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="metric-value" id="stat-active"><?php echo number_format($active_users); ?></div>
                    <div class="metric-change">
                        <span class="metric-period">current</span>
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
                <div class="router-list">
                    <?php
                    // Fetch configured routers
                    try {
                        $r_stmt = $db->prepare("SELECT * FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ?");
                        $r_stmt->execute([$tenant_id]);
                        $routers = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (count($routers) > 0) {
                            foreach ($routers as $router) {
                                ?>
                                <div class="router-item">
                                    <div class="router-info">
                                        <div class="router-status-dot" id="router-dot-<?php echo $router['id']; ?>"></div>
                                        <div>
                                            <div class="router-name"><?php echo htmlspecialchars($router['name']); ?></div>
                                            <div class="router-ip"><?php echo htmlspecialchars($router['ip_address']); ?></div>
                                        </div>
                                    </div>
                                    <div class="router-clients">
                                        <strong id="router-clients-<?php echo $router['id']; ?>">-</strong> Active
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
            <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin-bottom: 20px;">Analytics & Insights</h2>
            
            <!-- Row 1: Payments & Active Users -->
            <div class="dashboard-chart-row">
                <!-- Payments Chart -->
                <div class="status-card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Payments</h3>
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">Payments and expenses trend</p>
                        </div>
                        <select style="padding: 6px 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;" id="activeUsersSubtitle">Last 6 months revenue trend</p>
                        </div>
                        <select style="padding: 6px 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">How many customers are returning and how many are churning?</p>
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
                            <h3 class="card-title">Data Usage</h3>
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">Data usage trend for PPPoE and Hotspot users</p>
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">Distribution of packages in use</p>
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">How much revenue will you expect to generate in the next 3 months?</p>
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">SMS sent from the system</p>
                        </div>
                        <select style="padding: 6px 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">Total Download: 8579 GB, Total Upload: 6.77 GB this week</p>
                        </div>
                        <select style="padding: 6px 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
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
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">User registrations trend</p>
                        </div>
                        <select style="padding: 6px 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 13px;">
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
                            <h3 class="card-title">Most Active Users</h3>
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">The most active users in the last 30 days</p>
                        </div>
                    </div>
                    <div style="padding: 20px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="border-bottom: 1px solid #E5E7EB;">
                                <tr>
                                    <th style="padding: 8px 0; text-align: left; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Username</th>
                                    <th style="padding: 8px 0; text-align: right; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Data Used</th>
                                    <th style="padding: 8px 0; text-align: right; font-size: 11px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Phone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="3" class="text-center p-4 text-muted">No usage data data available.</td>
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
let chartPayments, chartRegistrations, chartMonthly, chartPackage, chartSMS;

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
}

// ── Update stat cards ─────────────────────────────────────────────────────────
function updateStatCards(s) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('stat-daily',   'KES ' + (s.daily_revenue   || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    set('stat-monthly', 'KES ' + (s.monthly_revenue || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    set('stat-yearly',  'KES ' + (s.yearly_revenue  || 0).toLocaleString('en-KE', {minimumFractionDigits:0}));
    set('stat-active',  (s.active_users       || 0).toLocaleString());
    set('stat-expired', (s.expired_accounts   || 0).toLocaleString());
    set('stat-newreg',  (s.new_registrations  || 0).toLocaleString());
}

// ── Update router status dots from live MikroTik data ─────────────────────────
function updateRouterStatus(routers) {
    if (!routers || !routers.length) return;
    routers.forEach(r => {
        const dot = document.getElementById('router-dot-' + r.id);
        const clients = document.getElementById('router-clients-' + r.id);
        if (dot) {
            dot.style.background = r.online ? '#10B981' : '#EF4444';
            dot.title = r.online ? 'Online' : 'Offline';
        }
        if (clients) {
            clients.textContent = r.online ? r.active_clients : '—';
        }
    });
}

// ── Fetch and refresh everything ──────────────────────────────────────────────
function refreshDashboard() {
    const refreshIcon = document.getElementById('dash-refresh-icon');
    if (refreshIcon) refreshIcon.classList.add('fa-spin');

    fetch('api/dashboard/stats.php')
        .then(r => r.json())
        .then(s => {
            if (!s.success) { console.warn('Dashboard stats:', s.message); return; }
            updateStatCards(s);
            buildCharts(s);
            updateRouterStatus(s.router_status || []);
        })
        .catch(err => console.error('Dashboard refresh error:', err))
        .finally(() => {
            if (refreshIcon) refreshIcon.classList.remove('fa-spin');
        });
}

// Initial load
document.addEventListener('DOMContentLoaded', refreshDashboard);
// Auto-refresh every 60 seconds
setInterval(refreshDashboard, 60000);

// Keep old updateDashboardStats function working (called by router sync icon)
function updateDashboardStats() { refreshDashboard(); }

// All charts are built by refreshDashboard() — no static initialization needed
</script>

<?php include 'includes/footer.php'; ?>