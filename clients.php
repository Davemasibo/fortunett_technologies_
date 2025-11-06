<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$message = '';
$active_tab = $_GET['tab'] ?? 'all';

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
    if (isset($_POST['change_expiry']) && isset($_POST['id'])) {
        $cid = (int)$_POST['id'];
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
            $stmt = $db->prepare("UPDATE clients SET expiry_date = :expiry_date WHERE id = :id");
            $stmt->execute([':expiry_date' => $expiry_date_sql, ':id' => $cid]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'expiry_date' => $expiry_date_sql]);
                exit;
            }
            $message = "Expiry updated successfully.";
        } catch (Exception $e) {
            $message = "Error updating expiry: " . $e->getMessage();
        }
    }

    if (isset($_POST['delete_client']) && isset($_POST['id'])) {
        $did = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("DELETE FROM clients WHERE id = :id");
            $stmt->execute([':id' => $did]);
            header('Location: clients.php?deleted=1');
            exit;
        } catch (Exception $e) {
            $message = 'Error deleting client: ' . $e->getMessage();
        }
    }

    if (isset($_POST['add_client'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $mikrotik_username = trim($_POST['mikrotik_username']);
        $subscription_plan = trim($_POST['subscription_plan']);
        $user_type = $_POST['user_type'] ?? 'pppoe';
        $auth_password = trim($_POST['auth_password'] ?? '');
        
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
        $stmt = $db->prepare("SELECT phone FROM clients WHERE id = :id");
        $stmt->bindParam(':id', $client_id);
        $stmt->execute();
        $to = $stmt->fetchColumn();
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
                $upd = $db->prepare("UPDATE clients SET status = 'inactive', expiry_date = NOW() WHERE id = :id");
                $upd->bindParam(':id', $cid);
                $upd->execute();
                $message = "Client disconnected successfully.";
            } else {
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

// Calculate counts for tabs
try {
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE user_type = ?");
    $stmt->execute(['hotspot']); 
    $hotspotCount = (int)$stmt->fetchColumn();
    $stmt->execute(['pppoe']); 
    $pppoeCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $totalCount = $hotspotCount = $pppoeCount = 0;
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
    return round($diffSeconds / 86400, 1);
}

// Function to get client IP/MAC (placeholder)
function getClientIpMac($client_id, $user_type) {
    if ($user_type === 'pppoe') {
        return ['ip' => '192.168.1.' . (100 + $client_id), 'mac' => '00:1B:44:11:3A:' . sprintf('%02d', $client_id)];
    } else {
        return ['ip' => '10.0.0.' . (50 + $client_id), 'mac' => '00:1C:42:22:4B:' . sprintf('%02d', $client_id)];
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- MAIN TITLE + ACTION -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;">
            <div>
                <h1 style="margin:0; font-size:34px; color:#222; font-weight:700;">Clients</h1>
                <div style="color:#666;font-size:14px;margin-top:6px;">Manage and monitor all client accounts and connections</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <div style="position:relative;">
                    <input id="clientSearch" type="search" placeholder="Search clients" style="padding:8px 12px;border:1px solid #e6e9ed;border-radius:8px;width:260px;">
                </div>
                <select id="perPage" class="form-select" style="width:120px;padding:8px;border-radius:8px;">
                    <option value="10">Per page: 10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Add Client</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- CATEGORY PILLS -->
        <ul class="nav nav-pills mb-3" style="gap:8px;">
            <li class="nav-item"><a class="nav-link <?php echo $active_tab==='all' ? 'active' : ''; ?>" href="?tab=all">All Clients <span class="badge bg-light text-muted ms-2"><?php echo $totalCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab==='hotspot' ? 'active' : ''; ?>" href="?tab=hotspot">Hotspot <span class="badge bg-light text-muted ms-2"><?php echo $hotspotCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $active_tab==='pppoe' ? 'active' : ''; ?>" href="?tab=pppoe">PPPoE <span class="badge bg-light text-muted ms-2"><?php echo $pppoeCount ?? 0; ?></span></a></li>
        </ul>

        <?php if ($action == 'add'): ?>
            <!-- Add Client Form -->
            <div style="background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,0.04);overflow:hidden;">
                <div style="padding:20px;border-bottom:1px solid #f0f0f0;">
                    <h2 style="margin:0;font-size:22px;color:#333;">Add New Client</h2>
                </div>
                <div style="padding:20px;">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="mikrotik_username" class="form-label">MikroTik Username *</label>
                                    <input type="text" class="form-control" id="mikrotik_username" name="mikrotik_username" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone *</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="user_type" class="form-label">User Type *</label>
                                    <select class="form-select" id="user_type" name="user_type" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                        <option value="pppoe">PPPoE</option>
                                        <option value="hotspot">Hotspot</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="auth_password" class="form-label">Password *</label>
                                    <input type="text" class="form-control" id="auth_password" name="auth_password" placeholder="Set client password" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <input type="text" class="form-control" id="address" name="address" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <?php
                                $pkgStmt = $db->query("SELECT name FROM packages ORDER BY name ASC");
                                $availablePackages = $pkgStmt ? $pkgStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                            ?>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subscription_plan" class="form-label">Package</label>
                                    <select class="form-select" id="subscription_plan" name="subscription_plan" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                        <?php foreach ($availablePackages as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date *</label>
                                    <input type="datetime-local" class="form-control" id="expiry_date" name="expiry_date" required value="<?php echo date('Y-m-d\TH:i', strtotime('+30 days')); ?>" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                    <div class="form-text">Select the date/time this client's subscription will expire.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                            <a href="clients.php" class="btn btn-secondary" style="padding:10px 20px;">Cancel</a>
                            <button type="submit" name="add_client" class="btn btn-primary" style="padding:10px 20px;">Add Client</button>
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

                $days_remaining = null;
                $expiry_date = null;
                $expiry_text = 'No expiry';
                if ($client_view && !empty($client_view['expiry_date'])) {
                    try {
                        $expiry_date = new DateTime($client_view['expiry_date']);
                        $days_remaining = calculateDaysRemaining($client_view['expiry_date']);
                        $expiry_text = $expiry_date->format('F j, Y g:i A');
                    } catch (Exception $e) { }
                }
                $package_label = htmlspecialchars($client_view['subscription_plan'] ?? '—');
                $online_badge = (isset($client_view['status']) && $client_view['status'] === 'active') ? '<span style="background:#e6f6ef;color:#0a6;border-radius:6px;padding:4px 8px;font-weight:700;font-size:12px;">Currently Online</span>' : '';
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

            <!-- Change Expiry Modal -->
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

            <!-- hidden delete form -->
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
            <div style="background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,0.04);overflow:hidden;">
                <div style="padding:20px;border-bottom:1px solid #f0f0f0;">
                    <h2 style="margin:0;font-size:22px;color:#333;">Edit Client</h2>
                </div>
                <div style="padding:20px;">
                    <form method="POST" action="">
                        <input type="hidden" name="id" value="<?php echo $edit_client['id']; ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($edit_client['full_name']); ?>" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="mikrotik_username" class="form-label">MikroTik Username *</label>
                                    <input type="text" class="form-control" id="mikrotik_username" name="mikrotik_username" value="<?php echo htmlspecialchars($edit_client['mikrotik_username']); ?>" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($edit_client['email']); ?>" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone *</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($edit_client['phone']); ?>" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="user_type" class="form-label">User Type *</label>
                                    <select class="form-select" id="user_type" name="user_type" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                        <option value="pppoe" <?php echo ($edit_client['user_type'] ?? '')==='pppoe'?'selected':''; ?>>PPPoE</option>
                                        <option value="hotspot" <?php echo ($edit_client['user_type'] ?? '')==='hotspot'?'selected':''; ?>>Hotspot</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="auth_password" class="form-label">Password *</label>
                                    <input type="text" class="form-control" id="auth_password" name="auth_password" value="<?php echo htmlspecialchars($edit_client['auth_password'] ?? ''); ?>" required style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($edit_client['address']); ?>" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
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
                                    <select class="form-select" id="subscription_plan" name="subscription_plan" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                        <?php foreach ($availablePackages2 as $p): $sel = ($edit_client['subscription_plan'] === $p) ? 'selected' : ''; ?>
                                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($p); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date *</label>
                                    <input type="datetime-local" class="form-control" id="expiry_date" name="expiry_date" required 
                                           value="<?php echo !empty($edit_client['expiry_date']) ? date('Y-m-d\TH:i', strtotime($edit_client['expiry_date'])) : date('Y-m-d\TH:i', strtotime('+30 days')); ?>" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                    <div class="form-text">Set when this client's subscription will expire.</div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status" style="padding:10px;border:1px solid #ddd;border-radius:6px;">
                                        <?php $statuses=['active','inactive','suspended']; foreach($statuses as $s){ $sel = ($edit_client['status']===$s)?'selected':''; echo "<option value=\"$s\" $sel>".ucfirst($s)."</option>"; } ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                            <a href="clients.php" class="btn btn-secondary" style="padding:10px 20px;">Cancel</a>
                            <button type="submit" name="update_client" class="btn btn-primary" style="padding:10px 20px;">Update Client</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Clients Table View -->
            <div style="background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,0.04);overflow:hidden;">
                <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;">
                    <div style="font-weight:700;color:#444;">Clients</div>
                    <div style="color:#888;font-size:13px;">Showing clients — <?php echo htmlspecialchars($active_tab); ?></div>
                </div>

                <div style="padding:0 16px 16px 16px;">
                    <div class="table-responsive" style="margin-top:12px;">
                        <table id="clientsTable" class="table table-hover" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#fafafa;">
                                    <th style="width:40px;"><input type="checkbox" id="selectAllClients"></th>
                                    <th style="width: 25%;">Client</th>
                                    <th style="width: 15%;">Username</th>
                                    <th style="width: 12%;">Phone</th>
                                    <th style="width: 15%;">Package</th>
                                    <th style="width: 10%;">Type</th>
                                    <th style="width: 8%;">Status</th>
                                    <th style="width: 15%; text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="clientsTbody">
                                <?php foreach ($clients as $client): 
                                    $status = strtolower($client['status'] ?? 'active');
                                    $status_color = $status === 'active' ? '#10b981' : ($status === 'paused' ? '#f59e0b' : '#ef4444');
                                    $expiry_date = $client['expiry_date'] ?? null;
                                    $is_expired = $expiry_date && strtotime($expiry_date) < time();
                                ?>
                                <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s; cursor: pointer;">
                                    <td style="padding: 12px 8px; text-align: left;" onclick="event.stopPropagation();"><input type="checkbox" /></td>
                                    <td style="padding: 12px 8px; text-align: left;">
                                        <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($client['username'] ?? ''); ?></div>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: left;">
                                        <div style="font-weight: 500; color: #333;"><?php echo htmlspecialchars($client['full_name'] ?? ''); ?></div>
                                        <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($client['email'] ?? ''); ?></div>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: left; color: #333; font-size: 13px;">
                                        <?php echo htmlspecialchars($client['phone_number'] ?? '—'); ?>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: left; color: #333; font-size: 13px;">
                                        <?php echo htmlspecialchars($client['package_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: left;">
                                        <span style="background: <?php echo $status_color; ?>20; color: <?php echo $status_color; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; border: 1px solid rgba(0,0,0,0.04); text-transform: capitalize;">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: left; color: #333; font-size: 13px;">
                                        <?php 
                                            if ($expiry_date) {
                                                echo date('M j, Y', strtotime($expiry_date));
                                                if ($is_expired) echo ' <span style="color: #ef4444; font-weight: 600;">(Expired)</span>';
                                            } else {
                                                echo '—';
                                            }
                                        ?>
                                    </td>
                                    <td style="padding: 12px 8px; text-align: right;" onclick="event.stopPropagation();">
                                        <a href="user_detail.php?id=<?php echo $client['id']; ?>" style="color: #667eea; text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s;">
                                            <i class="fas fa-arrow-right me-1"></i>View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- footer: pagination -->
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 4px 0 4px;">
                        <div style="color:#666;font-size:13px;" id="clientInfo">Showing <?php echo count($clients); ?> clients</div>
                        <div>
                            <nav id="clientPagination" aria-label="Clients pages"></nav>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

<?php include 'includes/footer.php'; ?>

<script>
// Search and pagination functionality
(function(){
    const rows = Array.from(document.querySelectorAll('#clientsTbody .client-row'));
    const perPageSelect = document.getElementById('perPage');
    const searchInput = document.getElementById('clientSearch');
    const paginationContainer = document.getElementById('clientPagination');
    const infoEl = document.getElementById('clientInfo');

    let perPage = parseInt(perPageSelect?.value, 10) || 10;
    let filtered = rows.slice();
    let currentPage = 1;

    function renderPage() {
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;
        rows.forEach(r => r.style.display = 'none');
        filtered.slice(start, end).forEach(r => r.style.display = '');
        const total = filtered.length;
        const showingFrom = total === 0 ? 0 : start + 1;
        const showingTo = Math.min(end, total);
        if (infoEl) infoEl.textContent = `Showing ${showingFrom} to ${showingTo} of ${total} results`;
        renderPagination(Math.ceil(total / perPage) || 1);
    }

    function renderPagination(totalPages) {
        if (!paginationContainer) return;
        paginationContainer.innerHTML = '';
        const ul = document.createElement('ul');
        ul.className = 'pagination';
        
        // Previous button
        const prevLi = document.createElement('li'); 
        prevLi.className = 'page-item' + (currentPage===1?' disabled':'');
        prevLi.innerHTML = `<a class="page-link" href="#" aria-label="Previous">&laquo;</a>`; 
        prevLi.addEventListener('click', e => { e.preventDefault(); if (currentPage>1){ currentPage--; renderPage(); }});
        ul.appendChild(prevLi);
        
        // Page numbers
        const maxVisible = 7;
        let start = 1, end = totalPages;
        if (totalPages > maxVisible) {
            const half = Math.floor(maxVisible/2);
            start = Math.max(1, currentPage - half);
            end = start + maxVisible -1;
            if (end > totalPages) { end = totalPages; start = end - maxVisible +1; }
        }
        for (let i=start;i<=end;i++){
            const li = document.createElement('li');
            li.className = 'page-item' + (i===currentPage ? ' active' : '');
            li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
            li.addEventListener('click', e => { e.preventDefault(); currentPage = i; renderPage(); });
            ul.appendChild(li);
        }
        
        // Next button
        const nextLi = document.createElement('li'); 
        nextLi.className = 'page-item' + (currentPage===totalPages?' disabled':'');
        nextLi.innerHTML = `<a class="page-link" href="#" aria-label="Next">&raquo;</a>`; 
        nextLi.addEventListener('click', e => { e.preventDefault(); if (currentPage<totalPages){ currentPage++; renderPage(); }});
        ul.appendChild(nextLi);

        paginationContainer.appendChild(ul);
    }

    function applyFilter() {
        const q = (searchInput?.value || '').trim().toLowerCase();
        filtered = rows.filter(r => {
            if (!q) return true;
            const name = (r.getAttribute('data-name') || '').toLowerCase();
            const type = (r.getAttribute('data-type') || '').toLowerCase();
            return name.indexOf(q) !== -1 || type.indexOf(q) !== -1;
        });
        currentPage = 1;
        renderPage();
    }

    if (perPageSelect) {
        perPageSelect.addEventListener('change', function(){ 
            perPage = parseInt(this.value,10) || 10; 
            currentPage=1; 
            renderPage(); 
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function(){ 
            applyFilter(); 
        });
    }

    document.getElementById('selectAllClients')?.addEventListener('change', function(){ 
        document.querySelectorAll('.client-checkbox').forEach(cb => cb.checked = this.checked); 
    });

    // initial render
    if (rows.length > 0) {
        renderPage();
    }
})();

// Change expiry function
window.changeExpiry = function(clientId) {
    const val = document.getElementById('expiryPickerLocal').value;
    if (!val) return alert('Pick a date');
    const payload = new FormData();
    payload.append('id', clientId);
    payload.append('expiry_date', val);
    payload.append('change_expiry', '1');

    fetch(location.pathname, { method:'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json ? r.json() : r.text())
      .then(res => {
        try {
          if (res && res.success) { location.reload(); return; }
        } catch (e) {}
        location.reload();
      })
      .catch(()=> alert('Failed to save expiry'));
};
</script>

<style>
/* Consistent table styling */
.table-hover tbody tr { border-bottom: 1px solid #f1f3f5; }
.table-hover tbody tr td { padding: 14px 12px; vertical-align: middle; }
.table thead th { padding: 12px; border-bottom: 1px solid #eef2f6; font-weight:600; color:#666; background:#fff; }
.pagination { display:flex; gap:6px; margin:0; padding:0; list-style:none; }
.page-item { display:inline-block; }
.page-item .page-link { display:block; padding:6px 10px; border-radius:6px; border:1px solid #e9ecef; color:#333; text-decoration:none; }
.page-item.active .page-link { background:#667eea; color:#fff; border-color:#667eea; }
.page-item.disabled .page-link { opacity:0.5; pointer-events:none; }

/* Consistent card styling */
.main-content-wrapper { margin-left: 260px; background: #f8f9fa; min-height:100vh; }
@media(max-width:900px){ .main-content-wrapper{margin-left:0;} }

.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid rgba(0,0,0,0.06);
}

table tbody tr:hover {
    background: #f9fafb;
}

table a {
    transition: color 0.2s ease;
}

table a:hover {
    color: #764ba2 !important;
}

@media (max-width: 900px) {
    .main-content-wrapper > div {
        padding: 0 16px;
    }
}
</style>