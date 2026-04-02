<?php
require_once 'includes/db_master.php';
require_once 'includes/auth.php';
redirectIfNotLoggedIn();

$user_id = $_SESSION['user_id'];

// Fetch user + tenant + plan
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.account_prefix, u.tenant_id,
           t.company_name, t.subdomain, t.status AS tenant_status,
           p.name AS plan_name
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$tenant_id = $user['tenant_id'] ?? null;

// Fetch tenant settings
$tSettings = [];
if ($tenant_id) {
    $sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $sStmt->execute([$tenant_id]);
    $tSettings = $sStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Quick stats
$totalClients = 0; $totalPackages = 0; $monthlyRevenue = 0.0; $activeGateways = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=?"); $s->execute([$tenant_id]); $totalClients = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE tenant_id=? AND status='active'"); $s->execute([$tenant_id]); $totalPackages = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id=? AND status='completed' AND YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE())"); $s->execute([$tenant_id]); $monthlyRevenue = (float)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM payment_gateways WHERE tenant_id=? AND is_active=1"); $s->execute([$tenant_id]); $activeGateways = (int)$s->fetchColumn();
} catch (Exception $e) {}

$initials = strtoupper(substr($user['username'] ?? 'U', 0, 1));
$brandColor = $tSettings['brand_color'] ?? '#3B6EA5';
$memberSince = date('F j, Y', strtotime($user['created_at']));
$portalUrl   = 'https://' . ($user['subdomain'] ?? 'your-subdomain') . '.fortunetttech.site';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
.prof-wrap { padding:28px 32px; max-width:1100px; margin:0 auto; }
.prof-hero {
    background: linear-gradient(135deg, var(--primary-dark,#2C5282) 0%, var(--primary-color,#3B6EA5) 100%);
    border-radius: 14px; padding: 28px 32px; display: flex; align-items: center; gap: 24px;
    margin-bottom: 24px; position: relative; overflow: hidden;
}
.prof-hero::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 180px; height: 180px; border-radius: 50%;
    background: rgba(255,255,255,.06);
}
.prof-avatar {
    width: 72px; height: 72px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 700; color: white;
    background: rgba(255,255,255,.2);
    border: 3px solid rgba(255,255,255,.4);
    letter-spacing: .02em;
}
.prof-hero-info { flex: 1; }
.prof-hero-info h2 { font-size: 22px; font-weight: 700; color: #fff; margin: 0 0 4px 0; }
.prof-hero-info p  { font-size: 13px; color: rgba(255,255,255,.75); margin: 0; }
.prof-role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,.18); color: #fff;
    font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    padding: 3px 10px; border-radius: 20px; margin-top: 6px;
}
.prof-stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 16px; margin-bottom: 24px; }
.prof-stat { background: #fff; border: 1px solid #E5E7EB; border-radius: 10px; padding: 18px 20px; }
.prof-stat .val { font-size: 26px; font-weight: 700; color: #111827; }
.prof-stat .lbl { font-size: 12px; color: #6B7280; font-weight: 500; margin-top: 2px; }
.prof-stat .ico { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; float: right; margin-top: -2px; }
.prof-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 768px) { .prof-grid { grid-template-columns: 1fr; } .prof-wrap { padding: 16px; } }
.prof-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; }
.prof-card-head {
    padding: 16px 20px; border-bottom: 1px solid #F3F4F6;
    display: flex; align-items: center; gap: 10px;
}
.prof-card-head h3 { font-size: 14px; font-weight: 700; color: #111827; margin: 0; }
.prof-card-head i  { color: var(--primary-color,#3B6EA5); width: 16px; }
.prof-card-body { padding: 20px; }
.prof-field { margin-bottom: 16px; }
.prof-field:last-child { margin-bottom: 0; }
.prof-label { font-size: 11px; font-weight: 700; color: #9CA3AF; text-transform: uppercase; letter-spacing: .05em; display: block; margin-bottom: 5px; }
.prof-input {
    width: 100%; padding: 9px 12px; border: 1px solid #D1D5DB; border-radius: 8px;
    font-size: 14px; color: #111827; background: #fff; box-sizing: border-box;
    transition: border-color .15s;
}
.prof-input:focus { outline: none; border-color: var(--primary-color,#3B6EA5); box-shadow: 0 0 0 3px rgba(59,110,165,.1); }
.prof-input[readonly] { background: #F9FAFB; color: #6B7280; cursor: default; }
.prof-save-btn {
    padding: 9px 22px; background: linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);
    color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity .2s;
}
.prof-save-btn:hover { opacity: .9; }
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px solid #F9FAFB; font-size: 13px; }
.info-row:last-child { border-bottom: none; }
.info-row .lbl { color: #6B7280; font-weight: 500; }
.info-row .val { color: #111827; font-weight: 600; }
.status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.status-pill.active { background: #D1FAE5; color: #065F46; }
.status-pill.trial  { background: #FEF3C7; color: #92400E; }
.url-box { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; padding: 10px 14px; font-family: monospace; font-size: 12px; color: var(--primary-dark,#2C5282); word-break: break-all; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.copy-btn { background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 2px; transition: color .15s; flex-shrink: 0; }
.copy-btn:hover { color: var(--primary-color,#3B6EA5); }
.danger-zone { margin-top: 20px; padding: 16px; background: #FFF5F5; border: 1px solid #FEE2E2; border-radius: 8px; }
.danger-zone p { font-size: 12px; color: #6B7280; margin: 0 0 10px 0; }
</style>

<div class="main-content-wrapper" style="background:#F3F4F6;">
<div class="prof-wrap">

    <!-- Hero -->
    <div class="prof-hero">
        <div class="prof-avatar"><?php echo $initials; ?></div>
        <div class="prof-hero-info">
            <h2><?php echo htmlspecialchars($user['username']); ?></h2>
            <p><?php echo htmlspecialchars($user['email'] ?? 'No email set'); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($user['company_name'] ?? 'No company'); ?></p>
            <div class="prof-role-badge"><i class="fas fa-shield-alt"></i> <?php echo ucfirst($user['role'] ?? 'admin'); ?></div>
        </div>
        <div style="text-align:right;color:rgba(255,255,255,.7);font-size:12px;">
            <div>Member since</div>
            <div style="color:#fff;font-weight:600;font-size:14px;"><?php echo $memberSince; ?></div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="prof-stats">
        <div class="prof-stat">
            <div class="ico" style="background:#DBEAFE;color:#1E40AF;"><i class="fas fa-users"></i></div>
            <div class="val"><?php echo number_format($totalClients); ?></div>
            <div class="lbl">Total Clients</div>
        </div>
        <div class="prof-stat">
            <div class="ico" style="background:#D1FAE5;color:#065F46;"><i class="fas fa-box"></i></div>
            <div class="val"><?php echo $totalPackages; ?></div>
            <div class="lbl">Active Packages</div>
        </div>
        <div class="prof-stat">
            <div class="ico" style="background:#FEF3C7;color:#92400E;"><i class="fas fa-chart-line"></i></div>
            <div class="val">KSh <?php echo number_format($monthlyRevenue, 0); ?></div>
            <div class="lbl">Revenue This Month</div>
        </div>
        <div class="prof-stat">
            <div class="ico" style="background:#E0E7FF;color:#4338CA;"><i class="fas fa-credit-card"></i></div>
            <div class="val"><?php echo $activeGateways; ?></div>
            <div class="lbl">Active Payment Methods</div>
        </div>
    </div>

    <div class="prof-grid">
        <!-- Left column -->
        <div>
            <!-- Edit Profile -->
            <div class="prof-card" style="margin-bottom:20px;">
                <div class="prof-card-head">
                    <i class="fas fa-user-edit"></i>
                    <h3>Account Information</h3>
                </div>
                <div class="prof-card-body">
                    <div id="profileMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:14px;"></div>
                    <div class="prof-field">
                        <label class="prof-label">Username</label>
                        <input type="text" id="profUsername" class="prof-input" value="<?php echo htmlspecialchars($user['username']); ?>" placeholder="Your login username">
                    </div>
                    <div class="prof-field">
                        <label class="prof-label">Email Address</label>
                        <input type="email" id="profEmail" class="prof-input" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="your@email.com">
                    </div>
                    <div class="prof-field">
                        <label class="prof-label">Role</label>
                        <input type="text" class="prof-input" value="<?php echo ucfirst($user['role'] ?? 'admin'); ?>" readonly>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:4px;">
                        <button class="prof-save-btn" onclick="saveProfile()">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="prof-card">
                <div class="prof-card-head">
                    <i class="fas fa-lock"></i>
                    <h3>Change Password</h3>
                </div>
                <div class="prof-card-body">
                    <div id="pwdMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:14px;"></div>
                    <div class="prof-field">
                        <label class="prof-label">Current Password</label>
                        <input type="password" id="pwdCurrent" class="prof-input" placeholder="Enter your current password">
                    </div>
                    <div class="prof-field">
                        <label class="prof-label">New Password</label>
                        <input type="password" id="pwdNew" class="prof-input" placeholder="At least 8 characters">
                    </div>
                    <div class="prof-field">
                        <label class="prof-label">Confirm New Password</label>
                        <input type="password" id="pwdConfirm" class="prof-input" placeholder="Repeat new password">
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:4px;">
                        <button class="prof-save-btn" onclick="changePassword()">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div>
            <!-- Account Details -->
            <div class="prof-card" style="margin-bottom:20px;">
                <div class="prof-card-head">
                    <i class="fas fa-building"></i>
                    <h3>Tenant Details</h3>
                </div>
                <div class="prof-card-body">
                    <div class="info-row">
                        <span class="lbl">Company</span>
                        <span class="val"><?php echo htmlspecialchars($user['company_name'] ?? '—'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Subscription Plan</span>
                        <span class="val"><?php echo htmlspecialchars($user['plan_name'] ?? 'Starter'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Account Status</span>
                        <span class="val">
                            <span class="status-pill <?php echo $user['tenant_status'] ?? 'active'; ?>">
                                <i class="fas fa-circle" style="font-size:7px;"></i>
                                <?php echo ucfirst($user['tenant_status'] ?? 'active'); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Account Prefix</span>
                        <span class="val" style="font-family:monospace;"><?php echo htmlspecialchars($user['account_prefix'] ?? strtoupper(substr($user['username'],0,1))); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Member Since</span>
                        <span class="val"><?php echo $memberSince; ?></span>
                    </div>
                    <div style="margin-top:14px;">
                        <div class="prof-label" style="margin-bottom:6px;">Your Portal URL</div>
                        <div class="url-box">
                            <span id="portalUrl"><?php echo htmlspecialchars($portalUrl); ?></span>
                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($portalUrl); ?>')" title="Copy URL">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branding quick-view -->
            <div class="prof-card" style="margin-bottom:20px;">
                <div class="prof-card-head">
                    <i class="fas fa-palette"></i>
                    <h3>Branding</h3>
                    <a href="settings.php" style="margin-left:auto;font-size:12px;color:var(--primary-color,#3B6EA5);text-decoration:none;font-weight:600;">Edit in Settings →</a>
                </div>
                <div class="prof-card-body">
                    <div class="info-row">
                        <span class="lbl">Brand Color</span>
                        <span class="val" style="display:flex;align-items:center;gap:8px;">
                            <span style="width:18px;height:18px;border-radius:4px;background:<?php echo htmlspecialchars($brandColor); ?>;border:1px solid #E5E7EB;display:inline-block;"></span>
                            <?php echo htmlspecialchars($brandColor); ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Support Phone</span>
                        <span class="val"><?php echo htmlspecialchars($tSettings['support_number'] ?? '—'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Support Email</span>
                        <span class="val"><?php echo htmlspecialchars($tSettings['support_email'] ?? '—'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Business Address</span>
                        <span class="val" style="text-align:right;max-width:220px;"><?php echo htmlspecialchars($tSettings['business_address'] ?? '—'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="prof-card">
                <div class="prof-card-head">
                    <i class="fas fa-link"></i>
                    <h3>Quick Links</h3>
                </div>
                <div class="prof-card-body" style="display:flex;flex-direction:column;gap:8px;">
                    <a href="settings.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;text-decoration:none;color:#111827;font-size:13px;font-weight:500;transition:background .15s;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='#F9FAFB'">
                        <i class="fas fa-cog" style="color:var(--primary-color,#3B6EA5);width:14px;"></i> System Settings
                    </a>
                    <a href="settings.php#payments" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;text-decoration:none;color:#111827;font-size:13px;font-weight:500;transition:background .15s;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='#F9FAFB'">
                        <i class="fas fa-mobile-alt" style="color:#059669;width:14px;"></i> Payment Gateways
                    </a>
                    <a href="mikrotik.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;text-decoration:none;color:#111827;font-size:13px;font-weight:500;transition:background .15s;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='#F9FAFB'">
                        <i class="fas fa-network-wired" style="color:#4338CA;width:14px;"></i> MikroTik Routers
                    </a>
                    <a href="<?php echo htmlspecialchars($portalUrl . '/customer/login.php'); ?>" target="_blank" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;text-decoration:none;color:#111827;font-size:13px;font-weight:500;transition:background .15s;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='#F9FAFB'">
                        <i class="fas fa-external-link-alt" style="color:#D97706;width:14px;"></i> Customer Portal <i class="fas fa-external-link-alt" style="font-size:10px;margin-left:auto;color:#9CA3AF;"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<script>
function showMsg(elId, msg, type) {
    const el = document.getElementById(elId);
    el.textContent = msg;
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#D1FAE5' : '#FEE2E2';
    el.style.color       = type === 'success' ? '#065F46' : '#991B1B';
    el.style.border      = '1px solid ' + (type === 'success' ? '#6EE7B7' : '#FCA5A5');
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function saveProfile() {
    const username = document.getElementById('profUsername').value.trim();
    const email    = document.getElementById('profEmail').value.trim();
    if (!username) { showMsg('profileMsg','Username cannot be empty','error'); return; }

    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action','update_info');
    fd.append('username', username);
    fd.append('email', email);

    fetch('api/profile/update.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            showMsg('profileMsg', d.message, d.success ? 'success' : 'error');
            if (d.success) {
                // Update displayed name in hero
                document.querySelector('.prof-hero-info h2').textContent = username;
            }
        })
        .catch(() => showMsg('profileMsg','Network error. Please try again.','error'))
        .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
}

function changePassword() {
    const current = document.getElementById('pwdCurrent').value;
    const newPwd  = document.getElementById('pwdNew').value;
    const confirm = document.getElementById('pwdConfirm').value;
    if (!current || !newPwd) { showMsg('pwdMsg','Fill in all password fields','error'); return; }
    if (newPwd !== confirm)  { showMsg('pwdMsg','New passwords do not match','error'); return; }
    if (newPwd.length < 8)   { showMsg('pwdMsg','Password must be at least 8 characters','error'); return; }

    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action','change_password');
    fd.append('current_password', current);
    fd.append('new_password', newPwd);
    fd.append('confirm_password', confirm);

    fetch('api/profile/update.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            showMsg('pwdMsg', d.message, d.success ? 'success' : 'error');
            if (d.success) {
                document.getElementById('pwdCurrent').value = '';
                document.getElementById('pwdNew').value = '';
                document.getElementById('pwdConfirm').value = '';
            }
        })
        .catch(() => showMsg('pwdMsg','Network error.','error'))
        .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard', 'success');
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Copied!', 'success');
    });
}
</script>

<?php include 'includes/footer.php'; ?>
