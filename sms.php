<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$clients = $pdo->query("SELECT id, COALESCE(name, full_name) AS display_name, phone, mikrotik_username FROM clients ORDER BY display_name ASC")->fetchAll();
$prefill_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$prefill_template = $_GET['template'] ?? '';
$message = '';

// Persist Talksasa settings in a simple config table if not exists
try { $pdo->query("SELECT api_key FROM sms_settings LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("CREATE TABLE IF NOT EXISTS sms_settings (id INT PRIMARY KEY DEFAULT 1, provider VARCHAR(50) DEFAULT 'talksasa', api_url VARCHAR(255), api_key VARCHAR(255), sender_id VARCHAR(20))"); $pdo->exec("INSERT IGNORE INTO sms_settings (id) VALUES (1)"); } catch (Exception $ignored) {} }

$settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        $api_url = trim($_POST['api_url'] ?? '');
        $api_key = trim($_POST['api_key'] ?? '');
        $sender_id = trim($_POST['sender_id'] ?? '');
        $stmt = $pdo->prepare("UPDATE sms_settings SET api_url = ?, api_key = ?, sender_id = ? WHERE id = 1");
        $stmt->execute([$api_url, $api_key, $sender_id]);
        $message = 'SMS settings saved';
        $settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch();
    }
    // Stub: integrate Talksasa API here
    $target = $_POST['target'] ?? 'single';
    $text = trim($_POST['text'] ?? '');
    if ($target === 'single') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT phone FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $phone = $stmt->fetchColumn();
        // TODO: send SMS via Talksasa with $settings['api_url'], $settings['api_key'], $settings['sender_id']
        $message = 'SMS queued to ' . htmlspecialchars($phone);
    } else {
        // TODO: bulk send via Talksasa with saved settings
        $message = 'Bulk SMS queued to all clients';
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h1 style="margin: 0; color: #333;"><i class="fas fa-sms me-3"></i>SMS Center</h1>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><strong>Provider Settings</strong></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">API URL</label>
                            <input type="text" name="api_url" class="form-control" value="<?php echo htmlspecialchars($settings['api_url'] ?? ''); ?>" placeholder="https://api.talksasa.com/sms/send">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">API Key</label>
                            <input type="text" name="api_key" class="form-control" value="<?php echo htmlspecialchars($settings['api_key'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Sender ID</label>
                            <input type="text" name="sender_id" class="form-control" value="<?php echo htmlspecialchars($settings['sender_id'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" name="save_settings" class="btn btn-success">Save Settings</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Send To</label>
                            <select name="target" class="form-select" onchange="document.getElementById('single-select').style.display = this.value==='single'?'block':'none'">
                                <option value="single">Single Client</option>
                                <option value="all">All Clients</option>
                            </select>
                        </div>
                        <div class="col-md-8" id="single-select">
                            <label class="form-label">Client</label>
                            <select name="client_id" id="client_id" class="form-select">
                                <?php foreach ($clients as $c): ?>
                                    <option data-phone="<?php echo htmlspecialchars($c['phone'] ?? ''); ?>" data-name="<?php echo htmlspecialchars($c['display_name'] ?? ''); ?>" value="<?php echo $c['id']; ?>" <?php echo ($prefill_client_id === (int)$c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(($c['display_name'] ?? '—') . ' (' . ($c['phone'] ?? '-') . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label">Template</label>
                            <select id="template" class="form-select" onchange="applyTemplate()">
                                <option value="">-- Choose template --</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="credentials">Credentials</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Sender ID</label>
                            <input type="text" name="sender_id" class="form-control" value="<?php echo htmlspecialchars($settings['sender_id'] ?? ''); ?>" placeholder="e.g. YourBrand">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">Message</label>
                        <textarea name="text" id="text" rows="5" class="form-control" placeholder="Reminder/credentials/payment details..."></textarea>
                        <div class="form-text">Use placeholders: {name}, {package}, {expiry}</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Send</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function applyTemplate(){
  const sel = document.getElementById('template');
  const clientSel = document.getElementById('client_id');
  const opt = clientSel.options[clientSel.selectedIndex];
  const name = opt ? (opt.getAttribute('data-name') || '') : '';
  const pkg = ''; // could fetch live later
  const expiry = '';
  let text = '';
  if (sel.value === 'expiry') {
    text = `Hi {name}, your subscription {package} expires on {expiry}. Kindly renew to stay connected.`;
  } else if (sel.value === 'payment') {
    text = `Hello {name}, payment details:\nPaybill: 123456\nAccount: {name}\nAmount: KES <amount> for {package}. Thank you.`;
  } else if (sel.value === 'credentials') {
    text = `Hello {name}, your WiFi account is ready. Username: {name}. Package: {package}.`;
  }
  text = text.replaceAll('{name}', name).replaceAll('{package}', pkg).replaceAll('{expiry}', expiry);
  document.getElementById('text').value = text.trim();
}
// preselect template from query
(function(){
  const t = <?php echo json_encode($prefill_template); ?>;
  if (t) {
    const sel = document.getElementById('template');
    sel.value = t;
    applyTemplate();
  }
})();
</script>


