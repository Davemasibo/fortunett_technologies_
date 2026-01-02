<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get email logs
try {
    $email_logs = $pdo->query("SELECT el.*, c.full_name AS client_name 
                               FROM email_logs el 
                               LEFT JOIN clients c ON el.client_id = c.id 
                               ORDER BY el.sent_at DESC 
                               LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $email_logs = [];
}

// Get SMTP settings
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_settings (
        id INT PRIMARY KEY DEFAULT 1, 
        smtp_host VARCHAR(255),
        smtp_port INT DEFAULT 587,
        smtp_username VARCHAR(255),
        smtp_password VARCHAR(255),
        from_email VARCHAR(255),
        from_name VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM email_settings WHERE id = 1");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO email_settings (id) VALUES (1)");
    }
    
    $email_settings = $pdo->query("SELECT * FROM email_settings WHERE id = 1")->fetch();
} catch (Exception $e) {
    $email_settings = null;
}

// Handle SMTP settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_smtp'])) {
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int)($_POST['smtp_port'] ?? 587);
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password = trim($_POST['smtp_password'] ?? '');
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE email_settings SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, from_email = ?, from_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
    $stmt->execute([$smtp_host, $smtp_port, $smtp_username, $smtp_password, $from_email, $from_name]);
    
    header('Location: emails.php?saved=1');
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .emails-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .emails-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }
    
    .emails-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0;
    }
    
    .send-email-btn {
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
    
    /* Emails Table */
    .emails-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .emails-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .emails-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .emails-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .emails-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .emails-table tbody tr:hover {
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
    
    /* SMTP Modal */
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
    <div class="emails-container">
        <!-- Header -->
        <div class="emails-header">
            <h1 class="emails-title">Emails</h1>
            <button class="send-email-btn" onclick="alert('Send Email feature coming soon!')">
                <i class="fas fa-paper-plane"></i>
                Send Email
            </button>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search" style="color: #9CA3AF;"></i>
            <input type="text" class="search-input" placeholder="Search">
            <button class="filter-icon" onclick="openSMTPModal()">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <!-- Emails Table -->
        <div class="emails-section">
            <table class="emails-table">
                <thead>
                    <tr>
                        <th>SUBJECT</th>
                        <th>EMAIL</th>
                        <th>MESSAGE</th>
                        <th>STATUS</th>
                        <th>SENT AT</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($email_logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #9CA3AF;">
                            No emails sent yet
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($email_logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['subject']); ?></td>
                        <td><?php echo htmlspecialchars($log['recipient_email']); ?></td>
                        <td><?php echo htmlspecialchars(substr($log['message'], 0, 50)) . '...'; ?></td>
                        <td>
                            <span class="status-badge sent">sent</span>
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
                    Showing 1 to <?php echo min(10, count($email_logs)); ?> of <?php echo count($email_logs); ?> results
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

<!-- SMTP Configuration Modal -->
<div class="modal-overlay" id="smtpModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">SMTP Server Configuration</h3>
            <button class="modal-close" onclick="closeSMTPModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?php if (isset($_GET['saved'])): ?>
                <div style="padding: 12px; background: #D1FAE5; color: #065F46; border-radius: 6px; margin-bottom: 16px;">
                    Settings saved successfully!
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-input" value="<?php echo htmlspecialchars($email_settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-input" value="<?php echo htmlspecialchars($email_settings['smtp_port'] ?? '587'); ?>" placeholder="587">
                </div>
                
                <div class="form-group">
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_username" class="form-input" value="<?php echo htmlspecialchars($email_settings['smtp_username'] ?? ''); ?>" placeholder="your@email.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">SMTP Password</label>
                    <input type="password" name="smtp_password" class="form-input" value="<?php echo htmlspecialchars($email_settings['smtp_password'] ?? ''); ?>" placeholder="••••••••">
                </div>
                
                <div class="form-group">
                    <label class="form-label">From Email</label>
                    <input type="email" name="from_email" class="form-input" value="<?php echo htmlspecialchars($email_settings['from_email'] ?? ''); ?>" placeholder="noreply@yourcompany.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">From Name</label>
                    <input type="text" name="from_name" class="form-input" value="<?php echo htmlspecialchars($email_settings['from_name'] ?? ''); ?>" placeholder="Your Company">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeSMTPModal()">Cancel</button>
                <button type="submit" name="save_smtp" class="btn-save">Save Configuration</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSMTPModal() {
    document.getElementById('smtpModal').classList.add('active');
}

function closeSMTPModal() {
    document.getElementById('smtpModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('smtpModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSMTPModal();
    }
});
</script>

<?php include 'includes/footer.php'; ?>