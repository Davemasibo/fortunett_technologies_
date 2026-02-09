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

// Fetch Data
$logs = $pdo->prepare("SELECT sl.*, c.full_name FROM sms_outbox sl LEFT JOIN clients c ON sl.client_id = c.id WHERE sl.tenant_id = ? ORDER BY sl.sent_at DESC LIMIT 50");
$logs->execute([$tenant_id]);
$sms_logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$templates = $smsHelper->getTemplates();

// Fetch Config
$confStmt = $pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id = ?");
$confStmt->execute([$tenant_id]);
$config = $confStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch Clients
$clientsStmt = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE tenant_id = ? ORDER BY full_name ASC");
$clientsStmt->execute([$tenant_id]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div class="sms-container" style="padding: 24px; max-width: 1400px; margin: 0 auto;">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 28px; font-weight: 600; color: #111827; margin: 0;">SMS Manager</h1>
                <p style="color: #6B7280; font-size: 14px;">Manage templates, configuration, and messaging.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="openConfigModal()" style="padding: 10px 16px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-cog"></i> Settings
                </button>
                <button onclick="openSendModal()" style="padding: 10px 16px; background: #2C5282; color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
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
            <div style="background: white; border-radius: 8px; border: 1px solid #E5E7EB; overflow: hidden;">
                <div style="padding: 16px; border-bottom: 1px solid #E5E7EB; font-weight: 600;">Outbox History</div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #F9FAFB;">
                        <tr>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">RECIPIENT</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">MESSAGE</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">STATUS</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">DATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sms_logs as $log): ?>
                        <tr style="border-bottom: 1px solid #F3F4F6;">
                            <td style="padding: 12px;">
                                <div><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 12px; color: #9CA3AF;"><?php echo htmlspecialchars($log['recipient_phone']); ?></div>
                            </td>
                            <td style="padding: 12px; max-width: 300px;">
                                <?php echo htmlspecialchars(substr($log['message'], 0, 80)) . (strlen($log['message']) > 80 ? '...' : ''); ?>
                            </td>
                            <td style="padding: 12px;">
                                <span style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; 
                                    background: <?php echo $log['status'] === 'sent' ? '#D1FAE5' : '#FEE2E2'; ?>; 
                                    color: <?php echo $log['status'] === 'sent' ? '#065F46' : '#991B1B'; ?>;">
                                    <?php echo strtoupper($log['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 12px; font-size: 12px; color: #6B7280;">
                                <?php echo date('M d, H:i', strtotime($log['sent_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sms_logs)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 20px; color: #9CA3AF;">No sent messages found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Right Column: Templates -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="background: white; border-radius: 8px; border: 1px solid #E5E7EB; padding: 16px;">
                    <div style="font-weight: 600; margin-bottom: 12px;">Templates</div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($templates as $tpl): ?>
                        <div onclick='openTemplateModal(<?php echo json_encode($tpl); ?>)' style="padding: 10px; border: 1px solid #E5E7EB; border-radius: 6px; cursor: pointer; hover: background: #F9FAFB;">
                            <div style="font-weight: 500; font-size: 14px;"><?php echo htmlspecialchars($tpl['template_name']); ?></div>
                            <div style="font-size: 12px; color: #6B7280; margin-top: 4px;"><?php echo htmlspecialchars(substr($tpl['template_content'], 0, 40)); ?>...</div>
                        </div>
                        <?php endforeach; ?>
                        <button onclick="openTemplateModal()" style="width: 100%; padding: 8px; border: 1px dashed #D1D5DB; background: none; color: #6B7280; border-radius: 6px; cursor: pointer;">+ New Template</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Config Modal -->
<div id="configModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; padding:24px; border-radius:8px; width:400px;">
        <h3 style="margin-top:0;">SMS Configuration</h3>
        <form method="POST">
            <input type="hidden" name="save_config" value="1">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Provider</label>
                <select name="provider" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;">
                    <option value="talksasa">TalkSasa</option>
                    <option value="africastalking">Africa's Talking</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">API URL</label>
                <input type="text" name="api_url" value="<?php echo htmlspecialchars($config['api_url'] ?? 'https://api.talksasa.com/v1/sms/send'); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">API Key</label>
                <input type="text" name="api_key" value="<?php echo htmlspecialchars($config['api_key'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="API Key">
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Sender ID</label>
                <input type="text" name="sender_id" value="<?php echo htmlspecialchars($config['sender_id'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="e.g. FORTUNETT">
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('configModal').style.display='none'" style="padding:8px 16px; margin-right:8px; background:#F3F4F6; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:#2C5282; color:white; border:none; border-radius:4px; cursor:pointer;">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Send Modal -->
<div id="sendModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; padding:24px; border-radius:8px; width:450px;">
        <h3 style="margin-top:0;">Send SMS</h3>
        <form method="POST">
            <input type="hidden" name="send_sms" value="1">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Recipient</label>
                <select name="recipient_id" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
                    <option value="">Select Client...</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['phone']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Message</label>
                <textarea name="message" rows="4" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required></textarea>
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('sendModal').style.display='none'" style="padding:8px 16px; margin-right:8px; background:#F3F4F6; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:#2C5282; color:white; border:none; border-radius:4px; cursor:pointer;">Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Template Modal -->
<div id="templateModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; padding:24px; border-radius:8px; width:450px;">
        <h3 style="margin-top:0;">Edit Template</h3>
        <form method="POST">
            <input type="hidden" name="save_template" value="1">
            <div style="margin-bottom:12px;">
                 <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Template Key (Unique ID)</label>
                 <input type="text" name="template_key" id="tplKey" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
            </div>
            <div style="margin-bottom:12px;">
                 <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Template Name</label>
                 <input type="text" name="template_name" id="tplName" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Content (Variables: {name}, {amount}, {account_number})</label>
                <textarea name="template_content" id="tplContent" rows="4" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required></textarea>
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('templateModal').style.display='none'" style="padding:8px 16px; margin-right:8px; background:#F3F4F6; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:#2C5282; color:white; border:none; border-radius:4px; cursor:pointer;">Save Template</button>
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