<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$invoice = $_GET['invoice'] ?? ($_GET['preview'] === 'latest' ? null : null);

if ($invoice === null && isset($_GET['preview']) && $_GET['preview'] === 'latest') {
    $stmt = $db->prepare("SELECT invoice FROM payments WHERE invoice LIKE 'INV-fortunett-%' ORDER BY payment_date DESC LIMIT 1");
    $stmt->execute();
    $invoice = $stmt->fetchColumn() ?: null;
}

if (empty($invoice)) {
    echo "Invoice not found.";
    exit;
}

try {
    $stmt = $db->prepare("SELECT * FROM payments WHERE invoice = ? LIMIT 1");
    $stmt->execute([$invoice]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        echo "Invoice not found.";
        exit;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit;
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
            <h2 style="margin:0;">View Invoice & Payment Details</h2>
            <div>
                <?php if ($inv['status'] !== 'completed'): ?>
                <a href="stk_payment.php?invoice=<?php echo urlencode($invoice); ?>" style="background:#06b6d4;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;">Pay Now</a>
                <?php else: ?>
                <span style="background:#d1fae5;color:#065f46;padding:8px 12px;border-radius:6px;font-weight:600;">PAID</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Simple invoice layout -->
        <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,0.05);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3 style="margin:0;color:#06b6d4;">Fortunett Technologies Ltd.</h3>
                    <div style="color:#666;font-size:13px;">sales@fortunett.com<br>+254 700 000 000<br>Upper Hill, Nairobi, Kenya</div>
                </div>
                <div style="text-align:right;">
                    <h4 style="margin:0;">INVOICE</h4>
                    <div style="color:#666;font-size:13px;">Invoice #: <?php echo htmlspecialchars($inv['invoice']); ?></div>
                    <div style="color:#666;font-size:13px;">Date: <?php echo date('M j, Y', strtotime($inv['payment_date'] ?? 'now')); ?></div>
                    <div style="color:#666;font-size:13px;">Status: <?php echo htmlspecialchars(ucfirst($inv['status'])); ?></div>
                </div>
            </div>

            <hr style="margin:18px 0;border:none;border-top:1px solid #f3f4f6;">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;">
                <div>
                    <strong>BILL TO</strong>
                    <div style="color:#666;">FortuNett<br><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?><br><?php echo htmlspecialchars($inv['message'] ?? ''); ?></div>
                </div>
                <div style="text-align:right;">
                    <strong>TOTAL DUE</strong>
                    <div style="font-size:18px;color:#06b6d4;font-weight:700;">KES <?php echo number_format($inv['amount'],2); ?></div>
                </div>
            </div>

            <table style="width:100%;border-collapse:collapse;">
                <thead style="background:#f9fafb;">
                    <tr>
                        <th style="padding:12px;text-align:left;">DESCRIPTION</th>
                        <th style="padding:12px;text-align:right;">PRICE</th>
                        <th style="padding:12px;text-align:right;">QTY</th>
                        <th style="padding:12px;text-align:right;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:12px;"><?php echo htmlspecialchars($inv['message'] ?: 'Fortunett License'); ?></td>
                        <td style="padding:12px;text-align:right;">KES <?php echo number_format($inv['amount'],2); ?></td>
                        <td style="padding:12px;text-align:right;">1</td>
                        <td style="padding:12px;text-align:right;">KES <?php echo number_format($inv['amount'],2); ?></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top:18px;color:#666;font-size:13px;">
                Please use the Pay Now button to pay via STK push. After successful payment the invoice will be marked paid and appear in the payments table.
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
