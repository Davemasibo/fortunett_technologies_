<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/SMSHelper.php';
redirectIfNotLoggedIn();

// Get tenant context using existing logic (assuming auth.php sets session or we infer from user)
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

$smsHelper = new SMSHelper($pdo, $tenant_id);

// Handle Actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_config'])) {
        $result = $smsHelper->saveConfig($_POST['provider'], $_POST['api_key'], $_POST['sender_id'], $_POST['api_url']);
        if ($result) $success_message = "Configuration saved successfully.";
        else $error_message = "Failed to save configuration.";
    }
    
    if (isset($_POST['save_template'])) {
        $result = $smsHelper->saveTemplate($_POST['template_key'], $_POST['template_name'], $_POST['template_content']);
        if ($result) $success_message = "Template saved successfully.";
        else $error_message = "Failed to save template.";
    }
    
    if (isset($_POST['send_sms'])) {
        $recipientId = $_POST['recipient_id'];
        $message = $_POST['message'];
        
        // Lookup phone
        $cStmt = $pdo->prepare("SELECT phone FROM clients WHERE id = ?");
        $cStmt->execute([$recipientId]);
        $phone = $cStmt->fetchColumn();
        
        if ($phone) {
            $res = $smsHelper->send($phone, $message, $recipientId);
            if ($res['success']) $success_message = "Message queued for delivery.";
            else $error_message = "Failed to send: " . ($res['message'] ?? 'Unknown error');
        } else {
            $error_message = "Client phone number not found.";
        }
    }
}

// Fetch outbox log
$sms_logs = [];
try {
    $logs = $pdo->prepare("SELECT sl.*, c.full_name FROM sms_outbox sl LEFT JOIN clients c ON sl.client_id = c.id WHERE sl.tenant_id = ? ORDER BY sl.sent_at DESC LIMIT 50");
    $logs->execute([$tenant_id]);
    $sms_logs = $logs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Templates
$all_templates = [];
try {
    $t_stmt = $pdo->prepare("SELECT * FROM sms_templates WHERE tenant_id = ? OR is_global = 1 ORDER BY is_global DESC, template_name ASC");
    $t_stmt->execute([$tenant_id]);
    $all_templates = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Config
$config = [];
try {
    $confStmt = $pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id = ?");
    $confStmt->execute([$tenant_id]);
    $config = $confStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Clients
$clientsStmt = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE tenant_id = ? ORDER BY full_name ASC");
$clientsStmt->execute([$tenant_id]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    :root { --neu-bg:#141414; --neu-surf:#1c1c1b; --neu-s2:#222221; --neu-border:rgba(255,255,255,.06); --neu-card:8px 8px 20px rgba(0,0,0,.45),-4px -4px 10px rgba(255,255,255,.03); }
    .main-content-wrapper { background: var(--neu-bg) !important; }
    /* Page-wide inline override */
    .sms-container h1 { color: #e2e2e0 !important; }
    .sms-container > div > div > p { color: rgba(255,255,255,.45) !important; }
    /* Table & card panels */
    .sms-panel { background: var(--neu-s2) !important; border: 1px solid var(--neu-border) !important; box-shadow: var(--neu-card) !important; border-radius: 10px !important; overflow: hidden; }
    .sms-panel-header { padding: 16px; border-bottom: 1px solid var(--neu-border) !important; font-weight: 600; color: #e2e2e0; }
    .sms-table { width: 100%; border-collapse: collapse; }
    .sms-table thead { background: rgba(255,255,255,.04) !important; }
    .sms-table th { text-align: left; padding: 12px; font-size: 12px; color: rgba(255,255,255,.4) !important; }
    .sms-table tr { border-bottom: 1px solid rgba(255,255,255,.04) !important; }
    .sms-table td { padding: 12px; color: #d4d4d2; font-size: 13px; }
    .sms-table tr:hover td { background: rgba(255,255,255,.03); }
    /* Template cards */
    .tpl-card { background: var(--neu-surf) !important; border: 1px solid var(--neu-border) !important; border-radius: 8px; padding: 10px; cursor: pointer; position: relative; }
    .tpl-card:hover { background: rgba(255,255,255,.06) !important; }
    .tpl-new-btn { width: 100%; padding: 8px; border: 1px dashed rgba(255,255,255,.2) !important; background: none !important; color: rgba(255,255,255,.45) !important; border-radius: 6px; cursor: pointer; }
    /* Modal inner panels */
    .sms-modal-inner { background: #222221 !important; border: 1px solid var(--neu-border) !important; color: #e2e2e0 !important; border-radius: 12px !important; padding: 24px !important; }
    .sms-modal-inner h3 { color: #e2e2e0; margin-top: 0; }
    .sms-modal-inner label { color: rgba(255,255,255,.6); font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px; }
    .sms-modal-inner input, .sms-modal-inner select, .sms-modal-inner textarea {
        width: 100%; padding: 9px 11px; margin-bottom: 12px;
        background: var(--neu-surf) !important; border: 1px solid var(--neu-border) !important;
        border-radius: 7px; color: #e2e2e0 !important; font-family: inherit; font-size: 13px;
        box-shadow: inset 3px 3px 7px rgba(0,0,0,.35);
    }
    .sms-modal-inner input::placeholder, .sms-modal-inner textarea::placeholder { color: rgba(255,255,255,.25); }
    .sms-modal-inner input:focus, .sms-modal-inner select:focus, .sms-modal-inner textarea:focus { outline: none; border-color: var(--primary-color, #3B6EA5) !important; }
    .sms-modal-inner select option { background: #222221; }
    .sms-modal-cancel { padding: 8px 16px; margin-right: 8px; background: rgba(255,255,255,.07) !important; border: 1px solid var(--neu-border) !important; border-radius: 6px; cursor: pointer; color: rgba(255,255,255,.6) !important; }
    .sms-status-sent   { padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; background:rgba(52,211,153,.15); color:#6ee7b7; }
    .sms-status-failed { padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; background:rgba(248,113,113,.15); color:#fca5a5; }
    /* Global template badge */
    .global-badge { background: rgba(99,102,241,.15) !important; color: #a5b4fc !important; font-size: 10px; padding: 2px 6px; border-radius: 4px; }
    /* Settings / send buttons */
    .btn-settings { padding:10px 16px; background:var(--neu-s2) !important; border:1px solid var(--neu-border) !important; border-radius:6px; cursor:pointer; display:flex; align-items:center; gap:6px; color:rgba(255,255,255,.7) !important; }
    .btn-settings:hover { background:rgba(255,255,255,.08) !important; }
</style>

<div class="main-content-wrapper">
    <div class="sms-container" style="padding: 24px; max-width: 1400px; margin: 0 auto;">

        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0;">SMS Manager</h1>
                <p style="color: rgba(255,255,255,.45); font-size: 14px;">Manage templates, configuration, and messaging.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="openConfigModal()" class="btn-settings">
                    <i class="fas fa-cog"></i> Settings
                </button>
                <button onclick="openSendModal()" style="padding: 10px 16px; background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-paper-plane"></i> Send SMS
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div style="padding: 12px; background: #D1FAE5; color: #065F46; border-radius: 6px; margin-bottom: 20px;"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div style="padding: 12px; background: #FEE2E2; color: #991B1B; border-radius: 6px; margin-bottom: 20px;"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Content Grid -->
        <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 24px;">

            <!-- Left Column: Logs -->
            <div class="sms-panel">
                <div class="sms-panel-header">Outbox History</div>
                <div style="overflow-x:auto;">
                <table class="sms-table">
                    <thead>
                        <tr>
                            <th>RECIPIENT</th>
                            <th>MESSAGE</th>
                            <th>STATUS</th>
                            <th>DATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sms_logs as $log): ?>
                        <tr>
                            <td>
                                <div style="color:#e2e2e0;"><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size:12px;color:rgba(255,255,255,.35);"><?php echo htmlspecialchars($log['recipient_phone']); ?></div>
                            </td>
                            <td style="max-width:300px;">
                                <?php echo htmlspecialchars(substr($log['message'], 0, 80)) . (strlen($log['message']) > 80 ? '...' : ''); ?>
                            </td>
                            <td>
                                <span class="<?php echo $log['status'] === 'sent' ? 'sms-status-sent' : 'sms-status-failed'; ?>">
                                    <?php echo strtoupper($log['status']); ?>
                                </span>
                            </td>
                            <td style="font-size:12px;color:rgba(255,255,255,.4);">
                                <?php echo date('M d, H:i', strtotime($log['sent_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sms_logs)): ?>
                            <tr><td colspan="4" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);">No sent messages found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Right Column: Templates -->
            <div style="display:flex;flex-direction:column;gap:20px;">
                <div class="sms-panel" style="padding:16px;">
                    <div style="font-weight:600;margin-bottom:12px;color:#e2e2e0;">Templates</div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($all_templates as $tpl): ?>
                        <div onclick='openTemplateModal(<?php echo json_encode($tpl); ?>)' class="tpl-card">
                            <?php if (!empty($tpl['is_global'])): ?>
                                <span class="global-badge" style="position:absolute;top:4px;right:4px;">Global</span>
                            <?php endif; ?>
                            <div style="font-weight:500;font-size:14px;color:#e2e2e0;"><?php echo htmlspecialchars($tpl['template_name']); ?></div>
                            <div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:4px;"><?php echo htmlspecialchars(substr($tpl['template_content'], 0, 40)); ?>...</div>
                        </div>
                        <?php endforeach; ?>
                        <button onclick="openTemplateModal()" class="tpl-new-btn">+ New Template</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Config Modal -->
<div id="configModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;">
    <div class="sms-modal-inner" style="width:400px;max-width:95vw;">
        <h3>SMS Configuration</h3>
        <form method="POST">
            <input type="hidden" name="save_config" value="1">
            <label>Provider</label>
            <select name="provider">
                <option value="talksasa">TalkSasa</option>
                <option value="africastalking">Africa's Talking</option>
            </select>
            <label>API URL</label>
            <input type="text" name="api_url" value="<?php echo htmlspecialchars($config['api_url'] ?? 'https://api.talksasa.com/v1/sms/send'); ?>">
            <label>API Key</label>
            <input type="text" name="api_key" value="<?php echo htmlspecialchars($config['api_key'] ?? ''); ?>" placeholder="API Key">
            <label>Sender ID</label>
            <input type="text" name="sender_id" value="<?php echo htmlspecialchars($config['sender_id'] ?? ''); ?>" placeholder="e.g. FORTUNETT">
            <div style="text-align:right;margin-top:4px;">
                <button type="button" onclick="document.getElementById('configModal').style.display='none'" class="sms-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px;background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary-color) 100%);color:white;border:none;border-radius:6px;cursor:pointer;">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Send Modal -->
<div id="sendModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;">
    <div class="sms-modal-inner" style="width:450px;max-width:95vw;">
        <h3>Send SMS</h3>
        <form method="POST">
            <input type="hidden" name="send_sms" value="1">
            <label>Recipient</label>
            <select name="recipient_id" required>
                <option value="">Select Client...</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['phone']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <label>Message</label>
            <textarea name="message" rows="4" required></textarea>
            <div style="text-align:right;margin-top:4px;">
                <button type="button" onclick="document.getElementById('sendModal').style.display='none'" class="sms-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px;background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary-color) 100%);color:white;border:none;border-radius:6px;cursor:pointer;">Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Template Modal -->
<div id="templateModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;">
    <div class="sms-modal-inner" style="width:450px;max-width:95vw;">
        <h3>Edit Template</h3>
        <form method="POST">
            <input type="hidden" name="save_template" value="1">
            <label>Template Key (Unique ID)</label>
            <input type="text" name="template_key" id="tplKey" required>
            <label>Template Name</label>
            <input type="text" name="template_name" id="tplName" required>
            <label>Content (Variables: {name}, {amount}, {account_number})</label>
            <textarea name="template_content" id="tplContent" rows="4" required></textarea>
            <div style="text-align:right;margin-top:4px;">
                <button type="button" onclick="document.getElementById('templateModal').style.display='none'" class="sms-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px;background:linear-gradient(135deg,var(--primary-dark) 0%,var(--primary-color) 100%);color:white;border:none;border-radius:6px;cursor:pointer;">Save Template</button>
            </div>
        </form>
    </div>
</div>

<script>
function openConfigModal() {
    document.getElementById('configModal').style.display = 'flex';
}
function openSendModal() {
    document.getElementById('sendModal').style.display = 'flex';
}
function openTemplateModal(data) {
    document.getElementById('templateModal').style.display = 'flex';
    if(data) {
        document.getElementById('tplKey').value = data.template_key;
        document.getElementById('tplName').value = data.template_name;
        document.getElementById('tplContent').value = data.template_content;
    } else {
        document.getElementById('tplKey').value = '';
        document.getElementById('tplName').value = '';
        document.getElementById('tplContent').value = '';
    }
}
</script>

<?php include 'includes/footer.php'; ?>