                        <!-- Add Form -->
                        <div class="card bg-light border-0 hover-card mb-5">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0" id="gatewayFormTitle">Add New Payment Gateway</h5>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetGatewayForm()">Reset Form</button>
                                </div>
                                <form method="POST" id="gatewayForm">
                                    <input type="hidden" name="action" value="save_gateway">
                                    <input type="hidden" name="gateway_id" id="gateway_id">

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Gateway Name</label>
                                            <input type="text" name="gateway_name" id="gateway_name" placeholder="e.g. Main M-Pesa" class="form-control" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Gateway Type</label>
                                            <select name="gateway_type" id="gateway_type" class="form-select" onchange="toggleGatewayFields()">
                                                <option value="">-- Select Type --</option>
                                                <option value="paybill_no_api">Paybill - Without API keys</option>
                                                <option value="mpesa_api">M-Pesa Paybill / Till (With API)</option>
                                                <option value="bank_account">Bank Account</option>
                                                <option value="paypal">PayPal</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Dynamic Fields -->
                                    <div id="fields_paybill_no_api" class="gateway-fields d-none">
                                         <div class="mb-3">
                                            <label class="form-label">Paybill / Till Number</label>
                                            <input type="text" name="paybill_number" id="paybill_number" class="form-control">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Account Number (Optional/Instruction)</label>
                                            <input type="text" name="account_number" id="account_number" class="form-control" placeholder="e.g. Enter your automated Account ID">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Payment Instructions</label>
                                            <textarea name="instructions" id="instructions" class="form-control" placeholder="e.g. Go to M-Pesa > Lipa na M-Pesa..."></textarea>
                                        </div>
                                    </div>

                                    <div id="fields_mpesa_api" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Consumer Key</label><input type="text" name="mpesa_consumer_key" id="mpesa_consumer_key" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Consumer Secret</label><input type="password" name="mpesa_consumer_secret" id="mpesa_consumer_secret" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Passkey</label><input type="password" name="mpesa_passkey" id="mpesa_passkey" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Shortcode</label><input type="text" name="mpesa_shortcode" id="mpesa_shortcode" class="form-control"></div>
                                            <div class="col-md-12">
                                                <label class="form-label">Environment</label>
                                                <select name="mpesa_env" id="mpesa_env" class="form-select">
                                                    <option value="sandbox">Sandbox</option>
                                                    <option value="production">Production</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="fields_bank_account" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" id="bank_name" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Account Name</label><input type="text" name="bank_account_name" id="bank_account_name" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Account Number</label><input type="text" name="bank_account_number" id="bank_account_number" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Bank Paybill</label><input type="text" name="bank_paybill" id="bank_paybill" class="form-control"></div>
                                        </div>
                                    </div>
                                    
                                    <div id="fields_paypal" class="gateway-fields d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6"><label class="form-label">Client ID</label><input type="text" name="paypal_client_id" id="paypal_client_id" class="form-control"></div>
                                            <div class="col-md-6"><label class="form-label">Client Secret</label><input type="password" name="paypal_client_secret" id="paypal_client_secret" class="form-control"></div>
                                            <div class="col-md-12">
                                                <label class="form-label">Mode</label>
                                                <select name="paypal_mode" id="paypal_mode" class="form-select">
                                                    <option value="sandbox">Sandbox</option>
                                                    <option value="live">Live</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                                        <label class="form-check-label" for="is_active">Enable this gateway</label>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Save Payment Gateway</button>
                                </form>
                            </div>
                        </div>

                        <h5 class="fw-bold mb-3">Configured Payment Methods</h5>
                        <p class="text-muted small mb-4">These payment methods will be displayed to your customers during checkout.</p>
                        
                        <?php if (empty($gateways)): ?>
                        <div class="alert alert-info border-0 mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            No payment gateways configured yet.
                        </div>
                        <?php else: ?>
                        <!-- Gateway Cards -->
                        <div class="row g-3 mb-4">
                            <?php foreach ($gateways as $g): ?>
                                <?php $creds = json_decode($g['credentials'], true); ?>
                                <div class="col-md-6">
                                    <div class="card border shadow-sm h-100 hover-card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($g['gateway_name']); ?></h6>
                                                    <span class="badge bg-primary text-uppercase small"><?php echo str_replace('_', ' ', $g['gateway_type']); ?></span>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" <?php echo $g['is_active'] ? 'checked' : ''; ?> disabled>
                                                </div>
                                            </div>
                                            
                                            <?php if ($g['gateway_type'] == 'paybill_no_api'): ?>
                                                <div class="bg-light p-2 rounded mb-3">
                                                    <small class="text-muted d-block">Paybill Number</small>
                                                    <strong><?php echo htmlspecialchars($creds['paybill_number'] ?? '-'); ?></strong>
                                                </div>
                                            <?php elseif ($g['gateway_type'] == 'bank_account'): ?>
                                                <div class="bg-light p-2 rounded mb-3">
                                                    <small class="text-muted d-block">Bank</small>
                                                    <strong><?php echo htmlspecialchars($creds['bank_name'] ?? '-'); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-primary" onclick='editGateway(<?php echo json_encode($g); ?>)'>
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this payment gateway? This action cannot be undone.');" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_gateway">
                                                    <input type="hidden" name="gateway_id" value="<?php echo $g['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
