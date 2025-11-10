<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

// detect whether payments.invoice column exists
try {
    $has_invoice_col = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='invoice'")->fetchColumn();
} catch (Throwable $e) {
    $has_invoice_col = false;
}

$profile = getISPProfile($db);

// Create invoice on demand (quick flow)
if (isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? 'Fortunett License Renewal');
    $invoice_no = 'INV-fortunett-' . date('Ymd') . substr(uniqid(), -6);

    try {
        if ($has_invoice_col) {
            $stmt = $db->prepare("INSERT INTO payments (client_id, amount, payment_date, status, invoice, payment_method, message) VALUES (?, ?, NOW(), 'pending', ?, 'invoice', ?)");
            // client_id = 0 for license/invoice (adjust if you track a client id)
            $stmt->execute([0, $amount, $invoice_no, $description]);
            header("Location: invoice.php?invoice=" . urlencode($invoice_no));
            exit;
        } else {
            // payments table doesn't have invoice column; insert without it and redirect back
            $stmt = $db->prepare("INSERT INTO payments (client_id, amount, payment_date, status, payment_method, message) VALUES (?, ?, NOW(), 'pending', 'invoice', ?)");
            $stmt->execute([0, $amount, $description]);
            header("Location: subscription.php?created=1");
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch subscription-related invoices/payments
try {
    if ($has_invoice_col) {
        $stmt = $db->prepare("SELECT id, amount, message, payment_date, status, invoice, payment_method FROM payments WHERE invoice LIKE 'INV-fortunett-%' OR payment_method IN ('subscription','invoice','mpesa') ORDER BY payment_date DESC");
    } else {
        $stmt = $db->prepare("SELECT id, amount, message, payment_date, status, payment_method FROM payments WHERE payment_method IN ('subscription','invoice','mpesa') ORDER BY payment_date DESC");
    }
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $subscriptions = [];
}

// License expiry display (from profile)
$expiry_text = !empty($profile['subscription_expiry']) ? date('F j, Y \a\t g:i A', strtotime($profile['subscription_expiry'])) : 'Not set';

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="main-content-wrapper">
    <div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
            <div>
                <h1 style="font-size:24px;margin:0 0 6px 0;">Fortunett Licence</h1>
                <div style="color:#666;">Your subscription expires on <?php echo $expiry_text; ?>. Please renew before it expires.</div>
            </div>

            <div style="display:flex;gap:12px;align-items:center;">
                <a href="invoice.php?preview=latest" class="btn" style="background:#06b6d4;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;">
                    <i class="fas fa-file-invoice me-1"></i>View Invoice & Payment Details
                </a>
            </div>
        </div>

        <!-- Create invoice quick form -->
        <div style="background:#fff;padding:18px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);margin-bottom:20px;">
            <form method="POST" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="create_invoice">
                <div>
                    <label style="display:block;font-size:12px;color:#666;">Description</label>
                    <input type="text" name="description" value="Fortunett License Renewal" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:#666;">Amount (KES)</label>
                    <input type="number" name="amount" value="500" step="0.01" style="padding:8px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>
                <div style="margin-top:20px;">
                    <button type="submit" style="padding:10px 16px;background:#667eea;color:#fff;border:none;border-radius:8px;font-weight:600;">Generate Invoice</button>
                </div>
            </form>
            <?php if (!empty($error)): ?>
                <div style="color:#b91c1c;margin-top:8px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
        </div>

        <!-- Payments / Invoices Table -->
        <div style="background:#fff;padding:18px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
            <div style="overflow:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #eee;">
                            <th style="padding:12px;text-align:left;">Amount</th>
                            <th style="padding:12px;text-align:left;">Message</th>
                            <th style="padding:12px;text-align:left;">Payment date</th>
                            <th style="padding:12px;text-align:left;">Invoice</th>
                            <th style="padding:12px;text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($subscriptions)): foreach ($subscriptions as $row): ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px;">KES <?php echo number_format($row['amount'],2); ?></td>
                            <td style="padding:12px;"><?php echo htmlspecialchars($row['message']); ?></td>
                            <td style="padding:12px;"><?php echo !empty($row['payment_date']) ? date('M j, Y H:i', strtotime($row['payment_date'])) : '—'; ?></td>
                            <td style="padding:12px;"><?php echo htmlspecialchars($row['invoice'] ?? '—'); ?></td>
                            <td style="padding:12px;text-align:right;">
                                <a href="invoice.php?invoice=<?php echo urlencode($row['invoice']); ?>" style="color:#06b6d4;text-decoration:none;margin-right:10px;">View</a>
                                <?php if ($row['status'] !== 'completed'): ?>
                                    <a href="stk_payment.php?invoice=<?php echo urlencode($row['invoice']); ?>" style="background:#06b6d4;color:#fff;padding:6px 10px;border-radius:6px;text-decoration:none;">Pay</a>
                                <?php else: ?>
                                    <span style="color:#10b981;font-weight:600;">Paid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5" style="padding:20px;text-align:center;color:#6b7280;">No subscription invoices/payments found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
