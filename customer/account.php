<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

$package = null;
if ($customer['package_id']) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$customer['package_id']]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
}

include 'includes/header.php';
?>

<style>
/* ── Page ──────────────────────────────────────────── */
.acct-page { padding: 28px 32px; max-width: 1100px; }
.acct-page-title { font-size: 26px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px; }
.acct-page-sub   { font-size: 14px; color: rgba(255,255,255,.4); margin: 0 0 28px; }

/* ── Dark card ─────────────────────────────────────── */
.acct-card {
    background: #222221;
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03);
    overflow: hidden;
    margin-bottom: 22px;
}
.acct-card-head {
    padding: 16px 22px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 15px;
    font-weight: 600;
    color: #e2e2e0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.acct-card-head i { color: rgba(255,255,255,.4); font-size: 14px; }
.acct-card-body  { padding: 6px 0; }

/* ── Info rows ─────────────────────────────────────── */
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 13px 22px;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.info-row:last-child { border-bottom: none; }
.info-label {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,.35);
    text-transform: uppercase;
    letter-spacing: .04em;
    flex-shrink: 0;
}
.info-value {
    font-size: 14px;
    font-weight: 500;
    color: #e2e2e0;
    text-align: right;
}
.mono {
    font-family: 'JetBrains Mono', 'Fira Mono', monospace;
    background: rgba(255,255,255,.06);
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 13px;
    letter-spacing: .03em;
}

/* ── Status badges ─────────────────────────────────── */
.sb { padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.sb.active    { background: rgba(16,185,129,.15);  color: #6ee7b7; border: 1px solid rgba(16,185,129,.3); }
.sb.inactive  { background: rgba(107,114,128,.12); color: rgba(255,255,255,.45); border: 1px solid rgba(107,114,128,.25); }
.sb.suspended { background: rgba(251,191,36,.12);  color: #fcd34d; border: 1px solid rgba(251,191,36,.25); }
.sb.expired   { background: rgba(239,68,68,.12);   color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
.text-ok  { color: #6ee7b7; }
.text-bad { color: #fca5a5; }

/* ── Two-column grid ───────────────────────────────── */
.acct-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-bottom: 22px;
}
@media(max-width:760px){ .acct-grid{ grid-template-columns:1fr; } }

/* ── Balance strip ─────────────────────────────────── */
.balance-strip {
    background: linear-gradient(135deg, #1a3a5c 0%, #0f2a42 60%, #0a1f30 100%);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    padding: 24px 28px;
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
    box-shadow: 0 8px 32px rgba(0,0,0,.35);
}
.bal-icon {
    width: 56px; height: 56px;
    background: rgba(255,255,255,.12);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    color: #93c5fd;
    flex-shrink: 0;
}
.bal-info { flex: 1; }
.bal-label  { font-size: 12px; color: rgba(255,255,255,.45); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
.bal-amount { font-size: 30px; font-weight: 800; color: #fff; }
.bal-topup {
    padding: 11px 22px;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: all .2s;
    white-space: nowrap;
}
.bal-topup:hover { background: rgba(255,255,255,.22); transform: translateY(-1px); color: #fff; }
@media(max-width:560px){ .balance-strip{ flex-direction:column; text-align:center; } }

/* ── Empty state ───────────────────────────────────── */
.acct-empty { text-align:center; padding:40px 20px; color:rgba(255,255,255,.25); }
.acct-empty i { font-size:40px; margin-bottom:10px; display:block; }

/* ── Form inputs ───────────────────────────────────── */
.form-body { padding: 22px; }
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
@media(max-width:560px){ .form-grid{ grid-template-columns:1fr; } }
.fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 0; }
.fg label {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,.4);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.fc {
    padding: 10px 14px;
    background: #1a1a19;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    color: #e2e2e0;
    font-size: 14px;
    font-family: inherit;
    box-shadow: inset 3px 3px 7px rgba(0,0,0,.35), inset -1px -1px 3px rgba(255,255,255,.03);
    transition: border-color .18s, box-shadow .18s;
}
.fc::placeholder { color: rgba(255,255,255,.2); }
.fc:focus {
    outline: none;
    border-color: rgba(59,130,246,.5);
    box-shadow: inset 3px 3px 7px rgba(0,0,0,.35), 0 0 0 3px rgba(59,130,246,.15);
}
.form-divider { height: 1px; background: rgba(255,255,255,.06); margin: 18px 0; }
.form-actions { margin-top: 20px; }
.btn-save {
    padding: 11px 26px;
    background: linear-gradient(135deg, var(--primary-dark, #1e3a5f) 0%, var(--primary, #2C5282) 100%);
    border: none;
    border-radius: 9px;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: opacity .2s, transform .15s;
}
.btn-save:hover { opacity: .88; transform: translateY(-1px); }
.btn-save:disabled { opacity: .5; cursor: default; transform: none; }

/* ── Toast fallback (reuse customer portal toast if available) */
#acctToast {
    display: none;
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 13px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    z-index: 9999;
    gap: 10px;
    align-items: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
#acctToast.show { display: flex; }
#acctToast.success { background: rgba(16,185,129,.15); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }
#acctToast.error   { background: rgba(239,68,68,.15);  border: 1px solid rgba(239,68,68,.3);  color: #fca5a5; }
</style>

<div class="acct-page">
    <h1 class="acct-page-title"><i class="fas fa-user-circle" style="color:rgba(255,255,255,.4);margin-right:10px;"></i>My Account</h1>
    <p class="acct-page-sub">Manage your profile, subscription and security settings</p>

    <!-- ── Balance Strip ── -->
    <div class="balance-strip">
        <div class="bal-icon"><i class="fas fa-wallet"></i></div>
        <div class="bal-info">
            <div class="bal-label">Account Balance</div>
            <div class="bal-amount"><?php echo formatCurrency($customer['account_balance'] ?? 0); ?></div>
        </div>
        <a href="payment.php" class="bal-topup"><i class="fas fa-plus"></i> Top Up</a>
    </div>

    <!-- ── Two-column info grid ── -->
    <div class="acct-grid">

        <!-- Account Info -->
        <div class="acct-card">
            <div class="acct-card-head"><i class="fas fa-id-card"></i> Account Information</div>
            <div class="acct-card-body">
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['full_name'] ?? $customer['name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Account No.</div>
                    <div class="info-value"><span class="mono"><?php echo htmlspecialchars($customer['account_number']); ?></span></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['email'] ?: '—'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['phone'] ?: '—'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['address'] ?: '—'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Username</div>
                    <div class="info-value"><span class="mono"><?php echo htmlspecialchars($customer['username'] ?? $customer['mikrotik_username']); ?></span></div>
                </div>
            </div>
        </div>

        <!-- Subscription Details -->
        <div class="acct-card">
            <div class="acct-card-head"><i class="fas fa-box"></i> Subscription</div>
            <div class="acct-card-body">
                <?php if ($package): ?>
                <div class="info-row">
                    <div class="info-label">Package</div>
                    <div class="info-value"><?php echo htmlspecialchars($package['name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Fee</div>
                    <div class="info-value"><?php echo formatCurrency($package['price']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Speed</div>
                    <div class="info-value"><?php echo (int)$package['download_speed']; ?>/<?php echo (int)$package['upload_speed']; ?> Mbps</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Data Limit</div>
                    <div class="info-value"><?php echo $package['data_limit'] > 0 ? number_format($package['data_limit'] / 1073741824, 0) . ' GB' : 'Unlimited'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Expires</div>
                    <div class="info-value <?php echo isSubscriptionActive($customer['expiry_date']) ? 'text-ok' : 'text-bad'; ?>">
                        <?php echo formatDate($customer['expiry_date']); ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="sb <?php echo strtolower($customer['status']); ?>"><?php echo ucfirst($customer['status']); ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="acct-empty"><i class="fas fa-box-open"></i><p>No active package</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Update Profile ── -->
    <div class="acct-card">
        <div class="acct-card-head"><i class="fas fa-edit"></i> Update Profile</div>
        <div class="form-body">
            <form id="updateProfileForm" onsubmit="handleProfileUpdate(event)">
                <div class="form-grid">
                    <div class="fg">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="fc"
                               value="<?php echo htmlspecialchars($customer['full_name'] ?? $customer['name']); ?>" required>
                    </div>
                    <div class="fg">
                        <label>Email Address</label>
                        <input type="email" name="email" class="fc"
                               value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                    </div>
                    <div class="fg">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="fc"
                               value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                    </div>
                    <div class="fg">
                        <label>Address</label>
                        <input type="text" name="address" class="fc"
                               value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-save" id="saveProfBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Change Password ── -->
    <div class="acct-card">
        <div class="acct-card-head"><i class="fas fa-lock"></i> Change Password</div>
        <div class="form-body">
            <form id="changePasswordForm" onsubmit="handlePasswordChange(event)">
                <div class="form-grid">
                    <div class="fg">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="fc" required autocomplete="current-password">
                    </div>
                    <div class="fg" style="grid-column:1/-1;"></div>
                    <div class="fg">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="fc" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="fg">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="fc" required minlength="6" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-save" id="savePwBtn">
                        <i class="fas fa-key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- In-page toast (falls back if customer portal toast unavailable) -->
<div id="acctToast"></div>

<script>
const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
    ? '/fortunett_technologies_' : '';
const API = _base + '/api/customer';

function acctToast(msg, type) {
    if (typeof showCustToast === 'function') { showCustToast(msg, type); return; }
    const el = document.getElementById('acctToast');
    el.className = 'show ' + type;
    el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + msg;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('show'), 3500);
}

async function handleProfileUpdate(e) {
    e.preventDefault();
    const btn = document.getElementById('saveProfBtn');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try {
        const res  = await fetch(API + '/update_profile.php', { method: 'POST', body: new FormData(e.target) });
        const data = await res.json();
        if (data.success) { acctToast('Profile updated successfully!', 'success'); setTimeout(() => location.reload(), 1800); }
        else acctToast(data.message || 'Update failed.', 'error');
    } catch { acctToast('Connection error. Please try again.', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
}

async function handlePasswordChange(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    if (fd.get('new_password') !== fd.get('confirm_password')) {
        acctToast('New passwords do not match.', 'error'); return;
    }
    const btn = document.getElementById('savePwBtn');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';
    try {
        const res  = await fetch(API + '/change_password.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { acctToast('Password updated successfully!', 'success'); e.target.reset(); }
        else acctToast(data.message || 'Update failed.', 'error');
    } catch { acctToast('Connection error. Please try again.', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
}
</script>

<?php include 'includes/footer.php'; ?>
