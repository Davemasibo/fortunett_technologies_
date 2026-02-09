<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

// Get tenant context properly
$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE username = ?");
$stmt->execute([$customer['username']]);
$tenant_id = $stmt->fetchColumn();

if (!$tenant_id) {
    // Fallback if not set 
    $tenant_id = 1; 
}

// Get package details if package_id in URL
$selectedPackageId = $_GET['package_id'] ?? $customer['package_id'];
$package = null;

if ($selectedPackageId) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$selectedPackageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate amount to pay
$packagePrice = $package ? $package['price'] : 0;
$accountBalance = $customer['account_balance'] ?? 0;
$amountToPay = max(0, $packagePrice - $accountBalance);

// Fetch Payment Gateways for this tenant
$gatewaysStmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? AND is_active = 1");
$gatewaysStmt->execute([$tenant_id]);
$gateways = $gatewaysStmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="payment-container py-4">
    <div class="page-header mb-4 bg-primary text-white p-4 rounded shadow-sm">
        <h1 class="h3 mb-1"><i class="fas fa-credit-card me-2"></i> Make Payment</h1>
        <p class="mb-0">Complete your payment to activate or renew your subscription</p>
    </div>
    
    <div class="row g-4">
        <!-- Payment Summary -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4">Payment Summary</h5>
                    
                    <?php if ($package): ?>
                    <div class="d-flex align-items-center bg-light p-3 rounded mb-3">
                        <div class="bg-primary text-white p-3 rounded me-3">
                            <i class="fas fa-wifi fa-lg"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?php echo htmlspecialchars($package['name']); ?></div>
                            <small class="text-muted"><?php echo $package['download_speed']; ?> Mbps</small>
                        </div>
                        <div class="h5 mb-0 text-primary fw-bold">KES <?php echo number_format($packagePrice, 2); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="border-top pt-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Package Price</span>
                            <span>KES <?php echo number_format($packagePrice, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Account Balance</span>
                            <span class="text-success">- KES <?php echo number_format($accountBalance, 2); ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between h5 fw-bold text-dark">
                            <span>Amount to Pay</span>
                            <span>KES <?php echo number_format($amountToPay, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($amountToPay <= 0): ?>
            <div class="alert alert-success d-flex align-items-center">
                <i class="fas fa-check-circle me-2"></i>
                <span>Your balance covers this package.</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Payment Methods -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4">Choose Payment Method</h5>
                    
                    <div class="accordion" id="paymentAccordion">
                        <?php 
                        $has_api = false;
                        foreach ($gateways as $idx => $g): 
                            $creds = json_decode($g['credentials'], true);
                            $isActive = $idx === 0 ? 'show' : '';
                            if ($g['gateway_type'] === 'mpesa_api') $has_api = true;
                        ?>
                        <div class="accordion-item mb-3 border rounded shadow-sm overflow-hidden">
                            <h2 class="accordion-header">
                                <button class="accordion-button <?php echo $idx === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#method-<?php echo $g['id']; ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box me-3 bg-<?php echo ($g['gateway_type'] == 'mpesa_api' || $g['gateway_type'] == 'paybill_no_api') ? 'success' : 'primary'; ?> text-white p-2 rounded">
                                            <i class="fas <?php 
                                                echo $g['gateway_type'] == 'bank_account' ? 'fa-university' : 
                                                    ($g['gateway_type'] == 'paypal' ? 'fa-paypal' : 'fa-mobile-alt'); 
                                            ?>"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($g['gateway_name']); ?></div>
                                            <small class="text-muted opacity-75"><?php echo ucfirst(str_replace('_', ' ', $g['gateway_type'])); ?></small>
                                        </div>
                                    </div>
                                </button>
                            </h2>
                            <div id="method-<?php echo $g['id']; ?>" class="accordion-collapse collapse <?php echo $isActive; ?>" data-bs-parent="#paymentAccordion">
                                <div class="accordion-body bg-light">
                                    <?php if ($g['gateway_type'] == 'mpesa_api'): ?>
                                        <p class="small text-muted mb-3">Pay instantly via M-Pesa STK Push. Enter your phone below.</p>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                            <input type="tel" id="stk_phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone']); ?>" placeholder="07xxxxxxxx">
                                            <button class="btn btn-success" onclick="initiateSTK(<?php echo $g['id']; ?>, <?php echo $amountToPay; ?>)">Pay Now</button>
                                        </div>
                                    <?php elseif ($g['gateway_type'] == 'paybill_no_api'): ?>
                                        <div class="bg-white p-3 rounded border">
                                            <div class="mb-2"><span class="text-muted small">Paybill Number:</span> <strong class="fs-5"><?php echo htmlspecialchars($creds['paybill_number']); ?></strong></div>
                                            <div class="mb-2"><span class="text-muted small">Account Name/No:</span> <strong><?php echo htmlspecialchars($creds['account_number'] ?: $customer['account_number']); ?></strong></div>
                                            <div class="alert alert-warning py-2 px-3 small mt-2">
                                                <i class="fas fa-info-circle me-1"></i> <?php echo htmlspecialchars($creds['instructions'] ?: 'Pay via M-Pesa then submit the code below.'); ?>
                                            </div>
                                        </div>
                                    <?php elseif ($g['gateway_type'] == 'bank_account'): ?>
                                        <div class="bg-white p-3 rounded border">
                                            <div class="mb-1"><strong><?php echo htmlspecialchars($creds['bank_name']); ?></strong></div>
                                            <div class="mb-1"><span class="text-muted small">Acc Name:</span> <?php echo htmlspecialchars($creds['account_name']); ?></div>
                                            <div class="mb-1"><span class="text-muted small">Acc No:</span> <strong class="fs-5"><?php echo htmlspecialchars($creds['account_number']); ?></strong></div>
                                            <?php if (!empty($creds['paybill_number'])): ?>
                                                <div class="mt-2"><span class="text-muted small">Bank Paybill:</span> <strong><?php echo htmlspecialchars($creds['paybill_number']); ?></strong></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($gateways)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                            <p class="text-muted">No payment methods configured. Please contact support.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Manual Confirmation Form (Visible if there are manual gateways) -->
                    <div class="mt-4 p-3 bg-secondary bg-opacity-10 rounded border-dashed border-2">
                        <h6 class="fw-bold mb-3"><i class="fas fa-check-double me-2"></i>Already Paid? Confirm Transaction</h6>
                        <p class="small text-muted">If you paid via Paybill or Bank, enter your Transaction Code here (e.g., RJH123456).</p>
                        <form id="verifyPaymentForm">
                            <div class="input-group">
                                <input type="text" id="trans_code" class="form-control" placeholder="M-Pesa / Bank Ref Code" required>
                                <button type="submit" class="btn btn-dark">Confirm Payment</button>
                            </div>
                        </form>
                    </div>

                    <button type="button" class="btn btn-lg btn-outline-primary w-100 mt-4 <?php echo ($amountToPay > 0) ? 'd-none' : ''; ?>" id="activateBtn" onclick="activateWithBalance()">
                        <i class="fas fa-bolt me-2"></i> Activate Now
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- STK Loading Modal -->
<div class="modal fade" id="paymentModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <div class="spinner-border text-success mx-auto mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
            <h5 class="fw-bold">Processing...</h5>
            <p id="paymentStatus" class="text-muted mb-0">Please check your phone for M-Pesa PIN prompt.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const statusModal = new bootstrap.Modal(document.getElementById('paymentModal'));

function initiateSTK(gatewayId, amount) {
    const phone = document.getElementById('stk_phone').value;
    if (!phone) return alert('Enter phone number');
    
    statusModal.show();
    document.getElementById('paymentStatus').textContent = 'Initiating STK Push...';
    
    const formData = new FormData();
    formData.append('gateway_id', gatewayId);
    formData.append('phone', phone);
    formData.append('amount', amount);
    
    fetch('api/initiate_stk.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('paymentStatus').textContent = 'Prompt sent! Enter your PIN on your phone.';
            pollStatus(data.checkout_id);
        } else {
            statusModal.hide();
            alert('Error: ' + data.message);
        }
    })
    .catch(e => {
        statusModal.hide();
        alert('Failed to connect to server');
    });
}

function pollStatus(checkoutId) {
    const interval = setInterval(() => {
        fetch('api/check_status.php?checkout_id=' + checkoutId)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'paid') {
                clearInterval(interval);
                document.getElementById('paymentStatus').textContent = 'Payment Received! Activating...';
                setTimeout(() => window.location.href = 'dashboard.php?payment=success', 2000);
            } else if (data.status === 'failed') {
                clearInterval(interval);
                statusModal.hide();
                alert('Payment failed or cancelled.');
            }
        });
    }, 3000);
}

function activateWithBalance() {
    fetch('api/activate.php', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.success) window.location.href = 'dashboard.php?activation=success';
        else alert(data.message);
    });
}

document.getElementById('verifyPaymentForm').onsubmit = function(e) {
    e.preventDefault();
    const code = document.getElementById('trans_code').value;
    const btn = this.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Verifying...';
    
    const formData = new FormData();
    formData.append('code', code);
    
    fetch('api/customer/verify_manual_payment.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Payment code submitted successfully! Your account will be activated once verified by admin.');
            window.location.href = 'dashboard.php';
        } else {
            alert(data.message);
            btn.disabled = false;
            btn.textContent = 'Confirm Payment';
        }
    });
};
</script>

<?php include 'includes/footer.php'; ?>
