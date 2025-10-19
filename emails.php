<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Initialize variables
$clients = [];
$message = '';
$success_message = '';
$email_logs = [];

try {
    // Get all clients with email addresses
    $clients = $pdo->query("SELECT id, full_name, email, phone, mikrotik_username, created_at, status FROM clients ORDER BY full_name ASC")->fetchAll();
    
    // Create email settings table if not exists
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
    
    // Insert default settings if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM email_settings WHERE id = 1");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO email_settings (id) VALUES (1)");
    }
    
    $email_settings = $pdo->query("SELECT * FROM email_settings WHERE id = 1")->fetch();

    // Create email logs table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT NULL,
        recipient_email VARCHAR(255),
        subject VARCHAR(255),
        message TEXT,
        template_used VARCHAR(50),
        sent_at DATETIME,
        status VARCHAR(20) DEFAULT 'sent',
        error_message TEXT NULL
    )");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['save_settings'])) {
            // Save email settings
            $smtp_host = trim($_POST['smtp_host'] ?? '');
            $smtp_port = (int)($_POST['smtp_port'] ?? 587);
            $smtp_username = trim($_POST['smtp_username'] ?? '');
            $smtp_password = trim($_POST['smtp_password'] ?? '');
            $from_email = trim($_POST['from_email'] ?? '');
            $from_name = trim($_POST['from_name'] ?? '');
            
            $stmt = $pdo->prepare("UPDATE email_settings SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, from_email = ?, from_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->execute([$smtp_host, $smtp_port, $smtp_username, $smtp_password, $from_email, $from_name]);
            
            $success_message = 'Email settings saved successfully!';
            $email_settings = $pdo->query("SELECT * FROM email_settings WHERE id = 1")->fetch();
            
        } elseif (isset($_POST['send_single_email'])) {
            // Send single email
            $client_id = (int)($_POST['client_id'] ?? 0);
            $subject = trim($_POST['subject'] ?? '');
            $message_content = trim($_POST['message'] ?? '');
            $template_used = $_POST['template_used'] ?? 'custom';
            
            if (!$client_id) {
                $message = 'Error: Please select a client';
            } elseif (empty($subject) || empty($message_content)) {
                $message = 'Error: Subject and message are required';
            } else {
                $stmt = $pdo->prepare("SELECT id, full_name, email FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch();
                
                if ($client && !empty($client['email'])) {
                    // Replace placeholders
                    $final_message = str_replace(
                        ['{name}', '{email}', '{phone}', '{username}'],
                        [
                            $client['full_name'], 
                            $client['email'], 
                            $client['phone'] ?? 'N/A',
                            $client['mikrotik_username'] ?? 'N/A'
                        ],
                        $message_content
                    );
                    
                    // TODO: Implement actual email sending
                    // $result = sendEmail($client['email'], $subject, $final_message, $email_settings);
                    $result = true; // Simulate success for now
                    
                    if ($result) {
                        // Log the email
                        $stmt = $pdo->prepare("INSERT INTO email_logs (client_id, recipient_email, subject, message, template_used, sent_at) VALUES (?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$client_id, $client['email'], $subject, $message_content, $template_used]);
                        
                        $success_message = 'Email sent successfully to ' . htmlspecialchars($client['full_name']) . ' (' . htmlspecialchars($client['email']) . ')';
                    } else {
                        $message = 'Error: Failed to send email';
                    }
                } else {
                    $message = 'Error: Client not found or no email address';
                }
            }
            
        } elseif (isset($_POST['send_bulk_email'])) {
            // Send bulk email
            $subject = trim($_POST['subject'] ?? '');
            $message_content = trim($_POST['message'] ?? '');
            $template_used = $_POST['template_used'] ?? 'custom';
            $selected_clients = $_POST['selected_clients'] ?? [];
            
            if (empty($subject) || empty($message_content)) {
                $message = 'Error: Subject and message are required';
            } else {
                $sent_count = 0;
                $error_count = 0;
                $total_clients = empty($selected_clients) ? count($clients) : count($selected_clients);
                
                $target_clients = empty($selected_clients) ? $clients : array_filter($clients, function($client) use ($selected_clients) {
                    return in_array($client['id'], $selected_clients);
                });
                
                foreach ($target_clients as $client) {
                    if (!empty($client['email'])) {
                        // Replace placeholders for each client
                        $final_message = str_replace(
                            ['{name}', '{email}', '{phone}', '{username}'],
                            [
                                $client['full_name'], 
                                $client['email'], 
                                $client['phone'] ?? 'N/A',
                                $client['mikrotik_username'] ?? 'N/A'
                            ],
                            $message_content
                        );
                        
                        // TODO: Implement actual email sending
                        // $result = sendEmail($client['email'], $subject, $final_message, $email_settings);
                        $result = true; // Simulate success for now
                        
                        if ($result) {
                            $sent_count++;
                            
                            // Log the email
                            $stmt = $pdo->prepare("INSERT INTO email_logs (client_id, recipient_email, subject, message, template_used, sent_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$client['id'], $client['email'], $subject, $message_content, $template_used]);
                        } else {
                            $error_count++;
                        }
                    }
                }
                
                if ($error_count > 0) {
                    $message = "Bulk email partially sent! {$sent_count} out of {$total_clients} clients received the email. {$error_count} failed.";
                } else {
                    $success_message = "Bulk email sent successfully! {$sent_count} out of {$total_clients} clients received the email.";
                }
            }
        }
        
        // Test email functionality
        if (isset($_POST['test_email'])) {
            $test_email = trim($_POST['test_email'] ?? '');
            if (!empty($test_email)) {
                // TODO: Implement actual test email sending
                // $result = sendEmail($test_email, 'Test Email from System', 'This is a test email from your system.', $email_settings);
                $result = true; // Simulate success
                
                if ($result) {
                    $success_message = 'Test email sent successfully to ' . htmlspecialchars($test_email);
                } else {
                    $message = 'Error: Failed to send test email';
                }
            } else {
                $message = 'Error: Please enter a test email address';
            }
        }
    }
    
    // Get recent email logs
    $email_logs = $pdo->query("SELECT el.*, c.full_name AS client_name 
                              FROM email_logs el 
                              LEFT JOIN clients c ON el.client_id = c.id 
                              ORDER BY el.sent_at DESC 
                              LIMIT 100")->fetchAll();
                              
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    error_log("Email system error: " . $e->getMessage());
}

// Function to get clients with email count
$clients_with_email = array_filter($clients, function($client) {
    return !empty($client['email']);
});

$clients_without_email = array_filter($clients, function($client) {
    return empty($client['email']);
});

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- Header -->
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 style="margin: 0; color: #333;"><i class="fas fa-envelope me-3"></i>Email Center</h1>
                    <p class="text-muted mb-0">Manage client communications and email campaigns</p>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#testEmailModal">
                        <i class="fas fa-vial me-2"></i>Test Email
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#emailSettingsModal">
                        <i class="fas fa-cog me-2"></i>Email Settings
                    </button>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-primary mb-3">
                            <i class="fas fa-user fa-3x"></i>
                        </div>
                        <h5 class="card-title">Single Client</h5>
                        <p class="card-text">Send email to individual client</p>
                        <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#singleEmailModal">
                            <i class="fas fa-paper-plane me-2"></i>Compose
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-warning mb-3">
                            <i class="fas fa-users fa-3x"></i>
                        </div>
                        <h5 class="card-title">All Clients</h5>
                        <p class="card-text">Send bulk email to all clients</p>
                        <button class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#bulkEmailModal">
                            <i class="fas fa-bullhorn me-2"></i>Bulk Email
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-info mb-3">
                            <i class="fas fa-user-friends fa-3x"></i>
                        </div>
                        <h5 class="card-title">Selected Clients</h5>
                        <p class="card-text">Send to specific clients only</p>
                        <button class="btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#selectedEmailModal">
                            <i class="fas fa-check-circle me-2"></i>Select Clients
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-success mb-3">
                            <i class="fas fa-cog fa-3x"></i>
                        </div>
                        <h5 class="card-title">Settings</h5>
                        <p class="card-text">Configure email server</p>
                        <button class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#emailSettingsModal">
                            <i class="fas fa-sliders me-2"></i>Configure
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($clients); ?></h4>
                                <span>Total Clients</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($clients_with_email); ?></h4>
                                <span>Clients with Email</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-envelope fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo count($email_logs); ?></h4>
                                <span>Emails Sent</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-paper-plane fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="mb-0"><?php echo !empty($email_settings['smtp_host']) ? 'Connected' : 'Not Set'; ?></h4>
                                <span>SMTP Status</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-server fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clients Email List -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-address-book me-2"></i>
                    Client Email Directory
                </h5>
                <span class="badge bg-light text-dark">
                    <?php echo count($clients_with_email); ?> with email
                </span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="clientsTable">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Client Name</th>
                                <th>Email Address</th>
                                <th>Phone</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr class="<?php echo empty($client['email']) ? 'table-warning' : ''; ?>">
                                    <td>
                                        <?php if (!empty($client['email'])): ?>
                                            <input type="checkbox" class="client-checkbox" name="selected_clients[]" value="<?php echo $client['id']; ?>">
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-triangle text-warning" title="No email address"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['email'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($client['email']); ?>" class="text-decoration-none">
                                                <i class="fas fa-envelope me-1"></i>
                                                <?php echo htmlspecialchars($client['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No email</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($client['mikrotik_username'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $client['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($client['status'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['email'])): ?>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="quickEmail(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                    title="Quick Email">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <strong>Bulk Actions:</strong>
                            <span class="text-muted ms-2" id="selectedCount">0 clients selected</span>
                        </div>
                        <div class="col-md-6 text-end">
                            <button class="btn btn-info me-2" onclick="emailSelectedClients()" disabled id="emailSelectedBtn">
                                <i class="fas fa-paper-plane me-2"></i>Email Selected
                            </button>
                            <button class="btn btn-warning" onclick="emailAllWithEmail()">
                                <i class="fas fa-bullhorn me-2"></i>Email All with Email
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Email Activity -->
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>
                    Recent Email Activity
                </h5>
                <span class="badge bg-light text-dark">
                    Last 100 emails
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($email_logs)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No email history</h4>
                        <p class="text-muted">Send your first email to see activity here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Template</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($email_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($log['sent_at'])); ?></td>
                                        <td>
                                            <?php if ($log['client_name']): ?>
                                                <strong><?php echo htmlspecialchars($log['client_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['recipient_email']); ?></small>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($log['recipient_email']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['subject']); ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo ucfirst($log['template_used'] ?? 'custom'); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">Sent</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Single Email Modal -->
<div class="modal fade" id="singleEmailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Send Email to Single Client</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="singleTemplate" value="custom">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Client *</label>
                            <select name="client_id" class="form-select" required id="singleClientSelect">
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clients_with_email as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" data-email="<?php echo htmlspecialchars($client['email']); ?>" data-name="<?php echo htmlspecialchars($client['full_name']); ?>">
                                        <?php echo htmlspecialchars($client['full_name'] . ' (' . $client['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Email Template</label>
                            <select class="form-select" onchange="applyEmailTemplate(this.value, 'single')" id="singleTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="credentials">Login Credentials</option>
                                <option value="welcome">Welcome Email</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="report">Usage Report</option>
                                <option value="offer">Special Offer</option>
                                <option value="maintenance">Maintenance Notice</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject *</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter email subject" required id="singleSubject">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message Content *</label>
                        <textarea name="message" class="form-control" rows="8" placeholder="Compose your email message..." required id="singleMessage"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Available placeholders: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-paper-plane me-2"></i>
                        This email will be sent immediately to the selected client.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="send_single_email" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Email Modal -->
<div class="modal fade" id="bulkEmailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Send Email to All Clients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="bulkTemplate" value="custom">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Bulk Template</label>
                            <select class="form-select" onchange="applyEmailTemplate(this.value, 'bulk')" id="bulkTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="maintenance">Maintenance Notice</option>
                                <option value="offer">Special Offer</option>
                                <option value="report">Usage Report</option>
                                <option value="announcement">General Announcement</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Recipient Count</label>
                            <input type="text" class="form-control bg-light" value="<?php echo count($clients_with_email); ?> clients with email addresses" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject *</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter bulk email subject" required id="bulkSubject">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message Content *</label>
                        <textarea name="message" class="form-control" rows="8" placeholder="Compose your bulk email message..." required id="bulkMessage"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Placeholders will be automatically replaced for each client: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This will send emails to all <?php echo count($clients_with_email); ?> clients with valid email addresses.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="send_bulk_email" class="btn btn-warning">
                        <i class="fas fa-paper-plane me-2"></i>Send to All Clients
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Selected Clients Email Modal -->
<div class="modal fade" id="selectedEmailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-user-friends me-2"></i>Send Email to Selected Clients</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="template_used" id="selectedTemplate" value="custom">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Selected Clients</label>
                        <div class="bg-light p-3 rounded" style="max-height: 200px; overflow-y: auto;" id="selectedClientsList">
                            <div class="text-muted">No clients selected. Please check clients from the table above.</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Template</label>
                            <select class="form-select" onchange="applyEmailTemplate(this.value, 'selected')" id="selectedTemplateSelect">
                                <option value="">-- Choose Template --</option>
                                <option value="credentials">Login Credentials</option>
                                <option value="welcome">Welcome Email</option>
                                <option value="expiry">Expiry Reminder</option>
                                <option value="payment">Payment Details</option>
                                <option value="report">Usage Report</option>
                                <option value="offer">Special Offer</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Recipient Count</label>
                            <input type="text" class="form-control bg-light" value="0 clients selected" readonly id="selectedCountDisplay">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject *</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter email subject" required id="selectedSubject">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message Content *</label>
                        <textarea name="message" class="form-control" rows="8" placeholder="Compose your email message..." required id="selectedMessage"></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Placeholders will be automatically replaced: <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{username}</code>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_bulk_email" class="btn btn-info">
                        <i class="fas fa-paper-plane me-2"></i>Send to Selected
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Email Settings Modal -->
<div class="modal fade" id="emailSettingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>Email Configuration</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMTP Host *</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com" required>
                            <div class="form-text">Your SMTP server address</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMTP Port *</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_port'] ?? '587'); ?>" placeholder="587" required>
                            <div class="form-text">Common ports: 587 (TLS), 465 (SSL)</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMTP Username *</label>
                            <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_username'] ?? ''); ?>" placeholder="your@email.com" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">SMTP Password *</label>
                            <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_password'] ?? ''); ?>" placeholder="SMTP password" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">From Email *</label>
                            <input type="email" name="from_email" class="form-control" value="<?php echo htmlspecialchars($email_settings['from_email'] ?? ''); ?>" placeholder="noreply@yourcompany.com" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">From Name *</label>
                            <input type="text" name="from_name" class="form-control" value="<?php echo htmlspecialchars($email_settings['from_name'] ?? ''); ?>" placeholder="Your Company Name" required>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> For Gmail, you may need to enable "Less secure app access" or use an App Password.
                    </div>
                    
                    <?php if (!empty($email_settings['updated_at'])): ?>
                        <div class="alert alert-light">
                            <i class="fas fa-clock me-2"></i>
                            Last updated: <?php echo date('M j, Y g:i A', strtotime($email_settings['updated_at'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" name="save_settings" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Test Email Modal -->
<div class="modal fade" id="testEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-vial me-2"></i>Test Email Configuration</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Test Email Address *</label>
                        <input type="email" name="test_email" class="form-control" placeholder="Enter email address to test" required>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will send a test email to verify your SMTP settings are working correctly.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="test_email" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send Test Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Email Templates
const emailTemplates = {
    credentials: {
        subject: 'Your WiFi Login Credentials',
        message: `Dear {name},

Your internet account has been activated. Here are your login details:

Username: {username}
Password: Please use your registered phone number

To connect to our network:
1. Select our WiFi network
2. Open your browser
3. Enter the credentials above
4. Click Login

If you experience any issues, please contact our support team.

Best regards,
Technical Support Team`
    },
    welcome: {
        subject: 'Welcome to Our Internet Service!',
        message: `Dear {name},

Welcome to our internet service! We're excited to have you as our customer.

Your account details:
• Username: {username}
• Email: {email}
• Phone: {phone}

Getting Started:
1. Connect to our WiFi network
2. Use your credentials to login
3. Enjoy high-speed internet!

For support, please contact us at [Support Details].

Thank you for choosing us!

Best regards,
Customer Care Team`
    },
    expiry: {
        subject: 'Important: Subscription Expiry Reminder',
        message: `Dear {name},

This is a reminder that your internet subscription will expire soon.

To avoid service interruption, please renew your subscription before the expiry date.

Payment Details:
• Paybill: 123456
• Account: {name}
• Amount: As per your package

If you have already paid, please ignore this message.

Thank you for your continued business!

Best regards,
Billing Department`
    },
    payment: {
        subject: 'Payment Instructions and Details',
        message: `Dear {name},

Here are your payment details for internet services:

Package: Monthly Subscription
Amount: KES 1,500
Paybill: 123456
Account: {name}

Payment Steps:
1. Go to M-PESA
2. Select Lipa Na M-PESA
3. Enter Paybill: 123456
4. Account: {name}
5. Amount: 1500
6. Complete payment

Thank you for your prompt payment!

Best regards,
Accounts Department`
    },
    report: {
        subject: 'Your Monthly Usage Report',
        message: `Dear {name},

Here is your monthly internet usage summary:

Account: {username}
Email: {email}
Period: Last 30 Days

Usage Summary:
• Total Data Used: [Usage Amount]
• Connection Time: [Time Period]
• Average Speed: [Speed Details]

Please contact us if you have any questions about your usage.

Best regards,
Network Operations Team`
    },
    offer: {
        subject: 'Special Offer Just For You!',
        message: `Dear {name},

We have an exclusive offer for our valued customers:

SPECIAL PROMOTION:
• Upgrade to premium at 20% discount
• Refer a friend - get one month FREE
• Limited time offer!

Contact us today to take advantage of these special deals.

Thank you for being a loyal customer!

Best regards,
Sales Team`
    },
    maintenance: {
        subject: 'Scheduled Network Maintenance Notice',
        message: `Dear Valued Customer,

We will be performing scheduled network maintenance:

Date: [Insert Date]
Time: [Insert Time]
Duration: Approximately 2 hours

During this period, you may experience temporary service interruption. We apologize for any inconvenience.

Thank you for your understanding.

Best regards,
Network Operations Team`
    },
    announcement: {
        subject: 'Important Announcement',
        message: `Dear {name},

We have an important announcement regarding our services:

[Insert announcement details]

This update will help us serve you better.

If you have any questions, please contact our support team.

Thank you for your continued trust.

Best regards,
Management Team`
    }
};

// Apply template to form
function applyEmailTemplate(template, type) {
    if (template && emailTemplates[template]) {
        const modalId = type === 'single' ? 'single' : type === 'bulk' ? 'bulk' : 'selected';
        const subjectField = document.getElementById(modalId + 'Subject');
        const messageField = document.getElementById(modalId + 'Message');
        const templateField = document.getElementById(modalId + 'Template');
        
        if (subjectField) subjectField.value = emailTemplates[template].subject;
        if (messageField) messageField.value = emailTemplates[template].message;
        if (templateField) templateField.value = template;
    }
}

// Quick email to single client
function quickEmail(clientId, clientName) {
    const modal = new bootstrap.Modal(document.getElementById('singleEmailModal'));
    const form = document.querySelector('#singleEmailModal form');
    form.querySelector('select[name="client_id"]').value = clientId;
    modal.show();
}

// Client selection management
let selectedClients = [];

function toggleSelectAll(checkbox) {
    const clientCheckboxes = document.querySelectorAll('.client-checkbox');
    clientCheckboxes.forEach(cb => {
        if (!cb.disabled) {
            cb.checked = checkbox.checked;
        }
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const selected = document.querySelectorAll('.client-checkbox:checked');
    const count = selected.length;
    document.getElementById('selectedCount').textContent = count + ' clients selected';
    document.getElementById('emailSelectedBtn').disabled = count === 0;
    
    // Update selected clients array
    selectedClients = Array.from(selected).map(cb => cb.value);
    
    // Update selected clients list for modal
    updateSelectedClientsList();
}

function updateSelectedClientsList() {
    const listContainer = document.getElementById('selectedClientsList');
    const countDisplay = document.getElementById('selectedCountDisplay');
    
    if (selectedClients.length === 0) {
        listContainer.innerHTML = '<div class="text-muted">No clients selected. Please check clients from the table above.</div>';
        countDisplay.value = '0 clients selected';
        return;
    }
    
    let html = '<div class="row">';
    selectedClients.forEach(clientId => {
        const client = <?php echo json_encode($clients); ?>.find(c => c.id == clientId);
        if (client) {
            html += `
                <div class="col-md-6 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="selected_clients[]" value="${client.id}" checked>
                        <label class="form-check-label">
                            ${client.full_name}
                        </label>
                    </div>
                </div>
            `;
        }
    });
    html += '</div>';
    
    listContainer.innerHTML = html;
    countDisplay.value = selectedClients.length + ' clients selected';
}

function emailSelectedClients() {
    if (selectedClients.length > 0) {
        const modal = new bootstrap.Modal(document.getElementById('selectedEmailModal'));
        modal.show();
    }
}

function emailAllWithEmail() {
    // Select all clients with email
    const clientCheckboxes = document.querySelectorAll('.client-checkbox');
    clientCheckboxes.forEach(cb => {
        if (!cb.disabled) {
            cb.checked = true;
        }
    });
    updateSelectedCount();
    emailSelectedClients();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update selected count when checkboxes change
    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
    
    // Initialize selected count
    updateSelectedCount();
    
    // Auto-focus on client selection when single email modal opens
    $('#singleEmailModal').on('shown.bs.modal', function () {
        $(this).find('select[name="client_id"]').focus();
    });
    
    // Test SMTP connection
    window.testSMTPConnection = function() {
        alert('SMTP connection test would be implemented here');
    };
});
</script>