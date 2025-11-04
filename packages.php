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

// Ensure packages table has created_at / updated_at columns (idempotent)
$colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

$colCheck->execute(['packages', 'created_at']);
if ($colCheck->fetchColumn() == 0) {
    try { $pdo->exec("ALTER TABLE packages ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $ignored) { error_log("Could not add packages.created_at: ".$ignored->getMessage()); }
}

$colCheck->execute(['packages', 'updated_at']);
if ($colCheck->fetchColumn() == 0) {
    try { $pdo->exec("ALTER TABLE packages ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Exception $ignored) { error_log("Could not add packages.updated_at: ".$ignored->getMessage()); }
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
            <div style="display:flex;gap:8px;align-items:center;">
                <div style="position:relative;">
                    <input id="pkgSearch" type="search" placeholder="Search packages" style="padding:8px 12px;border:1px solid #e6e9ed;border-radius:8px;width:260px;">
                </div>
                <select id="perPage" class="form-select" style="width:120px;padding:8px;border-radius:8px;">
                    <option value="10">Per page: 10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <button type="button" class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus me-1"></i> Create Package</button>
            </div>
        </div>

        <!-- CATEGORY PILLS -->
        <ul class="nav nav-pills mb-3" style="gap:8px;">
            <li class="nav-item"><a class="nav-link <?php echo $tab==='all' ? 'active' : ''; ?>" href="?tab=all">All <span class="badge bg-light text-muted ms-2"><?php echo $totalCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='hotspot' ? 'active' : ''; ?>" href="?tab=hotspot">Hotspot <span class="badge bg-light text-muted ms-2"><?php echo $hotspotCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='pppoe' ? 'active' : ''; ?>" href="?tab=pppoe">PPPoE <span class="badge bg-light text-muted ms-2"><?php echo $pppoeCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='data' ? 'active' : ''; ?>" href="?tab=data">Data Plans <span class="badge bg-light text-muted ms-2"><?php echo $dataCount ?? 0; ?></span></a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab==='free' ? 'active' : ''; ?>" href="?tab=free">Free Trial <span class="badge bg-light text-muted ms-2"><?php echo $freeCount ?? 0; ?></span></a></li>
        </ul>

        <!-- Table container -->
        <div style="background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,0.04);overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;">
                <div style="font-weight:700;color:#444;">Packages</div>
                <div style="color:#888;font-size:13px;">Showing packages — <?php echo htmlspecialchars($tab); ?></div>
            </div>

            <div style="padding:0 16px 16px 16px;">
                <div class="table-responsive" style="margin-top:12px;">
                    <table id="packagesTable" class="table table-hover" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#fafafa;">
                                <th style="width:40px;"><input type="checkbox" id="selectAllPkgs"></th>
                                <th>Name</th>
                                <th style="width:140px;">Price</th>
                                <th style="width:110px;">Speed</th>
                                <th style="width:130px;">Time</th>
                                <th style="width:110px;">Type</th>
                                <th style="width:90px;">Devices</th>
                                <th style="width:90px;">Enabled</th>
                                <th style="width:90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="packagesTbody">
                            <?php foreach ($packages as $pkg): 
                                $speedLabel = (int)($pkg['download_speed'] ?? 0) . '↓/' . (int)($pkg['upload_speed'] ?? 0) . '↑';
                                $timeLabel = htmlspecialchars($pkg['duration'] ?? '30 days');
                                $typeLabel = strtoupper($pkg['type'] ?? 'HOTSPOT');
                                if ($typeLabel === 'DATA') $typeLabel = 'DATA PLAN';
                                if ($typeLabel === 'FREE') $typeLabel = 'FREE TRIAL';
                                $enabled = (isset($pkg['status']) && $pkg['status'] === 'active') ? 'Yes' : 'No';
                                ?>
                                <tr class="pkg-row" data-name="<?php echo htmlspecialchars(strtolower($pkg['name'])); ?>" data-type="<?php echo htmlspecialchars(strtolower($pkg['type'])); ?>">
                                    <td><input type="checkbox" class="pkg-checkbox" value="<?php echo (int)$pkg['id']; ?>"></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                        <div style="font-size:12px;color:#888;"><?php echo htmlspecialchars($pkg['description'] ?? ''); ?></div>
                                    </td>
                                    <td>Ksh <?php echo number_format($pkg['price'], 2); ?></td>
                                    <td><?php echo $speedLabel; ?></td>
                                    <td><?php echo $timeLabel; ?></td>
                                    <td><?php echo $typeLabel; ?></td>
                                    <td><?php echo (int)($pkg['allowed_clients'] ?? 1); ?></td>
                                    <td>
                                        <?php if ($enabled === 'Yes'): ?>
                                            <span style="display:inline-block;padding:4px 8px;background:#e6f6ef;color:#0a6;border-radius:6px;font-weight:700;font-size:12px;">Yes</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:4px 8px;background:#fff3e6;color:#e68;border-radius:6px;font-weight:700;font-size:12px;">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;justify-content:flex-end;">
                                            <a href="?edit=<?php echo $pkg['id']; ?>" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation();">Edit</a>
                                            <form method="POST" onsubmit="return confirm('Delete this package?');" style="display:inline;" onclick="event.stopPropagation();">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- footer: pagination -->
                <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 4px 0 4px;">
                    <div style="color:#666;font-size:13px;" id="pkgInfo">Showing <?php echo count($packages); ?> packages</div>
                    <div>
                        <nav id="pkgPagination" aria-label="Packages pages"></nav>
                    </div>
                </div>
            </div>
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

        <!-- existing modal remains unchanged -->
        <!-- ...existing packageModal code... -->
<!-- Modal remains (unchanged) -->
<div id="packageModal" style="display: <?php echo $edit_package ? 'flex' : 'none'; ?>; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 9999;">
    <!-- ...existing modal content unchanged ... -->
    <?php // modal markup unchanged - omitted for brevity ?>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// client-side search + pagination for packages table
(function(){
    const rows = Array.from(document.querySelectorAll('#packagesTbody .pkg-row'));
    const perPageSelect = document.getElementById('perPage');
    const searchInput = document.getElementById('pkgSearch');
    const paginationContainer = document.getElementById('pkgPagination');
    const infoEl = document.getElementById('pkgInfo');

    let perPage = parseInt(perPageSelect.value, 10) || 10;
    let filtered = rows.slice();
    let currentPage = 1;

    function renderPage() {
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;
        rows.forEach(r => r.style.display = 'none');
        filtered.slice(start, end).forEach(r => r.style.display = '');
        // update info
        const total = filtered.length;
        const showingFrom = total === 0 ? 0 : start + 1;
        const showingTo = Math.min(end, total);
        infoEl.textContent = `Showing ${showingFrom} to ${showingTo} of ${total} results`;
        renderPagination(Math.ceil(total / perPage) || 1);
    }

    function renderPagination(totalPages) {
        paginationContainer.innerHTML = '';
        const ul = document.createElement('ul');
        ul.className = 'pagination';
        // previous
        const prevLi = document.createElement('li'); prevLi.className = 'page-item' + (currentPage===1?' disabled':'');
        prevLi.innerHTML = `<a class="page-link" href="#" aria-label="Previous">&laquo;</a>`; 
        prevLi.addEventListener('click', e => { e.preventDefault(); if (currentPage>1){ currentPage--; renderPage(); }});
        ul.appendChild(prevLi);
        // pages (limit to 7 pages visible)
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
        // next
        const nextLi = document.createElement('li'); nextLi.className = 'page-item' + (currentPage===totalPages?' disabled':'');
        nextLi.innerHTML = `<a class="page-link" href="#" aria-label="Next">&raquo;</a>`; 
        nextLi.addEventListener('click', e => { e.preventDefault(); if (currentPage<totalPages){ currentPage++; renderPage(); }});
        ul.appendChild(nextLi);

        paginationContainer.appendChild(ul);
    }

    function applyFilter() {
        const q = (searchInput.value || '').trim().toLowerCase();
        filtered = rows.filter(r => {
            if (!q) return true;
            const name = (r.getAttribute('data-name') || '').toLowerCase();
            const type = (r.getAttribute('data-type') || '').toLowerCase();
            return name.indexOf(q) !== -1 || type.indexOf(q) !== -1;
        });
        currentPage = 1;
        renderPage();
    }

    perPageSelect.addEventListener('change', function(){ perPage = parseInt(this.value,10) || 10; currentPage=1; renderPage(); });
    searchInput.addEventListener('input', function(){ applyFilter(); });

    document.getElementById('selectAllPkgs')?.addEventListener('change', function(){ document.querySelectorAll('.pkg-checkbox').forEach(cb => cb.checked = this.checked); });

    // initial render
    renderPage();
})();
</script>

<style>
/* table visuals closer to screenshot */
.table-hover tbody tr { border-bottom: 1px solid #f1f3f5; }
.table-hover tbody tr td { padding: 14px 12px; vertical-align: middle; }
.table thead th { padding: 12px; border-bottom: 1px solid #eef2f6; font-weight:600; color:#666; background:#fff; }
.pagination { display:flex; gap:6px; margin:0; padding:0; list-style:none; }
.page-item { display:inline-block; }
.page-item .page-link { display:block; padding:6px 10px; border-radius:6px; border:1px solid #e9ecef; color:#333; text-decoration:none; }
.page-item.active .page-link { background:#667eea; color:#fff; border-color:#667eea; }
.page-item.disabled .page-link { opacity:0.5; pointer-events:none; }
</style>
