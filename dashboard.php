<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Get ISP Profile
$profile = getISPProfile($db);
$days_left = getSubscriptionDaysLeft($profile['subscription_expiry']);

// Get statistics
$query = "SELECT 
    COUNT(*) as total_clients,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_clients,
    SUM(monthly_fee) as monthly_revenue
    FROM clients";
$stmt = $db->prepare($query);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent payments
$query = "SELECT p.*, c.full_name 
          FROM payments p 
          JOIN clients c ON p.client_id = c.id 
          ORDER BY p.payment_date DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get client status distribution
$query = "SELECT status, COUNT(*) as count FROM clients GROUP BY status";
$stmt = $db->prepare($query);
$stmt->execute();
$status_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Dashboard</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary">Export</button>
            </div>
        </div>
    </div>

    <!-- Subscription Alert -->
    <div class="alert alert-info d-flex align-items-center">
        <i class="fas fa-info-circle me-2 fa-lg"></i>
        <div>
            <strong>ISP Subscription:</strong> Your plan expires in <strong><?php echo $days_left; ?> days</strong> 
            (<?php echo date('M j, Y', strtotime($profile['subscription_expiry'])); ?>)
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-md-4">
            <div class="card stat-card bg-primary text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="card-title">Active Clients</h5>
                            <h2 class="card-text"><?php echo $stats['active_clients']; ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                    </div>
                    <p class="card-text">Subscribed: <?php echo $stats['total_clients']; ?> total</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-success text-white mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="card-title">Monthly Revenue</h5>
                            <h2 class="card-text">KES <?php echo number_format($stats['monthly_revenue'], 2); ?></h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-money-bill-wave fa-2x"></i>
                        </div>
                    </div>
                    <p class="card-text">Projected monthly income</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card bg-warning text-dark mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="card-title">Service Status</h5>
                            <h2 class="card-text">Operational</h2>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-server fa-2x"></i>
                        </div>
                    </div>
                    <p class="card-text">All systems normal</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Payments -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0"><i class="fas fa-history me-2"></i>Recent Payments</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['full_name']); ?></td>
                                    <td>KES <?php echo number_format($payment['amount'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo ucfirst($payment['payment_method']); ?></span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-success"><?php echo ucfirst($payment['status']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Status Distribution -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Client Status</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($status_distribution as $status): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-capitalize"><?php echo $status['status']; ?></span>
                            <span class="fw-bold"><?php echo $status['count']; ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar 
                                <?php 
                                switch($status['status']) {
                                    case 'active': echo 'bg-success'; break;
                                    case 'inactive': echo 'bg-warning'; break;
                                    case 'suspended': echo 'bg-danger'; break;
                                    default: echo 'bg-secondary';
                                }
                                ?>" 
                                style="width: <?php echo ($status['count'] / $stats['total_clients']) * 100; ?>%">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="clients.php?action=add" class="btn btn-primary btn-sm">Add New Client</a>
                        <a href="payments.php?action=add" class="btn btn-success btn-sm">Record Payment</a>
                        <a href="mikrotik.php" class="btn btn-info btn-sm">MikroTik Management</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>