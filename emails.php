<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/classes/EmailHelper.php';
redirectIfNotLoggedIn();

// Get tenant context
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

$emailHelper = new EmailHelper($pdo, $tenant_id);

// Handle Actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_config'])) {
        $result = $emailHelper->saveConfig($_POST['smtp_host'], $_POST['smtp_port'], $_POST['smtp_username'], $_POST['smtp_password'], $_POST['from_email'], $_POST['from_name']);
        if ($result) $success_message = "SMTP Configuration saved successfully.";
        else $error_message = "Failed to save configuration.";
    }
    
    if (isset($_POST['save_template'])) {
        $result = $emailHelper->saveTemplate($_POST['template_key'], $_POST['subject'], $_POST['body_content']);
        if ($result) $success_message = "Template saved successfully.";
        else $error_message = "Failed to save template.";
    }
    
    if (isset($_POST['send_email'])) {
        $recipientId = $_POST['recipient_id'];
        $subject = $_POST['subject'];
        $message = $_POST['message'];
        
        // Lookup email
        $cStmt = $pdo->prepare("SELECT email FROM clients WHERE id = ?");
        $cStmt->execute([$recipientId]);
        $email = $cStmt->fetchColumn();
        
        if ($email) {
            $res = $emailHelper->send($email, $subject, $message, $recipientId);
            if ($res['success']) $success_message = "Email sent successfully.";
            else $error_message = "Failed to send: " . ($res['message'] ?? 'Unknown error');
        } else {
            $error_message = "Client email address not found.";
        }
    }
}

// Fetch outbox log
$email_logs = [];
try {
    $logs = $pdo->prepare("SELECT el.*, c.full_name FROM email_outbox el LEFT JOIN clients c ON el.client_id = c.id WHERE el.tenant_id = ? ORDER BY el.sent_at DESC LIMIT 50");
    $logs->execute([$tenant_id]);
    $email_logs = $logs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Templates
$all_templates = [];
try {
    $t_stmt = $pdo->prepare("SELECT * FROM email_templates WHERE tenant_id = ? OR is_global = 1 ORDER BY is_global DESC, template_name ASC");
    $t_stmt->execute([$tenant_id]);
    $all_templates = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Config
$config = [];
try {
    $confStmt = $pdo->prepare("SELECT * FROM email_configurations WHERE tenant_id = ?");
    $confStmt->execute([$tenant_id]);
    $config = $confStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { /* table may not exist yet */ }

// Fetch Clients
$clientsStmt = $pdo->prepare("SELECT id, full_name, email FROM clients WHERE tenant_id = ? AND email IS NOT NULL AND email != '' ORDER BY full_name ASC");
$clientsStmt->execute([$tenant_id]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    :root { --neu-bg:#141414; --neu-surf:#1c1c1b; --neu-s2:#222221; --neu-border:rgba(255,255,255,.06); --neu-card:8px 8px 20px rgba(0,0,0,.45),-4px -4px 10px rgba(255,255,255,.03); }
    .main-content-wrapper { background: var(--neu-bg) !important; }
    .email-panel { background: var(--neu-s2) !important; border: 1px solid var(--neu-border) !important; box-shadow: var(--neu-card) !important; border-radius: 10px !important; overflow: hidden; }
    .email-panel-header { padding: 16px; border-bottom: 1px solid var(--neu-border); font-weight: 600; color: #e2e2e0; }
    .email-table { width: 100%; border-collapse: collapse; }
    .email-table thead { background: rgba(255,255,255,.04); }
    .email-table th { text-align: left; padding: 12px; font-size: 12px; color: rgba(255,255,255,.4); }
    .email-table tr { border-bottom: 1px solid rgba(255,255,255,.04); }
    .email-table td { padding: 12px; color: #d4d4d2; font-size: 13px; }
    .email-table tr:hover td { background: rgba(255,255,255,.03); }
    .tpl-card { background: var(--neu-surf) !important; border: 1px solid var(--neu-border) !important; border-radius: 8px; padding: 10px; cursor: pointer; position: relative; }
    .tpl-card:hover { background: rgba(255,255,255,.06) !important; }
    .tpl-new-btn { width: 100%; padding: 8px; border: 1px dashed rgba(255,255,255,.2) !important; background: none !important; color: rgba(255,255,255,.45) !important; border-radius: 6px; cursor: pointer; }
    .email-modal-inner { background: #222221 !important; border: 1px solid var(--neu-border) !important; color: #e2e2e0 !important; border-radius: 12px !important; padding: 24px !important; }
    .email-modal-inner h3 { color: #e2e2e0; margin-top: 0; }
    .email-modal-inner label { color: rgba(255,255,255,.6); font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px; }
    .email-modal-inner input, .email-modal-inner select, .email-modal-inner textarea {
        width: 100%; padding: 9px 11px; margin-bottom: 12px;
        background: var(--neu-surf) !important; border: 1px solid var(--neu-border) !important;
        border-radius: 7px; color: #e2e2e0 !important; font-family: inherit; font-size: 13px;
        box-shadow: inset 3px 3px 7px rgba(0,0,0,.35); box-sizing: border-box;
    }
    .email-modal-inner input::placeholder, .email-modal-inner textarea::placeholder { color: rgba(255,255,255,.25); }
    .email-modal-inner input:focus, .email-modal-inner select:focus, .email-modal-inner textarea:focus { outline: none; border-color: var(--primary-color, #3B6EA5) !important; }
    .email-modal-inner select option { background: #222221; }
    .email-modal-cancel { padding: 8px 16px; margin-right: 8px; background: rgba(255,255,255,.07) !important; border: 1px solid var(--neu-border) !important; border-radius: 6px; cursor: pointer; color: rgba(255,255,255,.6) !important; }
    .btn-settings { padding:10px 16px; background:var(--neu-s2) !important; border:1px solid var(--neu-border) !important; border-radius:6px; cursor:pointer; display:flex; align-items:center; gap:6px; color:rgba(255,255,255,.7) !important; }
    .btn-settings:hover { background:rgba(255,255,255,.08) !important; }
    .email-status-sent   { padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; background:rgba(52,211,153,.15); color:#6ee7b7; }
    .email-status-failed { padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; background:rgba(248,113,113,.15); color:#fca5a5; }
    .global-badge { background:rgba(99,102,241,.15) !important; color:#a5b4fc !important; font-size:10px; padding:2px 6px; border-radius:4px; }
</style>

<div class="main-content-wrapper">
    <div class="emails-container" style="padding: 24px; max-width: 1400px; margin: 0 auto;">

        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 28px; font-weight: 600; color: #e2e2e0; margin: 0;">Email Manager</h1>
                <p style="color: rgba(255,255,255,.45); font-size: 14px;">Manage templates, SMTP, and communications.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="openConfigModal()" class="btn-settings">
                    <i class="fas fa-cog"></i> SMTP Settings
                </button>
                <button onclick="openSendModal()" style="padding: 10px 16px; background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-paper-plane"></i> Send Email
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div style="padding: 12px; background: rgba(52,211,153,.12); color: #6ee7b7; border: 1px solid rgba(52,211,153,.25); border-radius: 6px; margin-bottom: 20px;"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div style="padding: 12px; background: rgba(248,113,113,.12); color: #fca5a5; border: 1px solid rgba(248,113,113,.25); border-radius: 6px; margin-bottom: 20px;"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Content Grid -->
        <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 24px;">

            <!-- Left Column: Logs -->
            <div class="email-panel">
                <div class="email-panel-header">Sent Emails</div>
                <div style="overflow-x:auto;">
                <table class="email-table">
                    <thead>
                        <tr>
                            <th>RECIPIENT</th>
                            <th>SUBJECT</th>
                            <th>STATUS</th>
                            <th>DATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($email_logs as $log): ?>
                        <tr>
                            <td>
                                <div><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 12px; color: rgba(255,255,255,.35);"><?php echo htmlspecialchars($log['recipient_email']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($log['subject']); ?></td>
                            <td>
                                <span class="<?php echo $log['status'] === 'sent' ? 'email-status-sent' : 'email-status-failed'; ?>">
                                    <?php echo strtoupper($log['status']); ?>
                                </span>
                            </td>
                            <td style="font-size: 12px; color: rgba(255,255,255,.35);">
                                <?php echo date('M d, H:i', strtotime($log['sent_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($email_logs)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 20px; color: rgba(255,255,255,.3);">No emails sent yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Right Column: Templates -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div class="email-panel" style="padding: 16px;">
                    <div style="font-weight: 600; margin-bottom: 12px; color: #e2e2e0;">Templates</div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($all_templates as $tpl): ?>
                        <div onclick='openTemplateModal(<?php echo json_encode($tpl); ?>)' class="tpl-card">
                            <?php if (!empty($tpl['is_global'])): ?>
                                <span class="global-badge" style="position: absolute; top: 4px; right: 4px;">Global</span>
                            <?php endif; ?>
                            <div style="font-weight: 500; font-size: 14px; color: #e2e2e0;"><?php echo htmlspecialchars($tpl['template_name']); ?></div>
                            <div style="font-size: 12px; color: rgba(255,255,255,.4); margin-top: 4px;"><?php echo htmlspecialchars($tpl['subject']); ?></div>
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
<div id="configModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div class="email-modal-inner" style="width:420px; max-width:95vw;">
        <h3>SMTP Configuration</h3>
        <form method="POST">
            <input type="hidden" name="save_config" value="1">
            <label>SMTP Host</label>
            <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
            <label>Port</label>
            <input type="text" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp_port'] ?? '587'); ?>">
            <label>Username</label>
            <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($config['smtp_username'] ?? ''); ?>" placeholder="Email">
            <label>Password</label>
            <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($config['smtp_password'] ?? ''); ?>" placeholder="Password">
            <label>From Email</label>
            <input type="text" name="from_email" value="<?php echo htmlspecialchars($config['from_email'] ?? ''); ?>" placeholder="no-reply@domain.com">
            <label>From Name</label>
            <input type="text" name="from_name" value="<?php echo htmlspecialchars($config['from_name'] ?? ''); ?>" placeholder="Sender Name" style="margin-bottom:20px;">
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('configModal').style.display='none'" class="email-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer;">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Send Modal -->
<div id="sendModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div class="email-modal-inner" style="width:550px; max-width:95vw;">
        <h3>Send Email</h3>
        <form method="POST">
            <input type="hidden" name="send_email" value="1">
            <label>Recipient</label>
            <select name="recipient_id" id="emailRecipient" onchange="checkTemplate()" required>
                <option value="">Select Client...</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['email']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <label>Use Template (Optional)</label>
            <select id="useTemplate" onchange="applyTemplate()">
                <option value="">-- Manual Entry --</option>
                <?php foreach ($all_templates as $tpl): ?>
                    <option value="<?php echo htmlspecialchars($tpl['template_key']); ?>"
                            data-subject="<?php echo htmlspecialchars($tpl['subject']); ?>"
                            data-body="<?php echo htmlspecialchars($tpl['body_content']); ?>">
                            <?php echo htmlspecialchars($tpl['template_key']); ?> - <?php echo htmlspecialchars($tpl['subject']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Subject</label>
            <input type="text" name="subject" id="emailSubject" required>
            <label>Message (HTML)</label>
            <textarea name="message" id="emailBody" rows="6" style="font-family:monospace;" required></textarea>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('sendModal').style.display='none'" class="email-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer;">Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Template Modal -->
<div id="templateModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div class="email-modal-inner" style="width:550px; max-width:95vw; max-height:85vh; overflow:auto;">
        <h3>Edit Template</h3>
        <form method="POST">
            <input type="hidden" name="save_template" value="1">
            <label>Template Key (Unique ID)</label>
            <input type="text" name="template_key" id="tplKey" required>
            <label>Subject</label>
            <input type="text" name="subject" id="tplSubject" required>
            <label>Body HTML (Variables: {name}, {username}, {password}, {amount})</label>
            <textarea name="body_content" id="tplContent" rows="8" style="font-family:monospace;" required></textarea>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('templateModal').style.display='none'" class="email-modal-cancel">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary-color,#3B6EA5) 100%); color:white; border:none; border-radius:6px; cursor:pointer;">Save Template</button>
            </div>
        </form>
    </div>
</div>

<script>
function openConfigModal() { document.getElementById('configModal').style.display = 'flex'; }
function openSendModal() { document.getElementById('sendModal').style.display = 'flex'; }

function openTemplateModal(data) {
    document.getElementById('templateModal').style.display = 'flex';
    if(data) {
        document.getElementById('tplKey').value = data.template_key;
        document.getElementById('tplSubject').value = data.subject;
        document.getElementById('tplContent').value = data.body_content;
    } else {
        document.getElementById('tplKey').value = '';
        document.getElementById('tplSubject').value = '';
        document.getElementById('tplContent').value = '';
    }
}

function applyTemplate() {
    const selector = document.getElementById('useTemplate');
    if(!selector.value) return;
    
    const option = selector.options[selector.selectedIndex];
    document.getElementById('emailSubject').value = option.getAttribute('data-subject');
    document.getElementById('emailBody').value = option.getAttribute('data-body');
}

// Close modals on outside click
window.onclick = function(event) {
    const config = document.getElementById('configModal');
    const send = document.getElementById('sendModal');
    const tpl = document.getElementById('templateModal');
    if (event.target == config) config.style.display='none';
    if (event.target == send) send.style.display='none';
    if (event.target == tpl) tpl.style.display='none';
}
</script>

<?php include 'includes/footer.php'; ?>