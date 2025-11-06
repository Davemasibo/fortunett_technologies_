<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$invoice = $_GET['invoice'] ?? '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

$inv = null;
$client = null;
$defaultPhone = '';
$defaultAmount = 0.00;

if ($user_id) {
    // load client + package info
    $stmt = $db->prepare("SELECT c.*, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON c.package_id = p.id WHERE c.id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) {
        $defaultPhone = $client['phone_number'] ?? '';
        $defaultAmount = (float)($client['package_price'] ?? 0.0);
    } else {
        header('Location: clients.php');
        exit;
    }
} elseif ($invoice) {
    // existing invoice flow
    $stmt = $db->prepare("SELECT * FROM payments WHERE invoice = ? LIMIT 1");
    $stmt->execute([$invoice]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        header('Location: subscription.php');
        exit;
    }
    $defaultPhone = $_SESSION['phone'] ?? '';
    $defaultAmount = (float)($inv['amount'] ?? 0.0);
} else {
    header('Location: subscription.php');
    exit;
}

$action_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    if (!$phone || $amount <= 0) {
        $action_result = 'error|Phone and amount required';
    } else {
        // normalize phone for logging (basic)
        $formatted = $phone;
        if (substr($phone,0,1) === '0') $formatted = '254' . substr($phone,1);
        if (substr($formatted,0,1) === '+') $formatted = ltrim($formatted,'+');

        try {
            // Log payment attempt (pending)
            $stmt = $db->prepare("INSERT INTO payments (client_id, amount, payment_date, status, invoice, payment_method, message) VALUES (?, ?, NOW(), 'pending', ?, 'mpesa', ?)");
            $invoiceRef = $invoice ?: ('INV-user-' . ($user_id ? $user_id : time()));
            $message = ($client ? "STK push for user_id {$user_id}" : "STK push for invoice {$invoiceRef}") . " to {$phone}";
            $stmt->execute([ $user_id ?: 0, $amount, $invoiceRef, $message ]);

            $payment_id = $db->lastInsertId();

            // TODO: Integrate real STK provider here — for now simulate success
            $stmt = $db->prepare("UPDATE payments SET status = 'completed', payment_date = NOW(), message = CONCAT(message,' | completed') WHERE id = ?");
            $stmt->execute([$payment_id]);

            // If created against an invoice record mark that invoice completed
            if ($invoice) {
                $stmt = $db->prepare("UPDATE payments SET status = 'completed' WHERE invoice = ? AND payment_method = 'invoice'");
                $stmt->execute([$invoice]);
            }

            $action_result = 'success|Payment recorded successfully';
            // redirect to subscription or client page
            if ($user_id) {
                header('Location: clients.php?action=view&id=' . $user_id);
            } else {
                header('Location: subscription.php');
            }
            exit;
        } catch (Exception $e) {
            $action_result = 'error|' . $e->getMessage();
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="main-content-wrapper">
    <div>
        <div style="margin-bottom:18px;">
            <?php if ($user_id): ?>
                <a href="clients.php?action=view&id=<?php echo $user_id; ?>" style="color:#667eea;text-decoration:none;"><i class="fas fa-arrow-left me-2"></i>Back to User</a>
            <?php else: ?>
                <a href="subscription.php" style="color:#667eea;text-decoration:none;"><i class="fas fa-arrow-left me-2"></i>Back to Subscription</a>
            <?php endif; ?>
        </div>

        <div style="background:#fff;padding:22px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,0.05);max-width:700px;">
            <h3 style="margin:0 0 10px 0;">Send STK Push</h3>
            <div style="color:#666;margin-bottom:16px;">
                <?php if ($user_id): ?>
                    User: <strong><?php echo htmlspecialchars($client['full_name'] ?? $client['username']); ?></strong> — Package: <strong><?php echo htmlspecialchars($client['package_name'] ?? 'N/A'); ?></strong>
                <?php else: ?>
                    Invoice: <strong><?php echo htmlspecialchars($inv['invoice']); ?></strong>
                <?php endif; ?>
            </div>

            <?php if ($action_result): 
                list($type,$msg) = explode('|', $action_result, 2);
            ?>
                <div style="background:<?php echo $type==='success' ? '#d1fae5' : '#fee2e2'; ?>; color:<?php echo $type==='success' ? '#065f46' : '#991b1b'; ?>; padding:12px; border-radius:6px; margin-bottom:12px;"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <form method="POST" onsubmit="return validateForm(this);">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;color:#666;margin-bottom:6px;">Phone number</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($defaultPhone); ?>" placeholder="0712345678 or 254712345678" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;color:#666;margin-bottom:6px;">Amount (KES)</label>
                    <input type="number" name="amount" value="<?php echo number_format((float)$defaultAmount,2,'.',''); ?>" step="0.01" style="width:200px;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
                </div>

                <div style="display:flex;gap:10px;">
                    <button type="submit" style="padding:10px 16px;background:#06b6d4;color:#fff;border:none;border-radius:6px;font-weight:600;">Send STK Push</button>
                    <a href="<?php echo $user_id ? 'clients.php?action=view&id='.$user_id : 'subscription.php'; ?>" style="padding:10px 16px;border-radius:6px;border:1px solid #e5e7eb;color:#333;text-decoration:none;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function validateForm(form){
    const phone = form.phone.value.trim();
    const amount = parseFloat(form.amount.value);
    if (!phone) { alert('Phone required'); return false; }
    if (!amount || amount <= 0) { alert('Valid amount required'); return false; }
    return confirm('Send STK push to ' + phone + ' for KES ' + amount.toFixed(2) + '?');
}
</script>
