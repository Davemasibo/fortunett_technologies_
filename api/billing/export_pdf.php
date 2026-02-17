<?php
/**
 * Billing PDF Export using TCPDF
 * Generates invoice PDFs for customer billing
 */

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auth.php';

// Check if TCPDF is available, otherwise provide installation instructions
if (!class_exists('TCPDF')) {
    // Fallback: Simple HTML to PDF conversion
    class SimplePDF {
        public static function generate($invoice_id, $tenant_id, $pdo) {
            // Fetch invoice/billing data
            $stmt = $pdo->prepare("
                SELECT c.*, p.amount, p.payment_date, p.transaction_id,
                       pkg.name as package_name, pkg.price as package_price
                FROM clients c
                LEFT JOIN payments p ON c.id = p.client_id
                LEFT JOIN packages pkg ON c.package_id = pkg.id
                WHERE c.id = ? AND c.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$invoice_id, $tenant_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) {
                die("Invoice not found");
            }
            
            // Generate HTML invoice
            $html = self::getInvoiceHTML($data, $tenant_id, $pdo);
            
            // Set headers for PDF download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="invoice_' . $invoice_id . '.pdf"');
            
            // For now, output HTML (client can print to PDF)
            // TODO: Integrate TCPDF or use wkhtmltopdf
            echo $html;
        }
        
        private static function getInvoiceHTML($data, $tenant_id, $pdo) {
            // Get tenant info
            $tenantStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $tenantStmt->execute([$tenant_id]);
            $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);
            
            $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . $data['id'] . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; }
        .company-name { font-size: 24px; font-weight: bold; color: #333; }
        .invoice-title { font-size: 20px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; }
        .total { font-size: 18px; font-weight: bold; text-align: right; margin-top: 20px; }
        .footer { margin-top: 50px; text-align: center; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">' . htmlspecialchars($tenant['company_name'] ?? 'ISP Company') . '</div>
        <div>Internet Service Provider</div>
    </div>
    
    <div class="invoice-title">INVOICE</div>
    
    <table>
        <tr>
            <td><strong>Invoice #:</strong></td>
            <td>' . $data['id'] . '</td>
            <td><strong>Date:</strong></td>
            <td>' . date('F j, Y') . '</td>
        </tr>
        <tr>
            <td><strong>Customer:</strong></td>
            <td>' . htmlspecialchars($data['full_name']) . '</td>
            <td><strong>Account #:</strong></td>
            <td>' . htmlspecialchars($data['account_number'] ?? 'N/A') . '</td>
        </tr>
        <tr>
            <td><strong>Phone:</strong></td>
            <td>' . htmlspecialchars($data['phone']) . '</td>
            <td><strong>Email:</strong></td>
            <td>' . htmlspecialchars($data['email'] ?? '') . '</td>
        </tr>
    </table>
    
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Package</th>
                <th>Period</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Internet Service</td>
                <td>' . htmlspecialchars($data['package_name'] ?? 'N/A') . '</td>
                <td>Monthly</td>
                <td>KES ' . number_format($data['package_price'] ?? 0, 2) . '</td>
            </tr>
        </tbody>
    </table>
    
    <div class="total">
        Total: KES ' . number_format($data['package_price'] ?? 0, 2) . '
    </div>
    
    <div class="footer">
        <p>Thank you for your business!</p>
        <p>' . htmlspecialchars($tenant['company_name'] ?? '') . ' | ' . date('Y') . '</p>
    </div>
</body>
</html>';
            
            return $html;
        }
    }
    
    // Use SimplePDF
    if (isset($_GET['invoice_id']) && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $tenant_id = $stmt->fetchColumn();
        
        SimplePDF::generate($_GET['invoice_id'], $tenant_id, $pdo);
    } else {
        echo "Missing invoice_id or not authenticated";
    }
    exit;
}
