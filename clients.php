<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$message = '';
$active_tab = $_GET['tab'] ?? 'all'; // Fixed: Added this missing variable

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

// Ensure expiry_date column exists
try {
    $db->query("SELECT expiry_date FROM clients LIMIT 1");
} catch (Exception $e) {
    try { $db->exec("ALTER TABLE clients ADD COLUMN expiry_date DATETIME NULL AFTER next_payment_date"); } catch (Exception $ignored) {}
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
        
        // Expiry date handling
        $expiry_input = trim($_POST['expiry_date'] ?? '');
        $expiry_date_sql = null;
        if ($expiry_input !== '') {
            try {
                $dt = new DateTime($expiry_input);
                $expiry_date_sql = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $expiry_date_sql = null;
            }
        }

        $query = "INSERT INTO clients (full_name, email, phone, address, mikrotik_username, subscription_plan, user_type, auth_password, status, expiry_date) 
                  VALUES (:full_name, :email, :phone, :address, :mikrotik_username, :subscription_plan, :user_type, :auth_password, :status, :expiry_date)";
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
        $stmt->bindParam(':expiry_date', $expiry_date_sql);

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
        
        // Expiry date handling on update
        $expiry_input = trim($_POST['expiry_date'] ?? '');
        $expiry_date_sql = null;
        if ($expiry_input !== '') {
            try {
                $dt = new DateTime($expiry_input);
                $expiry_date_sql = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $expiry_date_sql = null;
            }
        }

        try {
            $stmt = $db->prepare("UPDATE clients SET full_name = :full_name, email = :email, phone = :phone, address = :address, mikrotik_username = :mikrotik_username, subscription_plan = :subscription_plan, user_type = :user_type, auth_password = :auth_password, status = :status, expiry_date = :expiry_date WHERE id = :id");
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':mikrotik_username', $mikrotik_username);
            $stmt->bindParam(':subscription_plan', $subscription_plan);
            $stmt->bindParam(':user_type', $user_type);
            $stmt->bindParam(':auth_password', $auth_password);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':expiry_date', $expiry_date_sql);
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
    
    if (isset($_POST['toggle_connection']) && isset($_POST['id'])) {
        $cid = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("SELECT status FROM clients WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $cid);
            $stmt->execute();
            $curr = $stmt->fetchColumn();

            if ($curr === 'active') {
                // disconnect: mark inactive and set expiry to now
                $upd = $db->prepare("UPDATE clients SET status = 'inactive', expiry_date = NOW() WHERE id = :id");
                $upd->bindParam(':id', $cid);
                $upd->execute();
                $message = "Client disconnected successfully.";
            } else {
                // reconnect: mark active and give 30 days by default
                $upd = $db->prepare("UPDATE clients SET status = 'active', expiry_date = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id");
                $upd->bindParam(':id', $cid);
                $upd->execute();
                $message = "Client reconnected successfully (30 days granted).";
            }
        } catch (Exception $e) {
            $message = 'Error toggling client: ' . $e->getMessage();
        }
    }
}

// Get clients based on active tab
$query = "SELECT * FROM clients";
if ($active_tab === 'hotspot') {
    $query .= " WHERE user_type = 'hotspot'";
} elseif ($active_tab === 'pppoe') {
    $query .= " WHERE user_type = 'pppoe'";
}
$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to calculate session end from expiry date
function calculateSessionEnd($expiry_date) {
    if (!$expiry_date) return 'N/A';
    
    $expiry = new DateTime($expiry_date);
    $now = new DateTime();
    
    if ($expiry <= $now) {
        return 'Expired';
    }
    
    $interval = $now->diff($expiry);
    return $interval->format('%a days, %h hrs');
}

// Function to calculate days remaining
function calculateDaysRemaining($expiry_date) {
    if (!$expiry_date) return null;
    
    $expiry = new DateTime($expiry_date);
    $now = new DateTime();
    
    if ($expiry <= $now) {
        return 0;
    }
    
    $diffSeconds = $expiry->getTimestamp() - $now->getTimestamp();
    return round($diffSeconds / 86400, 1); // Return days with 1 decimal place
}

// --- added: server-side helper to render initial relative time ---
function timeAgoPHP($datetime) {
    if (!$datetime) return 'just now';
    try {
        $time = new DateTime($datetime);
    } catch (Exception $e) {
        return 'unknown';
    }
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();
    if ($diff < 0) $diff = 0;
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff/60) . ' minutes ago';
    if ($diff < 86400) return floor($diff/3600) . ' hours ago';
    return floor($diff/86400) . ' days ago';
}

// Function to get client IP/MAC (placeholder - integrate with your MikroTik API)
function getClientIpMac($client_id, $user_type) {
    // This is a placeholder - implement based on your MikroTik integration
    if ($user_type === 'pppoe') {
        return ['ip' => '192.168.1.' . (100 + $client_id), 'mac' => '00:1B:44:11:3A:' . sprintf('%02d', $client_id)];
    } else {
        return ['ip' => '10.0.0.' . (50 + $client_id), 'mac' => '00:1C:42:22:4B:' . sprintf('%02d', $client_id)];
    }
}
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
                        
                        <div class="row">
                            <?php
                                // Fetch available package names for selection
                                $pkgStmt = $db->query("SELECT name FROM packages ORDER BY name ASC");
                                $availablePackages = $pkgStmt ? $pkgStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                            ?>
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

                            <!-- Expiry date input for create -->
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date *</label>
                                    <input type="datetime-local" class="form-control" id="expiry_date" name="expiry_date" required value="<?php echo date('Y-m-d\TH:i', strtotime('+30 days')); ?>">
                                    <div class="form-text">Select the date/time this client's subscription will expire.</div>
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

                // Calculate days remaining and formatted expiry
                $days_remaining = null;
                $expiry_date = null;
                $expiry_text = 'No expiry';
                if ($client_view && !empty($client_view['expiry_date'])) {
                    try {
                        $expiry_date = new DateTime($client_view['expiry_date']);
                        $days_remaining = calculateDaysRemaining($client_view['expiry_date']);
                        $expiry_text = $expiry_date->format('F j, Y g:i A');
                    } catch (Exception $e) { /* ignore */ }
                }
                // package/name display
                $package_label = htmlspecialchars($client_view['subscription_plan'] ?? '—');
                $online_badge = (isset($client_view['status']) && $client_view['status'] === 'active') ? '<span style="background:#e9f8ee;color:#1e8e3e;border:1px solid #c9efda; padding:4px 10px; border-radius:999px; font-size:12px;">Currently Online</span>' : '';
            ?>

            <div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div>
                                <h2 style="margin:0; color:#333; font-weight:600;"><?php echo htmlspecialchars($client_view['full_name'] ?? $client_view['mikrotik_username']); ?></h2>
                                <div style="color:#666;font-size:13px;margin-top:6px;">
                                    Package: <?php echo $package_label; ?> • Expires: <?php echo htmlspecialchars($expiry_text); ?>
                                </div>
                            </div>
                            <div><?php echo $online_badge; ?></div>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="btn btn-outline-warning" onclick="alert('Pause/Resume action');">Pause Subscription</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('expiryModal').classList.add('show')">Change Expiry</button>
                        <a href="sms.php?client_id=<?php echo (int)$client_view['id']; ?>" class="btn btn-outline-primary">Send voucher</a>
                        <div class="dropdown">
                            <button class="btn btn-outline-dark dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="?action=edit&id=<?php echo (int)$client_view['id']; ?>">Edit</a></li>
                                <li><a class="dropdown-item" href="sms.php?client_id=<?php echo (int)$client_view['id']; ?>&template=credentials">Send SMS</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="if(confirm('Delete user?')){document.getElementById('deleteForm').submit();}">Delete user</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div style="background:white;padding:12px;border-radius:8px;margin-bottom:16px;display:flex;gap:18px;align-items:center;">
                <button class="tab-btn btn btn-link active" data-bs-target="#tab-general" data-tab>General Information</button>
                <button class="tab-btn btn btn-link" data-bs-target="#tab-reports" data-tab>Reports</button>
                <button class="tab-btn btn btn-link" data-bs-target="#tab-payments" data-tab>Payments</button>
                <button class="tab-btn btn btn-link" data-bs-target="#tab-sessions" data-tab>Sessions</button>
            </div>

            <!-- Tab Panes -->
            <div id="tab-general" class="tab-pane-custom active">
                <div style="background:white; padding:0; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
                    <div style="padding:14px 18px; border-bottom:1px solid #eee; font-weight:600;">Account Information</div>
                    <div style="padding:14px;">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div style="font-size:11px;color:#888; text-transform:uppercase;">Account Number</div>
                                        <div style="font-weight:600;">F<?php echo 4900 + (int)$client_view['id']; ?></div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-light" onclick="navigator.clipboard?.writeText('F<?php echo 4900 + (int)$client_view['id']; ?>')">Copy</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div style="font-size:11px;color:#888; text-transform:uppercase;">Full Name</div>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($client_view['full_name'] ?? '—'); ?></div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-light" onclick="navigator.clipboard?.writeText('<?php echo htmlspecialchars($client_view['full_name'] ?? '', ENT_QUOTES); ?>')">Copy</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div style="font-size:11px;color:#888; text-transform:uppercase;">Username</div>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($client_view['mikrotik_username'] ?? '—'); ?></div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-light" onclick="navigator.clipboard?.writeText('<?php echo htmlspecialchars($client_view['mikrotik_username'] ?? '', ENT_QUOTES); ?>')">Copy</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div style="font-size:11px;color:#888; text-transform:uppercase;">Password</div>
                                        <div style="font-weight:600;"><span id="pwdHidden">••••••••</span><span id="pwdValue" style="display:none;"><?php echo htmlspecialchars($client_view['auth_password'] ?? ''); ?></span></div>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-link me-2 p-0" onclick="(function(){const h=document.getElementById('pwdHidden'), v=document.getElementById('pwdValue'); if(!h||!v) return; const show = v.style.display==='none'; v.style.display=show?'inline':'none'; h.style.display=show?'none':'inline';})();" type="button" title="Show/Hide"><i class="fas fa-eye" id="pwdEye"></i></button>
                                        <button class="btn btn-sm btn-outline-light" onclick="navigator.clipboard?.writeText(document.getElementById('pwdValue').textContent)" type="button">Copy</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Package</div>
                                    <div style="font-weight:600;"><?php echo $package_label; ?></div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Status</div>
                                    <div style="font-weight:600;"><?php echo ucfirst($client_view['status'] ?? '—'); ?> <?php echo ($client_view['status'] === 'active') ? '<span style="color:#1e8e3e;">• Online</span>' : ''; ?></div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">User Type</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars(ucfirst($client_view['user_type'] ?? '')); ?></div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Phone Number</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($client_view['phone'] ?? '—'); ?></div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Time Remaining</div>
                                    <div style="font-weight:600;" id="timeRemaining"><?php echo $days_remaining !== null ? $days_remaining . ' days' : '—'; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Simple placeholders for other tabs -->
            <div id="tab-reports" class="tab-pane-custom" style="display:none;">
                <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No reports implemented yet.</div>
            </div>
            <div id="tab-payments" class="tab-pane-custom" style="display:none;">
                <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No payments displayed yet.</div>
            </div>
            <div id="tab-sessions" class="tab-pane-custom" style="display:none;">
                <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No sessions displayed yet.</div>
            </div>

            <!-- Change Expiry Modal (simple bootstrap modal structure kept minimal) -->
            <div class="modal fade" id="expiryModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">
                  <form onsubmit="event.preventDefault(); document.getElementById('expiryForm').submit();">
                    <div class="modal-header">
                      <h5 class="modal-title">Change Expiry</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="document.getElementById('expiryModal').classList.remove('show')"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Expiry Date</label>
                        <input type="datetime-local" id="expiryPickerLocal" class="form-control" value="<?php echo $expiry_date ? $expiry_date->format('Y-m-d\TH:i') : date('Y-m-d\TH:i', strtotime('+30 days')); ?>">
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="document.getElementById('expiryModal').classList.remove('show')">Cancel</button>
                      <button type="button" class="btn btn-success" onclick="changeExpiry(<?php echo (int)$client_view['id']; ?>)">Save</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- hidden delete form (used by actions) -->
            <form id="deleteForm" method="POST" style="display:none;">
                <input type="hidden" name="id" value="<?php echo (int)$client_view['id']; ?>">
                <input type="hidden" name="delete_client" value="1">
            </form>

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

                            <!-- Expiry date input for edit -->
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date *</label>
                                    <input type="datetime-local" class="form-control" id="expiry_date" name="expiry_date" required 
                                           value="<?php echo !empty($edit_client['expiry_date']) ? date('Y-m-d\TH:i', strtotime($edit_client['expiry_date'])) : date('Y-m-d\TH:i', strtotime('+30 days')); ?>">
                                    <div class="form-text">Set when this client's subscription will expire.</div>
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
            <!-- Clients List with Tabs -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Clients</h5>
                </div>
                <div class="card-body">
                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs" id="clientsTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $active_tab === 'all' ? 'active' : ''; ?>" 
                                    id="all-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#all" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="all" 
                                    aria-selected="<?php echo $active_tab === 'all' ? 'true' : 'false'; ?>"
                                    onclick="window.location.href='?tab=all'">
                                All Clients
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $active_tab === 'hotspot' ? 'active' : ''; ?>" 
                                    id="hotspot-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#pppoe" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="hotspot" 
                                    aria-selected="<?php echo $active_tab === 'hotspot' ? 'true' : 'false'; ?>"
                                    onclick="window.location.href='?tab=hotspot'">
                                Hotspot Clients
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $active_tab === 'pppoe' ? 'active' : ''; ?>" 
                                    id="pppoe-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#all" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="pppoe" 
                                    aria-selected="<?php echo $active_tab === 'pppoe' ? 'true' : 'false'; ?>"
                                    onclick="window.location.href='?tab=pppoe'">
                                PPPoE Clients
                            </button>
                        </li>
                    </ul>

                    <!-- Tabs Content -->
                    <div class="tab-content" id="clientsTabContent">
                        <!-- All Clients Tab -->
                        <div class="tab-pane fade <?php echo $active_tab === 'all' ? 'show active' : ''; ?>" 
                             id="all" 
                             role="tabpanel" 
                             aria-labelledby="all-tab">
                            <div class="table-responsive mt-3">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>IP/MAC</th>
                                            <th>Session Start</th>
                                            <th>Session End</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clients as $client): 
                                            $ip_mac = getClientIpMac($client['id'], $client['user_type']);
                                            $session_end = calculateSessionEnd($client['expiry_date'] ?? '');
                                            // session start source (use created_at or fallback)
                                            $session_start_raw = $client['created_at'] ?? date('Y-m-d H:i:s');
                                            $session_start_iso = date('c', strtotime($session_start_raw));
                                            $session_start_label = timeAgoPHP($session_start_raw);
                                        ?>
                                        <tr class="client-row" data-client-id="<?php echo $client['id']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($client['mikrotik_username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($client['full_name']); ?></small>
                                            </td>
                                            <td>
                                                <div><strong>IP:</strong> <?php echo $ip_mac['ip']; ?></div>
                                                <div><strong>MAC:</strong> <?php echo $ip_mac['mac']; ?></div>
                                            </td>
                                            <td>
                                                <span class="relative-time" data-start="<?php echo $session_start_iso; ?>"><?php echo $session_start_label; ?></span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $session_end === 'Expired' ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo $session_end; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo strtoupper($client['user_type']); ?></span>
                                            </td>
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
                                                <div class="btn-group btn-group-sm">
                                                    <form method="POST" onsubmit="return confirm('<?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?> this client?');" style="display:inline-block; margin-left:6px;" onclick="event.stopPropagation();">
                                                        <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                                        <button type="submit" name="toggle_connection" class="btn <?php echo ($client['status']==='active') ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                            <?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Hotspot Clients Tab -->
                        <div class="tab-pane fade <?php echo $active_tab === 'hotspot' ? 'show active' : ''; ?>" 
                             id="hotspot" 
                             role="tabpanel" 
                             aria-labelledby="hotspot-tab">
                            <div class="table-responsive mt-3">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>IP/MAC</th>
                                            <th>Session Start</th>
                                            <th>Session End</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $hotspot_clients = array_filter($clients, function($client) {
                                            return $client['user_type'] === 'hotspot';
                                        });
                                        foreach ($hotspot_clients as $client): 
                                            $ip_mac = getClientIpMac($client['id'], 'hotspot');
                                            $session_end = calculateSessionEnd($client['expiry_date'] ?? '');
                                            $session_start_raw = $client['created_at'] ?? date('Y-m-d H:i:s');
                                            $session_start_iso = date('c', strtotime($session_start_raw));
                                            $session_start_label = timeAgoPHP($session_start_raw);
                                        ?>
                                        <tr class="client-row" data-client-id="<?php echo $client['id']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($client['mikrotik_username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($client['full_name']); ?></small>
                                            </td>
                                            <td>
                                                <div><strong>IP:</strong> <?php echo $ip_mac['ip']; ?></div>
                                                <div><strong>MAC:</strong> <?php echo $ip_mac['mac']; ?></div>
                                            </td>
                                            <td>
                                                <span class="relative-time" data-start="<?php echo $session_start_iso; ?>"><?php echo $session_start_label; ?></span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $session_end === 'Expired' ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo $session_end; ?>
                                                </span>
                                            </td>
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
                                                <div class="btn-group btn-group-sm">
                                                    <form method="POST" onsubmit="return confirm('<?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?> this client?');" style="display:inline-block; margin-left:6px;" onclick="event.stopPropagation();">
                                                        <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                                        <button type="submit" name="toggle_connection" class="btn <?php echo ($client['status']==='active') ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                            <?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- PPPoE Clients Tab -->
                        <div class="tab-pane fade <?php echo $active_tab === 'pppoe' ? 'show active' : ''; ?>" 
                             id="pppoe" 
                             role="tabpanel" 
                             aria-labelledby="pppoe-tab">
                            <div class="table-responsive mt-3">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>IP/MAC</th>
                                            <th>Session Start</th>
                                            <th>Session End</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $pppoe_clients = array_filter($clients, function($client) {
                                            return $client['user_type'] === 'pppoe';
                                        });
                                        foreach ($pppoe_clients as $client): 
                                            $ip_mac = getClientIpMac($client['id'], 'pppoe');
                                            $session_end = calculateSessionEnd($client['expiry_date'] ?? '');
                                            $session_start_raw = $client['created_at'] ?? date('Y-m-d H:i:s');
                                            $session_start_iso = date('c', strtotime($session_start_raw));
                                            $session_start_label = timeAgoPHP($session_start_raw);
                                        ?>
                                        <tr class="client-row" data-client-id="<?php echo $client['id']; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($client['mikrotik_username']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($client['full_name']); ?></small>
                                            </td>
                                            <td>
                                                <div><strong>IP:</strong> <?php echo $ip_mac['ip']; ?></div>
                                                <div><strong>MAC:</strong> <?php echo $ip_mac['mac']; ?></div>
                                            </td>
                                            <td>
                                                <span class="relative-time" data-start="<?php echo $session_start_iso; ?>"><?php echo $session_start_label; ?></span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $session_end === 'Expired' ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo $session_end; ?>
                                                </span>
                                            </td>
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
                                                <div class="btn-group btn-group-sm">
                                                    <form method="POST" onsubmit="return confirm('<?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?> this client?');" style="display:inline-block; margin-left:6px;" onclick="event.stopPropagation();">
                                                        <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
                                                        <button type="submit" name="toggle_connection" class="btn <?php echo ($client['status']==='active') ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                            <?php echo ($client['status']==='active') ? 'Disconnect' : 'Reconnect'; ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div> {
                </div>return Math.floor(diffSec) + ' seconds ago';
            </div>3600) return Math.floor(diffSec/60) + ' minutes ago';
        <?php endif; ?>) return Math.floor(diffSec/3600) + ' hours ago';
    </div> Math.floor(diffSec/86400) + ' days ago';
</div>

<?php include 'includes/footer.php'; ?>

<!-- Clean JS: live relative-time updater + row click init + tabs + expiry update -->
<script>
(function(){
  const fmt = (diffSec) => {
    if (diffSec < 60) return Math.floor(diffSec) + ' seconds ago';
    if (diffSec < 3600) return Math.floor(diffSec/60) + ' minutes ago';
    if (diffSec < 86400) return Math.floor(diffSec/3600) + ' hours ago';
    return Math.floor(diffSec/86400) + ' days ago';
  };

  function updateAll() {
    const els = document.querySelectorAll('.relative-time');
    const now = Date.now();
    els.forEach(el => {
      const start = el.getAttribute('data-start');
      if (!start) return;
      const t = Date.parse(start);
      if (isNaN(t)) return;
      let diffSec = Math.floor((now - t) / 1000);
      if (diffSec < 0) diffSec = 0;
      el.textContent = fmt(diffSec);
    });

    // update timeRemaining (if present) every minute
    const tr = document.getElementById('timeRemaining');
    if (tr) {
      // leave server-calculated days value as-is; could add auto-decrement if desired
    }
  }

  function initRowClicks() {
    document.querySelectorAll('.client-row').forEach(row=>{
      if (row._clickInit) return;
      row._clickInit = true;
      row.addEventListener('click', function(e){
        // if click originated inside a form/button, don't navigate
        if (e.target.closest('form') || e.target.closest('button')) return;
        const id = this.getAttribute('data-client-id');
        if (!id) return;
        window.location.href = '?action=view&id=' + encodeURIComponent(id);
      });
    });
  }

  // Tabs handler (simple)
  function initTabs() {
    document.querySelectorAll('[data-tab]').forEach(btn=>{
      btn.addEventListener('click', (e)=>{
        document.querySelectorAll('[data-tab]').forEach(x=>x.classList.remove('active'));
        e.currentTarget.classList.add('active');
        const target = e.currentTarget.getAttribute('data-bs-target');
        document.querySelectorAll('.tab-pane-custom').forEach(p=>p.style.display='none');
        const pane = document.querySelector(target);
        if (pane) pane.style.display = 'block';
      });
    });
  }

  // Change expiry via POST (simple)
  window.changeExpiry = function(clientId) {
    const val = document.getElementById('expiryPickerLocal').value;
    if (!val) return alert('Pick a date');
    const payload = new FormData();
    payload.append('id', clientId);
    payload.append('expiry_date', val);
    // reuse update_client endpoint via fetch for simplicity
    fetch(location.pathname, { method:'POST', body: payload })
      .then(()=> location.reload())
      .catch(()=> alert('Failed to save expiry'));
  };

  document.addEventListener('DOMContentLoaded', function(){
    updateAll();
    initRowClicks();
    initTabs();
    setInterval(updateAll, 30000);
  });
})();
</script>

<style>
/* make rows look clickable */
.table-hover tbody tr.client-row { cursor: pointer; }
.table-hover tbody tr.client-row .btn { cursor: pointer; }
</style>