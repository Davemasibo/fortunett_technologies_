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

// Fetch Data
$logs = $pdo->prepare("SELECT el.*, c.full_name FROM email_outbox el LEFT JOIN clients c ON el.client_id = c.id WHERE el.tenant_id = ? ORDER BY el.sent_at DESC LIMIT 50");
$logs->execute([$tenant_id]);
$email_logs = $logs->fetchAll(PDO::FETCH_ASSOC);

// Fetch Templates (Tenant specific + Global)
$t_stmt = $pdo->prepare("SELECT * FROM email_templates WHERE tenant_id = ? OR is_global = 1 ORDER BY is_global DESC, template_name ASC");
$t_stmt->execute([$tenant_id]);
$all_templates = $t_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to organize templates logic if needed, but simple fetch is fine
$emailHelper = new stdClass(); // Mock if needed or remove if $emailHelper was used


// Fetch Config
$confStmt = $pdo->prepare("SELECT * FROM email_configurations WHERE tenant_id = ?");
$confStmt->execute([$tenant_id]);
$config = $confStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch Clients
$clientsStmt = $pdo->prepare("SELECT id, full_name, email FROM clients WHERE tenant_id = ? AND email IS NOT NULL AND email != '' ORDER BY full_name ASC");
$clientsStmt->execute([$tenant_id]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div class="emails-container" style="padding: 24px; max-width: 1400px; margin: 0 auto;">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="font-size: 28px; font-weight: 600; color: #111827; margin: 0;">Email Manager</h1>
                <p style="color: #6B7280; font-size: 14px;">Manage templates, SMTP, and communications.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="openConfigModal()" style="padding: 10px 16px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; color: #374151;">
                    <i class="fas fa-cog"></i> SMTP Settings
                </button>
                <button onclick="openSendModal()" style="padding: 10px 16px; background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-paper-plane"></i> Send Email
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
                <div style="padding: 16px; border-bottom: 1px solid #E5E7EB; font-weight: 600;">Sent Emails</div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #F9FAFB;">
                        <tr>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">RECIPIENT</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">SUBJECT</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">STATUS</th>
                            <th style="text-align: left; padding: 12px; font-size: 12px; color: #6B7280;">DATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($email_logs as $log): ?>
                        <tr style="border-bottom: 1px solid #F3F4F6;">
                            <td style="padding: 12px;">
                                <div><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size: 12px; color: #9CA3AF;"><?php echo htmlspecialchars($log['recipient_email']); ?></div>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($log['subject']); ?>
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
                        <?php if (empty($email_logs)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 20px; color: #9CA3AF;">No emails sent yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Right Column: Templates -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="background: white; border-radius: 8px; border: 1px solid #E5E7EB; padding: 16px;">
                    <div style="font-weight: 600; margin-bottom: 12px;">Templates</div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($all_templates as $tpl): ?>
                        <div onclick='openTemplateModal(<?php echo json_encode($tpl); ?>)' style="padding: 10px; border: 1px solid #E5E7EB; border-radius: 6px; cursor: pointer; position: relative;">
                            <?php if (!empty($tpl['is_global'])): ?>
                                <span style="position: absolute; top: 4px; right: 4px; background: #E0E7FF; color: #4338CA; font-size: 10px; padding: 2px 6px; border-radius: 4px;">Global</span>
                            <?php endif; ?>
                            <div style="font-weight: 500; font-size: 14px;"><?php echo htmlspecialchars($tpl['template_name']); ?></div>
                            <div style="font-size: 12px; color: #6B7280; margin-top: 4px;"><?php echo htmlspecialchars($tpl['subject']); ?></div>
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
        <h3 style="margin-top:0;">SMTP Configuration</h3>
        <form method="POST">
            <input type="hidden" name="save_config" value="1">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">SMTP Host</label>
                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp_host'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="smtp.gmail.com">
            </div>
             <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Port</label>
                <input type="text" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp_port'] ?? '587'); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Username</label>
                <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($config['smtp_username'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="Email">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Password</label>
                <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($config['smtp_password'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="Password">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">From Email</label>
                <input type="text" name="from_email" value="<?php echo htmlspecialchars($config['from_email'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="no-reply@domain.com">
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">From Name</label>
                <input type="text" name="from_name" value="<?php echo htmlspecialchars($config['from_name'] ?? ''); ?>" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" placeholder="Sender Name">
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('configModal').style.display='none'" style="padding:8px 16px; margin-right:8px; background:#F3F4F6; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color:white; border:none; border-radius:4px; cursor:pointer;">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Send Modal -->
<div id="sendModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; padding:24px; border-radius:8px; width:550px;">
        <h3 style="margin-top:0;">Send Email</h3>
        <form method="POST">
            <input type="hidden" name="send_email" value="1">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Recipient</label>
                <select name="recipient_id" id="emailRecipient" onchange="checkTemplate()" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
                    <option value="">Select Client...</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['email']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom:12px;">
                 <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Use Template (Optional)</label>
                 <select id="useTemplate" onchange="applyTemplate()" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;">
                    <option value="">-- Manual Entry --</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?php echo htmlspecialchars($tpl['template_key']); ?>"
                                data-subject="<?php echo htmlspecialchars($tpl['subject']); ?>"
                                data-body="<?php echo htmlspecialchars($tpl['body_content']); ?>">
                                <?php echo htmlspecialchars($tpl['template_key']); ?> - <?php echo htmlspecialchars($tpl['subject']); ?>
                        </option>
                    <?php endforeach; ?>
                 </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Subject</label>
                <input type="text" name="subject" id="emailSubject" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Message (HTML)</label>
                <textarea name="message" id="emailBody" rows="6" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px; font-family: monospace;" required></textarea>
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
    <div style="background:white; padding:24px; border-radius:8px; width:550px; max-height:85vh; overflow:auto;">
        <h3 style="margin-top:0;">Edit Template</h3>
        <form method="POST">
            <input type="hidden" name="save_template" value="1">
            <div style="margin-bottom:12px;">
                 <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Template Key (Unique ID)</label>
                 <input type="text" name="template_key" id="tplKey" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
            </div>
            <div style="margin-bottom:12px;">
                 <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Subject</label>
                 <input type="text" name="subject" id="tplSubject" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px;" required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Body HTML (Variables: {name}, {username}, {password}, {amount})</label>
                <textarea name="body_content" id="tplContent" rows="8" style="width:100%; padding:8px; border:1px solid #D1D5DB; border-radius:4px; font-family: monospace;" required></textarea>
            </div>
            <div style="text-align:right;">
                <button type="button" onclick="document.getElementById('templateModal').style.display='none'" style="padding:8px 16px; margin-right:8px; background:#F3F4F6; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                <button type="submit" style="padding:8px 16px; background:#2C5282; color:white; border:none; border-radius:4px; cursor:pointer;">Save Template</button>
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