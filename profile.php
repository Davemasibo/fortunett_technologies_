<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$profile = getISPProfile($db);
$days_left = getSubscriptionDaysLeft($profile['subscription_expiry']);

// Get user profile
$query = "SELECT * FROM users WHERE id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="col-md-9 col-lg-10 main-content">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">ISP Profile & Settings</h1>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">ISP Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Business Name:</th>
                            <td><?php echo htmlspecialchars($profile['business_name']); ?></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><?php echo htmlspecialchars($profile['email']); ?></td>
                        </tr>
                        <tr>
                            <th>Phone:</th>
                            <td><?php echo htmlspecialchars($profile['phone']); ?></td>
                        </tr>
                        <tr>
                            <th>Subscription Expiry:</th>
                            <td>
                                <?php echo date('F j, Y', strtotime($profile['subscription_expiry'])); ?>
                                <span class="badge bg-<?php echo $days_left > 7 ? 'success' : 'warning'; ?> ms-2">
                                    <?php echo $days_left; ?> days left
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">User Account</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">Username:</th>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                        </tr>
                        <tr>
                            <th>Role:</th>
                            <td>
                                <span class="badge bg-primary"><?php echo ucfirst($user['role']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th>Member Since:</th>
                            <td><?php echo date('F j, Y', strtotime($user['created_at'])); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="fas fa-code fa-2x text-primary mb-2"></i>
                                <h6>Backend</h6>
                                <p class="mb-0">PHP <?php echo PHP_VERSION; ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="fas fa-database fa-2x text-success mb-2"></i>
                                <h6>Database</h6>
                                <p class="mb-0">MySQL</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-3 border rounded">
                                <i class="fas fa-shield-alt fa-2x text-warning mb-2"></i>
                                <h6>Security</h6>
                                <p class="mb-0">Encrypted Sessions</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>