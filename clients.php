<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$message = '';

// Ensure clients.user_type column exists (runtime safe migration)
try {
    $db->query("SELECT user_type FROM clients LIMIT 1");
} catch (Exception $e) {
    try {
        $db->exec("ALTER TABLE clients ADD COLUMN user_type ENUM('pppoe','hotspot') DEFAULT 'pppoe' AFTER mikrotik_username");
    } catch (Exception $ignored) {}
}

// Ensure clients.auth_password exists
try {
    $db->query("SELECT auth_password FROM clients LIMIT 1");
} catch (Exception $e) {
    try { $db->exec("ALTER TABLE clients ADD COLUMN auth_password VARCHAR(100) NULL AFTER user_type"); } catch (Exception $ignored) {}
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_client'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $mikrotik_username = trim($_POST['mikrotik_username']);
$subscription_plan = trim($_POST['subscription_plan']);
        $user_type = $_POST['user_type'] ?? 'pppoe';
        $auth_password = trim($_POST['auth_password'] ?? '');
        
        $query = "INSERT INTO clients (full_name, email, phone, address, mikrotik_username, subscription_plan, user_type, auth_password, status) 
                  VALUES (:full_name, :email, :phone, :address, :mikrotik_username, :subscription_plan, :user_type, :auth_password, :status)";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':mikrotik_username', $mikrotik_username);
        $stmt->bindParam(':subscription_plan', $subscription_plan);
        $stmt->bindParam(':user_type', $user_type);
        $stmt->bindParam(':auth_password', $auth_password);
        $status = ($_POST['user_type'] === 'pppoe' || $_POST['user_type'] === 'hotspot') ? 'active' : 'inactive';
        $stmt->bindParam(':status', $status);
        
        try {
            if ($stmt->execute()) {
                $message = "Client added successfully!";
            } else {
                $message = "Error adding client. Please try again.";
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
    if (isset($_POST['update_client'])) {
        $id = (int)$_POST['id'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $mikrotik_username = trim($_POST['mikrotik_username']);
        $subscription_plan = trim($_POST['subscription_plan']);
        $user_type = $_POST['user_type'] ?? 'pppoe';
        $status = $_POST['status'] ?? 'inactive';
        $auth_password = trim($_POST['auth_password'] ?? '');

        try {
            $stmt = $db->prepare("UPDATE clients SET full_name = :full_name, email = :email, phone = :phone, address = :address, mikrotik_username = :mikrotik_username, subscription_plan = :subscription_plan, user_type = :user_type, auth_password = :auth_password, status = :status WHERE id = :id");
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':mikrotik_username', $mikrotik_username);
            $stmt->bindParam(':subscription_plan', $subscription_plan);
            $stmt->bindParam(':user_type', $user_type);
            $stmt->bindParam(':auth_password', $auth_password);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $message = "Client updated successfully!";
            $action = '';
        } catch (Exception $e) {
            $message = 'Error updating client: ' . $e->getMessage();
        }
    }
    if (isset($_POST['send_sms'])) {
        $client_id = (int)$_POST['id'];
        $text = trim($_POST['text'] ?? '');
        // Talksasa integration point: send $text to client's phone
        $stmt = $db->prepare("SELECT phone FROM clients WHERE id = :id");
        $stmt->bindParam(':id', $client_id);
        $stmt->execute();
        $to = $stmt->fetchColumn();
        // TODO: Implement API call using credentials saved in sms.php/settings
        $message = 'SMS queued to ' . htmlspecialchars($to);
        $action = 'view';
        $_GET['id'] = (string)$client_id;
    }
    if (isset($_POST['delete_client']) && isset($_POST['id'])) {
        try {
            $stmt = $db->prepare("DELETE FROM clients WHERE id = :id");
            $stmt->bindParam(':id', $_POST['id']);
            $stmt->execute();
            $message = "Client deleted successfully.";
        } catch (Exception $e) {
            $message = 'Error deleting client: ' . $e->getMessage();
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

<div class="main-content-wrapper">
    <div style="padding: 30px;">
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
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="user_type" class="form-label">User Type *</label>
                                <select class="form-select" id="user_type" name="user_type" required>
                                    <option value="pppoe">PPPoE</option>
                                    <option value="hotspot">Hotspot</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="auth_password" class="form-label">Password *</label>
                                <input type="text" class="form-control" id="auth_password" name="auth_password" placeholder="Set client password" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address">
                            </div>
                        </div>
                    </div>
                    
                    <?php
                        // Fetch available package names for selection
                        $pkgStmt = $db->query("SELECT name FROM packages ORDER BY name ASC");
                        $availablePackages = $pkgStmt ? $pkgStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                    ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="subscription_plan" class="form-label">Package</label>
                                <select class="form-select" id="subscription_plan" name="subscription_plan">
                                    <?php foreach ($availablePackages as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                    <?php endforeach; ?>
                                </select>
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
    <?php elseif ($action == 'view' && isset($_GET['id'])): ?>
        <?php
            $viewId = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $viewId);
            $stmt->execute();
            $client_view = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><?php echo htmlspecialchars($client_view['full_name']); ?>
                    <small class="text-muted">(<?php echo strtoupper($client_view['user_type'] ?? ''); ?>)</small>
                </h5>
                <div>
                    <a href="?action=edit&id=<?php echo $client_view['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                    <a href="payments.php?client_id=<?php echo $client_view['id']; ?>" class="btn btn-sm btn-success">Payments</a>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Account Number</label>
                        <div class="form-control">F<?php echo 4900 + (int)$client_view['id']; ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['full_name']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['mikrotik_username']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <div class="form-control"><?php echo ucfirst($client_view['status']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Package</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['subscription_plan']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['phone']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['email']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <div class="form-control"><?php echo htmlspecialchars($client_view['address']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" value="<?php echo htmlspecialchars($client_view['auth_password'] ?? ''); ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="this.previousElementSibling.type = this.previousElementSibling.type==='password'?'text':'password'">Show</button>
                        </div>
                    </div>
                </div>
                <hr>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="id" value="<?php echo $client_view['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Send SMS</label>
                            <textarea name="text" rows="2" class="form-control" placeholder="Choose a template or write a message"></textarea>
                            <div class="form-text">Templates: credentials, payment reminder, expiry notice.</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" name="send_sms" class="btn btn-primary w-100">Send SMS</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif ($action == 'edit' && isset($_GET['id'])): ?>
        <?php
            $editId = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $editId);
            $stmt->execute();
            $edit_client = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <!-- Edit Client Form -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Edit Client</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="id" value="<?php echo $edit_client['id']; ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($edit_client['full_name']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="mikrotik_username" class="form-label">MikroTik Username *</label>
                                <input type="text" class="form-control" id="mikrotik_username" name="mikrotik_username" value="<?php echo htmlspecialchars($edit_client['mikrotik_username']); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($edit_client['email']); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone *</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($edit_client['phone']); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="user_type" class="form-label">User Type *</label>
                                <select class="form-select" id="user_type" name="user_type" required>
                                    <option value="pppoe" <?php echo ($edit_client['user_type'] ?? '')==='pppoe'?'selected':''; ?>>PPPoE</option>
                                    <option value="hotspot" <?php echo ($edit_client['user_type'] ?? '')==='hotspot'?'selected':''; ?>>Hotspot</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="auth_password" class="form-label">Password *</label>
                                <input type="text" class="form-control" id="auth_password" name="auth_password" value="<?php echo htmlspecialchars($edit_client['auth_password'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($edit_client['address']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <?php
                            $pkgStmt2 = $db->query("SELECT name FROM packages ORDER BY name ASC");
                            $availablePackages2 = $pkgStmt2 ? $pkgStmt2->fetchAll(PDO::FETCH_COLUMN) : [];
                        ?>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="subscription_plan" class="form-label">Package</label>
                                <select class="form-select" id="subscription_plan" name="subscription_plan">
                                    <?php foreach ($availablePackages2 as $p): $sel = ($edit_client['subscription_plan'] === $p) ? 'selected' : ''; ?>
                                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($p); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php $statuses=['active','inactive','suspended']; foreach($statuses as $s){ $sel = ($edit_client['status']===$s)?'selected':''; echo "<option value=\"$s\" $sel>".ucfirst($s)."</option>"; } ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="clients.php" class="btn btn-secondary me-md-2">Cancel</a>
                        <button type="submit" name="update_client" class="btn btn-primary">Update Client</button>
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
                                <th>Package</th>
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
                                    <a href="?action=view&id=<?php echo $client['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                    </a><br>
                                    <small class="text-muted">@<?php echo htmlspecialchars($client['mikrotik_username']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($client['phone']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($client['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($client['subscription_plan']); ?></td>
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
                                        <form method="POST" onsubmit="return confirm('Delete this client?');" style="display:inline-block; margin-left:6px;">
                                            <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                            <button type="submit" name="delete_client" class="btn btn-outline-danger">Delete</button>
                                        </form>
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