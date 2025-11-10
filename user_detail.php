<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// Detect whether clients.package_id column exists (some installs use subscription_plan instead)
try {
    $has_package_id = (bool) $db->query("SELECT COUNT(*) as c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='package_id'")->fetchColumn();
} catch (Throwable $e) {
    $has_package_id = false;
}

// Normalize client fields to expected keys used by the UI
function normalizeClientRecord(array $c): array {
    // username: prefer 'username', fall back to mikrotik_username, name, full_name
    if (empty($c['username'])) {
        if (!empty($c['mikrotik_username'])) $c['username'] = $c['mikrotik_username'];
        elseif (!empty($c['name'])) $c['username'] = $c['name'];
        elseif (!empty($c['full_name'])) $c['username'] = $c['full_name'];
        else $c['username'] = '';
    }

    // phone_number normalize
    if (empty($c['phone_number'])) {
        if (!empty($c['phone'])) $c['phone_number'] = $c['phone'];
        else $c['phone_number'] = '';
    }

    // full_name fallback
    if (empty($c['full_name'])) {
        if (!empty($c['name'])) $c['full_name'] = $c['name'];
        elseif (!empty($c['username'])) $c['full_name'] = $c['username'];
        else $c['full_name'] = '';
    }

    // email and address defaults
    if (empty($c['email'])) $c['email'] = $c['email'] ?? '';
    if (empty($c['address'])) $c['address'] = $c['address'] ?? '';

    // expiry_date may be stored under expiry_date or next_payment_date
    if (empty($c['expiry_date'])) {
        if (!empty($c['expiry'])) $c['expiry_date'] = $c['expiry'];
        elseif (!empty($c['next_payment_date'])) $c['expiry_date'] = $c['next_payment_date'];
        else $c['expiry_date'] = null;
    }

    return $c;
}


// --- Load user id and ensure record exists ---
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
// Debug mode: when set to 1, avoid silent redirects and print diagnostic info
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($user_id <= 0) {
	// fallback to clients list
    if ($debugMode) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Debug: invalid id provided (id={$user_id})\n";
        echo "Session: " . (isset($_SESSION) ? json_encode($_SESSION) : 'no session') . "\n";
        exit;
    }
    header('Location: clients.php');
    exit;
}

// Fetch user with package info. Some installations use a package_id foreign key, others store package name in subscription_plan.
try {
    if (!empty($has_package_id)) {
        $stmt = $db->prepare(
            "SELECT c.*, p.id AS package_id, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON c.package_id = p.id WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // fallback: match package by name using subscription_plan column
        $stmt = $db->prepare(
            "SELECT c.*, p.id AS package_id, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON p.name = c.subscription_plan WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        if ($debugMode) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Debug: user not found for id={$user_id}\n";
            exit;
        }
        header('Location: clients.php');
        exit;
    }
    // normalize fetched record for consistent keys
    $user = normalizeClientRecord($user ?: []);
} catch (Throwable $e) {
    error_log("User load error: " . $e->getMessage());
    if ($debugMode) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Debug: DB error while loading user id={$user_id}\n";
        echo "Error: " . $e->getMessage() . "\n";
        exit;
    }
    header('Location: clients.php');
    exit;
}

// Ensure $package for display
$package = [
	'id'    => $user['package_id'] ?? null,
	'name'  => $user['package_name'] ?? 'N/A',
	'price' => isset($user['package_price']) ? (float)$user['package_price'] : 0.0,
];

// Compute time remaining for display
$timeRemainingText = 'N/A';
$timeRemainingDays = null;
if (!empty($user['expiry_date'])) {
    try {
        $expiryTs = strtotime($user['expiry_date']);
        $now = time();
        $delta = $expiryTs - $now;
        if ($delta <= 0) {
            $timeRemainingText = 'Expired';
            $timeRemainingDays = 0;
        } else {
            $days = floor($delta / 86400);
            $hours = floor(($delta % 86400) / 3600);
            $timeRemainingDays = $days;
            if ($days > 0) $timeRemainingText = $days . ' day' . ($days>1? 's':'') . ' left';
            elseif ($hours > 0) $timeRemainingText = $hours . ' hour' . ($hours>1? 's':'') . ' left';
            else $timeRemainingText = 'Less than 1 hour';
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// Fetch recent payments for this client
try {
	$stmt = $db->prepare("SELECT * FROM payments WHERE client_id = ? ORDER BY payment_date DESC LIMIT 50");
	$stmt->execute([$user_id]);
	$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	$payments = [];
}

// Fetch all packages for edit dropdown
try {
	$stmt = $db->query("SELECT id, name, price FROM packages ORDER BY name ASC");
	$all_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	$all_packages = [];
}

// --- Actions handling (save, pause, change_expiry) ---
$action_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	if ($action === 'pause') {
		try {
			$stmt = $db->prepare("UPDATE clients SET status = 'paused' WHERE id = ?");
			$stmt->execute([$user_id]);
			$user['status'] = 'paused';
			$action_result = 'success|Subscription paused';
		} catch (Throwable $e) {
			$action_result = 'error|' . $e->getMessage();
		}
	} elseif ($action === 'unpause') {
		try {
			$stmt = $db->prepare("UPDATE clients SET status = 'active' WHERE id = ?");
			$stmt->execute([$user_id]);
			$user['status'] = 'active';
			$action_result = 'success|Subscription resumed';
		} catch (Throwable $e) {
			$action_result = 'error|' . $e->getMessage();
		}
	} elseif ($action === 'change_expiry') {
		$newDate = trim($_POST['new_date'] ?? '');
		if ($newDate) {
			try {
				$stmt = $db->prepare("UPDATE clients SET expiry_date = ? WHERE id = ?");
				$stmt->execute([$newDate, $user_id]);
				$user['expiry_date'] = $newDate;
				$action_result = 'success|Expiry updated to ' . htmlspecialchars($newDate);
			} catch (Throwable $e) {
				$action_result = 'error|' . $e->getMessage();
			}
		} else {
			$action_result = 'error|Invalid date';
		}
	} elseif ($action === 'update_user') {
		// Collect and validate fields
		$username   = trim($_POST['username'] ?? $user['username']);
		$password   = trim($_POST['password'] ?? '');
		$full_name  = trim($_POST['full_name'] ?? $user['full_name']);
		$phone      = trim($_POST['phone'] ?? $user['phone_number']);
		$email      = trim($_POST['email'] ?? $user['email']);
		$address    = trim($_POST['address'] ?? $user['address']);
		$package_id = !empty($_POST['package_id']) ? intval($_POST['package_id']) : null;
		$expiry     = trim($_POST['expiry_date'] ?? $user['expiry_date']);
		$status     = trim($_POST['status'] ?? $user['status'] ?? 'active');

		// Basic validation
		if ($username === '') {
			$action_result = 'error|Username is required';
		} else {
			try {
                // Build a safe UPDATE that adapts to the actual columns present in the clients table.
                // Detect commonly used alternative column names and prefer canonical names when available.
                try {
                    $has_username = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='username'")->fetchColumn();
                } catch (Throwable $e) { $has_username = false; }
                try {
                    $has_mikrotik_username = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='mikrotik_username'")->fetchColumn();
                } catch (Throwable $e) { $has_mikrotik_username = false; }
                try {
                    $has_name = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='name'")->fetchColumn();
                } catch (Throwable $e) { $has_name = false; }
                try {
                    $has_phone_number = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='phone_number'")->fetchColumn();
                } catch (Throwable $e) { $has_phone_number = false; }
                try {
                    $has_phone = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='phone'")->fetchColumn();
                } catch (Throwable $e) { $has_phone = false; }

                // Determine package target (id vs subscription_plan)
                $pkgName = null;
                if (empty($has_package_id) && $package_id) {
                    // resolve package name when we must write into subscription_plan
                    try {
                        $pstmt = $db->prepare("SELECT name FROM packages WHERE id = ? LIMIT 1");
                        $pstmt->execute([$package_id]);
                        $pkgName = $pstmt->fetchColumn() ?: null;
                    } catch (Throwable $e) { $pkgName = null; }
                }

                // Build SET parts and params in order
                $set = [];
                $params = [];

                // username variant
                if ($has_username) {
                    $set[] = "username = ?";
                    $params[] = $username;
                } elseif ($has_mikrotik_username) {
                    $set[] = "mikrotik_username = ?";
                    $params[] = $username;
                } elseif ($has_name) {
                    $set[] = "name = ?";
                    $params[] = $username;
                }

                // password (only if provided)
                if ($password !== '') {
                    $pwd_hash = password_hash($password, PASSWORD_DEFAULT);
                    // prefer 'password' column if present (most installs have it)
                    $set[] = "password = ?";
                    $params[] = $pwd_hash;
                }

                // full_name (likely present)
                try {
                    $has_full_name = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='full_name'")->fetchColumn();
                } catch (Throwable $e) { $has_full_name = false; }
                if ($has_full_name) {
                    $set[] = "full_name = ?";
                    $params[] = $full_name;
                }

                // phone column variants
                if ($has_phone_number) {
                    $set[] = "phone_number = ?";
                    $params[] = $phone;
                } elseif ($has_phone) {
                    $set[] = "phone = ?";
                    $params[] = $phone;
                }

                // email & address
                try {
                    $has_email = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='email'")->fetchColumn();
                } catch (Throwable $e) { $has_email = false; }
                if ($has_email) {
                    $set[] = "email = ?";
                    $params[] = $email;
                }
                try {
                    $has_address = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='address'")->fetchColumn();
                } catch (Throwable $e) { $has_address = false; }
                if ($has_address) {
                    $set[] = "address = ?";
                    $params[] = $address;
                }

                // package: either package_id or subscription_plan
                if (!empty($has_package_id)) {
                    $set[] = "package_id = ?";
                    $params[] = $package_id;
                } else {
                    // write package name into subscription_plan
                    try {
                        $has_subscription_plan = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='subscription_plan'")->fetchColumn();
                    } catch (Throwable $e) { $has_subscription_plan = false; }
                    if ($has_subscription_plan) {
                        $set[] = "subscription_plan = ?";
                        $params[] = $pkgName;
                    }
                }

                // expiry_date & status
                try {
                    $has_expiry = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='expiry_date'")->fetchColumn();
                } catch (Throwable $e) { $has_expiry = false; }
                if ($has_expiry) {
                    $set[] = "expiry_date = ?";
                    $params[] = $expiry;
                }
                try {
                    $has_status = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='status'")->fetchColumn();
                } catch (Throwable $e) { $has_status = false; }
                if ($has_status) {
                    $set[] = "status = ?";
                    $params[] = $status;
                }

                if (count($set) === 0) {
                    throw new Exception('No writable client columns detected');
                }

                $sql = "UPDATE clients SET " . implode(', ', $set) . " WHERE id = ?";
                $params[] = $user_id;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                // Refresh user and package info using the same logic as earlier
                if (!empty($has_package_id)) {
                    $stmt = $db->prepare("SELECT c.*, p.id AS package_id, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON c.package_id = p.id WHERE c.id = ? LIMIT 1");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $db->prepare("SELECT c.*, p.id AS package_id, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON p.name = c.subscription_plan WHERE c.id = ? LIMIT 1");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // normalize fetched record for consistent keys
                $user = normalizeClientRecord($user ?: []);

                $package = [
                    'id'    => $user['package_id'] ?? null,
                    'name'  => $user['package_name'] ?? 'N/A',
                    'price' => isset($user['package_price']) ? (float)$user['package_price'] : 0.0,
                ];

				$action_result = 'success|User updated successfully';
			} catch (Throwable $e) {
				$action_result = 'error|' . $e->getMessage();
			}
		}
	}
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div>
        <!-- Page header -->
        <div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
            <div style="flex:1;min-width:320px;">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:64px;height:64px;border-radius:12px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:22px;">
                        <?php echo strtoupper(substr($user['username'] ?? 'U',0,1)); ?>
                    </div>
                    <div>
                        <h1 style="margin:0;font-size:20px;"><?php echo htmlspecialchars($user['full_name'] ?? 'Unknown'); ?></h1>
                        <div style="color:#666;font-size:13px;">
                            @<?php echo htmlspecialchars($user['username'] ?? ''); ?> • <?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>
                            <?php if (!empty($user['account_number']) || true):
                                // show account number if present, otherwise generate a formatted one based on business initial + id
                                $displayAcc = !empty($user['account_number']) ? $user['account_number'] : getAccountNumber($db, $user);
                            ?>
                                • Acc: <?php echo htmlspecialchars($displayAcc); ?>
                            <?php endif; ?>
                            <?php if (!empty($user['user_type'])): ?>
                                • <?php echo htmlspecialchars(ucfirst($user['user_type'])); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top:12px;color:#666;font-size:13px;">
                    Package: <strong><?php echo htmlspecialchars($package['name'] ?? 'N/A'); ?></strong> |
                    Expiry: <strong><?php echo !empty($user['expiry_date']) ? date('F j, Y', strtotime($user['expiry_date'])) : 'N/A'; ?></strong>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center">
                <?php if (strtolower($user['status'] ?? '') === 'active'): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="pause">
                        <button type="submit" style="padding:8px 14px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;color:#92400e;font-weight:600;">Pause</button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="unpause">
                        <button type="submit" style="padding:8px 14px;background:#d1fae5;border:1px solid #10b981;border-radius:8px;color:#065f46;font-weight:600;">Unpause</button>
                    </form>
                <?php endif; ?>

                <button type="button" onclick="openEditModal()" style="padding:8px 14px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;">Edit</button>

                <a href="stk_payment.php?user_id=<?php echo (int)$user_id; ?>" style="padding:8px 14px;background:#06b6d4;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Send STK Push</a>
            </div>
        </div>

        <!-- Action result -->
        <?php if ($action_result): list($type,$msg) = explode('|',$action_result,2); ?>
            <div style="margin-bottom:16px;padding:12px;border-radius:8px;background:<?php echo $type==='success' ? '#d1fae5' : '#fee2e2'; ?>;color:<?php echo $type==='success' ? '#065f46' : '#991b1b'; ?>;">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div style="display:flex;gap:20px;border-bottom:2px solid #f3f4f6;padding-bottom:8px;margin-bottom:16px;flex-wrap:wrap;">
            <a href="#" class="tab-link settings" onclick="switchTab(event,'general')" style="font-weight:600;color:#667eea;border-bottom:3px solid #667eea;padding:10px 0;">General</a>
            <a href="#" class="tab-link" onclick="switchTab(event,'paymentsTab')" style="padding:10px 0;color:#6b7280;">Payments</a>
            <a href="#" class="tab-link" onclick="switchTab(event,'sessionsTab')" style="padding:10px 0;color:#6b7280;">Sessions</a>
        </div>

        <!-- General content -->
        <div id="general" class="tab-content" style="background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.04);margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;">
                <div>
                    <!-- account details -->
                    <h3 style="margin-top:0;font-size:14px;color:#333;">Account Details</h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label style="font-size:12px;color:#666">Account Number</label>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($user['account_number'] ?? getAccountNumber($db, $user_id)); ?></div>
                        </div>
                        <div>
                            <label style="font-size:12px;color:#666">User Type</label>
                            <div style="font-weight:600;text-transform:capitalize;"><?php echo htmlspecialchars($user['user_type'] ?? 'user'); ?></div>
                        </div>

                        <div>
                            <label style="font-size:12px;color:#666">Username</label>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($user['username']); ?></div>
                        </div>
                        <div>
                            <label style="font-size:12px;color:#666">Password</label>
                            <div style="font-weight:600;display:flex;align-items:center;gap:8px;">
                                <span id="pwdDisplay"><?php echo !empty($user['auth_password']) || !empty($user['password']) ? '••••••••' : '—'; ?></span>
                                <?php if (!empty($user['auth_password'])): ?>
                                    <button type="button" onclick="togglePasswordDetail()" title="Show/Hide password" style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:6px;cursor:pointer;color:#333;">
                                        <i class="fas fa-eye" id="pwdEye"></i>
                                    </button>
                                    <span id="pwdValue" style="display:none;"><?php echo htmlspecialchars($user['auth_password'], ENT_QUOTES); ?></span>
                                <?php elseif (!empty($user['password'])): ?>
                                    <button type="button" title="Password stored as hash" style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:6px;color:#999;"> <i class="fas fa-lock"></i> </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <label style="font-size:12px;color:#666">Phone</label>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($user['phone_number']); ?></div>
                        </div>
                        <div>
                            <label style="font-size:12px;color:#666">Email</label>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>

                        <div>
                            <label style="font-size:12px;color:#666">Status</label>
                            <div style="font-weight:600;text-transform:capitalize;"><?php echo htmlspecialchars($user['status']); ?></div>
                        </div>
                        <div>
                            <label style="font-size:12px;color:#666">Time Remaining</label>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($timeRemainingText); ?></div>
                        </div>

                        <div style="grid-column:1/3;">
                            <label style="font-size:12px;color:#666">Address</label>
                            <div style="font-weight:600;"><?php echo nl2br(htmlspecialchars($user['address'])); ?></div>
                        </div>
                    </div>
                </div>

                <div>
                    <!-- summary card -->
            <div style="background:#f9fafb;padding:14px;border-radius:8px;">
                        <div style="font-size:12px;color:#666">Package</div>
                        <div style="font-weight:700;font-size:16px;"><?php echo htmlspecialchars($package['name'] ?? 'N/A'); ?></div>
                <div style="color:#666;margin-top:8px;">Expires: <?php echo !empty($user['expiry_date']) ? date('M j, Y', strtotime($user['expiry_date'])) : 'N/A'; ?><?php if(!empty($timeRemainingText)): ?> • <strong><?php echo htmlspecialchars($timeRemainingText); ?></strong><?php endif; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payments tab -->
        <div id="paymentsTab" class="tab-content" style="display:none;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.04);margin-bottom:16px;">
            <!-- payments list -->
            <h3 style="margin-top:0;">Payment History</h3>
            <div style="overflow:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead style="background:#f9fafb;"><tr><th style="padding:12px;text-align:left">Date</th><th style="padding:12px;text-align:left">Amount</th><th style="padding:12px;text-align:left">Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px;"><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></td>
                                <td style="padding:12px;">KES <?php echo number_format($p['amount'],2); ?></td>
                                <td style="padding:12px;"><?php echo htmlspecialchars($p['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sessions tab -->
        <div id="sessionsTab" class="tab-content" style="display:none;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.04);">
            <h3 style="margin-top:0;">Active Sessions</h3>
            <p style="color:#666;margin:0;">Sessions data not available in this view.</p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Edit Modal (full edit form) -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:3000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:20px;max-width:760px;width:100%;box-shadow:0 20px 30px rgba(0,0,0,0.12);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h2 style="margin:0;font-size:18px;">Edit Client</h2>
            <button onclick="closeEditModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#666;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_user">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="font-size:12px;color:#666">Username</label>
                    <input name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;color:#666">Password (leave blank to keep)</label>
                    <input name="password" type="password" value="" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;color:#666">Full name</label>
                    <input name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;color:#666">Phone</label>
                    <input name="phone" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;color:#666">Email</label>
                    <input name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;color:#666">Status</label>
                    <select name="status" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                        <option value="active" <?php echo (strtolower($user['status'] ?? '')==='active')?'selected':''; ?>>Active</option>
                        <option value="paused" <?php echo (strtolower($user['status'] ?? '')==='paused')?'selected':''; ?>>Paused</option>
                        <option value="disabled" <?php echo (strtolower($user['status'] ?? '')==='disabled')?'selected':''; ?>>Disabled</option>
                    </select>
                </div>

                <div style="grid-column:1/3;">
                    <label style="font-size:12px;color:#666">Address</label>
                    <textarea name="address" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label style="font-size:12px;color:#666">Package</label>
                    <select name="package_id" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                        <option value="">-- none --</option>
                        <?php foreach ($all_packages as $pkg): ?>
                            <option value="<?php echo (int)$pkg['id']; ?>" <?php echo (($user['package_id'] ?? '') == $pkg['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pkg['name'] . ' — KES ' . number_format($pkg['price'],2)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size:12px;color:#666">Expiry (YYYY-MM-DD HH:MM:SS)</label>
                    <input name="expiry_date" value="<?php echo htmlspecialchars($user['expiry_date'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
                <button type="button" onclick="closeEditModal()" style="padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;">Cancel</button>
                <button type="submit" style="padding:10px 14px;border-radius:6px;background:#667eea;color:#fff;border:none;">Save changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(e, tabName) {
    e.preventDefault();
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-link').forEach(el => { el.style.borderBottomColor = 'transparent'; el.style.color = '#6b7280'; el.style.fontWeight = '500'; });
    const tab = document.getElementById(tabName);
    if (tab) {
        tab.style.display = 'block';
        const link = Array.from(document.querySelectorAll('.tab-link')).find(el => el.getAttribute('onclick').includes(tabName));
        if (link) {
            link.style.borderBottomColor = '#667eea';
            link.style.color = '#111827';
            link.style.fontWeight = '600';
        }
    }
}

function openEditModal() {
    document.getElementById('editModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Toggle password reveal for user detail (reveals auth_password only)
function togglePasswordDetailJS() {
    // placeholder for server-side rendering safety
}

// Password toggle for detail page
function togglePasswordDetail() {
    var display = document.getElementById('pwdDisplay');
    var val = document.getElementById('pwdValue');
    var eye = document.getElementById('pwdEye');
    if (!display) return;
    if (val && val.style.display === 'none') {
        // show actual password
        display.textContent = val.textContent;
        val.style.display = 'inline';
        if (eye) eye.classList.add('fa-eye-slash');
    } else if (val) {
        // hide
        display.textContent = '••••••••';
        val.style.display = 'none';
        if (eye) eye.classList.remove('fa-eye-slash');
    }
}

// Add JS inlined safely
</script>
