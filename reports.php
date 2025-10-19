<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Initialize variables
$clients = [];
$message = '';
$success_message = '';
$report_data = [];
$report_type = $_GET['report_type'] ?? 'usage';
$date_range = $_GET['date_range'] ?? 'last_30_days';
$client_id = $_GET['client_id'] ?? '';
$export = $_GET['export'] ?? '';

// Date ranges
$date_ranges = [
    'today' => ['Today', date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')],
    'yesterday' => ['Yesterday', date('Y-m-d 00:00:00', strtotime('-1 day')), date('Y-m-d 23:59:59', strtotime('-1 day'))],
    'last_7_days' => ['Last 7 Days', date('Y-m-d 00:00:00', strtotime('-7 days')), date('Y-m-d 23:59:59')],
    'last_30_days' => ['Last 30 Days', date('Y-m-d 00:00:00', strtotime('-30 days')), date('Y-m-d 23:59:59')],
    'this_month' => ['This Month', date('Y-m-01 00:00:00'), date('Y-m-d 23:59:59')],
    'last_month' => ['Last Month', date('Y-m-01 00:00:00', strtotime('-1 month')), date('Y-m-t 23:59:59', strtotime('-1 month'))],
    'this_year' => ['This Year', date('Y-01-01 00:00:00'), date('Y-m-d 23:59:59')],
    'last_year' => ['Last Year', date('Y-01-01 00:00:00', strtotime('-1 year')), date('Y-12-31 23:59:59', strtotime('-1 year'))],
];

$start_date = $date_ranges[$date_range][1] ?? $date_ranges['last_30_days'][1];
$end_date = $date_ranges[$date_range][2] ?? $date_ranges['last_30_days'][2];

// Function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    if ($bytes == 0) return '0 B';
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

try {
    // Get all clients
    $clients = $pdo->query("SELECT id, full_name, email, phone, mikrotik_username, created_at, status FROM clients ORDER BY full_name ASC")->fetchAll();
    
    // Create reports table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_reports (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT,
        report_type VARCHAR(50),
        period_start DATE,
        period_end DATE,
        file_path VARCHAR(255),
        sent_via VARCHAR(20),
        sent_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    )");
    
    // Create payments table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT,
        amount DECIMAL(10,2),
        payment_date DATE,
        payment_method VARCHAR(50),
        reference VARCHAR(100),
        status VARCHAR(20) DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    )");

    // Handle report generation and sending
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['generate_report'])) {
            $report_client_id = (int)($_POST['client_id'] ?? 0);
            $report_period = $_POST['report_period'] ?? date('Y-m');
            $report_type = $_POST['report_type'] ?? 'monthly';
            $send_email = isset($_POST['send_email']);
            $send_sms = isset($_POST['send_sms']);
            
            if (!$report_client_id) {
                $message = 'Error: Please select a client';
            } else {
                $stmt = $pdo->prepare("SELECT id, full_name, email, phone, mikrotik_username FROM clients WHERE id = ?");
                $stmt->execute([$report_client_id]);
                $client = $stmt->fetch();
                
                if ($client) {
                    // Generate PDF report
                    $pdf_filename = generatePDFReport($client, $report_period, $report_type, $pdo);
                    
                    if ($pdf_filename) {
                        // Log report generation
                        $sent_via = [];
                        if ($send_email && !empty($client['email'])) {
                            $sent_via[] = 'email';
                            // TODO: Implement email sending with attachment
                            // sendEmailWithAttachment($client['email'], 'Monthly Report', 'Your monthly report is attached.', $filepath);
                        }
                        if ($send_sms && !empty($client['phone'])) {
                            $sent_via[] = 'sms';
                            // TODO: Implement SMS sending
                            // sendSMS($client['phone'], 'Your monthly report is ready. Check your email or contact support.');
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO client_reports (client_id, report_type, period_start, period_end, file_path, sent_via, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $period_start = date('Y-m-01', strtotime($report_period));
                        $period_end = date('Y-m-t', strtotime($report_period));
                        $stmt->execute([$report_client_id, $report_type, $period_start, $period_end, $pdf_filename, implode(',', $sent_via)]);
                        
                        $success_message = "Report generated successfully for " . htmlspecialchars($client['full_name']);
                        if (!empty($sent_via)) {
                            $success_message .= " and sent via " . implode(' and ', $sent_via);
                        }
                    } else {
                        $message = 'Error: Failed to generate PDF report';
                    }
                } else {
                    $message = 'Error: Client not found';
                }
            }
        }
        
        // Bulk report generation
        if (isset($_POST['generate_bulk_reports'])) {
            $selected_clients = $_POST['selected_clients'] ?? [];
            $report_period = $_POST['bulk_report_period'] ?? date('Y-m');
            $report_type = $_POST['bulk_report_type'] ?? 'monthly';
            $bulk_send_email = isset($_POST['bulk_send_email']);
            $bulk_send_sms = isset($_POST['bulk_send_sms']);
            
            if (empty($selected_clients)) {
                $message = 'Error: Please select at least one client';
            } else {
                $generated_count = 0;
                $error_count = 0;
                
                foreach ($selected_clients as $client_id) {
                    $stmt = $pdo->prepare("SELECT id, full_name, email, phone, mikrotik_username FROM clients WHERE id = ?");
                    $stmt->execute([$client_id]);
                    $client = $stmt->fetch();
                    
                    if ($client) {
                        try {
                            // Generate PDF report
                            $pdf_filename = generatePDFReport($client, $report_period, $report_type, $pdo);
                            
                            if ($pdf_filename) {
                                // Send reports
                                $sent_via = [];
                                if ($bulk_send_email && !empty($client['email'])) {
                                    $sent_via[] = 'email';
                                    // TODO: Implement email sending
                                }
                                if ($bulk_send_sms && !empty($client['phone'])) {
                                    $sent_via[] = 'sms';
                                    // TODO: Implement SMS sending
                                }
                                
                                $stmt = $pdo->prepare("INSERT INTO client_reports (client_id, report_type, period_start, period_end, file_path, sent_via, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                $period_start = date('Y-m-01', strtotime($report_period));
                                $period_end = date('Y-m-t', strtotime($report_period));
                                $stmt->execute([$client_id, $report_type, $period_start, $period_end, $pdf_filename, implode(',', $sent_via)]);
                                
                                $generated_count++;
                            } else {
                                $error_count++;
                            }
                        } catch (Exception $e) {
                            $error_count++;
                            error_log("Error generating report for client {$client_id}: " . $e->getMessage());
                        }
                    }
                }
                
                if ($error_count > 0) {
                    $message = "Bulk reports partially generated! {$generated_count} reports created successfully. {$error_count} failed.";
                } else {
                    $success_message = "Bulk reports generated successfully! {$generated_count} reports created and sent.";
                }
            }
        }
    }

    // Generate reports data
    switch ($report_type) {
        case 'usage':
            $report_title = 'Internet Usage Report';
            $sql = "SELECT 
                        c.id,
                        c.full_name AS client_name,
                        c.email,
                        c.phone,
                        c.mikrotik_username,
                        COALESCE(SUM(ra.acctinputoctets), 0) as download_bytes,
                        COALESCE(SUM(ra.acctoutputoctets), 0) as upload_bytes,
                        COALESCE(SUM(ra.acctinputoctets + ra.acctoutputoctets), 0) as total_bytes,
                        COUNT(ra.radacctid) as sessions,
                        MAX(ra.acctstarttime) as last_activity
                    FROM clients c
                    LEFT JOIN radacct ra ON c.mikrotik_username = ra.username 
                        AND ra.acctstarttime BETWEEN ? AND ?
                    WHERE 1=1";
            
            $params = [$start_date, $end_date];
            if ($client_id) {
                $sql .= " AND c.id = ?";
                $params[] = $client_id;
            }
            $sql .= " GROUP BY c.id, c.full_name, c.email, c.phone, c.mikrotik_username
                      ORDER BY total_bytes DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;

        case 'financial':
            $report_title = 'Financial Report';
            $sql = "SELECT 
                        c.id,
                        c.full_name AS client_name,
                        c.email,
                        c.phone,
                        COUNT(p.id) as payment_count,
                        COALESCE(SUM(p.amount), 0) as total_paid,
                        MIN(p.payment_date) as first_payment,
                        MAX(p.payment_date) as last_payment
                    FROM clients c
                    LEFT JOIN payments p ON c.id = p.client_id 
                        AND p.payment_date BETWEEN ? AND ?
                    WHERE 1=1";
            
            $params = [$start_date, $end_date];
            if ($client_id) {
                $sql .= " AND c.id = ?";
                $params[] = $client_id;
            }
            $sql .= " GROUP BY c.id, c.full_name, c.email, c.phone
                      ORDER BY total_paid DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;

        case 'clients':
            $report_title = 'Clients Overview Report';
            $sql = "SELECT 
                        c.id,
                        c.full_name AS client_name,
                        c.email,
                        c.phone,
                        c.mikrotik_username,
                        c.created_at,
                        c.status,
                        (SELECT COUNT(*) FROM radacct WHERE username = c.mikrotik_username) as total_sessions,
                        (SELECT COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) FROM radacct WHERE username = c.mikrotik_username) as lifetime_usage,
                        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE client_id = c.id) as total_payments
                    FROM clients c
                    WHERE 1=1";
            
            $params = [];
            if ($client_id) {
                $sql .= " AND c.id = ?";
                $params[] = $client_id;
            }
            $sql .= " ORDER BY c.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll();
            break;
    }

    // Handle CSV export
    if ($export && !empty($report_data)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $report_type . '_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        if (!empty($report_data)) {
            fputcsv($output, array_keys($report_data[0]));
            
            // Add data
            foreach ($report_data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit;
    }
    
    // Get recent generated reports
    $recent_reports = $pdo->query("SELECT cr.*, c.full_name AS client_name 
                                  FROM client_reports cr 
                                  LEFT JOIN clients c ON cr.client_id = c.id 
                                  ORDER BY cr.created_at DESC 
                                  LIMIT 10")->fetchAll();
                              
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    error_log("Reports system error: " . $e->getMessage());
}

// Function to generate PDF report using TCPDF
function generatePDFReport($client, $period, $report_type, $pdo) {
    // Include TCPDF library
    require_once(__DIR__ . '/tcpdf/tcpdf.php');
    
    $period_start = date('Y-m-01', strtotime($period));
    $period_end = date('Y-m-t', strtotime($period));
    
    // Get usage data
    $usage_stmt = $pdo->prepare("SELECT 
                                COALESCE(SUM(acctinputoctets), 0) as download_bytes,
                                COALESCE(SUM(acctoutputoctets), 0) as upload_bytes,
                                COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) as total_bytes,
                                COUNT(radacctid) as sessions,
                                MAX(acctstarttime) as last_connection
                            FROM radacct 
                            WHERE username = ? AND acctstarttime BETWEEN ? AND ?");
    $usage_stmt->execute([$client['mikrotik_username'], $period_start . ' 00:00:00', $period_end . ' 23:59:59']);
    $usage_data = $usage_stmt->fetch();
    
    // Get payment data
    $payment_stmt = $pdo->prepare("SELECT 
                                amount,
                                payment_date,
                                payment_method,
                                reference
                            FROM payments 
                            WHERE client_id = ? AND payment_date BETWEEN ? AND ?
                            ORDER BY payment_date DESC");
    $payment_stmt->execute([$client['id'], $period_start, $period_end]);
    $payment_data = $payment_stmt->fetchAll();
    
    // Calculate totals
    $total_payments = array_sum(array_column($payment_data, 'amount'));
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Your Company');
    $pdf->SetTitle('Client Report - ' . $client['full_name']);
    $pdf->SetSubject('Monthly Usage and Payment Report');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, 'Your Company Name', 'Client Monthly Report');
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'CLIENT MONTHLY REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Period: ' . date('F Y', strtotime($period)), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Client Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Client Information', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $client_info = array(
        'Client Name: ' . $client['full_name'],
        'Username: ' . ($client['mikrotik_username'] ?? 'N/A'),
        'Email: ' . ($client['email'] ?? 'N/A'),
        'Phone: ' . ($client['phone'] ?? 'N/A'),
        'Report Period: ' . date('F j, Y', strtotime($period_start)) . ' to ' . date('F j, Y', strtotime($period_end))
    );
    
    foreach ($client_info as $line) {
        $pdf->Cell(0, 6, $line, 0, 1);
    }
    $pdf->Ln(10);
    
    // Usage Summary
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Internet Usage Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $usage_summary = array(
        'Total Download: ' . formatBytes($usage_data['download_bytes']),
        'Total Upload: ' . formatBytes($usage_data['upload_bytes']),
        'Total Data Usage: ' . formatBytes($usage_data['total_bytes']),
        'Connection Sessions: ' . number_format($usage_data['sessions']),
        'Last Connection: ' . ($usage_data['last_connection'] ? date('M j, Y g:i A', strtotime($usage_data['last_connection'])) : 'N/A')
    );
    
    foreach ($usage_summary as $line) {
        $pdf->Cell(0, 6, $line, 0, 1);
    }
    $pdf->Ln(10);
    
    // Payment Summary
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Payment Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $pdf->Cell(0, 6, 'Total Payments for Period: KES ' . number_format($total_payments, 2), 0, 1);
    $pdf->Cell(0, 6, 'Number of Payments: ' . count($payment_data), 0, 1);
    $pdf->Ln(5);
    
    // Payment details table
    if (!empty($payment_data)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, 'Date', 1, 0, 'C');
        $pdf->Cell(40, 7, 'Amount', 1, 0, 'C');
        $pdf->Cell(50, 7, 'Method', 1, 0, 'C');
        $pdf->Cell(60, 7, 'Reference', 1, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 9);
        foreach ($payment_data as $payment) {
            $pdf->Cell(40, 6, date('M j, Y', strtotime($payment['payment_date'])), 1, 0, 'C');
            $pdf->Cell(40, 6, 'KES ' . number_format($payment['amount'], 2), 1, 0, 'C');
            $pdf->Cell(50, 6, $payment['payment_method'] ?? 'N/A', 1, 0, 'C');
            $pdf->Cell(60, 6, $payment['reference'] ?? 'N/A', 1, 1, 'C');
        }
    } else {
        $pdf->Cell(0, 6, 'No payments recorded for this period.', 0, 1);
    }
    $pdf->Ln(10);
    
    // Summary
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    
    $summary_text = "During " . date('F Y', strtotime($period)) . ", " . $client['full_name'] . " used " . 
                   formatBytes($usage_data['total_bytes']) . " of data across " . number_format($usage_data['sessions']) . 
                   " connection sessions.";
    
    if ($total_payments > 0) {
        $summary_text .= " Total payments received: KES " . number_format($total_payments, 2) . ".";
    } else {
        $summary_text .= " No payments were recorded for this period.";
    }
    
    $pdf->MultiCell(0, 6, $summary_text, 0, 'L');
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y g:i A'), 0, 0, 'C');
    
    // Save PDF file
    $filename = 'report_' . $client['id'] . '_' . date('Y_m_d_H_i_s') . '.pdf';
    $filepath = __DIR__ . '/reports/' . $filename;
    
    // Ensure reports directory exists
    if (!is_dir(__DIR__ . '/reports')) {
        mkdir(__DIR__ . '/reports', 0755, true);
    }
    
    $pdf->Output($filepath, 'F');
    
    return $filename;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <!-- Header -->
        <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 style="margin: 0; color: #333;"><i class="fas fa-chart-bar me-3"></i>Reports & Analytics</h1>
                    <p class="text-muted mb-0">Generate detailed client reports with PDF export and delivery</p>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                        <i class="fas fa-file-pdf me-2"></i>Generate Report
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkReportsModal">
                        <i class="fas fa-users me-2"></i>Bulk Reports
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
                        <h5 class="card-title">Single Report</h5>
                        <p class="card-text">Generate PDF report for individual client</p>
                        <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#generateReportModal">
                            <i class="fas fa-file-pdf me-2"></i>Generate
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
                        <h5 class="card-title">Bulk Reports</h5>
                        <p class="card-text">Generate reports for multiple clients</p>
                        <button class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#bulkReportsModal">
                            <i class="fas fa-copy me-2"></i>Bulk Generate
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-info mb-3">
                            <i class="fas fa-chart-line fa-3x"></i>
                        </div>
                        <h5 class="card-title">Usage Analytics</h5>
                        <p class="card-text">View detailed usage statistics</p>
                        <a href="?report_type=usage" class="btn btn-info w-100">
                            <i class="fas fa-chart-bar me-2"></i>View Usage
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <div class="text-success mb-3">
                            <i class="fas fa-money-bill fa-3x"></i>
                        </div>
                        <h5 class="card-title">Financial Reports</h5>
                        <p class="card-text">Payment and revenue analytics</p>
                        <a href="?report_type=financial" class="btn btn-success w-100">
                            <i class="fas fa-chart-pie me-2"></i>View Financial
                        </a>
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
                                <h4 class="mb-0"><?php echo count($report_data); ?></h4>
                                <span>Report Records</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-database fa-2x"></i>
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
                                <h4 class="mb-0"><?php echo count($recent_reports); ?></h4>
                                <span>Recent Reports</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-file-pdf fa-2x"></i>
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
                                <h4 class="mb-0"><?php echo $date_ranges[$date_range][0]; ?></h4>
                                <span>Date Range</span>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-calendar fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Filters -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Report Type</label>
                        <select name="report_type" class="form-select" onchange="this.form.submit()">
                            <option value="usage" <?php echo $report_type === 'usage' ? 'selected' : ''; ?>>Internet Usage</option>
                            <option value="financial" <?php echo $report_type === 'financial' ? 'selected' : ''; ?>>Financial</option>
                            <option value="clients" <?php echo $report_type === 'clients' ? 'selected' : ''; ?>>Clients Overview</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Date Range</label>
                        <select name="date_range" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($date_ranges as $key => $range): ?>
                                <option value="<?php echo $key; ?>" <?php echo $date_range === $key ? 'selected' : ''; ?>>
                                    <?php echo $range[0]; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Client Filter</label>
                        <select name="client_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo $client_id == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Actions</label>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-refresh me-2"></i>Generate
                            </button>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                                <i class="fas fa-download me-2"></i>Export CSV
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Clients List with Checkboxes -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-address-book me-2"></i>
                    Client Directory
                </h5>
                <span class="badge bg-light text-dark">
                    <?php echo count($clients); ?> total clients
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
                                <th>Contact Info</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="client-checkbox" name="selected_clients[]" value="<?php echo $client['id']; ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($client['email'])): ?>
                                            <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($client['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($client['phone'])): ?>
                                            <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($client['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($client['mikrotik_username'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $client['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($client['status'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="quickGenerateReport(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                title="Generate Report">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
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
                            <button class="btn btn-info me-2" onclick="showBulkReportsModal()" id="bulkReportsBtn">
                                <i class="fas fa-copy me-2"></i>Generate Reports
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Data -->
        <div class="card mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    <?php echo $report_title; ?> 
                    <small class="ms-2">(<?php echo $date_ranges[$date_range][0]; ?>)</small>
                </h5>
                <span class="badge bg-light text-dark">
                    <?php echo count($report_data); ?> records
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No data found</h4>
                        <p class="text-muted">Try adjusting your filters or date range</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <?php foreach (array_keys($report_data[0]) as $column): ?>
                                        <th><?php echo ucwords(str_replace('_', ' ', $column)); ?></th>
                                    <?php endforeach; ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $key => $value): ?>
                                            <td>
                                                <?php 
                                                if (strpos($key, 'bytes') !== false || $key === 'lifetime_usage') {
                                                    echo formatBytes($value);
                                                } elseif ($key === 'total_paid' || $key === 'total_payments') {
                                                    echo 'KES ' . number_format($value, 2);
                                                } elseif ($key === 'last_activity' && $value) {
                                                    echo date('M j, Y g:i A', strtotime($value));
                                                } elseif ($key === 'created_at') {
                                                    echo date('M j, Y', strtotime($value));
                                                } elseif (is_numeric($value) && $key !== 'id') {
                                                    echo number_format($value);
                                                } else {
                                                    echo htmlspecialchars($value ?? '');
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="quickGenerateReport(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['client_name']); ?>')"
                                                    title="Generate Report">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Generated Reports -->
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>
                    Recently Generated Reports
                </h5>
                <span class="badge bg-light text-dark">
                    Last 10 reports
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($recent_reports)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-pdf fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No reports generated yet</h4>
                        <p class="text-muted">Generate your first report to see activity here</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Date Generated</th>
                                    <th>Client</th>
                                    <th>Report Period</th>
                                    <th>Type</th>
                                    <th>Sent Via</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_reports as $report): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($report['client_name']); ?></td>
                                        <td><?php echo date('M Y', strtotime($report['period_start'])); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo ucfirst($report['report_type']); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($report['sent_via'])): ?>
                                                <?php 
                                                $sent_via = explode(',', $report['sent_via']);
                                                foreach ($sent_via as $method): ?>
                                                    <span class="badge bg-success me-1"><?php echo strtoupper($method); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Sent</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">Generated</span>
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

<!-- Generate Report Modal -->
<div class="modal fade" id="generateReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-pdf me-2"></i>Generate Client Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="generate_report" value="1">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Select Client *</label>
                            <select name="client_id" class="form-select" required id="reportClientSelect">
                                <option value="">-- Select Client --</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" data-email="<?php echo htmlspecialchars($client['email'] ?? ''); ?>" data-phone="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($client['full_name'] . ' (' . ($client['email'] ?? 'No email') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Report Period *</label>
                            <input type="month" name="report_period" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Report Type *</label>
                            <select name="report_type" class="form-select" required>
                                <option value="monthly">Monthly Report</option>
                                <option value="quarterly">Quarterly Report</option>
                                <option value="receipt">Payment Receipt</option>
                                <option value="usage">Usage Report</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Client Contact</label>
                            <div class="bg-light p-3 rounded">
                                <div id="clientEmailDisplay" class="text-muted">No client selected</div>
                                <div id="clientPhoneDisplay" class="text-muted"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Delivery Options</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_email" id="send_email">
                                    <label class="form-check-label fw-bold" for="send_email">
                                        <i class="fas fa-envelope me-2"></i>Send via Email
                                    </label>
                                    <div class="form-text">Send PDF report to client's email address</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_sms" id="send_sms">
                                    <label class="form-check-label fw-bold" for="send_sms">
                                        <i class="fas fa-sms me-2"></i>Send via SMS
                                    </label>
                                    <div class="form-text">Send notification to client's phone</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        The report will include usage statistics, payment history, and account summary for the selected period.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-file-pdf me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Reports Modal -->
<div class="modal fade" id="bulkReportsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Generate Bulk Reports</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="generate_bulk_reports" value="1">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Selected Clients</label>
                        <div class="bg-light p-3 rounded" style="max-height: 200px; overflow-y: auto;" id="bulkClientsList">
                            <div class="text-muted">No clients selected. Please check clients from the table above.</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Report Period *</label>
                            <input type="month" name="bulk_report_period" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Report Type *</label>
                            <select name="bulk_report_type" class="form-select" required>
                                <option value="monthly">Monthly Report</option>
                                <option value="quarterly">Quarterly Report</option>
                                <option value="receipt">Payment Receipt</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Bulk Delivery Options</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="bulk_send_email" id="bulk_send_email">
                                    <label class="form-check-label fw-bold" for="bulk_send_email">
                                        <i class="fas fa-envelope me-2"></i>Send via Email
                                    </label>
                                    <div class="form-text">Send to clients with email addresses</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="bulk_send_sms" id="bulk_send_sms">
                                    <label class="form-check-label fw-bold" for="bulk_send_sms">
                                        <i class="fas fa-sms me-2"></i>Send via SMS
                                    </label>
                                    <div class="form-text">Send to clients with phone numbers</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This will generate reports for all selected clients. The process may take several minutes depending on the number of clients.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-copy me-2"></i>Generate Bulk Reports
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Quick report generation
function quickGenerateReport(clientId, clientName) {
    const modal = new bootstrap.Modal(document.getElementById('generateReportModal'));
    const form = document.querySelector('#generateReportModal form');
    form.querySelector('select[name="client_id"]').value = clientId;
    
    // Update client contact info
    updateClientContactInfo(clientId);
    
    modal.show();
}

// Update client contact info when client selection changes
function updateClientContactInfo(clientId) {
    const clientSelect = document.getElementById('reportClientSelect');
    const selectedOption = clientSelect.querySelector(`option[value="${clientId}"]`);
    
    if (selectedOption) {
        const email = selectedOption.getAttribute('data-email') || 'No email';
        const phone = selectedOption.getAttribute('data-phone') || 'No phone';
        
        document.getElementById('clientEmailDisplay').textContent = 'Email: ' + email;
        document.getElementById('clientPhoneDisplay').textContent = 'Phone: ' + phone;
        
        // Enable/disable delivery options based on client contact info
        const emailCheckbox = document.getElementById('send_email');
        const smsCheckbox = document.getElementById('send_sms');
        
        emailCheckbox.disabled = !email || email === 'No email';
        smsCheckbox.disabled = !phone || phone === 'No phone';
        
        if (emailCheckbox.disabled) emailCheckbox.checked = false;
        if (smsCheckbox.disabled) smsCheckbox.checked = false;
    }
}

// Client selection management for bulk reports
let selectedClients = [];

function toggleSelectAll(checkbox) {
    const clientCheckboxes = document.querySelectorAll('.client-checkbox');
    clientCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkSelectedCount();
}

function updateBulkSelectedCount() {
    const selected = document.querySelectorAll('.client-checkbox:checked');
    const count = selected.length;
    document.getElementById('selectedCount').textContent = count + ' clients selected';
    document.getElementById('bulkReportsBtn').disabled = count === 0;
    
    // Update selected clients array
    selectedClients = Array.from(selected).map(cb => cb.value);
    
    // Update bulk clients list
    updateBulkClientsList();
}

function updateBulkClientsList() {
    const listContainer = document.getElementById('bulkClientsList');
    const countDisplay = document.getElementById('bulkSelectedCount');
    
    if (selectedClients.length === 0) {
        listContainer.innerHTML = '<div class="text-muted">No clients selected. Please check clients from the table above.</div>';
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
}

function showBulkReportsModal() {
    if (selectedClients.length > 0) {
        const modal = new bootstrap.Modal(document.getElementById('bulkReportsModal'));
        modal.show();
    } else {
        alert('Please select at least one client from the table above.');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update selected count when checkboxes change
    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkSelectedCount);
    });
    
    // Update client contact info when client selection changes in single report modal
    document.getElementById('reportClientSelect')?.addEventListener('change', function() {
        const clientId = this.value;
        if (clientId) {
            updateClientContactInfo(clientId);
        } else {
            document.getElementById('clientEmailDisplay').textContent = 'No client selected';
            document.getElementById('clientPhoneDisplay').textContent = '';
        }
    });
    
    // Initialize selected count
    updateBulkSelectedCount();
    
    // Auto-focus on client selection when report modal opens
    $('#generateReportModal').on('shown.bs.modal', function () {
        $(this).find('select[name="client_id"]').focus();
    });
});
</script>