<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

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

include 'includes/header.php';
?>

<div class="payment-container">
    <div class="page-header">
        <h1><i class="fas fa-credit-card"></i> Make Payment</h1>
        <p>Complete your payment to activate or renew your subscription</p>
    </div>
    
    <div class="payment-layout">
        <!-- Payment Summary -->
        <div class="payment-summary">
            <h2>Payment Summary</h2>
            
            <?php if ($package): ?>
            <div class="summary-item package-info">
                <div class="package-details">
                    <div class="package-icon-small">
                        <i class="fas fa-wifi"></i>
                    </div>
                    <div>
                        <div class="package-name-small"><?php echo htmlspecialchars($package['name']); ?></div>
                        <div class="package-speed"><?php echo $package['download_speed']; ?>/<?php echo $package['upload_speed']; ?> Mbps</div>
                    </div>
                </div>
                <div class="package-price-small"><?php echo formatCurrency($packagePrice); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="summary-breakdown">
                <div class="summary-row">
                    <span>Package Price</span>
                    <span><?php echo formatCurrency($packagePrice); ?></span>
                </div>
                <div class="summary-row">
                    <span>Account Balance</span>
                    <span class="balance-amount">-<?php echo formatCurrency($accountBalance); ?></span>
                </div>
                <div class="summary-row total">
                    <span>Amount to Pay</span>
                    <span><?php echo formatCurrency($amountToPay); ?></span>
                </div>
            </div>
            
            <?php if ($amountToPay <= 0): ?>
            <div class="payment-notice success">
                <i class="fas fa-check-circle"></i>
                <span>Your balance covers this package. Click activate to proceed.</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Payment Methods -->
        <div class="payment-methods">
            <h2>Payment Method</h2>
            
            <div class="payment-method-card mpesa">
                <div class="method-header">
                    <div class="method-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="method-info">
                        <h3>M-Pesa</h3>
                        <p>Pay via M-Pesa STK Push</p>
                    </div>
                </div>
                
                <form id="mpesaForm" onsubmit="handleMpesaPayment(event)">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" id="phoneNumber" class="form-control" 
                               placeholder="0712345678" 
                               value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" 
                               required>
                        <small>Enter the M-Pesa registered phone number</small>
                    </div>
                    
                    <button type="submit" id="payButton" class="btn-pay">
                        <?php if ($amountToPay <= 0): ?>
                        <i class="fas fa-bolt"></i> Get Connected
                        <?php else: ?>
                        <i class="fas fa-paper-plane"></i> Pay <?php echo formatCurrency($amountToPay); ?>
                        <?php endif; ?>
                    </button>
                </form>
            </div>
            
            <div class="payment-info-cards">
                <div class="info-card">
                    <div class="info-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Paybill Number</div>
                        <div class="info-value">174379</div>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="info-content">
                        <div class="info-label">Till Number</div>
                        <div class="info-value">7198572</div>
                    </div>
                </div>
            </div>
            
            <div class="payment-instructions">
                <h3><i class="fas fa-info-circle"></i> Manual Payment Instructions</h3>
                <ol>
                    <li>Go to M-Pesa menu on your phone</li>
                    <li>Select Lipa na M-Pesa</li>
                    <li>Choose Pay Bill or Buy Goods</li>
                    <li>Enter the number above</li>
                    <li>Enter amount: <strong><?php echo formatCurrency($amountToPay); ?></strong></li>
                    <li>Use account number: <strong><?php echo htmlspecialchars($customer['account_number']); ?></strong></li>
                    <li>Complete the transaction</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Payment Processing Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-body text-center">
            <div class="payment-processing">
                <div class="spinner-large"></div>
                <h3>Processing Payment</h3>
                <p>Please check your phone and enter your M-Pesa PIN</p>
                <div class="payment-status" id="paymentStatus">Waiting for confirmation...</div>
            </div>
        </div>
    </div>
</div>

<style>
.payment-container {
    max-width: 1200px;
    margin: 0 auto;
}

.payment-layout {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: 32px;
}

.payment-summary, .payment-methods {
    background: white;
    border-radius: 16px;
    padding: 32px;
    border: 1px solid var(--gray-200);
}

.payment-summary h2, .payment-methods h2 {
    font-size: 20px;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 24px;
}

.summary-item.package-info {
    background: var(--gray-50);
    padding: 20px;
    border-radius: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.package-details {
    display: flex;
    gap: 16px;
    align-items: center;
}

.package-icon-small {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.package-name-small {
    font-size: 16px;
    font-weight: 600;
    color: var(--gray-900);
}

.package-speed {
    font-size: 13px;
    color: var(--gray-500);
}

.package-price-small {
    font-size: 20px;
    font-weight: 700;
    color: var(--primary);
}

.summary-breakdown {
    border-top: 1px solid var(--gray-200);
    padding-top: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    font-size: 14px;
    color: var(--gray-700);
}

.summary-row.total {
    border-top: 2px solid var(--gray-200);
    margin-top: 12px;
    padding-top: 16px;
    font-size: 18px;
    font-weight: 700;
    color: var(--gray-900);
}

.balance-amount {
    color: var(--success);
}

.payment-notice {
    margin-top: 20px;
    padding: 16px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
}

.payment-notice.success {
    background: #D1FAE5;
    color: #065F46;
}

.payment-method-card {
    border: 2px solid var(--gray-200);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.payment-method-card.mpesa {
    border-color: #10B981;
    background: linear-gradient(to bottom, rgba(16,185,129,0.05) 0%, white 100%);
}

.method-header {
    display: flex;
    gap: 16px;
    align-items: center;
    margin-bottom: 24px;
}

.method-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: #10B981;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.method-info h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 4px;
}

.method-info p {
    font-size: 13px;
    color: var(--gray-500);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 8px;
}

.form-group small {
    display: block;
    font-size: 12px;
    color: var(--gray-500);
    margin-top: 6px;
}

.btn-pay {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-pay:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16,185,129,0.3);
}

.btn-pay:disabled {
    background: var(--gray-300);
    cursor: not-allowed;
}

.payment-info-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--gray-50);
    padding: 16px;
    border-radius: 10px;
    display: flex;
    gap: 12px;
    align-items: center;
}

.info-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: white;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.info-label {
    font-size: 11px;
    color: var(--gray-500);
    margin-bottom: 4px;
}

.info-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--gray-900);
}

.payment-instructions {
    background: #EFF6FF;
    padding: 20px;
    border-radius: 10px;
}

.payment-instructions h3 {
    font-size: 14px;
    font-weight: 600;
    color: #1E40AF;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-instructions ol {
    margin-left: 20px;
    font-size: 13px;
    color: var(--gray-700);
    line-height: 1.8;
}

.payment-processing {
    padding: 40px 20px;
}

.spinner-large {
    width: 60px;
    height: 60px;
    border: 4px solid var(--gray-200);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 24px;
}

.payment-processing h3 {
    font-size: 20px;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 8px;
}

.payment-processing p {
    font-size: 14px;
    color: var(--gray-500);
    margin-bottom: 20px;
}

.payment-status {
    font-size: 13px;
    color: var(--gray-600);
    padding: 12px;
    background: var(--gray-50);
    border-radius: 8px;
}

@media (max-width: 968px) {
    .payment-layout {
        grid-template-columns: 1fr;
    }
    
    .payment-info-cards {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
const API_BASE = '/fortunett_technologies_/api/customer';

async function handleMpesaPayment(e) {
    e.preventDefault();
    
    const phoneNumber = document.getElementById('phoneNumber').value;
    const packageId = <?php echo $selectedPackageId ?? 'null'; ?>;
    const amount = <?php echo $amountToPay; ?>;
    
    if (amount <= 0) {
        // Handle free activation (either free package or covered by balance)
        const payBtn = document.getElementById('payButton');
        const originalText = payBtn.innerHTML;
        
        try {
            payBtn.disabled = true;
            payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activating...';
            
            // Determine if it's a free package or balance payment
            const isFreePackage = <?php echo ($package['price'] <= 0) ? 'true' : 'false'; ?>;
            const endpoint = isFreePackage ? 'activate_free_package.php' : 'activate_with_balance.php';
            
            const formData = new FormData();
            formData.append('package_id', packageId);
            
            // Note: activate_with_balance.php logic needs to be implemented if not exists
            // For now, we only use activate_free_package.php for truly free packages
            if (!isFreePackage) {
                alert('Balance activation not yet implemented. Please contact support.');
                payBtn.disabled = false;
                payBtn.innerHTML = originalText;
                return;
            }
            
            const response = await fetch(API_BASE + '/' + endpoint, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                window.location.href = 'dashboard.php?activated=1';
            } else {
                alert('Activation failed: ' + (data.message || 'Unknown error'));
                payBtn.disabled = false;
                payBtn.innerHTML = originalText;
            }
        } catch (error) {
            alert('Connection error. Please try again.');
            payBtn.disabled = false;
            payBtn.innerHTML = originalText;
        }
        return;
    }
    
    // Show processing modal
    document.getElementById('paymentModal').classList.add('show');
    
    try {
        const formData = new FormData();
        formData.append('phone', phoneNumber);
        formData.append('package_id', packageId);
        formData.append('amount', amount);
        
        const response = await fetch(API_BASE + '/initiate_payment.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('paymentStatus').textContent = 'Payment request sent! Check your phone...';
            
            // Poll for payment status
            pollPaymentStatus(data.checkout_request_id);
        } else {
            document.getElementById('paymentModal').classList.remove('show');
            alert('Payment initiation failed: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        document.getElementById('paymentModal').classList.remove('show');
        alert('Connection error. Please try again.');
    }
}

function pollPaymentStatus(checkoutRequestId) {
    let attempts = 0;
    const maxAttempts = 30; // 30 seconds
    
    const interval = setInterval(async () => {
        attempts++;
        
        if (attempts > maxAttempts) {
            clearInterval(interval);
            document.getElementById('paymentStatus').textContent = 'Payment timeout. Please check your M-Pesa messages.';
            setTimeout(() => {
                document.getElementById('paymentModal').classList.remove('show');
            }, 3000);
            return;
        }
        
        try {
            const response = await fetch(API_BASE + '/payment_status.php?checkout_request_id=' + checkoutRequestId);
            const data = await response.json();
            
            if (data.status === 'completed') {
                clearInterval(interval);
                document.getElementById('paymentStatus').innerHTML = '<i class="fas fa-check-circle" style="color: #10B981; font-size: 48px; margin-bottom: 16px;"></i><br>Payment successful!';
                
                setTimeout(() => {
                    window.location.href = 'dashboard.php?payment_success=1';
                }, 2000);
            } else if (data.status === 'failed') {
                clearInterval(interval);
                document.getElementById('paymentStatus').textContent = 'Payment failed: ' + (data.message || 'Unknown error');
                setTimeout(() => {
                    document.getElementById('paymentModal').classList.remove('show');
                }, 3000);
            }
        } catch (error) {
            // Continue polling
        }
    }, 1000);
}
</script>

<?php include 'includes/footer.php'; ?>
