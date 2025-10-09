<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

redirectIfNotLoggedIn();

// Ensure packages.type exists (runtime safe migration)
try {
    $pdo->query("SELECT type FROM packages LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE packages ADD COLUMN type ENUM('hotspot','pppoe') DEFAULT 'hotspot' AFTER id");
    } catch (Exception $ignored) {}
}

// Ensure required columns exist (duration, features, speeds, data_limit, allowed_clients)
try { $pdo->query("SELECT duration FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN duration VARCHAR(50) DEFAULT '30 days' AFTER price"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT features FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN features TEXT AFTER duration"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT download_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN download_speed INT DEFAULT 0 AFTER features"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT upload_speed FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN upload_speed INT DEFAULT 0 AFTER download_speed"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT data_limit FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN data_limit BIGINT DEFAULT 0 AFTER upload_speed"); } catch (Exception $ignored) {} }
try { $pdo->query("SELECT allowed_clients FROM packages LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE packages ADD COLUMN allowed_clients INT DEFAULT 1 AFTER data_limit"); } catch (Exception $ignored) {} }

// Ensure packages.allowed_clients exists (runtime safe migration)
try {
    $pdo->query("SELECT allowed_clients FROM packages LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE packages ADD COLUMN allowed_clients INT DEFAULT 1 AFTER data_limit");
    } catch (Exception $ignored) {}
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

$packages = $pdo->query("SELECT * FROM packages ORDER BY created_at DESC")->fetchAll();
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
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h1 style="margin: 0; color: #333;"><i class="fas fa-box me-3"></i>Service Packages</h1>
                <button onclick="openModal()" style="padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    <i class="fas fa-plus me-2"></i>Add Package
                </button>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div style="padding: 15px 20px; background: #d4edda; color: #155724; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message ?? '')): ?>
            <div style="padding: 15px 20px; background: #f8d7da; color: #721c24; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px;">
            <?php foreach ($packages as $pkg): ?>
                <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                    <h3 style="margin: 0 0 10px 0; color: #667eea; font-size: 24px;"><?php echo htmlspecialchars($pkg['name']); ?></h3>
                    <div style="font-size: 32px; font-weight: bold; color: #333; margin: 15px 0;">KES <?php echo number_format($pkg['price'], 2); ?></div>
                    <div style="padding: 15px 0; border-top: 1px solid #eee; border-bottom: 1px solid #eee; margin: 15px 0;">
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span><i class="fas fa-clock" style="color: #667eea;"></i> Duration:</span>
                            <strong><?php echo $pkg['duration']; ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span><i class="fas fa-download" style="color: #28a745;"></i> Speed:</span>
                            <strong><?php echo $pkg['download_speed']; ?>↓ / <?php echo $pkg['upload_speed']; ?>↑ Mbps</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span><i class="fas fa-database" style="color: #17a2b8;"></i> Data:</span>
                            <strong><?php echo $pkg['data_limit'] > 0 ? round($pkg['data_limit']/1073741824, 2).' GB' : 'Unlimited'; ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 8px 0;">
                            <span><i class="fas fa-user-friends" style="color: #6f42c1;"></i> Allowed Clients:</span>
                            <strong><?php echo (int)($pkg['allowed_clients'] ?? 1); ?></strong>
                        </div>
                    </div>
                    <?php if ($pkg['features']): ?>
                        <ul style="list-style: none; padding: 0; margin: 15px 0;">
                            <?php foreach (array_slice(explode(',', $pkg['features']), 0, 3) as $feature): ?>
                                <li style="padding: 5px 0; color: #666;"><i class="fas fa-check" style="color: #28a745; margin-right: 8px;"></i><?php echo htmlspecialchars(trim($feature)); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
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
    </div>
</div>

<!-- Modal -->
<div id="packageModal" style="display: <?php echo $edit_package ? 'flex' : 'none'; ?>; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 9999;">
    <div style="background: white; width: 90%; max-width: 600px; border-radius: 10px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0;"><?php echo $edit_package ? 'Edit' : 'Add'; ?> Package</h2>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" style="padding: 20px;">
            <input type="hidden" name="action" value="<?php echo $edit_package ? 'update' : 'create'; ?>">
            <?php if ($edit_package): ?><input type="hidden" name="id" value="<?php echo $edit_package['id']; ?>"><?php endif; ?>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Package Type *</label>
                <select name="type" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="hotspot" <?php echo ($edit_package['type'] ?? '') === 'hotspot' ? 'selected' : ''; ?>>Hotspot</option>
                    <option value="pppoe" <?php echo ($edit_package['type'] ?? '') === 'pppoe' ? 'selected' : ''; ?>>PPPoE</option>
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
                    <select name="duration" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <?php
                        $durations = [
                            '1 hour','12 hours','24 hours','3 days','7 days','14 days','30 days','60 days','90 days','6 months','12 months'
                        ];
                        $currentDuration = $edit_package['duration'] ?? '30 days';
                        foreach ($durations as $d) {
                            $selected = ($currentDuration === $d) ? 'selected' : '';
                            echo "<option value=\"$d\" $selected>$d</option>";
                        }
                        ?>
                    </select>
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
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Allowed Clients (same account) *</label>
                <input type="number" name="allowed_clients" min="1" required value="<?php echo $edit_package['allowed_clients'] ?? '1'; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
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
function openModal() { document.getElementById('packageModal').style.display = 'flex'; }
function closeModal() { window.location.href = 'packages.php'; }
</script>

<?php include 'includes/footer.php'; ?>
