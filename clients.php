<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$message = '';

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_client'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $mikrotik_username = trim($_POST['mikrotik_username']);
        $subscription_plan = trim($_POST['subscription_plan']);
        $data_limit = (int)$_POST['data_limit'];
        $download_speed = (int)$_POST['download_speed'];
        $upload_speed = (int)$_POST['upload_speed'];
        $monthly_fee = (float)$_POST['monthly_fee'];
        
        $query = "INSERT INTO clients (full_name, email, phone, address, mikrotik_username, subscription_plan, data_limit, download_speed, upload_speed, monthly_fee) 
                  VALUES (:full_name, :email, :phone, :address, :mikrotik_username, :subscription_plan, :data_limit, :download_speed, :upload_speed, :monthly_fee)";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':mikrotik_username', $mikrotik_username);
        $stmt->bindParam(':subscription_plan', $subscription_plan);
        $stmt->bindParam(':data_limit', $data_limit);
        $stmt->bindParam(':download_speed', $download_speed);
        $stmt->bindParam(':upload_speed', $upload_speed);
        $stmt->bindParam(':monthly_fee', $monthly_fee);
        
        if ($stmt->execute()) {
            $message = "Client added successfully!";
        } else {
            $message = "Error adding client. Please try again.";
        }
    }
}

// Get all clients
$query = "SELECT * FROM clients ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="col-md-9 col-lg-10 main-content">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Clients Management</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="?action=add" class="btn btn-primary">Add New Client</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($action == 'add'): ?>
        <!-- Add Client Form -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Add New Client</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="mikrotik_username" class="form-label">MikroTik Username *</label>
                                <input type="text" class="form-control" id="mikrotik_username" name="mikrotik_username" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone *</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="subscription_plan" class="form-label">Subscription Plan</label>
                                <select class="form-select" id="subscription_plan" name="subscription_plan">
                                    <option value="Basic">Basic</option>
                                    <option value="Standard">Standard</option>
                                    <option value="Premium">Premium</option>
                                    <option value="Custom">Custom</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="data_limit" class="form-label">Data Limit (MB)</label>
                                <input type="number" class="form-control" id="data_limit" name="data_limit" value="102400">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="monthly_fee" class="form-label">Monthly Fee (KES)</label>
                                <input type="number" step="0.01" class="form-control" id="monthly_fee" name="monthly_fee" value="1500.00">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="download_speed" class="form-label">Download Speed (Mbps)</label>
                                <input type="number" class="form-control" id="download_speed" name="download_speed" value="10">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="upload_speed" class="form-label">Upload Speed (Mbps)</label>
                                <input type="number" class="form-control" id="upload_speed" name="upload_speed" value="5">
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="clients.php" class="btn btn-secondary me-md-2">Cancel</a>
                        <button type="submit" name="add_client" class="btn btn-primary">Add Client</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- Clients List -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">All Clients</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Plan</th>
                                <th>Speed</th>
                                <th>Monthly Fee</th>
                                <th>Status</th>
                                <th>Last Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['full_name']); ?></strong><br>
                                    <small class="text-muted">@<?php echo htmlspecialchars($client['mikrotik_username']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($client['phone']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($client['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($client['subscription_plan']); ?></td>
                                <td>
                                    <small><?php echo $client['download_speed']; ?>↓ / <?php echo $client['upload_speed']; ?>↑ Mbps</small>
                                </td>
                                <td>KES <?php echo number_format($client['monthly_fee'], 2); ?></td>
                                <td>
                                    <span class="badge 
                                        <?php 
                                        switch($client['status']) {
                                            case 'active': echo 'bg-success'; break;
                                            case 'inactive': echo 'bg-warning'; break;
                                            case 'suspended': echo 'bg-danger'; break;
                                            default: echo 'bg-secondary';
                                        }
                                        ?>">
                                        <?php echo ucfirst($client['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($client['last_payment_date']): ?>
                                        <?php echo date('M j, Y', strtotime($client['last_payment_date'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?action=edit&id=<?php echo $client['id']; ?>" class="btn btn-outline-primary">Edit</a>
                                        <a href="payments.php?client_id=<?php echo $client['id']; ?>" class="btn btn-outline-success">Payment</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>