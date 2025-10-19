<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

// --- ensure packages.type supports required categories (hotspot, pppoe, data, free) ---
try {
	$pdo->query("SELECT type FROM packages LIMIT 1");
} catch (Exception $e) {
	try {
		$pdo->exec("ALTER TABLE packages ADD COLUMN type ENUM('hotspot','pppoe','data','free') DEFAULT 'hotspot' AFTER id");
	} catch (Exception $ignored) {}
}
// if column exists but may lack values, attempt to alter safely
try {
	$pdo->exec("ALTER TABLE packages MODIFY COLUMN type ENUM('hotspot','pppoe','data','free') DEFAULT 'hotspot'");
} catch (Exception $ignored) { /* ignore errors if not supported */ }

// Ensure required columns exist (duration, features, speeds, data_limit, allowed_clients)
try { $pdo->query("SELECT duration FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN duration VARCHAR(50) DEFAULT '30 days' AFTER price"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT features FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN features TEXT AFTER duration"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT download_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN download_speed INT DEFAULT 0 AFTER features"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT upload_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN upload_speed INT DEFAULT 0 AFTER download_speed"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT data_limit FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN data_limit BIGINT DEFAULT 0 AFTER upload_speed"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT allowed_clients FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN allowed_clients INT DEFAULT 1 AFTER data_limit"); } catch (Exception $ignored) {} }

// Ensure packages.allowed_clients exists (runtime safe migration)
try { $pdo->query("SELECT allowed_clients FROM packages LIMIT 1"); } catch (Exception $e) {
	try { $pdo->exec("ALTER TABLE packages ADD COLUMN allowed_clients INT DEFAULT 1 AFTER data_limit"); } catch (Exception $ignored) {}
}

// Ensure 'free' type exists in enum (best-effort)
try {
    $pdo->exec("ALTER TABLE packages MODIFY COLUMN type ENUM('hotspot','pppoe','data','free') DEFAULT 'hotspot'");
} catch (Exception $ignored) {}

// Create a default Free Trial package (3 minutes, price 0) if it doesn't exist
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE type = 'free' AND price = 0");
    $stmt->execute();
    $countFree = (int)$stmt->fetchColumn();
    if ($countFree === 0) {
        $insert = $pdo->prepare("INSERT INTO packages (type, name, description, price, duration, features, download_speed, upload_speed, data_limit, allowed_clients, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $insert->execute([
            'free',
            'Free Trial',
            '3 minute free trial for new users',
            0.00,
            '3 minutes',
            'Free trial,Limited access,Single device',
            1, // download_speed Mbps
            1, // upload_speed Mbps
            0, // data_limit (0 = Unlimited for trial or none)
            1, // allowed_clients
            'active'
        ]);
    }
} catch (Exception $e) {
    error_log("Failed to ensure Free Trial package: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create') {
            $stmt = $pdo->prepare("INSERT INTO packages (name, type, price, duration, features, download_speed, upload_speed, data_limit, allowed_clients) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['name'], $_POST['type'], $_POST['price'], $_POST['duration'],
                $_POST['features'], $_POST['download_speed'], $_POST['upload_speed'],
                $_POST['data_limit'], $_POST['allowed_clients'] ?? 1
            ]);
            header("Location: packages.php?success=Package created successfully");
            exit;
        } elseif ($_POST['action'] === 'update') {
            $stmt = $pdo->prepare("UPDATE packages SET name=?, type=?, price=?, duration=?, features=?, download_speed=?, upload_speed=?, data_limit=?, allowed_clients=? WHERE id=?");
            $stmt->execute([
                $_POST['name'], $_POST['type'], $_POST['price'], $_POST['duration'],
                $_POST['features'], $_POST['download_speed'], $_POST['upload_speed'],
                $_POST['data_limit'], $_POST['allowed_clients'] ?? 1, $_POST['id']
            ]);
            header("Location: packages.php?success=Package updated successfully");
            exit;
        } elseif ($_POST['action'] === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            header("Location: packages.php?success=Package deleted successfully");
            exit;
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// --- changed: add tab selection and category counts, then fetch filtered packages ---
$tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'all';

// compute counts for tabs
try {
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM packages")->fetchColumn();
    $hotspotCount = (int)$pdo->prepare("SELECT COUNT(*) FROM packages WHERE type = 'hotspot'")->execute() ? (int)$pdo->prepare("SELECT COUNT(*) FROM packages WHERE type = 'hotspot'")->fetchColumn() : 0;
} catch (Exception $e) {
    // fallback safe counts
    $totalCount = $hotspotCount = 0;
}
// safer queries (prepared + fetch)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE type = ?");
    $stmt->execute(['hotspot']); $hotspotCount = (int)$stmt->fetchColumn();
    $stmt->execute(['pppoe']); $pppoeCount = (int)$stmt->fetchColumn();
    $freeStmt = $pdo->query("SELECT COUNT(*) FROM packages WHERE price = 0"); $freeCount = (int)$freeStmt->fetchColumn();
    $dataStmt = $pdo->query("SELECT COUNT(*) FROM packages WHERE data_limit > 0"); $dataCount = (int)$dataStmt->fetchColumn();
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM packages")->fetchColumn();
} catch (Exception $e) {
    $pppoeCount = $freeCount = $dataCount = 0;
}

// build filter SQL based on tab
$where = '';
$params = [];
switch ($tab) {
    case 'hotspot':
        $where = "WHERE type = 'hotspot'";
        break;
    case 'pppoe':
        $where = "WHERE type = 'pppoe'";
        break;
    case 'free':
        $where = "WHERE price = 0";
        break;
    case 'data':
        $where = "WHERE data_limit > 0";
        break;
    case 'all':
    default:
        $where = "";
        $tab = 'all';
        break;
}

$packages = $pdo->query("SELECT * FROM packages $where ORDER BY created_at DESC")->fetchAll();

// existing edit_package logic remains
$edit_package = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_package = $stmt->fetch();
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- MAIN TITLE + ACTION -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;">
    <div>
        <h1 style="margin:0; font-size:34px; color:#222; font-weight:700;">Packages</h1>
        <div style="color:#666;font-size:14px;margin-top:6px;">Create and manage Hotspot / PPPoE / Data Plans and Free Trial packages.</div>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus me-1"></i> Add Package</button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="padding: 15px 20px; background: #d4edda; color: #155724; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['success']); ?>
    </div>
<?php endif; ?>

<!-- CATEGORY PILLS -->
<?php /* Tabs: all / hotspot / pppoe / data / free */ ?>
<ul class="nav nav-pills mb-3" style="gap:8px;">
	<li class="nav-item"><a class="nav-link <?php echo $tab==='all' ? 'active' : ''; ?>" href="?tab=all">All</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab==='hotspot' ? 'active' : ''; ?>" href="?tab=hotspot">Hotspot</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab==='pppoe' ? 'active' : ''; ?>" href="?tab=pppoe">PPPoE</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab==='data' ? 'active' : ''; ?>" href="?tab=data">Data Plans</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab==='free' ? 'active' : ''; ?>" href="?tab=free">Free Trial</a></li>
</ul>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px;">
            <?php foreach ($packages as $pkg): ?>
                <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">
	                    <h3 style="margin: 0 0 10px 0; color: #667eea; font-size: 22px; font-weight:700;"><?php echo htmlspecialchars($pkg['name']); ?></h3>
	                    <span style="font-size:12px;">
	                        <span class="badge bg-secondary" style="text-transform:uppercase;">
	                            <?php
	                                $typeLabel = strtoupper($pkg['type'] ?? 'HOTSPOT');
	                                if ($typeLabel === 'DATA') $typeLabel = 'DATA PLAN';
	                                if ($typeLabel === 'FREE') $typeLabel = 'FREE TRIAL';
	                                echo htmlspecialchars($typeLabel);
	                            ?>
	                        </span>
	                    </span>
                    </div>

                    <div style="font-size: 28px; font-weight: bold; color: #333; margin: 12px 0;">KES <?php echo number_format($pkg['price'], 2); ?></div>

                    <div style="padding: 12px 0; border-top: 1px solid #eee; border-bottom: 1px solid #eee; margin: 12px 0;">
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span style="color:#555;"><i class="fas fa-clock" style="color: #667eea;"></i> Duration:</span>
                            <strong><?php echo $pkg['duration'] ?? '30 days'; ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span style="color:#555;"><i class="fas fa-download" style="color: #28a745;"></i> Speed:</span>
                            <strong><?php echo (int)($pkg['download_speed'] ?? 0); ?>↓ / <?php echo (int)($pkg['upload_speed'] ?? 0); ?>↑ Mbps</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span style="color:#555;"><i class="fas fa-database" style="color: #17a2b8;"></i> Data:</span>
                            <strong><?php echo ($pkg['data_limit'] ?? 0) > 0 ? round($pkg['data_limit']/1073741824, 2).' GB' : 'Unlimited'; ?></strong>
                        </div>

                        <!-- Added: Allowed Devices -->
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span style="color:#555;"><i class="fas fa-user-friends" style="color: #6f42c1;"></i> Allowed Devices:</span>
                            <strong><?php echo (int)($pkg['allowed_clients'] ?? 1); ?></strong>
                        </div>
                    </div>

                    <?php if ($pkg['features']): ?>
                        <ul style="list-style: none; padding: 0; margin: 12px 0;">
                            <?php foreach (array_slice(explode(',', $pkg['features']), 0, 3) as $feature): ?>
                                <li style="padding: 5px 0; color: #666;"><i class="fas fa-check" style="color: #28a745; margin-right: 8px;"></i><?php echo htmlspecialchars(trim($feature)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <a href="?edit=<?php echo $pkg['id']; ?>" style="flex: 1; padding: 10px; background: #ffc107; color: #000; text-align: center; border-radius: 6px; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Delete this package?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                            <button type="submit" style="width: 100%; padding: 10px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

<!-- Modal -->
<div id="packageModal" style="display: <?php echo $edit_package ? 'flex' : 'none'; ?>; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 9999;">
    <div style="background: white; width: 90%; max-width: 600px; border-radius: 10px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;"><?php echo $edit_package ? 'Edit' : 'Add'; ?> Package</h2>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" style="padding: 20px;" id="packageModalForm">
            <input type="hidden" name="action" value="<?php echo $edit_package ? 'update' : 'create'; ?>">
            <?php if ($edit_package): ?><input type="hidden" name="id" value="<?php echo $edit_package['id']; ?>"><?php endif; ?>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Package Type *</label>
                <select name="type" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="hotspot" <?php echo (($edit_package['type'] ?? '') === 'hotspot') ? 'selected' : ''; ?>>Hotspot</option>
                    <option value="pppoe" <?php echo (($edit_package['type'] ?? '') === 'pppoe') ? 'selected' : ''; ?>>PPPoE</option>
                    <option value="data" <?php echo (($edit_package['type'] ?? '') === 'data') ? 'selected' : ''; ?>>Data Plan</option>
                    <option value="free" <?php echo (($edit_package['type'] ?? '') === 'free') ? 'selected' : ''; ?>>Free Trial</option>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Package Name *</label>
                <input type="text" name="name" required value="<?php echo $edit_package['name'] ?? ''; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Price (KES) *</label>
                    <input type="number" step="0.01" name="price" required value="<?php echo $edit_package['price'] ?? ''; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Duration *</label>
                    <!-- NEW: Replace duration select with value + unit inputs + presets -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px;">
                        <div>
                            <label style="display:block;margin-bottom:6px;font-weight:600;">Duration (value)</label>
                            <input list="durationPresets" type="number" step="any" name="duration_value" id="duration_value" 
                                   value="<?php echo isset($edit_package['duration']) ? (int)filter_var($edit_package['duration'], FILTER_SANITIZE_NUMBER_INT) : '30'; ?>"
                                   class="form-control" placeholder="e.g. 3 or 1">
                            <datalist id="durationPresets">
                                <option value="45">45</option>
                                <option value="30">30</option>
                                <option value="15">15</option>
                                <option value="10">10</option>
                                <option value="5">5</option>
                                <option value="3">3</option>
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="4">4</option>
                            </datalist>
                            <div class="form-text">Type a number or pick a preset. Select unit on the right.</div>
                        </div>

                        <div>
                            <label style="display:block;margin-bottom:6px;font-weight:600;">Unit</label>
                            <select name="duration_unit" id="duration_unit" class="form-select" required>
                                <?php
                                // try to pre-select unit from existing duration string if editing
                                $selectedUnit = '';
                                if (!empty($edit_package['duration'])) {
                                    if (stripos($edit_package['duration'],'minute') !== false) $selectedUnit = 'minutes';
                                    elseif (stripos($edit_package['duration'],'hour') !== false) $selectedUnit = 'hours';
                                    elseif (stripos($edit_package['duration'],'week') !== false) $selectedUnit = 'weeks';
                                    elseif (stripos($edit_package['duration'],'month') !== false) $selectedUnit = 'months';
                                    elseif (stripos($edit_package['duration'],'day') !== false) $selectedUnit = 'days';
                                }
                                ?>
                                <option value="minutes" <?php echo $selectedUnit==='minutes' ? 'selected' : ''; ?>>Minutes</option>
                                <option value="hours" <?php echo $selectedUnit==='hours' ? 'selected' : ''; ?>>Hours</option>
                                <option value="days" <?php echo ($selectedUnit==='' || $selectedUnit==='days') ? 'selected' : ''; ?>>Days</option>
                                <option value="weeks" <?php echo $selectedUnit==='weeks' ? 'selected' : ''; ?>>Weeks</option>
                                <option value="months" <?php echo $selectedUnit==='months' ? 'selected' : ''; ?>>Months</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Download (Mbps) *</label>
                    <input type="number" name="download_speed" required value="<?php echo $edit_package['download_speed'] ?? '10'; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Upload (Mbps) *</label>
                    <input type="number" name="upload_speed" required value="<?php echo $edit_package['upload_speed'] ?? '5'; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Data Limit (Bytes, 0=Unlimited)</label>
                <input type="number" name="data_limit" value="<?php echo $edit_package['data_limit'] ?? '0'; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Features (comma-separated)</label>
                <textarea name="features" rows="2" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;"><?php echo $edit_package['features'] ?? ''; ?></textarea>
            </div>
            <!-- Ensure Allowed Devices input -->
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Allowed Devices (same account) *</label>
                <input type="number" name="allowed_clients" min="1" required value="<?php echo htmlspecialchars($edit_package['allowed_clients'] ?? '1'); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    <i class="fas fa-plus me-2"></i><?php echo $edit_package ? 'Update' : 'Create'; ?>
                </button>
                <button type="button" onclick="closeModal()" style="flex: 1; padding: 12px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    const m = document.getElementById('packageModal');
    if (!m) return;
    m.style.display = 'flex';
}
function closeModal() { window.location.href = 'packages.php'; }

// small helper JS to parse an existing duration into the two fields when modal opens (optional)
(function(){
    // If editing, try to populate duration_value + duration_unit from existing duration string
    const durationStr = "<?php echo isset($edit_package['duration']) ? addslashes($edit_package['duration']) : ''; ?>";
    if (durationStr) {
        const m = durationStr.match(/([\d\.]+)\s*(minute|minutes|min|hour|hours|day|days|week|weeks|month|months)/i);
        if (m) {
            const val = m[1];
            const unit = m[2].toLowerCase();
            document.addEventListener('DOMContentLoaded', function(){
                const dv = document.getElementById('duration_value');
                const du = document.getElementById('duration_unit');
                if (dv) dv.value = val;
                if (du) {
                    if (unit.indexOf('min')!==-1) du.value='minutes';
                    else if (unit.indexOf('hour')!==-1) du.value='hours';
                    else if (unit.indexOf('week')!==-1) du.value='weeks';
                    else if (unit.indexOf('month')!==-1) du.value='months';
                    else du.value='days';
                }
            });
        }
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
