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

// detect whether payments.invoice column exists to guide invoice flows
try {
    $has_invoice_col = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='invoice'")->fetchColumn();
} catch (Throwable $e) {
    $has_invoice_col = false;
}

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
    // If DB doesn't have invoice column, we cannot resolve invoice-based flow
    if (!$has_invoice_col) {
        header('Location: subscription.php');
        exit;
    }
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
            // Build invoice reference and message. Prefer client's account_number when available.
            // Prefer persisted account_number; otherwise generate one and persist it for future use
            $clientAccount = $client['account_number'] ?? null;
            if ($invoice) {
                $invoiceRef = $invoice;
            } else {
                // generate a formatted account number using business initial + zero-padded id
                $generatedAcc = getAccountNumber($db, $client ?: $user_id);
                if (!empty($clientAccount)) {
                    $invoiceRef = $clientAccount;
                } else {
                    $invoiceRef = $generatedAcc;
                    // persist generated account number to clients table so future STK pushes use the same value
                    if (!empty($user_id)) {
                        try {
                            $ustmt = $db->prepare("UPDATE clients SET account_number = ? WHERE id = ? AND (account_number IS NULL OR account_number = '')");
                            $ustmt->execute([$generatedAcc, $user_id]);
                        } catch (Throwable $e) {
                            // ignore persistence errors
                        }
                    }
                }
            }
            $message = ($client ? "STK push for user_id {$user_id}" : "STK push for invoice {$invoiceRef}") . " to {$phone}";

            // Detect whether payments.invoice column exists — keep insertion robust across schema variants
            try {
                $has_invoice = (bool) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='invoice'")->fetchColumn();
            } catch (Throwable $e) {
                $has_invoice = false;
            }

            if ($has_invoice) {
                $stmt = $db->prepare("INSERT INTO payments (client_id, amount, payment_date, status, invoice, payment_method, message) VALUES (?, ?, NOW(), 'pending', ?, 'mpesa', ?)");
                $stmt->execute([ $user_id ?: 0, $amount, $invoiceRef, $message ]);
            } else {
                // payments table doesn't have invoice column — insert without it
                $stmt = $db->prepare("INSERT INTO payments (client_id, amount, payment_date, status, payment_method, message) VALUES (?, ?, NOW(), 'pending', 'mpesa', ?)");
                $stmt->execute([ $user_id ?: 0, $amount, $message ]);
            }

            $payment_id = $db->lastInsertId();

            // Try to send a real STK push using lib/mpesa.php when configured
            $mpesaResult = null;
            try {
                require_once __DIR__ . '/lib/mpesa.php';
                $accountRef = $invoiceRef;
                $txnDesc = 'Payment for ' . ($client ? ($client['full_name'] ?? $client['username']) : $invoiceRef);
                $mpesaResult = mpesa_initiate_stk($phone, $amount, $accountRef, $txnDesc);
            } catch (Throwable $e) {
                $mpesaResult = ['success' => false, 'error' => $e->getMessage()];
            }

            if ($mpesaResult && $mpesaResult['success']) {
                // STK push accepted by provider — keep record pending and store provider response
                $respText = is_array($mpesaResult['data']) ? json_encode($mpesaResult['data']) : (string)$mpesaResult['data'];
                // Use positional placeholders only (avoid mixing named and positional parameters)
                $stmt = $db->prepare("UPDATE payments SET message = CONCAT(IFNULL(message,''), ' | ', ?) WHERE id = ?");
                $stmt->execute([ $respText, $payment_id ]);

                // If provider returned a CheckoutRequestID, persist it into transaction_id so callbacks can match by it
                $checkoutId = null;
                if (is_array($mpesaResult['data'])) {
                    if (!empty($mpesaResult['data']['CheckoutRequestID'])) {
                        $checkoutId = $mpesaResult['data']['CheckoutRequestID'];
                    } elseif (!empty($mpesaResult['data']['Response']['CheckoutRequestID'])) {
                        $checkoutId = $mpesaResult['data']['Response']['CheckoutRequestID'];
                    }
                }
                if ($checkoutId) {
                    try {
                        $u = $db->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?");
                        $u->execute([$checkoutId, $payment_id]);
                    } catch (Throwable $e) {
                        // ignore persistence errors
                    }
                }

                // Show a waiting UI that polls the payment status until completion
                $waitingPaymentId = $payment_id;
                include 'includes/header.php';
                include 'includes/sidebar.php';
                ?>
                <div class="main-content-wrapper">
                    <div style="max-width:700px;margin:24px auto;background:#fff;padding:22px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,0.05);">
                        <h3 style="margin:0 0 10px 0;">STK Push Initiated</h3>
                        <p style="color:#374151;margin-bottom:16px;">We've sent the STK push to <?php echo htmlspecialchars($phone); ?> for KES <?php echo number_format($amount,2); ?>. Please complete the payment on the phone. This page will wait for confirmation.</p>
                        <div id="statusBox" style="padding:12px;border-radius:6px;background:#f8fafc;color:#111;font-weight:600;">Awaiting confirmation…</div>
                    </div>
                </div>
                <script>
                const paymentId = <?php echo (int)$waitingPaymentId; ?>;
                let attempts = 0;
                const maxAttempts = 40; // ~2 minutes (40 * 3s)
                const box = document.getElementById('statusBox');

                const poll = () => {
                    attempts++;
                    fetch('api/payment_status.php?payment_id=' + paymentId, { credentials: 'same-origin' })
                        .then(r => {
                            if (!r.ok) throw new Error('Network response not ok');
                            return r.json();
                        })
                        .then(j => {
                            if (!j || j.error) {
                                box.innerText = 'Waiting for confirmation…';
                                return;
                            }
                            if (!j.status) return;
                            if (j.status === 'pending') {
                                box.innerText = 'Awaiting confirmation… (last update: ' + (j.updated_at || 'now') + ')';
                            } else if (j.status === 'completed') {
                                box.innerText = 'Payment completed ✅ — ' + (j.message || '');
                                clearInterval(intervalId);
                                setTimeout(()=>{ window.location = '<?php echo $user_id ? "clients.php?action=view&id={$user_id}" : "subscription.php"; ?>'; }, 1200);
                            } else if (j.status === 'failed') {
                                box.innerText = 'Payment failed ❌ — ' + (j.message || '');
                                clearInterval(intervalId);
                            }
                        })
                        .catch(err => {
                            console.debug('Payment poll error', err);
                            box.innerText = 'Waiting for confirmation…';
                        })
                        .finally(() => {
                            if (attempts >= maxAttempts) {
                                box.innerText = 'Timed out waiting for confirmation. Please check payment status or try again.';
                                clearInterval(intervalId);
                            }
                        });
                };

                // poll every 3 seconds
                const intervalId = setInterval(poll, 3000);
                poll();
                </script>
                <?php
                include 'includes/footer.php';
                exit;
            } else {
                // Provider not configured or STK failed — fall back to simulated completion and mark error
                $err = $mpesaResult['error'] ?? 'STK not configured';
                // mark as error
                $stmt = $db->prepare("UPDATE payments SET status = 'failed', message = CONCAT(message, ' | error: ', ?) WHERE id = ?");
                $stmt->execute([ $err, $payment_id ]);
                $action_result = 'error|STK push failed: ' . htmlspecialchars($err);
            }
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
