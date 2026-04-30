<?php
/**
 * Subscription Renewal Page — FortuNett Technologies
 *
 * Accessible by expired customers (no session required).
 * Shows account details, package options, and M-Pesa payment flow.
 * After payment, the M-Pesa callback re-activates the account and provisions the router.
 *
 * For PPPoE customers: shows renewal form; after payment they reconnect their CPE.
 * For hotspot customers: can auto-connect after payment via link-login.
 *
 * MikroTik walled-garden setup (PPPoE expired users):
 *   /ip firewall address-list add address=<this-server-IP> list=walled-garden
 *   /ip firewall filter add chain=forward src-address-list=expired-pppoe dst-address-list=walled-garden action=accept
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';

// ── MikroTik hotspot params (if reached via captive portal redirect) ───────────
$linkLogin  = $_GET['link-login']  ?? '';
$linkOrig   = $_GET['link-orig']   ?? '';
$macAddress = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_GET['mac'] ?? ''));

// ── Resolve tenant from subdomain ─────────────────────────────────────────────
$host      = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = explode('.', $host)[0];
$tenantId  = null;
$branding  = [
    'name'     => 'FortuNett Technologies',
    'color'    => '#0f3460',
    'gradient' => 'linear-gradient(135deg,#1a1a2e 0%,#0f3460 100%)',
    'logo'     => '',
];

if (!in_array($subdomain, ['localhost', 'www']) && !filter_var($host, FILTER_VALIDATE_IP)) {
    try {
        $tSt = $pdo->prepare("SELECT t.id, t.company_name, ts.setting_key, ts.setting_value
            FROM tenants t LEFT JOIN tenant_settings ts ON ts.tenant_id = t.id
            WHERE t.subdomain = ? LIMIT 30");
        $tSt->execute([$subdomain]);
        $rows = $tSt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $tenantId         = (int)$rows[0]['id'];
            $branding['name'] = $rows[0]['company_name'];
            foreach ($rows as $r) {
                if ($r['setting_key'] === 'brand_color' && $r['setting_value']) {
                    $branding['color']    = $r['setting_value'];
                    $branding['gradient'] = "linear-gradient(135deg,{$r['setting_value']} 0%,{$r['setting_value']}99 100%)";
                }
                if ($r['setting_key'] === 'system_logo' && $r['setting_value']) {
                    $branding['logo'] = $r['setting_value'];
                }
            }
        }
    } catch (Exception $_e) {}
}

// ── Find the customer ─────────────────────────────────────────────────────────
$client  = null;
$package = null;
$lookupError = '';

// Priority: customer portal session → URL param → POST lookup
if (!empty($_SESSION['customer_token']) && $tenantId) {
    try {
        $auth   = new CustomerAuth($pdo);
        $sessData = $auth->validateSession($_SESSION['customer_token']);
        if ($sessData['valid']) {
            $clSt = $pdo->prepare("SELECT c.*, p.name AS pkg_name, p.price AS pkg_price, p.download_speed, p.upload_speed, p.validity_value, p.validity_unit FROM clients c LEFT JOIN packages p ON p.id = c.package_id WHERE c.id = ? AND c.tenant_id = ? LIMIT 1");
            $clSt->execute([$sessData['client_id'], $tenantId]);
            $client = $clSt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Exception $_e) {}
}

$lookupValue = trim($_GET['account'] ?? $_POST['account'] ?? '');

if (!$client && $lookupValue && $tenantId) {
    try {
        $clSt = $pdo->prepare("SELECT c.*, p.name AS pkg_name, p.price AS pkg_price, p.download_speed, p.upload_speed, p.validity_value, p.validity_unit FROM clients c LEFT JOIN packages p ON p.id = c.package_id WHERE c.tenant_id = ? AND (c.account_number = ? OR c.phone = ? OR c.username = ?) LIMIT 1");
        $clSt->execute([$tenantId, $lookupValue, $lookupValue, $lookupValue]);
        $client = $clSt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$client) $lookupError = 'Account not found. Check your account number or phone.';
    } catch (Exception $_e) { $lookupError = 'Lookup failed. Please try again.'; }
}

if (!$client && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup'])) {
    $lookupError = $lookupError ?: 'Please enter your account number or phone.';
}

// ── Fetch available packages for renewal ───────────────────────────────────────
$packages = [];
if ($tenantId) {
    try {
        $connType = $client['connection_type'] ?? 'hotspot';
        $pkgSt = $pdo->prepare("SELECT id, name, price, download_speed, upload_speed, validity_value, validity_unit, connection_type FROM packages WHERE tenant_id = ? AND status = 'active' AND COALESCE(NULLIF(connection_type,''), 'hotspot') = ? ORDER BY price ASC, name ASC");
        $pkgSt->execute([$tenantId, $connType === 'pppoe' ? 'pppoe' : 'hotspot']);
        $packages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($packages)) {
            // Fallback: all active packages
            $pkgSt2 = $pdo->prepare("SELECT id, name, price, download_speed, upload_speed, validity_value, validity_unit FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY price ASC LIMIT 12");
            $pkgSt2->execute([$tenantId]);
            $packages = $pkgSt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $_e) {}
}

// ── M-Pesa paybill info (for manual payment fallback) ─────────────────────────
$paybill = '';
$accountFormat = '';
if ($tenantId) {
    try {
        $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
        $gwSt->execute([$tenantId]);
        $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
        if ($gwRow) {
            $gwCreds = json_decode($gwRow['credentials'], true) ?? [];
            $paybill = $gwCreds['shortcode'] ?? '';
        }
    } catch (Exception $_e) {}
}

// ── Status helpers ────────────────────────────────────────────────────────────
$isExpired = $client && (
    $client['status'] === 'inactive' ||
    (!empty($client['expiry_date']) && strtotime($client['expiry_date']) < time())
);
$daysLeft  = 0;
$expiryLabel = 'N/A';
if ($client && !empty($client['expiry_date'])) {
    $diff = strtotime($client['expiry_date']) - time();
    $daysLeft = max(0, (int)floor($diff / 86400));
    $expiryLabel = date('d M Y, H:i', strtotime($client['expiry_date']));
}

// ── Brand CSS vars ────────────────────────────────────────────────────────────
$hex = ltrim($branding['color'], '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Renew Subscription — <?php echo htmlspecialchars($branding['name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../css/auth.css?v=4">
<style>
:root{
    --brand:<?php echo $branding['color'];?>;
    --brand-glow:rgba(<?php echo "$r,$g,$b";?>,0.38);
    --brand-gradient:<?php echo $branding['gradient'];?>;
}
body.auth-page{padding:16px 12px;}
.renew-wrap{
    position:relative;z-index:1;
    background:rgb(34,34,33);
    border-radius:22px;
    box-shadow:14px 14px 28px rgb(10,10,9),-7px -7px 18px rgb(44,44,42),0 0 0 1px rgba(255,255,255,.043);
    width:100%;max-width:680px;
    animation:authSlideUp .42s cubic-bezier(.22,1,.36,1) both;
}
@media(max-width:480px){.renew-wrap{border-radius:16px;}}

/* Account info cards */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;}
@media(max-width:400px){.info-grid{grid-template-columns:1fr;}}
.info-card{background:rgb(27,27,26);border-radius:12px;padding:14px 16px;
    box-shadow:inset 2px 2px 5px rgb(10,10,9),inset -1px -1px 4px rgb(42,42,40);}
.info-label{font-size:11px;font-weight:600;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.info-value{font-size:15px;font-weight:700;color:#e8e8e6;}
.info-value.danger{color:#f87171;}
.info-value.ok{color:#34d399;}
.info-value.warn{color:#fbbf24;}

/* Package cards for renewal */
.pkg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-bottom:16px;}
.pkg-card{
    position:relative;border:1.5px solid rgba(255,255,255,.08);border-radius:12px;
    padding:14px 12px;cursor:pointer;transition:all .18s;background:rgb(27,27,26);
}
.pkg-card:hover{border-color:rgba(255,255,255,.2);}
.pkg-card.selected{border-color:var(--brand);box-shadow:0 0 0 2px var(--brand);}
.pkg-card input[type=radio]{position:absolute;opacity:0;pointer-events:none;}
.pkg-name{font-weight:700;font-size:12px;color:#e2e2e0;margin-bottom:4px;}
.pkg-meta{font-size:11px;color:rgba(255,255,255,.38);}
.pkg-price{font-size:15px;font-weight:800;color:var(--brand);margin-top:6px;}
.pkg-check{position:absolute;top:8px;right:8px;width:16px;height:16px;border-radius:50%;
    border:2px solid rgba(255,255,255,.2);background:transparent;transition:all .14s;}
.pkg-card.selected .pkg-check{border-color:var(--brand);background:var(--brand);}
.pkg-card.selected .pkg-check::after{content:'✓';color:#fff;font-size:8px;font-weight:700;
    display:flex;align-items:center;justify-content:center;line-height:13px;}

/* Modal overlay */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:9999;
    align-items:center;justify-content:center;padding:16px;}
.modal-overlay.show{display:flex;}
.modal-card{background:rgb(34,34,33);border-radius:22px;padding:36px 28px;width:100%;max-width:400px;
    box-shadow:0 24px 64px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.05);}
.modal-title{font-size:17px;font-weight:700;color:#e8e8e6;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.modal-detail{background:rgb(27,27,26);border-radius:10px;padding:14px 16px;margin-bottom:20px;}
.modal-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px;}
.modal-row:last-child{margin-bottom:0;}
.modal-row-label{color:rgba(255,255,255,.45);}
.modal-row-val{font-weight:700;color:#e8e8e6;}
.modal-total{font-size:20px;font-weight:800;color:var(--brand);}

/* Spinner + icons */
.pay-spinner{width:48px;height:48px;border:4px solid rgba(255,255,255,.1);border-top-color:var(--brand);
    border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px;}
@keyframes spin{to{transform:rotate(360deg);}}
.pay-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;margin:0 auto 16px;font-size:24px;}
.pay-icon.ok{background:rgba(52,211,153,.15);color:#34d399;}
.pay-icon.fail{background:rgba(248,113,113,.15);color:#f87171;}

/* Alerts */
.p-alert{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border-radius:9px;
    font-size:13px;line-height:1.5;margin-bottom:16px;}
.p-alert-err{background:rgba(239,68,68,.12);border-left:4px solid #ef4444;color:#fca5a5;}
.p-alert-ok{background:rgba(34,197,94,.12);border-left:4px solid #22c55e;color:#86efac;}
.p-alert-warn{background:rgba(251,191,36,.1);border-left:4px solid #fbbf24;color:#fde68a;}
.p-alert i{flex-shrink:0;margin-top:2px;}

/* Status badge */
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.badge-active{background:rgba(52,211,153,.15);color:#34d399;}
.badge-expired{background:rgba(248,113,113,.15);color:#f87171;}
.badge-suspended{background:rgba(251,191,36,.12);color:#fbbf24;}

.sec-divider{height:1px;background:rgba(255,255,255,.07);margin:22px 0;}
.lnk{background:none;border:none;color:var(--brand);filter:brightness(1.5);font-size:13px;font-weight:600;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline;}
</style>
</head>
<body class="auth-page">
<div class="renew-wrap">

    <!-- Header -->
    <div class="auth-header">
        <?php if (!empty($branding['logo'])): ?>
            <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo"
                 style="height:48px;margin:0 auto 14px;display:block;object-fit:contain;">
        <?php else: ?>
            <div class="auth-icon-wrap"><i class="fas fa-sync-alt"></i></div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        <p>Subscription Renewal</p>
    </div>

    <div class="auth-body" style="padding:28px 30px;">

    <?php if (!$client): ?>
    <!-- ── Account Lookup Form ──────────────────────────────────────────── -->
    <div style="font-size:16px;font-weight:700;color:#e8e8e6;margin-bottom:4px;">Find Your Account</div>
    <div style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:20px;">Enter your account number or phone to look up your subscription.</div>

    <?php if ($lookupError): ?>
    <div class="p-alert p-alert-err"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($lookupError); ?></span></div>
    <?php endif; ?>

    <form method="GET" action="">
        <?php if ($linkLogin): ?>
        <input type="hidden" name="link-login" value="<?php echo htmlspecialchars($linkLogin); ?>">
        <?php endif; ?>
        <div class="form-group">
            <label>Account Number or Phone <span class="required">*</span></label>
            <input type="text" name="account" class="form-control-auth" required autofocus
                   placeholder="e.g. A00001 or 0712345678"
                   value="<?php echo htmlspecialchars($lookupValue); ?>">
        </div>
        <button type="submit" name="lookup" value="1" class="btn-auth">
            <span>Look Up Account</span><i class="fas fa-arrow-right"></i>
        </button>
    </form>

    <div class="auth-link" style="margin-top:20px;">
        <a href="login.php<?php echo $linkLogin ? '?link-login=' . urlencode($linkLogin) : ''; ?>" style="color:var(--brand);filter:brightness(1.5);">
            ← Back to login
        </a>
    </div>

    <?php else: ?>
    <!-- ── Account Info ─────────────────────────────────────────────────── -->

    <?php if ($isExpired): ?>
    <div class="p-alert p-alert-err" style="margin-bottom:20px;">
        <i class="fas fa-clock"></i>
        <span>Your subscription <strong>expired on <?php echo $expiryLabel; ?></strong>. Renew below to get back online.</span>
    </div>
    <?php elseif ($client['status'] === 'suspended'): ?>
    <div class="p-alert p-alert-warn">
        <i class="fas fa-ban"></i>
        <span>Your account is suspended. Contact your ISP to resolve this before renewing.</span>
    </div>
    <?php else: ?>
    <div class="p-alert p-alert-ok">
        <i class="fas fa-check-circle"></i>
        <span>Your account is <strong>active</strong> until <?php echo $expiryLabel; ?>. You can renew early below.</span>
    </div>
    <?php endif; ?>

    <!-- Account details grid -->
    <div class="info-grid">
        <div class="info-card">
            <div class="info-label">Name</div>
            <div class="info-value"><?php echo htmlspecialchars($client['full_name'] ?? $client['name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <div class="info-label">Account No.</div>
            <div class="info-value"><?php echo htmlspecialchars($client['account_number'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <div class="info-label">Status</div>
            <div class="info-value">
                <?php
                $st = $client['status'] ?? '';
                $statusClass = $st === 'active' ? 'badge-active' : ($st === 'suspended' ? 'badge-suspended' : 'badge-expired');
                ?>
                <span class="status-badge <?php echo $statusClass; ?>">
                    <?php echo $isExpired ? 'Expired' : htmlspecialchars($client['status'] ?? 'N/A'); ?>
                </span>
            </div>
        </div>
        <div class="info-card">
            <div class="info-label">Package</div>
            <div class="info-value"><?php echo htmlspecialchars($client['pkg_name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <div class="info-label">Speed</div>
            <div class="info-value">
                <?php echo !empty($client['download_speed']) ? htmlspecialchars($client['download_speed']) . ' / ' . htmlspecialchars($client['upload_speed'] ?? '?') . ' Mbps' : 'N/A'; ?>
            </div>
        </div>
        <div class="info-card">
            <div class="info-label">Expiry Date</div>
            <div class="info-value <?php echo $isExpired ? 'danger' : ($daysLeft <= 3 ? 'warn' : 'ok'); ?>">
                <?php echo $expiryLabel; ?>
            </div>
        </div>
        <?php if (!empty($client['wallet_balance'])): ?>
        <div class="info-card">
            <div class="info-label">Wallet Balance</div>
            <div class="info-value">KES <?php echo number_format((float)$client['wallet_balance'], 0); ?></div>
        </div>
        <?php endif; ?>
        <div class="info-card">
            <div class="info-label">Connection</div>
            <div class="info-value"><?php echo htmlspecialchars(strtoupper($client['connection_type'] ?? 'Hotspot')); ?></div>
        </div>
    </div>

    <div class="sec-divider"></div>

    <!-- Package selection for renewal -->
    <div style="font-size:15px;font-weight:700;color:#e8e8e6;margin-bottom:4px;">Choose Package to Renew</div>
    <div style="font-size:12px;color:rgba(255,255,255,.38);margin-bottom:16px;">Select the plan you want to subscribe to</div>

    <?php if (!empty($packages)): ?>
    <div class="pkg-grid">
        <?php foreach ($packages as $pkg):
            $dur   = ($pkg['validity_value'] ?? 1) . ' ' . ucfirst($pkg['validity_unit'] ?? 'days');
            $speed = !empty($pkg['download_speed']) ? $pkg['download_speed'] . '/' . ($pkg['upload_speed'] ?? '?') . ' Mbps · ' : '';
            $isCurrent = ((int)($pkg['id'] ?? 0)) === ((int)($client['package_id'] ?? 0));
        ?>
        <label class="pkg-card<?php echo $isCurrent ? ' selected' : ''; ?>" id="pkr-<?php echo $pkg['id']; ?>">
            <input type="radio" name="pkg_sel" value="<?php echo $pkg['id']; ?>"
                   data-price="<?php echo htmlspecialchars($pkg['price']); ?>"
                   data-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                   data-id="<?php echo (int)$pkg['id']; ?>"
                   data-dur="<?php echo htmlspecialchars($dur); ?>"
                   <?php echo $isCurrent ? 'checked' : ''; ?>
                   onchange="selPkg(this)">
            <div class="pkg-check"></div>
            <div class="pkg-name"><?php echo htmlspecialchars($pkg['name']); ?><?php if ($isCurrent) echo ' <span style="font-size:9px;color:rgba(255,255,255,.4);">(current)</span>'; ?></div>
            <div class="pkg-meta"><?php echo htmlspecialchars($speed . $dur); ?></div>
            <div class="pkg-price">KES <?php echo number_format((float)$pkg['price'], 0); ?></div>
        </label>
        <?php endforeach; ?>
    </div>

    <!-- Phone number (for STK push) -->
    <div class="form-group" style="margin-bottom:14px;">
        <label>M-Pesa Phone Number <span class="required">*</span></label>
        <input type="tel" id="renew-phone" class="form-control-auth"
               placeholder="e.g. 0712345678"
               value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
        <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:5px;"><i class="fas fa-lock" style="font-size:9px;"></i> STK push will be sent to this number</div>
    </div>

    <button type="button" class="btn-auth" onclick="openRenewModal()">
        <i class="fas fa-credit-card"></i><span>Renew Subscription</span>
    </button>

    <?php if ($paybill): ?>
    <div style="text-align:center;margin-top:16px;">
        <div style="font-size:12px;color:rgba(255,255,255,.3);margin-bottom:8px;">Or pay manually via M-Pesa:</div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);">
            Paybill: <strong style="color:#e8e8e6;"><?php echo htmlspecialchars($paybill); ?></strong> &nbsp;|&nbsp;
            Account: <strong style="color:#e8e8e6;"><?php echo htmlspecialchars($client['account_number'] ?? 'Your Acc No.'); ?></strong>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="p-alert p-alert-warn"><i class="fas fa-info-circle"></i><span>No packages available. Contact your ISP.</span></div>
    <?php endif; ?>

    <div class="sec-divider"></div>
    <div class="auth-link">
        <a href="login.php<?php echo $linkLogin ? '?link-login=' . urlencode($linkLogin) : ''; ?>"
           style="color:var(--brand);filter:brightness(1.5);">← Back to Login</a>
        &nbsp;|&nbsp;
        <button class="lnk" onclick="document.getElementById('lookup-again').style.display='block';this.closest('div').style.display='none';">
            Different account
        </button>
    </div>
    <div id="lookup-again" style="display:none;margin-top:16px;">
        <form method="GET">
            <div class="form-group">
                <input type="text" name="account" class="form-control-auth" placeholder="Account number or phone">
            </div>
            <button type="submit" name="lookup" value="1" class="btn-auth" style="padding:10px 20px;width:auto;">
                <span>Look Up</span><i class="fas fa-search"></i>
            </button>
        </form>
    </div>

    <?php endif; ?>

    </div>
</div>

<!-- ── Renewal Confirmation Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-confirm">
    <div class="modal-card" id="modal-inner">
        <!-- Step 1: Confirm -->
        <div id="step-confirm">
            <div class="modal-title">
                <i class="fas fa-sync-alt" style="color:var(--brand);"></i>
                Renew Subscription
            </div>
            <div class="modal-detail">
                <div class="modal-row">
                    <span class="modal-row-label">Customer</span>
                    <span class="modal-row-val"><?php echo htmlspecialchars($client ? ($client['full_name'] ?? '') : ''); ?></span>
                </div>
                <div class="modal-row">
                    <span class="modal-row-label">Package</span>
                    <span class="modal-row-val" id="confirm-pkg">—</span>
                </div>
                <div class="modal-row">
                    <span class="modal-row-label">Duration</span>
                    <span class="modal-row-val" id="confirm-dur">—</span>
                </div>
                <div class="modal-row" style="border-top:1px solid rgba(255,255,255,.08);padding-top:10px;margin-top:4px;">
                    <span class="modal-row-label">Amount</span>
                    <span class="modal-row-val modal-total" id="confirm-amount">KES 0</span>
                </div>
            </div>
            <div style="margin-bottom:16px;">
                <div style="font-size:12px;color:rgba(255,255,255,.38);margin-bottom:8px;">Payment Method</div>
                <div style="display:flex;gap:10px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#e8e8e6;cursor:pointer;">
                        <input type="radio" name="pay_method" value="stk" checked style="accent-color:var(--brand);">
                        M-Pesa STK Push <span style="color:rgba(255,255,255,.4);font-size:11px;">(popup on phone)</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label style="font-size:13px;">Phone for M-Pesa <span class="required">*</span></label>
                <input type="tel" id="modal-phone" class="form-control-auth"
                       placeholder="e.g. 0712345678"
                       value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
            </div>
            <div style="display:flex;gap:10px;margin-top:4px;">
                <button type="button" class="btn-auth" onclick="confirmRenew()" style="flex:1;">
                    <i class="fas fa-mobile-alt"></i><span>Pay Now</span>
                </button>
                <button type="button" onclick="closeModal()" class="btn-auth"
                        style="flex:0 0 auto;background:rgba(255,255,255,.08);box-shadow:none;width:44px;padding:0;justify-content:center;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- Step 2: Processing -->
        <div id="step-processing" style="display:none;text-align:center;">
            <div class="pay-spinner" id="proc-spinner"></div>
            <div id="proc-icon-ok"   class="pay-icon ok"   style="display:none;"><i class="fas fa-check"></i></div>
            <div id="proc-icon-fail" class="pay-icon fail"  style="display:none;"><i class="fas fa-times"></i></div>
            <div style="font-size:17px;font-weight:700;color:#e8e8e6;margin-bottom:8px;" id="proc-title">Sending Payment Request</div>
            <div style="font-size:24px;font-weight:800;color:var(--brand);margin-bottom:4px;" id="proc-amount"></div>
            <div style="font-size:13px;color:rgba(255,255,255,.45);margin-bottom:16px;" id="proc-msg">
                A USSD popup will appear on your phone. Enter your M-Pesa PIN to complete the payment.
            </div>
            <div style="font-size:12px;color:rgba(255,255,255,.28);margin-bottom:18px;" id="proc-hint"></div>
            <button type="button" class="btn-auth" id="proc-close" style="display:none;" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<script>
var TENANT_ID   = <?php echo (int)($tenantId ?? 0); ?>;
var LINK_LOGIN  = <?php echo json_encode($linkLogin); ?>;
var MAC_ADDRESS = <?php echo json_encode($macAddress); ?>;
var CLIENT_ACC  = <?php echo json_encode($client['account_number'] ?? $client['phone'] ?? ''); ?>;
var SEL_PKG     = <?php
    $defaultPkg = null;
    if ($client && !empty($packages)) {
        foreach ($packages as $p) {
            if ((int)$p['id'] === (int)($client['package_id'] ?? 0)) { $defaultPkg = $p; break; }
        }
        if (!$defaultPkg) $defaultPkg = $packages[0];
    }
    echo json_encode($defaultPkg ? [
        'id' => (int)$defaultPkg['id'],
        'name' => $defaultPkg['name'],
        'price' => (float)$defaultPkg['price'],
        'dur' => ($defaultPkg['validity_value'] ?? 1) . ' ' . ucfirst($defaultPkg['validity_unit'] ?? 'days'),
    ] : null);
?>;
var pollTimer = null;
var pollCount = 0;

function selPkg(radio) {
    document.querySelectorAll('.pkg-card').forEach(function(c){ c.classList.remove('selected'); });
    var card = document.getElementById('pkr-'+radio.value);
    if (card) card.classList.add('selected');
    SEL_PKG = {
        id:    parseInt(radio.dataset.id),
        name:  radio.dataset.name,
        price: parseFloat(radio.dataset.price)||0,
        dur:   radio.dataset.dur || '',
    };
}

function openRenewModal() {
    if (!SEL_PKG) { alert('Please select a package.'); return; }
    var phone = document.getElementById('renew-phone') ? document.getElementById('renew-phone').value.trim() : '';
    document.getElementById('modal-phone').value = phone || '<?php echo addslashes($client['phone'] ?? ''); ?>';
    document.getElementById('confirm-pkg').textContent    = SEL_PKG.name;
    document.getElementById('confirm-dur').textContent    = SEL_PKG.dur;
    document.getElementById('confirm-amount').textContent = 'KES ' + SEL_PKG.price.toLocaleString();
    document.getElementById('step-confirm').style.display    = 'block';
    document.getElementById('step-processing').style.display = 'none';
    document.getElementById('modal-confirm').classList.add('show');
}
function closeModal() {
    clearInterval(pollTimer);
    document.getElementById('modal-confirm').classList.remove('show');
}

function confirmRenew() {
    if (!SEL_PKG) return;
    var phone = document.getElementById('modal-phone').value.trim();
    if (!phone) { alert('Please enter your M-Pesa phone number.'); return; }

    document.getElementById('step-confirm').style.display    = 'none';
    document.getElementById('step-processing').style.display = 'block';
    document.getElementById('proc-spinner').style.display    = 'block';
    document.getElementById('proc-icon-ok').style.display    = 'none';
    document.getElementById('proc-icon-fail').style.display  = 'none';
    document.getElementById('proc-title').textContent   = 'Sending Payment Request';
    document.getElementById('proc-amount').textContent  = 'KES ' + SEL_PKG.price.toLocaleString();
    document.getElementById('proc-msg').textContent     = 'A USSD popup will appear on your phone. Enter your M-Pesa PIN to complete.';
    document.getElementById('proc-hint').textContent    = 'Sending to: ' + phone;
    document.getElementById('proc-close').style.display = 'none';

    fetch('../api/mpesa/hotspot_stk_push.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action:      'renew',
            phone:        phone,
            account:      CLIENT_ACC,
            package_id:   SEL_PKG.id,
            amount:       SEL_PKG.price,
            tenant_id:    TENANT_ID,
            mac_address:  MAC_ADDRESS,
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success && d.checkout_request_id) {
            document.getElementById('proc-msg').textContent = 'Payment request sent! Waiting for confirmation...';
            startRenewPoll(d.checkout_request_id, d.client_id);
        } else {
            procFail(d.message || 'Payment request failed.');
        }
    })
    .catch(function(){ procFail('Network error. Please try again.'); });
}

function startRenewPoll(checkoutId, clientId) {
    pollCount = 0;
    pollTimer = setInterval(function(){
        pollCount++;
        if (pollCount > 60) { clearInterval(pollTimer); procFail('Payment timeout. Contact support if you were charged.'); return; }

        fetch('../api/mpesa/hotspot_payment_status.php?checkout_request_id=' + encodeURIComponent(checkoutId) + '&client_id=' + clientId)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.status === 'completed') {
                clearInterval(pollTimer);
                procOk(d.username, d.password, d.portal_token);
            } else if (d.status === 'failed') {
                clearInterval(pollTimer);
                procFail(d.message || 'Payment failed or cancelled.');
            }
        }).catch(function(){});
    }, 3000);
}

function procOk(username, password, portalToken) {
    document.getElementById('proc-spinner').style.display  = 'none';
    document.getElementById('proc-icon-ok').style.display  = 'flex';
    document.getElementById('proc-title').textContent = 'Payment Confirmed!';
    document.getElementById('proc-amount').style.display = 'none';

    if (username && password && LINK_LOGIN) {
        document.getElementById('proc-msg').textContent = 'Connecting you to the internet...';
        var dst = '';
        if (portalToken) dst = window.location.origin + '/customer/auto_login.php?token=' + encodeURIComponent(portalToken);
        var url = LINK_LOGIN + '?username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password);
        if (dst) url += '&dst=' + encodeURIComponent(dst);
        setTimeout(function(){ window.location.href = url; }, 1400);
    } else {
        document.getElementById('proc-msg').textContent = 'Your subscription is now active. ' +
            (username ? 'Sign in with username: ' + username : 'You can now sign in to the internet.');
        document.getElementById('proc-close').style.display = 'block';
        document.getElementById('proc-close').textContent = 'Go to Login';
        document.getElementById('proc-close').onclick = function(){ window.location.href = 'login.php'; };
    }
}

function procFail(msg) {
    document.getElementById('proc-spinner').style.display   = 'none';
    document.getElementById('proc-icon-fail').style.display = 'flex';
    document.getElementById('proc-title').textContent = 'Payment Failed';
    document.getElementById('proc-amount').style.display = 'none';
    document.getElementById('proc-msg').textContent = msg;
    document.getElementById('proc-hint').textContent = '';
    document.getElementById('proc-close').style.display = 'block';
}
</script>
</body>
</html>
