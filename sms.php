<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get SMS logs
try {
    $sms_logs = $pdo->query("SELECT sl.*, c.full_name AS client_name 
                               FROM sms_logs sl 
                               LEFT JOIN clients c ON sl.client_id = c.id 
                               ORDER BY sl.sent_at DESC 
                               LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch clients for dropdown
    $clients = $pdo->query("SELECT id, full_name, phone, username, password, expiry_date, account_number, package_price FROM clients ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sms_logs = [];
    $clients = [];
}

// Get SMS settings
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_settings (
        id INT PRIMARY KEY NOT NULL,
        provider VARCHAR(50) DEFAULT 'talksasa',
        api_url VARCHAR(255),
        api_key VARCHAR(255),
        sender_id VARCHAR(20),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_settings WHERE id = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $ins = $pdo->prepare("INSERT INTO sms_settings (id, provider, api_url, sender_id) VALUES (1, 'talksasa', ?, ?)");
        $ins->execute(['https://api.talksasa.com/v1/sms/send', 'TALKSASA']);
    }
    
    $sms_settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sms_settings = null;
}

// Handle SMS settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms'])) {
    $provider = trim($_POST['provider'] ?? 'talksasa');
    $api_url = trim($_POST['api_url'] ?? '');
    $api_key = trim($_POST['api_key'] ?? '');
    $sender_id = trim($_POST['sender_id'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE sms_settings SET provider = ?, api_url = ?, api_key = ?, sender_id = ?, updated_at = NOW() WHERE id = 1");
    $stmt->execute([$provider, $api_url, $api_key, $sender_id]);
    
    header('Location: sms.php?saved=1');
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .sms-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .sms-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }
    
    .sms-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0;
    }
    
    .send-sms-btn {
        padding: 10px 20px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Search Bar */
    .search-bar {
        background: white;
        border-radius: 10px;
        padding: 16px 20px;
        margin-bottom: 20px;
        border: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .search-input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .filter-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #D1D5DB;
        background: white;
        color: #6B7280;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
    
    /* SMS Table */
    .sms-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .sms-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .sms-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .sms-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .sms-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .sms-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .status-badge.sent {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .status-badge.failed {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    .view-btn {
        color: #3B6EA5;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
    }
    
    .pagination {
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-top: 1px solid #E5E7EB;
    }
    
    .pagination-info {
        font-size: 13px;
        color: #6B7280;
    }
    
    .pagination-controls {
        display: flex;
        gap: 8px;
    }
    
    .page-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: 1px solid #E5E7EB;
        background: white;
        color: #374151;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 14px;
    }
    
    .page-btn.active {
        background: #3B6EA5;
        color: white;
        border-color: #3B6EA5;
    }
    
    /* SMS Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 10px;
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: none;
        background: #F3F4F6;
        color: #6B7280;
        cursor: pointer;
        font-size: 20px;
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 8px;
    }
    
    .form-input {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #E5E7EB;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }
    
    .btn-cancel {
        padding: 10px 20px;
        background: #F3F4F6;
        color: #374151;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
    }
    
    .btn-save {
        padding: 10px 20px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
    }
</style>

<div class="main-content-wrapper">
    <div class="sms-container">
        <!-- Header -->
        <div class="sms-header">
            <h1 class="sms-title">SMS</h1>
            <button class="send-sms-btn" onclick="openSendModal()">
                <i class="fas fa-comment"></i>
                Send SMS
            </button>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search" style="color: #9CA3AF;"></i>
            <input type="text" class="search-input" placeholder="Search">
            <button class="filter-icon" onclick="openSMSModal()">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <!-- SMS Table -->
        <div class="sms-section">
            <table class="sms-table">
                <thead>
                    <tr>
                        <th>RECIPIENT</th>
                        <th>PHONE</th>
                        <th>MESSAGE</th>
                        <th>STATUS</th>
                        <th>SENT AT</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sms_logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #9CA3AF;">
                            No SMS sent yet
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($sms_logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['client_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($log['recipient_phone']); ?></td>
                        <td><?php echo htmlspecialchars(substr($log['message'], 0, 50)) . '...'; ?></td>
                        <td>
                            <span class="status-badge <?php echo strtolower($log['status'] ?? 'sent'); ?>">
                                <?php echo ucfirst($log['status'] ?? 'sent'); ?>
                            </span>
                        </td>
                        <td><?php echo date('d.m.Y H:i', strtotime($log['sent_at'])); ?></td>
                        <td>
                            <a href="#" class="view-btn">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <div class="pagination-info">
                    Showing 1 to <?php echo min(10, count($sms_logs)); ?> of <?php echo count($sms_logs); ?> results
                </div>
                <div class="pagination-controls">
                    <button class="page-btn active">1</button>
                    <button class="page-btn">2</button>
                    <button class="page-btn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SMS Configuration Modal -->
<div class="modal-overlay" id="smsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">SMS Provider Configuration</h3>
            <button class="modal-close" onclick="closeSMSModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?php if (isset($_GET['saved'])): ?>
                <div style="padding: 12px; background: #D1FAE5; color: #065F46; border-radius: 6px; margin-bottom: 16px;">
                    Settings saved successfully!
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">Provider</label>
                    <select name="provider" class="form-input">
                        <option value="talksasa" <?php echo ($sms_settings['provider'] ?? '') === 'talksasa' ? 'selected' : ''; ?>>TalkSasa</option>
                        <option value="africastalking">Africa's Talking</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">API URL</label>
                    <input type="text" name="api_url" class="form-input" value="<?php echo htmlspecialchars($sms_settings['api_url'] ?? ''); ?>" placeholder="https://api.talksasa.com/v1/sms/send">
                </div>
                
                <div class="form-group">
                    <label class="form-label">API Key</label>
                    <input type="text" name="api_key" class="form-input" value="<?php echo htmlspecialchars($sms_settings['api_key'] ?? ''); ?>" placeholder="Your API Key">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Sender ID</label>
                    <input type="text" name="sender_id" class="form-input" value="<?php echo htmlspecialchars($sms_settings['sender_id'] ?? ''); ?>" placeholder="TALKSASA">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeSMSModal()">Cancel</button>
                <button type="submit" name="save_sms" class="btn-save">Save Configuration</button>
            </div>
        </form>
    </div>
</div>

<!-- Send SMS Modal -->
<div class="modal-overlay" id="sendSMSModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Send SMS</h3>
            <button class="modal-close" onclick="closeSendModal()">&times;</button>
        </div>
        <form id="sendSMSForm" onsubmit="handleGlobalSend(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Recipient</label>
                    <select id="smsRecipient" name="recipient" class="form-input" onchange="applyGlobalTemplate()" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" 
                                    data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                                    data-name="<?php echo htmlspecialchars($c['full_name']); ?>"
                                    data-username="<?php echo htmlspecialchars($c['username']); ?>"
                                    data-password="<?php echo htmlspecialchars($c['password']); ?>"
                                    data-expiry="<?php echo htmlspecialchars($c['expiry_date']); ?>"
                                    data-account="<?php echo htmlspecialchars($c['account_number']); ?>"
                                    data-price="<?php echo htmlspecialchars($c['package_price']); ?>">
                                <?php echo htmlspecialchars($c['full_name']); ?> (<?php echo htmlspecialchars($c['phone']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                     <label class="form-label">Template</label>
                     <select id="globalTemplate" class="form-input" onchange="applyGlobalTemplate()">
                        <option value="">-- Select a Template --</option>
                        <option value="credentials">Login Credentials</option>
                        <option value="payment">Payment Details</option>
                        <option value="alert">Service Alert</option>
                        <option value="promo">Promotional Message</option>
                     </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea id="globalMessage" name="message" class="form-input" rows="5" required placeholder="Type message..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeSendModal()">Cancel</button>
                <button type="submit" class="btn-save">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSMSModal() {
    document.getElementById('smsModal').classList.add('active');
}

function closeSMSModal() {
    document.getElementById('smsModal').classList.remove('active');
}

// Close modal when clicking outside
</script>

<script>
function openSendModal() {
    document.getElementById('sendSMSModal').classList.add('active');
}

function closeSendModal() {
    document.getElementById('sendSMSModal').classList.remove('active');
}

function applyGlobalTemplate() {
    const template = document.getElementById('globalTemplate').value;
    const recipient = document.getElementById('smsRecipient');
    const msgBox = document.getElementById('globalMessage');
    
    if (!recipient.value) return; // Wait for recipient
    
    const opt = recipient.options[recipient.selectedIndex];
    
    const name = opt.getAttribute('data-name') || 'Customer';
    const username = opt.getAttribute('data-username') || '[Username]';
    const password = opt.getAttribute('data-password') || '[Password]';
    const expiryDate = opt.getAttribute('data-expiry');
    const expiry = expiryDate ? new Date(expiryDate).toLocaleDateString() : '[Date]';
    const account = opt.getAttribute('data-account') || opt.value;
    const price = opt.getAttribute('data-price') || '0';
    
    let text = '';
    
    switch(template) {
        case 'credentials':
             text = `Hello ${name}, your internet login details are:\nUsername: ${username}\nPassword: ${password}\nExpires: ${expiry}\nThank you for choosing Fortunnet.`;
            break;
        case 'payment':
            text = `Dear ${name}, kindly make your payment of KES ${price} to Paybill: 247247, Account: ${account}.\nTo avoid disconnection, please pay before ${expiry}.`;
            break;
        case 'alert':
            text = `Dear ${name}, this is a reminder that your internet subscription is expiring soon (${expiry}). Please renew to ensure uninterrupted service.`;
            break;
        case 'promo':
            text = `Hello ${name}, check out our new high-speed fibre packages! Upgrade today and get 2x speed for the same price. Call us on 0700000000.`;
            break;
    }
     if (text) msgBox.value = text;
}

function handleGlobalSend(e) {
    e.preventDefault();
    // Implementation of Send API call would go here
    // For now, simulate success
    const btn = e.target.querySelector('button[type="submit"]');
    const original = btn.textContent;
    btn.textContent = "Sending...";
    btn.disabled = true;
    
    setTimeout(() => {
        alert("Message queued successfully!");
        closeSendModal();
        e.target.reset();
        btn.textContent = original;
        btn.disabled = false;
    }, 1000);
}

// Close modals on outside click
window.onclick = function(event) {
    const smsConfig = document.getElementById('smsModal');
    const sendSMS = document.getElementById('sendSMSModal');
    if (event.target == smsConfig) smsConfig.classList.remove('active');
    if (event.target == sendSMS) sendSMS.classList.remove('active');
}
</script>

<?php include 'includes/footer.php'; ?>