<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Load settings for MikroTik metrics
$mk_settings = null;
try {
    $stmt = $db->query("SELECT * FROM mikrotik_settings WHERE id = 1 LIMIT 1");
    if ($stmt) {
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $mk_settings = is_array($f) ? $f : null;
    } else {
        $mk_settings = null;
    }
} catch (Exception $e) {
    $mk_settings = null;
}

// --- Greeting ---
$hour = (int)date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = 'Good afternoon';
} elseif ($hour >= 17 && $hour < 22) {
    $greeting = 'Good evening';
} else {
    $greeting = 'Good night';
}

// --- Time-aware subheading ---
if ($hour >= 5 && $hour < 12) {
    $greetingMessage = 'Have a productive morning — here\'s your dashboard.';
} elseif ($hour >= 12 && $hour < 17) {
    $greetingMessage = 'Hope your afternoon is going well.';
} elseif ($hour >= 17 && $hour < 22) {
    $greetingMessage = 'Hope you had a productive day.';
} else {
    $greetingMessage = 'Working late? Consider wrapping up and get some rest.';
}

// --- ISP profile & expiry countdown ---
$profile = getISPProfile($db);
$expiry_ts = null;
$days_left = null;
if (!empty($profile['subscription_expiry'])) {
    try {
        $expiry = new DateTime($profile['subscription_expiry']);
        $expiry_ts = $expiry->getTimestamp();
        $now = new DateTime();
        $diff = $now->diff($expiry);
        $days_left = ($expiry > $now) ? $diff->days : 0;
    } catch (Exception $e) {
        $days_left = null;
    }
}

// --- Key metrics ---
// Amount this month
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND status = 'completed'");
    $stmt->execute();
    $amount_this_month = (float)$stmt->fetchColumn();
} catch (Exception $e) {
    $amount_this_month = 0.0;
}

// Subscribed clients (active)
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status = 'active'");
    $stmt->execute();
    $subscribed_clients = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $subscribed_clients = 0;
}

// Total clients
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients");
    $stmt->execute();
    $total_clients = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $total_clients = 0;
}

// --- Customer Retention Metrics (6 months) ---
$new_customers = 0;
$recurring_customers = 0;
$churned_customers = 0;
$retention_rate = 0;

try {
    $six_months_ago_date = new DateTime('-6 months');
    $six_months_ago = $six_months_ago_date->format('Y-m-d H:i:s');

    // New customers: created in the last 6 months
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE created_at >= :six_months_ago");
    $stmt->execute([':six_months_ago' => $six_months_ago]);
    $new_customers = (int)$stmt->fetchColumn();

    // Base of customers from 6 months ago
    $stmt = $db->prepare("SELECT COUNT(DISTINCT p.client_id) FROM payments p JOIN clients c ON p.client_id = c.id WHERE c.created_at < :six_months_ago AND p.payment_date >= :six_months_ago");
    $stmt->execute([':six_months_ago' => $six_months_ago]);
    $customer_base_start = (int)$stmt->fetchColumn();

    // Recurring customers
    $stmt = $db->prepare("SELECT COUNT(DISTINCT p.client_id) FROM payments p JOIN clients c ON p.client_id = c.id WHERE c.created_at < :six_months_ago AND p.payment_date >= :six_months_ago");
    $stmt->execute([':six_months_ago' => $six_months_ago]);
    $recurring_customers = (int)$stmt->fetchColumn();

    // Churned customers
    $churned_customers = $customer_base_start - $recurring_customers;

    // Retention rate
    if ($customer_base_start > 0) {
        $retention_rate = round(($recurring_customers / $customer_base_start) * 100);
    }
} catch (Exception $e) {
    // Keep metrics at 0 on error
}

// --- MikroTik Metrics Function ---
function fetchMikrotikMetrics(PDO $db, ?array $mk_settings) {
    $metrics = [
        'active_now' => null,
        'hotspot_active' => null,
        'pppoe_active' => null,
        'hotspot_data_mb' => null,
        'pppoe_data_mb' => null,
    ];

    if (empty($mk_settings) || empty($mk_settings['host']) || empty($mk_settings['username'])) {
        error_log("MikroTik settings missing; skipping live fetch.");
        return $metrics;
    }

    if (!class_exists('RouterosAPI')) {
        error_log("RouterosAPI class not found.");
        return $metrics;
    }

    try {
        $apiClass = 'RouterosAPI';
        $api = new $apiClass();

        $host = $mk_settings['host'];
        $user = $mk_settings['username'];
        $pass = $mk_settings['password'] ?? '';
        $port = !empty($mk_settings['port']) ? (int)$mk_settings['port'] : 8728;

        if ($api->connect($host, $user, $pass, $port)) {
            try {
                // PPPoE active sessions
                $pppoe = $api->comm('/ppp/active/print');
                $metrics['pppoe_active'] = is_array($pppoe) ? count($pppoe) : 0;

                // Hotspot active users
                $hot = $api->comm('/ip/hotspot/active/print');
                $metrics['hotspot_active'] = is_array($hot) ? count($hot) : 0;

                // Interface traffic
                try {
                    $traffic = $api->comm('/interface/monitor-traffic', ['interface' => 'bridge-local', 'once' => '']);
                    if (is_array($traffic) && !empty($traffic[0])) {
                        $rx = floatval($traffic[0]['rx-bits-per-second'] ?? 0);
                        $tx = floatval($traffic[0]['tx-bits-per-second'] ?? 0);
                        $metrics['hotspot_data_mb'] = round(($rx + $tx) / 8 / 1024 / 1024, 2);
                    }
                } catch (Exception $e) {
                    // ignore traffic parsing errors
                }

                $metrics['active_now'] = ($metrics['pppoe_active'] ?? 0) + ($metrics['hotspot_active'] ?? 0);
            } finally {
                if (method_exists($api, 'disconnect')) {
                    $api->disconnect();
                }
            }
        } else {
            error_log("RouterOS API connect() failed to {$host}:{$port}");
        }
    } catch (Throwable $e) {
        error_log("MikroTik fetch error: " . $e->getMessage());
    }

    return $metrics;
}

// Fetch mikrotik metrics
$mkMetrics = fetchMikrotikMetrics($db, is_array($mk_settings) ? $mk_settings : null);

// Active users metrics with MikroTik override
$active_users_now = 7;
$average_users = 9;
$peak_users = 10;
$hotspot_users = 3;
$pppoe_users = 4;
$hotspot_data = 45;
$pppoe_data = 70;

if (!empty($mkMetrics['active_now'])) {
    $active_users_now = (int)$mkMetrics['active_now'];
}
if (!empty($mkMetrics['hotspot_active'])) {
    $hotspot_users = (int)$mkMetrics['hotspot_active'];
}
if (!empty($mkMetrics['pppoe_active'])) {
    $pppoe_users = (int)$mkMetrics['pppoe_active'];
}
if (!empty($mkMetrics['hotspot_data_mb'])) {
    $hotspot_data = (int)$mkMetrics['hotspot_data_mb'];
}
if (!empty($mkMetrics['pppoe_data_mb'])) {
    $pppoe_data = (int)$mkMetrics['pppoe_data_mb'];
}

// --- Chart data preparation ---
// Payments data for different timeframes
$payments_months_labels = [];
$payments_months_values = [];
$payments_30_labels = [];
$payments_30_values = [];
$payments_7_labels = [];
$payments_7_values = [];

try {
    // Last 12 months
    $labels = [];
    $values = [];
    for ($i = 11; $i >= 0; $i--) {
        $dt = new DateTime("first day of -{$i} months");
        $label = $dt->format('M');
        $year = $dt->format('Y');
        $month = $dt->format('n');
        $labels[] = $label;
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=? AND YEAR(payment_date)=? AND status = 'completed'");
        $stmt->execute([$month, $year]);
        $values[] = (float)$stmt->fetchColumn();
    }
    $payments_months_labels = $labels;
    $payments_months_values = $values;

    // Last 30 days
    for ($i = 29; $i >= 0; $i--) {
        $dt = new DateTime("-{$i} days");
        $label = $dt->format('M j');
        $date = $dt->format('Y-m-d');
        $payments_30_labels[] = $label;
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=? AND status = 'completed'");
        $stmt->execute([$date]);
        $payments_30_values[] = (float)$stmt->fetchColumn();
    }

    // Last 7 days
    for ($i = 6; $i >= 0; $i--) {
        $dt = new DateTime("-{$i} days");
        $label = $dt->format('D');
        $date = $dt->format('Y-m-d');
        $payments_7_labels[] = $label;
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=? AND status = 'completed'");
        $stmt->execute([$date]);
        $payments_7_values[] = (float)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    // Use fallback data in JavaScript
}

// Active users data
$active_labels = [];
$active_values = [];
try {
    for ($i = 6; $i >= 0; $i--) {
        $dt = new DateTime("-{$i} days");
        $label = $dt->format('D');
        $day = $dt->format('Y-m-d 23:59:59');
        $active_labels[] = $label;
        $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE created_at <= :day AND (expiry_date IS NULL OR expiry_date >= :day_short OR status = 'active')");
        $stmt->execute([':day' => $day, ':day_short' => $dt->format('Y-m-d')]);
        $active_values[] = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    // Use fallback data in JavaScript
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div>
        <!-- Header with greeting and subscription -->
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 30px;">
            <div style="flex: 1;">
                <h1 style="font-size: 28px; margin: 0 0 5px 0;"><?php echo $greeting; ?>, <?php echo htmlspecialchars($profile['business_name'] ?? 'Fortunett'); ?></h1>
                <div style="color: #666; font-size: 14px;"><?php echo $greetingMessage; ?></div>
            </div>

            <!-- Subscription and search -->
            <div style="display: flex; align-items: center; gap: 15px;">
                <!-- Compact subscription -->
                <div style="border: 1px solid rgba(0,0,0,0.08); background: #fff; padding: 6px 12px; border-radius: 8px; font-size: 12px; color: #333; white-space: nowrap;">
                    <strong><?php echo $days_left !== null ? $days_left : '—'; ?></strong> days left
                </div>

                <!-- Persistent search box -->
                <div style="position: relative;">
                    <input type="search" placeholder="Search..." style="padding: 8px 12px 8px 35px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; width: 200px;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #666; font-size: 14px;"></i>
                </div>
            </div>
        </div>

        <!-- Top metrics cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div class="card" style="padding:20px;">
                <div style="font-size:14px;color:#666;margin-bottom:8px;">Amount this month</div>
                <div style="font-size:22px;font-weight:700;color:#333;">KES <?php echo number_format($amount_this_month,2); ?></div>
                <div style="font-size:12px;color:#666;margin-top:6px;">Total earned this month</div>
            </div>

            <div class="card" style="padding:20px;">
                <div style="font-size:14px;color:#666;margin-bottom:8px;">Subscribed clients</div>
                <div style="font-size:22px;font-weight:700;color:#333;"><?php echo $subscribed_clients; ?></div>
                <div style="font-size:12px;color:#666;margin-top:6px;">Active subscriptions</div>
            </div>

            <div class="card" style="padding:20px;">
                <div style="font-size:14px;color:#666;margin-bottom:8px;">Total clients</div>
                <div style="font-size:22px;font-weight:700;color:#333;"><?php echo $total_clients; ?></div>
                <div style="font-size:12px;color:#666;margin-top:6px;">All registered clients</div>
            </div>
        </div>

        <!-- First Row: Payments Chart and Customer Retention -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Payments Chart -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <div style="font-weight: bold; font-size: 16px;">Payments</div>
                        <div class="chart-desc">Payments trend over the selected timeframe.</div>
                    </div>
                    <div>
                        <select id="paymentsTimeframe" class="form-select" style="width: 150px; font-size: 12px; padding: 5px 10px;">
                            <option value="today">Today</option>
                            <option value="last_week">Last week</option>
                            <option value="this_week" selected>This week</option>
                            <option value="this_month">This month</option>
                            <option value="last_month">Last month</option>
                            <option value="this_year">This year</option>
                        </select>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="paymentsChart"></canvas>
                </div>
            </div>

            <!-- Customer Retention -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Customer Retention Rate (6 months)</div>
                <div class="chart-desc">How many customers are returning and how many are churning?</div>
                <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 12px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>New Customers</span>
                        <span style="font-weight: bold;"><?php echo $new_customers; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Recurring Customers</span>
                        <span style="font-weight: bold;"><?php echo $recurring_customers; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Churned Customers</span>
                        <span style="font-weight: bold;"><?php echo $churned_customers; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                        <span>Retention Rate</span>
                        <span style="font-weight: bold;"><?php echo $retention_rate; ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row: Active Users and Data Usage -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Active Users -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div style="font-weight: bold; font-size: 16px;">Active Users</div>
                    <div style="font-size: 12px; color: #666; text-align: right;">
                        <div>Current: <?php echo $active_users_now; ?> users</div>
                        <div>Average: <?php echo $average_users; ?> | Peak: <?php echo $peak_users; ?></div>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="activeChart"></canvas>
                </div>
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 10px; font-size: 12px;">
                    <span>Hotspot: <?php echo $hotspot_users; ?></span>
                    <span>PPPoE: <?php echo $pppoe_users; ?></span>
                </div>
            </div>

            <!-- Data Usage -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Data Usage</div>
                <div class="chart-desc">Data usage trend for PPPoE and Hotspot users</div>
                <div id="dataUsageSummary" class="chart-desc" style="margin-bottom:8px; margin-top: 6px;">This week — Total Download: 166.6 GB, Total Upload: 24.65 GB</div>
                <div style="height: 200px;">
                    <div class="chart-wrap"><canvas id="dataUsageChart" height="200"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Third Row: Package Performance and Package Utilization -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Package Performance Table -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div style="font-weight: bold; font-size: 16px;">Package Performance</div>
                    <input type="search" placeholder="Search packages..." style="padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; width: 200px;">
                </div>
                <div style="overflow: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 1px solid #eee;">
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">Package Name</th>
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">Price</th>
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">Active Users</th>
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">Monthly Revenue</th>
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">Avg Data Usage</th>
                                <th style="padding: 12px 8px; font-size: 12px; color: #666;">ARPU</th>
                            </tr>
                        </thead>
                        <tbody id="packageTableBody">
                            <!-- Will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Package Utilization -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Package Utilization</div>
                <div class="chart-desc">Distribution of packages in use.</div>
                <div style="height: 250px;">
                    <div class="chart-wrap"><canvas id="pkgUtilChart" height="250"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Fourth Row: Revenue Forecast and Sent SMS -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Revenue Forecast -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Revenue Forecast (3 months)</div>
                <div class="chart-desc">How much revenue will you expect to generate in the next 3 months?</div>
                <div style="height: 250px;">
                    <div class="chart-wrap"><canvas id="revenueChart" height="250"></canvas></div>
                </div>
            </div>

            <!-- Sent SMS -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Sent SMS</div>
                <div class="chart-desc">SMS sent from the system.</div>
                <div style="height: 220px; margin-top: 12px;">
                    <div class="chart-wrap"><canvas id="smsChart" height="220"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Fifth Row: Mini Charts -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Payments Mini Chart -->
            <div class="card">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Payments Overview</div>
                <div class="chart-wrap" style="height: 120px;">
                    <canvas id="paymentsMiniChart"></canvas>
                </div>
            </div>

            <!-- Active Users Mini Chart -->
            <div class="card">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Active Users Trend</div>
                <div class="chart-wrap" style="height: 120px;">
                    <canvas id="activeUsersMiniChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Additional Rows: Network Data Usage, User Registrations, Most Active Users, Package Performance Comparison -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Network Data Usage -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">Network Data Usage</div>
                <div class="chart-desc">Total Download: 166.6 GB, Total Upload: 24.65 GB this week</div>
                <div style="height: 220px; margin-top: 12px;">
                    <div class="chart-wrap"><canvas id="networkDataChart" height="220"></canvas></div>
                </div>
            </div>

            <!-- User Registrations -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div class="chart-title">User Registrations</div>
                <div class="chart-desc">User registrations trend this week.</div>
                <div style="height: 220px; margin-top: 12px;">
                    <div class="chart-wrap"><canvas id="userRegChart" height="220"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Most Active Users Table -->
        <div class="card" style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px;">
            <div class="chart-title">Most Active Users</div>
            <div class="chart-desc">The most active users in the last 30 days.</div>
            <table class="table table-sm" style="margin-top: 12px;">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Data Used</th>
                        <th>Phone</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Rose</td><td>680.69GB</td><td>0717655705</td></tr>
                    <tr><td>Esther</td><td>636.25GB</td><td>0748583162</td></tr>
                    <tr><td>Bramwell</td><td>541.11GB</td><td>0741505864</td></tr>
                    <tr><td>Yvonne</td><td>331.17GB</td><td>0702933037</td></tr>
                    <tr><td>F27</td><td>62.59GB</td><td>0715387731</td></tr>
                    <tr><td>admin1</td><td>62.54GB</td><td>—</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Package Performance Comparison Table -->
        <div class="card" style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 20px;">
            <div class="chart-title">Package Performance Comparison</div>
            <div class="chart-desc">Compare all packages by price, active users, monthly revenue, data usage and ARPU.</div>
            <div style="overflow:auto; margin-top: 12px;">
                <table id="pkgTable" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background-color:#f9fafb;">
                            <th style="padding:12px 8px; font-size:12px; color:#333;">Package Name</th>
                            <th style="padding:12px 8px; font-size:12px; color:#333;">Price</th>
                            <th style="padding:12px 8px; font-size:12px; color:#333;">Active Users</th>
                            <th style="padding:12px 8px; font-size:12px; color:#333;">Monthly Revenue</th>
                            <th style="padding:12px 8px; font-size:12px; color:#333;">Avg Data Usage</th>
                            <th style="padding:12px 8px; font-size:12px; color:#333;">ARPU</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px 8px;">Basic 10Mbps</td>
                            <td style="padding:12px 8px;">KES 1,500</td>
                            <td style="padding:12px 8px;">45</td>
                            <td style="padding:12px 8px;">KES 67,500</td>
                            <td style="padding:12px 8px;">85 GB</td>
                            <td style="padding:12px 8px;">KES 1,500</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px 8px;">Standard 25Mbps</td>
                            <td style="padding:12px 8px;">KES 2,500</td>
                            <td style="padding:12px 8px;">30</td>
                            <td style="padding:12px 8px;">KES 75,000</td>
                            <td style="padding:12px 8px;">150 GB</td>
                            <td style="padding:12px 8px;">KES 2,500</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px 8px;">Premium 50Mbps</td>
                            <td style="padding:12px 8px;">KES 4,000</td>
                            <td style="padding:12px 8px;">15</td>
                            <td style="padding:12px 8px;">KES 60,000</td>
                            <td style="padding:12px 8px;">300 GB</td>
                            <td style="padding:12px 8px;">KES 4,000</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px 8px;">Business 100Mbps</td>
                            <td style="padding:12px 8px;">KES 8,000</td>
                            <td style="padding:12px 8px;">10</td>
                            <td style="padding:12px 8px;">KES 80,000</td>
                            <td style="padding:12px 8px;">500 GB</td>
                            <td style="padding:12px 8px;">KES 8,000</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart data with fallbacks
const fallbackPaymentsMonths = { 
    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 
    data: [1200,900,1100,700,1300,1500,1600,1400,1200,900,1000,1250] 
};

const fallbackPayments7 = { 
    labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 
    data: [200,240,180,220,260,300,280] 
};

const fallbackActive = { 
    labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 
    data: [5,7,6,8,9,7,10] 
};

const fallbackSms = { 
    labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 
    data: [5,12,8,10,6,9,4] 
};

const fallbackDataUsage = { 
    labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 
    download: [20,30,25,40,35,50,45], 
    upload: [5,8,6,10,9,12,11] 
};

const fallbackPackageUtil = { 
    labels: ['Basic','Standard','Premium','Business'], 
    data: [45,30,15,10], 
    colors: ['#4f8fff','#20c997','#f59e0b','#ef4444'] 
};

const fallbackRevenue = { 
    labels: ['Next Month','2 Months','3 Months'], 
    data: [15000,16500,18000] 
};

// Use server data or fallbacks
const paymentsMonths = <?php echo !empty($payments_months_labels) ? json_encode(['labels' => $payments_months_labels, 'data' => $payments_months_values]) : 'fallbackPaymentsMonths'; ?>;
const payments7 = <?php echo !empty($payments_7_labels) ? json_encode(['labels' => $payments_7_labels, 'data' => $payments_7_values]) : 'fallbackPayments7'; ?>;
const activeUsersData = <?php echo !empty($active_labels) ? json_encode(['labels' => $active_labels, 'data' => $active_values]) : 'fallbackActive'; ?>;

// Chart instances
let paymentsChart, activeChart, dataUsageChart, packageUtilChart, revenueChart, smsChart, paymentsMiniChart, activeUsersMiniChart;

function createChartInstance(canvasId, config) {
    const el = document.getElementById(canvasId);
    if (!el) return null;
    const ctx = el.getContext('2d');
    return new Chart(ctx, config);
}

// Initialize all charts
function initCharts() {
    // Main Payments Chart
    paymentsChart = createChartInstance('paymentsChart', {
        type: 'bar',
        data: {
            labels: payments7.labels,
            datasets: [{
                label: 'Amount',
                data: payments7.data,
                backgroundColor: '#4f8fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { maxTicksLimit: 6 } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // Active Users Chart
    activeChart = createChartInstance('activeChart', {
        type: 'line',
        data: {
            labels: activeUsersData.labels,
            datasets: [{
                label: 'Active Users',
                data: activeUsersData.data,
                borderColor: '#20c997',
                backgroundColor: 'rgba(32,201,151,0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, maxTicksLimit: 6 },
                x: { grid: { display: false } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // Data Usage Chart
    dataUsageChart = createChartInstance('dataUsageChart', {
        type: 'line',
        data: {
            labels: fallbackDataUsage.labels,
            datasets: [
                {
                    label: 'Download',
                    data: fallbackDataUsage.download,
                    borderColor: '#059fa8',
                    backgroundColor: 'rgba(5,159,168,0.08)',
                    fill: true
                },
                {
                    label: 'Upload',
                    data: fallbackDataUsage.upload,
                    borderColor: '#9fe6df',
                    backgroundColor: 'rgba(159,230,223,0.04)',
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } }
        }
    });

    // Package Utilization Chart
    packageUtilChart = createChartInstance('packageUtilChart', {
        type: 'doughnut',
        data: {
            labels: fallbackPackageUtil.labels,
            datasets: [{
                data: fallbackPackageUtil.data,
                backgroundColor: fallbackPackageUtil.colors
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'right' } 
            }
        }
    });

    // Revenue Forecast Chart
    revenueChart = createChartInstance('revenueChart', {
        type: 'line',
        data: {
            labels: fallbackRevenue.labels,
            datasets: [{
                label: 'Revenue Forecast',
                data: fallbackRevenue.data,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245,158,11,0.08)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    // Sent SMS Chart
    smsChart = createChartInstance('smsChart', {
        type: 'bar',
        data: {
            labels: fallbackSms.labels,
            datasets: [{
                label: 'Sent SMS',
                data: fallbackSms.data,
                backgroundColor: '#06b6d4'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    // Mini Charts
    paymentsMiniChart = createChartInstance('paymentsMiniChart', {
        type: 'line',
        data: {
            labels: payments7.labels,
            datasets: [{
                label: 'Payments',
                data: payments7.data,
                borderColor: '#059669',
                backgroundColor: 'rgba(5,150,105,0.06)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { display: false },
                y: { display: false }
            }
        }
    });

    activeUsersMiniChart = createChartInstance('activeUsersMiniChart', {
        type: 'line',
        data: {
            labels: activeUsersData.labels,
            datasets: [{
                label: 'Active Users',
                data: activeUsersData.data,
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220,38,38,0.06)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { display: false },
                y: { display: false }
            }
        }
    });
}

// Update payments chart based on timeframe
function updatePaymentsChart(timeframe) {
    let labels, data;
    
    switch(timeframe) {
        case 'today':
            labels = [new Date().toLocaleDateString('en-US', { weekday: 'short' })];
            data = [payments7.data[payments7.data.length - 1]];
            break;
        case 'last_week':
        case 'this_week':
            labels = payments7.labels;
            data = payments7.data;
            break;
        case 'this_month':
        case 'last_month':
            labels = paymentsMonths.labels.slice(-1);
            data = paymentsMonths.data.slice(-1);
            break;
        case 'this_year':
            labels = paymentsMonths.labels;
            data = paymentsMonths.data;
            break;
        default:
            labels = payments7.labels;
            data = payments7.data;
    }

    if (paymentsChart) {
        paymentsChart.data.labels = labels;
        paymentsChart.data.datasets[0].data = data;
        paymentsChart.update();
    }
}

// Populate package table with mock data
function populatePackageTable() {
    const packages = [
        { name: 'Basic 10Mbps', price: 'KES 1,500', users: 45, revenue: 'KES 67,500', data: '85 GB', arpu: 'KES 1,500' },
        { name: 'Standard 25Mbps', price: 'KES 2,500', users: 30, revenue: 'KES 75,000', data: '150 GB', arpu: 'KES 2,500' },
        { name: 'Premium 50Mbps', price: 'KES 4,000', users: 15, revenue: 'KES 60,000', data: '300 GB', arpu: 'KES 4,000' },
        { name: 'Business 100Mbps', price: 'KES 8,000', users: 10, revenue: 'KES 80,000', data: '500 GB', arpu: 'KES 8,000' }
    ];

    const tbody = document.getElementById('packageTableBody');
    if (tbody) {
        tbody.innerHTML = packages.map(pkg => `
            <tr style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 12px 8px;">${pkg.name}</td>
                <td style="padding: 12px 8px;">${pkg.price}</td>
                <td style="padding: 12px 8px;">${pkg.users}</td>
                <td style="padding: 12px 8px;">${pkg.revenue}</td>
                <td style="padding: 12px 8px;">${pkg.data}</td>
                <td style="padding: 12px 8px;">${pkg.arpu}</td>
            </tr>
        `).join('');
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initCharts();
    populatePackageTable();
    
    // Event listeners
    const paymentsTimeframe = document.getElementById('paymentsTimeframe');
    if (paymentsTimeframe) {
        paymentsTimeframe.addEventListener('change', function() {
            updatePaymentsChart(this.value);
        });
    }

    // Sidebar toggle functionality
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('show');
            localStorage.setItem('sidebarToggled', sidebar.classList.contains('show'));
        });

        // Load saved sidebar state
        const savedState = localStorage.getItem('sidebarToggled') === 'true';
        if (savedState) {
            sidebar.classList.add('show');
        }
    }

    // Update data usage summary
    const downloadTotal = fallbackDataUsage.download.reduce((a, b) => a + b, 0);
    const uploadTotal = fallbackDataUsage.upload.reduce((a, b) => a + b, 0);
    const summaryEl = document.getElementById('dataUsageSummary');
    if (summaryEl) {
        summaryEl.textContent = `This week — Total Download: ${downloadTotal} GB, Total Upload: ${uploadTotal} GB`;
    }
});
</script>

<style>
.main-content-wrapper {
    margin-left: 260px;
    background: #f8f9fa;
    min-height: 100vh;
}

.main-content-wrapper > div {
    margin: 0 auto;
    max-width: 1350px;
    padding: 30px 40px;
}

.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 25px;
    border: 1px solid rgba(0,0,0,0.06);
}

.chart-wrap {
    height: 250px;
    position: relative;
}

.chart-wrap canvas {
    width: 100% !important;
    height: 100% !important;
}

.chart-desc {
    color: #666;
    font-size: 12px;
    margin-top: 4px;
}

.chart-title {
    font-weight: 700;
    font-size: 15px;
    color: #333;
    margin-bottom: 6px;
}

@media (max-width: 768px) {
    .main-content-wrapper {
        margin-left: 0;
    }
    
    .main-content-wrapper > div {
        padding: 20px 16px;
    }
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 12px 8px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
}

th {
    font-weight: 600;
    font-size: 12px;
    color: #666;
}

td {
    font-size: 13px;
}
</style>