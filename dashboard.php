<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();
$pdo = $db; // Make it available globally for header.php

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
    
} catch (Exception $e) {
    $daily_revenue = 0;
    $monthly_revenue = 0;
    $yearly_revenue = 0;
    $active_users = 0;
    $expired_accounts = 0;
    $new_registrations = 0;
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
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
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
        background: linear-gradient(135deg, #234161 0%, #2F5A8A 100%);
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
                    <div class="metric-value">KES <?php echo number_format($daily_revenue, 0); ?></div>
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
                    <div class="metric-value">KES <?php echo number_format($monthly_revenue, 0); ?></div>
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
                    <div class="metric-value">KES <?php echo number_format($yearly_revenue, 0); ?></div>
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
                    <div class="metric-value" id="live-active-users"><?php echo number_format($active_users); ?></div>
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
                    <div class="metric-value"><?php echo number_format($expired_accounts); ?></div>
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
                    <div class="metric-value"><?php echo number_format($new_registrations); ?></div>
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
                    <i class="fas fa-sync-alt" style="color: #9CA3AF; cursor: pointer;" title="Refresh" onclick="updateDashboardStats()"></i>
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
                            <h3 class="card-title">Active Users</h3>
                            <p style="font-size: 12px; color: #6B7280; margin: 4px 0 0 0;">Active now: 4 users | Average: 3 | Peak: 7 this week</p>
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
// Payments Chart
const paymentsCtx = document.getElementById('paymentsChart');
if (paymentsCtx) {
    new Chart(paymentsCtx, {
        type: 'bar',
        data: {
            labels: ['Jan', 'Feb', 'Mar'],
            datasets: [{
                label: 'Payments',
                data: [0, 0, 0],
                backgroundColor: '#3B6EA5',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 20000, ticks: { stepSize: 2000 } }
            }
        }
    });
}

// Active Users Chart
const activeUsersCtx = document.getElementById('activeUsersChart');
if (activeUsersCtx) {
    new Chart(activeUsersCtx, {
        type: 'line',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            datasets: [{
                label: 'Hotspot Users',
                data: [0, 0, 0, 0, 0],
                borderColor: '#F59E0B',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'PPPoE Users',
                data: [0, 0, 0, 0, 0],
                borderColor: '#3B6EA5',
                backgroundColor: 'rgba(59, 110, 165, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, max: 8 } }
        }
    });
}

// Customer Retention Chart
const retentionCtx = document.getElementById('retentionChart');
if (retentionCtx) {
    new Chart(retentionCtx, {
        type: 'line',
        data: {
            labels: ['Aug 2025', 'Sep 2025', 'Oct 2025', 'Nov 2025', 'Dec 2025', 'Jan 2026'],
            datasets: [{
                label: 'New Customers',
                data: [0, 0, 0, 0, 0, 0],
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.2)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Returning Customers',
                data: [0, 0, 0, 0, 0, 0],
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.2)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Churned Customers',
                data: [0, 0, 0, 0, 0, 0],
                borderColor: '#EF4444',
                backgroundColor: 'rgba(239, 68, 68, 0.2)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Retention Rate (%)',
                data: [0, 0, 0, 0, 0, 0],
                borderColor: '#F59E0B',
                borderDash: [5, 5],
                fill: false,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
            scales: {
                y: { beginAtZero: true, max: 35, position: 'left' },
                y1: { beginAtZero: true, max: 100, position: 'right', grid: { display: false } }
            }
        }
    });
}

// Data Usage Chart
const dataUsageCtx = document.getElementById('dataUsageChart');
if (dataUsageCtx) {
    new Chart(dataUsageCtx, {
        type: 'line',
        data: {
            labels: ['26 Dec', '27 Dec', '28 Dec', '29 Dec', '30 Dec', '31 Dec', '01 Jan', '02 Jan'],
            datasets: [{
                label: 'Hotspot',
                data: [0, 0, 0, 0, 0, 0, 0, 0],
                borderColor: '#F59E0B',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'PPPoE',
                data: [0, 0, 0, 0, 0, 0, 0, 0],
                borderColor: '#3B6EA5',
                backgroundColor: 'rgba(59, 110, 165, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, max: 120 } }
        }
    });
}

// Package Utilization Chart
const packageCtx = document.getElementById('packageChart');
if (packageCtx) {
    new Chart(packageCtx, {
        type: 'doughnut',
        data: {
            labels: ['4 Hours 5Mbps', '1 Hour', 'pppoe 6Mbps', 'pppoe 4 Mbps', 'Daily'],
            datasets: [{
                data: [],
                backgroundColor: ['#92400E', '#F59E0B', '#FCD34D', '#FEF3C7', '#FEFCE8'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, padding: 10 }
                }
            }
        }
    });
}

// Revenue Forecast Chart
const revenueForecastCtx = document.getElementById('revenueForecastChart');
if (revenueForecastCtx) {
    new Chart(revenueForecastCtx, {
        type: 'line',
        data: {
            labels: ['Jan 2025', 'Feb 2025', 'Mar 2025', 'Apr 2025', 'May 2025', 'Jun 2025', 'Jul 2025', 'Aug 2025', 'Sep 2025'],
            datasets: [{
                label: 'Historical Revenue',
                data: [],
                borderColor: '#3B6EA5',
                backgroundColor: 'rgba(59, 110, 165, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Forecast Revenue',
                data: [],
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Upper Confidence',
                data: [],
                borderColor: '#F59E0B',
                borderDash: [5, 5],
                fill: false
            }, {
                label: 'Lower Confidence',
                data: [],
                borderColor: '#F59E0B',
                borderDash: [5, 5],
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
            scales: { y: { beginAtZero: true, max: 16000 } }
        }
    });
}

// SMS Chart
const smsCtx = document.getElementById('smsChart');
if (smsCtx) {
    new Chart(smsCtx, {
        type: 'bar',
        data: {
            labels: ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            datasets: [{
                label: 'SMS Sent',
                data: [0, 0, 0, 0, 0, 0, 0],
                backgroundColor: '#F59E0B',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 3 } }
        }
    });
}

// Network Data Usage Chart
const networkDataCtx = document.getElementById('networkDataChart');
if (networkDataCtx) {
    new Chart(networkDataCtx, {
        type: 'line',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            datasets: [{
                label: 'Download',
                data: [0, 0, 0, 0, 0],
                borderColor: '#F59E0B',
                backgroundColor: 'rgba(245, 158, 11, 0.3)',
                fill: true,
                tension: 0.4
            }, {
                label: 'Upload',
                data: [0, 0, 0, 0, 0],
                borderColor: '#3B6EA5',
                backgroundColor: 'rgba(59, 110, 165, 0.3)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, max: 45 } }
        }
    });
}

// User Registrations Chart
const registrationsCtx = document.getElementById('registrationsChart');
if (registrationsCtx) {
    new Chart(registrationsCtx, {
        type: 'bar',
        data: {
            labels: ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            datasets: [{
                label: 'Registrations',
                data: [0, 0, 0, 0, 0, 0, 0],
                backgroundColor: '#F59E0B',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 1 } }
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>

<script>
// Live Dashboard Updates
function updateDashboardStats() {
    fetch('api/mikrotik/get_stats.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update Active Users
                const activeUsers = document.getElementById('live-active-users');
                if (activeUsers) activeUsers.textContent = data.data.active_users;
                
                // Update Router Status (Green dot for all active)
                document.querySelectorAll('.router-status-dot').forEach(el => {
                    el.style.background = '#10B981';
                    el.style.boxShadow = '0 0 0 2px rgba(16, 185, 129, 0.2)';
                });
                
                // Update Router Client Count (Apply same count to all listed routers since API is single-source)
                document.querySelectorAll('.router-clients strong').forEach(el => {
                    el.textContent = data.data.active_users;
                });
            } else {
                 console.log('Stats update failed:', data.message);
            }
        })
        .catch(err => console.error('Failed to fetch stats:', err));
}

// Update every 30 seconds
setInterval(updateDashboardStats, 30000);

// Initial call after 2 seconds to allow charts to load
setTimeout(updateDashboardStats, 2000);
</script>