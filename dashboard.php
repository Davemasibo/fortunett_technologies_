<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

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
// Amount this month (payments table) - matches payments page calculation
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

// Active users now (placeholder - would need integration with MikroTik)
$active_users_now = 7; // Placeholder
$average_users = 9; // Placeholder
$peak_users = 10; // Placeholder

// Hotspot vs PPPoE users (placeholder)
$hotspot_users = 3; // Placeholder
$pppoe_users = 4; // Placeholder

// Customer retention data (placeholder)
$new_customers = 15; // Placeholder
$recurring_customers = 85; // Placeholder
$churned_customers = 10; // Placeholder
$retention_rate = 89; // Placeholder

// Data usage (placeholder)
$hotspot_data = 45; // Placeholder
$pppoe_data = 70; // Placeholder

// --- Payments aggregates for charts ---
// Last 12 months (monthly totals)
$payments_months_labels = [];
$payments_months_values = [];
try {
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
} catch (Exception $e) {
    $payments_months_labels = [];
    $payments_months_values = [];
}

// Last 30 days (daily totals)
$payments_30_labels = [];
$payments_30_values = [];
try {
    for ($i = 29; $i >= 0; $i--) {
        $dt = new DateTime("-{$i} days");
        $label = $dt->format('M j');
        $date = $dt->format('Y-m-d');
        $payments_30_labels[] = $label;
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=? AND status = 'completed'");
        $stmt->execute([$date]);
        $payments_30_values[] = (float)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    $payments_30_labels = [];
    $payments_30_values = [];
}

// Last 7 days (daily totals)
$payments_7_labels = [];
$payments_7_values = [];
try {
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
    $payments_7_labels = [];
    $payments_7_values = [];
}

// --- Active users line data (last 7 days) ---
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
    $active_labels = [];
    $active_values = [];
}

// After building $payments_months_values, $payments_30_values, $payments_7_values, $active_values
// compute safe maxima for charts (prevent extremely large axis)
$payments_max = 0;
foreach (array_merge($payments_months_values, $payments_30_values, $payments_7_values) as $v) { $payments_max = max($payments_max, (float)$v); }
$active_max = 0;
foreach ($active_values as $v) { $active_max = max($active_max, (int)$v); }

// apply a minimal fallback so suggestedMax isn't zero
$payments_max = max($payments_max, 1.0);
$active_max = max($active_max, 1);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- Greeting -->
        <div style="margin-bottom: 30px;">
            <h1 style="font-size: 28px; margin: 0 0 5px 0;"><?php echo $greeting; ?>, <?php echo htmlspecialchars($profile['business_name'] ?? 'Fortunett'); ?></h1>
            <div style="color: #666;">Working late? Don't forget to rest</div>
        </div>

        <!-- Top metrics cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 10px;">Amount this month</div>
                <div style="font-size: 24px; font-weight: bold; color: #333;">Ksh <?php echo number_format($amount_this_month, 2); ?></div>
                <div style="font-size: 12px; color: #666;">Total earned this month</div>
            </div>

            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 10px;">Scheduled clients</div>
                <div style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $subscribed_clients; ?></div>
                <div style="font-size: 12px; color: #666;">Number of subscribed clients</div>
            </div>
        </div>

        <!-- Charts row -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Payments Chart -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div style="font-weight: bold; font-size: 16px;">Payments</div>
                    <div>
                        <select id="paymentsTimeframe" class="form-select" style="width: 150px; font-size: 12px; padding: 5px 10px;" onchange="updatePaymentsChart()">
                            <option value="months" selected>This year</option>
                            <option value="30">Last 30 days</option>
                            <option value="7">Last 7 days</option>
                        </select>
                    </div>
                </div>
                <div style="height: 250px;">
                    <canvas id="paymentsChart"></canvas>
                </div>
            </div>

            <!-- Customer Retention -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Customer retention rate (6 months)</div>
                <div style="font-size: 12px; color: #666; margin-bottom: 15px;">How many customers are returning and how many are churning?</div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
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
                        <span>Retention Base (%)</span>
                        <span style="font-weight: bold;"><?php echo $retention_rate; ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Charts row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Active Users -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div style="font-weight: bold; font-size: 16px;">Active Users</div>
                    <div style="font-size: 12px; color: #666;">
                        Active now: <?php echo $active_users_now; ?> users | Average: <?php echo $average_users; ?> | Peak: <?php echo $peak_users; ?> this week
                    </div>
                </div>
                <div style="font-size: 12px; color: #666; margin-bottom: 10px;">This week</div>
                <div style="height: 200px; margin-bottom: 15px;">
                    <canvas id="activeChart"></canvas>
                </div>
                <div style="font-size: 12px; color: #666; text-align: center;">
                    Number of Unique Users
                </div>
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 10px; font-size: 12px;">
                    <span>Hotspot Users (Active: <?php echo $hotspot_users; ?>)</span>
                    <span>PPPoE Users (Active: <?php echo $pppoe_users; ?>)</span>
                </div>
            </div>

            <!-- Data Usage -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Data Usage</div>
                <div style="font-size: 12px; color: #666; margin-bottom: 15px;">Data usage trend for PPPoE and Hotspot users</div>
                <div style="height: 200px; margin-bottom: 15px; display: flex; align-items: end; gap: 20px; justify-content: center;">
                    <div style="text-align: center;">
                        <div style="height: <?php echo $hotspot_data * 2; ?>px; width: 30px; background: #4f8fff; border-radius: 3px;"></div>
                        <div style="margin-top: 5px; font-size: 12px;"><?php echo $hotspot_data; ?></div>
                        <div style="font-size: 12px; color: #666;">Hotspot</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="height: <?php echo $pppoe_data * 2; ?>px; width: 30px; background: #ff6b6b; border-radius: 3px;"></div>
                        <div style="margin-top: 5px; font-size: 12px;"><?php echo $pppoe_data; ?></div>
                        <div style="font-size: 12px; color: #666;">PPPoE</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Recent Payments -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Recent Payments</div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            try {
                                $recent_payments_stmt = $db->prepare("SELECT p.*, c.full_name 
                                    FROM payments p 
                                    LEFT JOIN clients c ON p.client_id = c.id 
                                    WHERE p.status = 'completed'
                                    ORDER BY p.payment_date DESC 
                                    LIMIT 5");
                                $recent_payments_stmt->execute();
                                $recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($recent_payments as $p): 
                            ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($p['full_name'] ?? 'Unknown'); ?></td>
                                    <td>KES <?php echo number_format($p['amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-success">Completed</span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            } catch (Exception $e) {
                                echo '<tr><td colspan="4" class="text-center">No recent payments</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Subscription Countdown -->
            <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="font-weight: bold; font-size: 16px; margin-bottom: 15px;">Subscription Status</div>
                <div style="text-align: center; padding: 20px 0;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 10px;">Current plan expires in</div>
                    <div style="font-size: 36px; font-weight: bold; color: #333; margin-bottom: 10px;">
                        <?php echo $days_left !== null ? $days_left : '—'; ?> days
                    </div>
                    <div style="font-size: 12px; color: #666;">
                        Renewal date: <?php echo !empty($profile['subscription_expiry']) ? date('F j, Y', strtotime($profile['subscription_expiry'])) : 'Not set'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Charts: Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const payments_months = {
    labels: <?php echo json_encode($payments_months_labels); ?>,
    data: <?php echo json_encode($payments_months_values); ?>
};
const payments_30 = {
    labels: <?php echo json_encode($payments_30_labels); ?>,
    data: <?php echo json_encode($payments_30_values); ?>
};
const payments_7 = {
    labels: <?php echo json_encode($payments_7_labels); ?>,
    data: <?php echo json_encode($payments_7_values); ?>
};

const activeUsers = {
    labels: <?php echo json_encode($active_labels); ?>,
    data: <?php echo json_encode($active_values); ?>
};

let paymentsChart, activeChart;

function createPaymentsChart(ctx, initialLabels, initialData) {
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: initialLabels,
            datasets: [{
                label: 'Amount (KES)',
                data: initialData,
                backgroundColor: '#4f8fff',
                borderColor: '#4f8fff',
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000) {
                                return 'Ksh ' + (value / 1000).toFixed(0) + 'K';
                            }
                            return 'Ksh ' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

function createActiveChart(ctx, labels, data) {
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Active Users',
                data: data,
                borderColor: '#20c997',
                backgroundColor: 'rgba(32, 201, 151, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#20c997',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    },
                    ticks: {
                        precision: 0
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

function updatePaymentsChart() {
    const tf = document.getElementById('paymentsTimeframe').value;
    let labels, data;
    if (tf === 'months') {
        labels = payments_months.labels;
        data = payments_months.data;
    } else if (tf === '30') {
        labels = payments_30.labels;
        data = payments_30.data;
    } else {
        labels = payments_7.labels;
        data = payments_7.data;
    }

    paymentsChart.data.labels = labels;
    paymentsChart.data.datasets[0].data = data;
    paymentsChart.update();
}

document.addEventListener('DOMContentLoaded', function(){
    const ctx1 = document.getElementById('paymentsChart').getContext('2d');
    const ctx2 = document.getElementById('activeChart').getContext('2d');

    paymentsChart = createPaymentsChart(ctx1, payments_months.labels, payments_months.data);
    activeChart = createActiveChart(ctx2, activeUsers.labels, activeUsers.data);
});
</script>

<style>
.main-content-wrapper { 
    margin-left: 260px; 
    background: #f8f9fa;
    min-height: 100vh;
}

@media(max-width: 900px) { 
    .main-content-wrapper { 
        margin-left: 0; 
    } 
}

.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 20px;
    margin-bottom: 20px;
}

.table th {
    font-weight: 600;
    font-size: 12px;
    color: #666;
    border-bottom: 1px solid #eee;
}

.table td {
    font-size: 13px;
    padding: 8px 12px;
    vertical-align: middle;
}

.badge {
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 4px;
}
</style>