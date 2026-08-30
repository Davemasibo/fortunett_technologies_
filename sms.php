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
        $recipientId = (int)($_POST['recipient_id'] ?? 0);
        $message     = trim($_POST['message'] ?? '');

        // Tenant-scoped, and the whole row rather than just the phone: the
        // placeholders below need it. The old query looked the client up by id
        // ALONE, so any logged-in tenant could message another tenant's
        // customer by changing the id in the form.
        $cStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
        $cStmt->execute([$recipientId, $tenant_id]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$client || empty($client['phone'])) {
            $error_message = "Customer not found in your account, or they have no phone number.";
        } elseif ($message === '') {
            $error_message = "Message cannot be empty.";
        } else {
            // Substitute whether the text came from a template or was typed, so
            // {name} always resolves and a customer never receives raw braces.
            $message = $smsHelper->renderPlaceholders($message, $client);

            $res = $smsHelper->send($client['phone'], $message, $recipientId);
            if ($res['success']) {
                $success_message = "Message sent to " . htmlspecialchars($client['full_name'] ?? $client['phone']) . ".";
            } else {
                $error_message = "Failed to send: " . ($res['message'] ?? 'Unknown error');
            }
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
            <select name="recipient_id" id="sendRecipient" required onchange="updateSmsMeta()">
                <option value="">Select Client...</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?php echo $c['id']; ?>"
                            data-name="<?php echo htmlspecialchars($c['full_name'] ?? '', ENT_QUOTES); ?>"
                            data-phone="<?php echo htmlspecialchars($c['phone'] ?? '', ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['phone']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Template <span style="font-weight:400;color:rgba(255,255,255,.35);font-size:12px;">(optional — fills the message below, which you can still edit)</span></label>
            <select id="sendTemplatePicker" onchange="applySmsTemplate(this)">
                <option value="">Write my own message...</option>
                <?php foreach ($all_templates as $tpl): ?>
                    <option value="<?php echo htmlspecialchars($tpl['template_content'], ENT_QUOTES); ?>">
                        <?php echo htmlspecialchars($tpl['template_name']); ?><?php echo !empty($tpl['is_global']) ? ' (global)' : ''; ?>
                    </option>
                <?php endforeach; ?>
                <?php if (empty($all_templates)): ?>
                    <option value="" disabled>No templates yet — create one under Templates</option>
                <?php endif; ?>
            </select>

            <label>Message</label>
            <textarea name="message" id="sendMessageBox" rows="4" required oninput="updateSmsMeta()"></textarea>

            <div style="display:flex;justify-content:space-between;gap:10px;font-size:11px;color:rgba(255,255,255,.4);margin-top:-2px;">
                <span>Placeholders: <code>{name}</code> <code>{account_number}</code> <code>{expiry_date}</code> <code>{username}</code> <code>{password}</code> <code>{amount}</code></span>
                <span id="smsCounter" style="white-space:nowrap;">0 chars · 0 SMS</span>
            </div>

            <div id="smsPreview" style="display:none;margin-top:10px;padding:10px 12px;border-radius:8px;
                 background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
                 font-size:12px;color:#a5f3fc;line-height:1.5;word-break:break-word;"></div>

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

<script>
/* ── Send SMS box: template picker, segment counter, live preview ─────────────
   The template dropdown only FILLS the textarea — the message still goes through
   the normal send path, so an operator can pick a template and then edit it. The
   server substitutes {placeholders} against the chosen customer either way, so
   editing a template can no longer send a customer a literal "{name}". */
function applySmsTemplate(sel) {
    if (!sel.value) return;
    const box = document.getElementById('sendMessageBox');
    box.value = sel.value;
    updateSmsMeta();
    box.focus();
}

function updateSmsMeta() {
    const box  = document.getElementById('sendMessageBox');
    const rcpt = document.getElementById('sendRecipient');
    if (!box) return;

    const opt  = rcpt && rcpt.selectedIndex > 0 ? rcpt.options[rcpt.selectedIndex] : null;
    const name  = opt ? (opt.dataset.name  || '') : '';
    const phone = opt ? (opt.dataset.phone || '') : '';

    // Only the two fields this page actually knows are previewed. The rest are
    // filled server-side from the customer's row; showing them as still-unfilled
    // is more honest than guessing a value that may differ at send time.
    let preview = box.value
        .replace(/\{name\}/g,  name  || '{name}')
        .replace(/\{phone\}/g, phone || '{phone}');

    // GSM-7 segments: 160 chars, or 153 each once it is multipart.
    const len  = box.value.length;
    const segs = len === 0 ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153));
    const counter = document.getElementById('smsCounter');
    if (counter) {
        counter.textContent = len + ' chars · ' + segs + ' SMS';
        counter.style.color = segs > 1 ? '#fcd34d' : 'rgba(255,255,255,.4)';
    }

    const box2 = document.getElementById('smsPreview');
    if (box2) {
        const changed = preview !== box.value;
        box2.style.display = (changed && len) ? 'block' : 'none';
        box2.textContent = 'Preview: ' + preview;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
