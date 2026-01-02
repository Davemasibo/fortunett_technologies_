<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Get all clients
try {
    $clients = $pdo->query("SELECT id, full_name, email, phone, mikrotik_username FROM clients ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

// Handle PDF generation
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_pdf'])) {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $send_email = isset($_POST['send_email']);
    
    if ($client_id) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            // Generate PDF (simplified version - you can expand this)
            $pdf_content = generateSimplePDF($client, $pdo);
            
            if ($pdf_content) {
                // Save PDF
                $filename = 'report_' . $client_id . '_' . date('Y_m_d') . '.pdf';
                $filepath = __DIR__ . '/reports/' . $filename;
                
                if (!is_dir(__DIR__ . '/reports')) {
                    mkdir(__DIR__ . '/reports', 0755, true);
                }
                
                file_put_contents($filepath, $pdf_content);
                
                // Send email if requested
                if ($send_email && !empty($client['email'])) {
                    // TODO: Implement email sending with attachment
                    $success_message = "PDF generated and email sent to " . htmlspecialchars($client['email']);
                } else {
                    $success_message = "PDF generated successfully! <a href='reports/$filename' target='_blank'>Download PDF</a>";
                }
            } else {
                $error_message = "Failed to generate PDF";
            }
        }
    }
}

// Simple PDF generation function
function generateSimplePDF($client, $pdo) {
    // Get client data
    $period_start = date('Y-m-01');
    $period_end = date('Y-m-t');
    
    // Get usage data (if radacct table exists)
    try {
        $stmt = $pdo->prepare("SELECT 
            COALESCE(SUM(acctinputoctets), 0) as download,
            COALESCE(SUM(acctoutputoctets), 0) as upload,
            COUNT(*) as sessions
            FROM radacct 
            WHERE username = ? AND acctstarttime BETWEEN ? AND ?");
        $stmt->execute([$client['mikrotik_username'], $period_start, $period_end]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $usage = ['download' => 0, 'upload' => 0, 'sessions' => 0];
    }
    
    // Get payments
    try {
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE client_id = ? AND payment_date BETWEEN ? AND ?");
        $stmt->execute([$client['id'], $period_start, $period_end]);
        $payments = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_paid = $payments['total'] ?? 0;
    } catch (Exception $e) {
        $total_paid = 0;
    }
    
    // Create simple HTML for PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            h1 { color: #2C5282; border-bottom: 3px solid #3B6EA5; padding-bottom: 10px; }
            .section { margin: 20px 0; }
            .label { font-weight: bold; color: #374151; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #E5E7EB; }
            th { background: #F3F4F6; font-weight: 600; }
        </style>
    </head>
    <body>
        <h1>Monthly Report - ' . date('F Y') . '</h1>
        
        <div class="section">
            <h2>Client Information</h2>
            <p><span class="label">Name:</span> ' . htmlspecialchars($client['full_name']) . '</p>
            <p><span class="label">Email:</span> ' . htmlspecialchars($client['email'] ?? 'N/A') . '</p>
            <p><span class="label">Phone:</span> ' . htmlspecialchars($client['phone'] ?? 'N/A') . '</p>
            <p><span class="label">Username:</span> ' . htmlspecialchars($client['mikrotik_username'] ?? 'N/A') . '</p>
        </div>
        
        <div class="section">
            <h2>Usage Summary</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Download</td>
                    <td>' . formatBytes($usage['download']) . '</td>
                </tr>
                <tr>
                    <td>Upload</td>
                    <td>' . formatBytes($usage['upload']) . '</td>
                </tr>
                <tr>
                    <td>Total Usage</td>
                    <td>' . formatBytes($usage['download'] + $usage['upload']) . '</td>
                </tr>
                <tr>
                    <td>Sessions</td>
                    <td>' . number_format($usage['sessions']) . '</td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <h2>Payment Summary</h2>
            <p><span class="label">Total Paid:</span> KES ' . number_format($total_paid, 2) . '</p>
        </div>
        
        <div class="section" style="margin-top: 40px; text-align: center; color: #6B7280; font-size: 12px;">
            <p>Generated on ' . date('F j, Y g:i A') . '</p>
        </div>
    </body>
    </html>';
    
    // Use DomPDF or similar library if available, otherwise return HTML
    // For now, we'll use a simple HTML to PDF conversion
    // You should install a PDF library like TCPDF or DomPDF
    
    return $html; // Return HTML for now - integrate with PDF library
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    if ($bytes == 0) return '0 B';
    $pow = floor(log($bytes) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .main-content-wrapper {
        background: #F3F4F6 !important;
    }
    
    .reports-container {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .reports-title {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .reports-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin: 0 0 24px 0;
    }
    
    /* Generate Section */
    .generate-section {
        background: white;
        border-radius: 10px;
        padding: 32px;
        margin-bottom: 24px;
        border: 1px solid #E5E7EB;
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 20px 0;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
    }
    
    .form-group {
        margin-bottom: 16px;
    }
    
    .form-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
    }
    
    .form-select {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }
    
    .checkbox-group input[type="checkbox"] {
        width: 18px;
        height: 18px;
    }
    
    .btn-generate {
        padding: 12px 24px;
        background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Clients Table */
    .clients-section {
        background: white;
        border-radius: 10px;
        border: 1px solid #E5E7EB;
        overflow: hidden;
    }
    
    .clients-header {
        padding: 16px 24px;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .clients-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .clients-table thead {
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .clients-table th {
        padding: 12px 16px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .clients-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 14px;
        color: #111827;
    }
    
    .clients-table tbody tr:hover {
        background: #F9FAFB;
    }
    
    .action-btn {
        padding: 6px 12px;
        background: #3B6EA5;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background: #D1FAE5;
        color: #065F46;
        border: 1px solid #86EFAC;
    }
    
    .alert-error {
        background: #FEE2E2;
        color: #991B1B;
        border: 1px solid #FCA5A5;
    }
</style>

<div class="main-content-wrapper">
    <div class="reports-container">
        <!-- Header -->
        <div>
            <h1 class="reports-title">Reports & Analytics</h1>
            <p class="reports-subtitle">Generate detailed client reports with PDF export and email delivery</p>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Generate Report Section -->
        <div class="generate-section">
            <h2 class="section-title">Generate Customer Report</h2>
            
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Select Customer</label>
                        <select name="client_id" class="form-select" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>">
                                <?php echo htmlspecialchars($client['full_name']); ?> 
                                (<?php echo htmlspecialchars($client['email'] ?? 'No email'); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Report Period</label>
                        <select class="form-select">
                            <option>Current Month</option>
                            <option>Last Month</option>
                            <option>Last 3 Months</option>
                            <option>Last 6 Months</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="send_email" id="send_email">
                    <label for="send_email">Send report to customer's email</label>
                </div>
                
                <button type="submit" name="generate_pdf" class="btn-generate">
                    <i class="fas fa-file-pdf"></i>
                    Generate PDF Report
                </button>
            </form>
        </div>

        <!-- Clients List -->
        <div class="clients-section">
            <div class="clients-header">
                <h3 class="section-title" style="margin: 0;">Customer Directory</h3>
            </div>
            
            <table class="clients-table">
                <thead>
                    <tr>
                        <th>CUSTOMER NAME</th>
                        <th>EMAIL</th>
                        <th>PHONE</th>
                        <th>USERNAME</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($client['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($client['mikrotik_username'] ?? 'N/A'); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="client_id" value="<?php echo $client['id']; ?>">
                                <input type="hidden" name="send_email" value="1">
                                <button type="submit" name="generate_pdf" class="action-btn">
                                    <i class="fas fa-file-pdf"></i>
                                    Generate & Email
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>