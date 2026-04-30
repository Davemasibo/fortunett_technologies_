<?php
/**
 * Customer Hotspot Portal — MikroTik Captive Portal + Customer Login
 *
 * Serves as both:
 *   1. MikroTik hotspot captive portal (configure hotspot profile: login-page = this URL)
 *   2. Customer self-service login for the customer portal
 *
 * MikroTik GET params: mac, ip, link-login, link-orig, link-logout, username, error
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['customer_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';

// ── MikroTik hotspot parameters ───────────────────────────────────────────────
$macAddress = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_GET['mac'] ?? ''));
$deviceIp   = filter_var($_GET['ip'] ?? '', FILTER_VALIDATE_IP) ?: '';
$linkLogin  = $_GET['link-login']  ?? '';
$linkOrig   = $_GET['link-orig']   ?? '';
$linkLogout = $_GET['link-logout'] ?? '';
$mikError   = trim($_GET['error']  ?? '');
$isMikrotikPortal = !empty($linkLogin) || !empty($macAddress);

$error   = $mikError ?: '';
$success = '';
if (isset($_GET['logged_out']))      $success = 'Logged out successfully.';
if (isset($_GET['session_expired'])) $error   = 'Your session expired. Please sign in again.';

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

// ── Fetch active hotspot packages ─────────────────────────────────────────────
$packages = [];
if ($tenantId) {
    try {
        $pkgSt = $pdo->prepare("
            SELECT id, name, price, download_speed, upload_speed, validity_value, validity_unit,
                   COALESCE(connection_type, 'hotspot') AS connection_type
            FROM packages
            WHERE tenant_id = ? AND status = 'active'
              AND COALESCE(NULLIF(connection_type,''), 'hotspot') = 'hotspot'
            ORDER BY price ASC, name ASC
        ");
        $pkgSt->execute([$tenantId]);
        $packages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $_e) {}
}

// ── Check if this device MAC has already used a free trial ────────────────────
$macUsedFreeTrial = false;
if ($macAddress && $tenantId) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_free_trials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            mac_address VARCHAR(20) NOT NULL,
            client_id INT DEFAULT NULL,
            used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY tenant_mac (tenant_id, mac_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $pdo->prepare("SELECT 1 FROM device_free_trials WHERE tenant_id = ? AND mac_address = ? LIMIT 1");
        $chk->execute([$tenantId, $macAddress]);
        $macUsedFreeTrial = (bool)$chk->fetchColumn();
    } catch (Exception $_e) {}
}

// ── Handle login form submission ───────────────────────────────────────────────
$loggedInClient = null;
$expiredClient  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginId  = trim($_POST['username'] ?? '');
    $loginPwd = trim($_POST['password'] ?? '');

    if (!$loginId || !$loginPwd) {
        $error = 'Please enter your username and password.';
    } elseif (!$tenantId) {
        $error = 'Portal not configured. Contact support.';
    } else {
        try {
            $clSt = $pdo->prepare("
                SELECT c.*, p.name AS pkg_name, p.price AS pkg_price,
                       p.validity_value, p.validity_unit
                FROM clients c
                LEFT JOIN packages p ON p.id = c.package_id
                WHERE c.tenant_id = ?
                  AND (c.username = ? OR c.phone = ? OR c.account_number = ?)
                LIMIT 1
            ");
            $clSt->execute([$tenantId, $loginId, $loginId, $loginId]);
            $foundClient = $clSt->fetch(PDO::FETCH_ASSOC);

            if (!$foundClient) {
                $error = 'Account not found. Check your credentials or create a new account.';
            } else {
                $pwMatch = password_verify($loginPwd, $foundClient['auth_password'] ?? '')
                        || ($loginPwd === ($foundClient['mikrotik_password'] ?? "\0NEVER\0"));
                if (!$pwMatch) {
                    $error = 'Incorrect password. Please try again.';
                } elseif ($foundClient['status'] === 'suspended') {
                    $error = 'Account suspended. Contact your ISP.';
                } elseif ($foundClient['status'] === 'pending') {
                    $error = 'Account pending payment. Please pay for a package to get connected.';
                } elseif ($foundClient['status'] === 'inactive'
                       || (!empty($foundClient['expiry_date']) && strtotime($foundClient['expiry_date']) < time())) {
                    $expiredClient = $foundClient;
                } else {
                    $loggedInClient = $foundClient;
                }
            }
        } catch (Exception $_e) {
            $error = 'Login failed. Please try again.';
        }
    }
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
<title><?php echo htmlspecialchars($branding['name']); ?> — Internet Portal</title>
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
body.auth-page { padding: 16px; }

/* Portal container — wider than standard auth card */
.portal-wrap {
    position:relative;z-index:1;
    background:rgb(34,34,33);
    border-radius:22px;
    box-shadow:14px 14px 28px rgb(10,10,9),-7px -7px 18px rgb(44,44,42),0 0 0 1px rgba(255,255,255,.043);
    width:100%;max-width:860px;
    animation:authSlideUp .42s cubic-bezier(.22,1,.36,1) both;
}
@media(max-width:600px){.portal-wrap{border-radius:16px;}}

/* Tabs */
.portal-tabs{display:flex;background:rgb(26,26,25);border-bottom:1px solid rgba(255,255,255,.07);}
.p-tab{flex:1;padding:14px 16px;font-size:13px;font-weight:600;color:rgba(255,255,255,.48);
    cursor:pointer;text-align:center;border:none;background:transparent;
    transition:all .2s;border-bottom:3px solid transparent;font-family:inherit;}
.p-tab.active{color:#fff;border-bottom-color:var(--brand);background:rgba(255,255,255,.04);}
.p-tab:hover:not(.active){color:rgba(255,255,255,.75);}

/* Tab panels */
.p-panel{display:none;padding:28px 30px;}
.p-panel.active{display:block;}
@media(max-width:600px){.p-panel{padding:20px 18px;}}

/* Section label */
.sec-title{font-size:16px;font-weight:700;color:#e8e8e6;margin-bottom:4px;}
.sec-sub{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:20px;}

/* Package grid */
.pkg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:10px;}
.pkg-card{
    position:relative;border:1.5px solid rgba(255,255,255,.08);border-radius:14px;
    padding:14px 12px;cursor:pointer;transition:all .18s;background:rgb(27,27,26);
    box-shadow:inset 2px 2px 5px rgb(10,10,9),inset -1px -1px 4px rgb(42,42,40);
}
.pkg-card:hover:not(.used){border-color:rgba(255,255,255,.2);}
.pkg-card.selected{border-color:var(--brand);box-shadow:0 0 0 2px var(--brand),inset 2px 2px 5px rgb(10,10,9);}
.pkg-card.used{opacity:.45;cursor:not-allowed;}
.pkg-card input[type=radio]{position:absolute;opacity:0;pointer-events:none;}
.pkg-name{font-weight:700;font-size:13px;color:#e2e2e0;margin-bottom:4px;}
.pkg-meta{font-size:11px;color:rgba(255,255,255,.38);}
.pkg-price{font-size:16px;font-weight:800;color:var(--brand);margin-top:8px;}
.pkg-free{font-size:11px;color:#34d399;font-weight:700;margin-top:6px;}
.pkg-used-label{font-size:10px;color:#f87171;font-weight:600;margin-top:4px;}
.pkg-check{
    position:absolute;top:9px;right:9px;width:17px;height:17px;border-radius:50%;
    border:2px solid rgba(255,255,255,.2);background:transparent;transition:all .15s;
}
.pkg-card.selected .pkg-check{border-color:var(--brand);background:var(--brand);}
.pkg-card.selected .pkg-check::after{content:'✓';color:#fff;font-size:9px;font-weight:700;
    display:flex;align-items:center;justify-content:center;line-height:14px;}

/* Registration sub-form */
.reg-box{display:none;margin-top:18px;padding-top:18px;border-top:1px solid rgba(255,255,255,.07);}
.reg-box.show{display:block;}

/* Inline alerts */
.p-alert{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border-radius:9px;
    font-size:13px;line-height:1.5;margin-bottom:14px;}
.p-alert-err{background:rgba(239,68,68,.12);border-left:4px solid #ef4444;color:#fca5a5;}
.p-alert-ok{background:rgba(34,197,94,.12);border-left:4px solid #22c55e;color:#86efac;}
.p-alert-warn{background:rgba(251,191,36,.1);border-left:4px solid #fbbf24;color:#fde68a;}
.p-alert-info{background:rgba(96,165,250,.1);border-left:4px solid #60a5fa;color:#bfdbfe;}
.p-alert i{flex-shrink:0;margin-top:2px;}

/* Connected / expired banners */
.connected-banner{text-align:center;padding:24px;background:rgba(52,211,153,.08);
    border:1px solid rgba(52,211,153,.2);border-radius:14px;margin-bottom:20px;}
.connected-banner i{font-size:32px;color:#34d399;margin-bottom:10px;display:block;}
.connected-banner h3{font-size:18px;font-weight:700;color:#e8e8e6;margin-bottom:6px;}
.connected-banner p{font-size:13px;color:rgba(255,255,255,.5);}

.expired-banner{text-align:center;padding:22px;background:rgba(248,113,113,.08);
    border:1px solid rgba(248,113,113,.25);border-radius:14px;margin-bottom:20px;}
.expired-banner i{font-size:30px;color:#f87171;margin-bottom:10px;display:block;}
.expired-banner h3{font-size:16px;font-weight:700;color:#fca5a5;margin-bottom:6px;}
.expired-banner p{font-size:13px;color:rgba(255,255,255,.45);margin-bottom:14px;}

/* Payment overlay */
.pay-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:9999;
    align-items:center;justify-content:center;}
.pay-overlay.show{display:flex;}
.pay-card{background:rgb(34,34,33);border-radius:22px;padding:40px 32px;max-width:360px;
    width:90%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.05);}
.pay-spinner{width:52px;height:52px;border:4px solid rgba(255,255,255,.1);border-top-color:var(--brand);
    border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px;}
@keyframes spin{to{transform:rotate(360deg);}}
.pay-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;margin:0 auto 20px;font-size:24px;}
.pay-icon.ok{background:rgba(52,211,153,.15);color:#34d399;}
.pay-icon.fail{background:rgba(248,113,113,.15);color:#f87171;}
.pay-title{font-size:17px;font-weight:700;color:#e8e8e6;margin-bottom:6px;}
.pay-amount{font-size:26px;font-weight:800;color:var(--brand);margin-bottom:4px;}
.pay-msg{font-size:13px;color:rgba(255,255,255,.5);margin-bottom:18px;}
.pay-hint{font-size:12px;color:rgba(255,255,255,.3);margin-bottom:18px;}

/* Small link button */
.lnk{background:none;border:none;color:var(--brand);filter:brightness(1.5);font-size:13px;
    font-weight:600;cursor:pointer;padding:0;text-decoration:underline;font-family:inherit;}
</style>
</head>
<body class="auth-page">
<div class="portal-wrap">

    <!-- Header -->
    <div class="auth-header">
        <?php if (!empty($branding['logo'])): ?>
            <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo"
                 style="height:48px;margin:0 auto 14px;display:block;object-fit:contain;">
        <?php else: ?>
            <div class="auth-icon-wrap"><i class="fas fa-wifi"></i></div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        <p>Internet Access Portal</p>
    </div>

    <!-- Tabs -->
    <div class="portal-tabs">
        <button class="p-tab active" id="tab-signin" onclick="switchTab('signin')">
            <i class="fas fa-sign-in-alt"></i> Sign In
        </button>
        <button class="p-tab" id="tab-connect" onclick="switchTab('connect')">
            <i class="fas fa-bolt"></i> Get Connected
        </button>
    </div>

    <!-- ── TAB: SIGN IN ──────────────────────────────────────────────────── -->
    <div class="p-panel active" id="panel-signin">

        <?php if ($loggedInClient): ?>
        <!-- Successful login → auto-connect to MikroTik -->
        <div class="connected-banner">
            <i class="fas fa-check-circle"></i>
            <h3>Welcome, <?php echo htmlspecialchars(explode(' ', $loggedInClient['full_name'] ?? $loggedInClient['username'])[0]); ?>!</h3>
            <p>Connecting you to the internet...</p>
        </div>
        <script>
        (function(){
            var ll = <?php echo json_encode($linkLogin); ?>;
            var u  = <?php echo json_encode($loggedInClient['mikrotik_username'] ?? $loggedInClient['username']); ?>;
            var p  = <?php echo json_encode($loggedInClient['mikrotik_password'] ?? ''); ?>;
            if (ll && u && p) {
                setTimeout(function(){
                    window.location.href = ll + '?username=' + encodeURIComponent(u) + '&password=' + encodeURIComponent(p);
                }, 900);
            }
        })();
        </script>

        <?php elseif ($expiredClient): ?>
        <!-- Expired account -->
        <div class="expired-banner">
            <i class="fas fa-clock"></i>
            <h3>Subscription Expired</h3>
            <p>Hi <?php echo htmlspecialchars(explode(' ', $expiredClient['full_name'] ?? '')[0]); ?>, your internet package expired on
               <?php echo date('d M Y', strtotime($expiredClient['expiry_date'] ?? 'now')); ?>.</p>
        </div>
        <a href="renew.php?account=<?php echo urlencode($expiredClient['account_number'] ?? $expiredClient['phone'] ?? ''); ?>"
           class="btn-auth" style="text-decoration:none;display:flex;margin-bottom:14px;">
            <i class="fas fa-sync-alt"></i><span>Renew Subscription</span>
        </a>
        <div class="auth-link">
            <button class="lnk" onclick="document.getElementById('login-inner').style.display='block';this.closest('div').remove();">Sign in again</button>
        </div>
        <div id="login-inner" style="display:none;margin-top:18px;">
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Username / Phone / Account No. <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control-auth" placeholder="e.g. john or 0712345678">
                </div>
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="repwd" class="form-control-auth" placeholder="Password" style="padding-right:44px;">
                        <i class="fas fa-eye password-toggle" onclick="togglePw('repwd',this)"></i>
                    </div>
                </div>
                <button type="submit" class="btn-auth"><span>Sign In</span><i class="fas fa-arrow-right"></i></button>
            </form>
        </div>

        <?php else: ?>

        <?php if ($error): ?>
        <div class="p-alert p-alert-err">
            <i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="p-alert p-alert-ok">
            <i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <div class="sec-title">Welcome Back</div>
        <div class="sec-sub">Sign in with your account credentials</div>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Username / Phone / Account No. <span class="required">*</span></label>
                <input type="text" name="username" class="form-control-auth" required autofocus
                       placeholder="e.g. john or 0712345678"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="login-pwd" class="form-control-auth"
                           required placeholder="Your password" style="padding-right:44px;">
                    <i class="fas fa-eye password-toggle" onclick="togglePw('login-pwd',this)"></i>
                </div>
            </div>
            <button type="submit" class="btn-auth"><span>Sign In</span><i class="fas fa-arrow-right"></i></button>
        </form>

        <?php endif; ?>

        <div class="auth-link" style="margin-top:18px;">
            New here? <button class="lnk" onclick="switchTab('connect')">Browse packages →</button>
        </div>
    </div>

    <!-- ── TAB: GET CONNECTED ────────────────────────────────────────────── -->
    <div class="p-panel" id="panel-connect">
        <div class="sec-title">Choose a Package</div>
        <div class="sec-sub">Pick a plan and get online in minutes</div>

        <?php if (empty($packages)): ?>
        <div class="p-alert p-alert-warn">
            <i class="fas fa-info-circle"></i>
            <span>No packages available yet. Contact your ISP administrator.</span>
        </div>
        <?php else: ?>

        <div class="pkg-grid">
            <?php foreach ($packages as $pkg):
                $isFree   = (float)$pkg['price'] == 0;
                $isUsed   = $isFree && $macUsedFreeTrial;
                $dur      = ($pkg['validity_value'] ?? 1) . ' ' . ucfirst(rtrim($pkg['validity_unit'] ?? 'days', 's') . 's');
                $speed    = !empty($pkg['download_speed']) ? $pkg['download_speed'] . ' Mbps' : '';
                $classes  = 'pkg-card' . ($isUsed ? ' used' : '');
            ?>
            <label class="<?php echo $classes; ?>" id="pkc-<?php echo $pkg['id']; ?>"
                   <?php echo $isUsed ? 'onclick="return false;"' : ''; ?>>
                <input type="radio" name="pkg" value="<?php echo $pkg['id']; ?>"
                       data-price="<?php echo htmlspecialchars($pkg['price']); ?>"
                       data-free="<?php echo $isFree ? '1' : '0'; ?>"
                       data-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                       data-id="<?php echo (int)$pkg['id']; ?>"
                       <?php echo $isUsed ? 'disabled' : ''; ?>
                       onchange="pkgSelect(this)">
                <div class="pkg-check"></div>
                <div class="pkg-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
                <div class="pkg-meta"><?php echo htmlspecialchars($speed . ($speed ? ' · ' : '') . $dur); ?></div>
                <?php if ($isFree): ?>
                    <?php if ($isUsed): ?>
                    <div class="pkg-used-label"><i class="fas fa-ban"></i> Already used on this device</div>
                    <?php else: ?>
                    <div class="pkg-free"><i class="fas fa-gift"></i> Free Trial</div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="pkg-price">KES <?php echo number_format((float)$pkg['price'], 0); ?></div>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- FREE registration form -->
        <div class="reg-box" id="reg-free">
            <div class="p-alert p-alert-ok">
                <i class="fas fa-gift"></i>
                <span>Free trial! Just enter your phone and name to connect instantly.</span>
            </div>
            <div class="form-group">
                <label>Phone Number <span class="required">*</span></label>
                <input type="tel" id="fp" class="form-control-auth" placeholder="e.g. 0712345678">
            </div>
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" id="fn" class="form-control-auth" placeholder="Your full name">
            </div>
            <button type="button" class="btn-auth" onclick="doFree()">
                <i class="fas fa-bolt"></i><span>Connect Free Now</span>
            </button>
        </div>

        <!-- PAID registration form -->
        <div class="reg-box" id="reg-paid">
            <div class="p-alert p-alert-info" id="paid-pkg-info">
                <i class="fas fa-box"></i><span id="paid-pkg-text">Loading...</span>
            </div>
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" id="pp-name" class="form-control-auth" placeholder="e.g. John Kamau">
            </div>
            <div class="form-group">
                <label>Phone (M-Pesa) <span class="required">*</span></label>
                <input type="tel" id="pp-phone" class="form-control-auth" placeholder="e.g. 0712345678">
            </div>
            <div class="form-group">
                <label>Create Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" id="pp-pwd" class="form-control-auth"
                           placeholder="Min 6 characters" style="padding-right:44px;">
                    <i class="fas fa-eye password-toggle" onclick="togglePw('pp-pwd',this)"></i>
                </div>
            </div>
            <button type="button" class="btn-auth" onclick="doPaid()">
                <i class="fas fa-mobile-alt"></i><span>Pay via M-Pesa &amp; Connect</span>
            </button>
            <div style="font-size:11px;color:rgba(255,255,255,.3);text-align:center;margin-top:10px;">
                <i class="fas fa-lock" style="font-size:9px;"></i> M-Pesa STK push will appear on your phone
            </div>
        </div>

        <?php endif; ?>

        <div class="auth-link" style="margin-top:18px;">
            Already have an account? <button class="lnk" onclick="switchTab('signin')">Sign in →</button>
        </div>
    </div>
</div>

<!-- Payment overlay -->
<div class="pay-overlay" id="pay-overlay">
    <div class="pay-card">
        <div id="ov-spinner" class="pay-spinner"></div>
        <div id="ov-icon-ok"   class="pay-icon ok"   style="display:none;"><i class="fas fa-check"></i></div>
        <div id="ov-icon-fail" class="pay-icon fail"  style="display:none;"><i class="fas fa-times"></i></div>
        <div class="pay-title" id="ov-title">Processing</div>
        <div class="pay-amount" id="ov-amount" style="display:none;"></div>
        <div class="pay-msg"   id="ov-msg">Please wait...</div>
        <div class="pay-hint"  id="ov-hint"  style="display:none;"></div>
        <button type="button" class="btn-auth" id="ov-close" style="display:none;" onclick="closeOverlay()">Close</button>
    </div>
</div>

<script>
var TENANT_ID    = <?php echo (int)($tenantId ?? 0); ?>;
var LINK_LOGIN   = <?php echo json_encode($linkLogin); ?>;
var LINK_ORIG    = <?php echo json_encode($linkOrig); ?>;
var MAC_ADDRESS  = <?php echo json_encode($macAddress); ?>;
// Absolute URL to auto_login.php on THIS server (not the MikroTik hotspot IP).
// window.location.origin would resolve to the router's hotspot address when the
// browser is on the captive portal — use the PHP-generated server origin instead.
var SERVER_ORIGIN = <?php
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    echo json_encode($proto . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
?>;
var SEL_PKG     = null;
var pollTimer   = null;
var pollCount   = 0;

/* ── Tab switch ────────────────────────────────────────────── */
function switchTab(t) {
    ['signin','connect'].forEach(function(x){
        document.getElementById('panel-'+x).classList.toggle('active', x===t);
        document.getElementById('tab-'+x).classList.toggle('active', x===t);
    });
}

/* ── Package selection ─────────────────────────────────────── */
function pkgSelect(radio) {
    document.querySelectorAll('.pkg-card').forEach(function(c){ c.classList.remove('selected'); });
    var card = document.getElementById('pkc-'+radio.value);
    if (card) card.classList.add('selected');

    var isFree = radio.dataset.free === '1';
    SEL_PKG = {id: parseInt(radio.dataset.id), free: isFree, price: parseFloat(radio.dataset.price)||0, name: radio.dataset.name};

    document.getElementById('reg-free').classList.toggle('show', isFree);
    document.getElementById('reg-paid').classList.toggle('show', !isFree);

    if (!isFree) {
        document.getElementById('paid-pkg-text').textContent =
            SEL_PKG.name + ' — KES ' + SEL_PKG.price.toLocaleString() + ' / period';
    }
}

/* ── Free package flow ─────────────────────────────────────── */
function doFree() {
    var phone = document.getElementById('fp').value.trim();
    var name  = document.getElementById('fn').value.trim();
    if (!phone || !name) { alert('Please enter your name and phone number.'); return; }
    if (!SEL_PKG) { alert('Please select a package first.'); return; }

    showOverlay('Activating Free Trial', 'Setting up your account...', null, null);

    postApi('free', {phone:phone, full_name:name, package_id:SEL_PKG.id, mac_address:MAC_ADDRESS, tenant_id:TENANT_ID})
    .then(function(d){
        if (d.success) {
            updateOverlay('ok', "You're Connected!", 'Enjoy your free internet access!');
            if (d.username && d.password && LINK_LOGIN) {
                setTimeout(function(){ doConnect(d.username, d.password, d.portal_token); }, 1400);
            } else {
                setTimeout(closeOverlay, 3000);
            }
        } else {
            updateOverlay('fail', 'Activation Failed', d.message || 'Please try again.');
        }
    }).catch(function(){ updateOverlay('fail', 'Connection Error', 'Please try again.'); });
}

/* ── Paid package flow ─────────────────────────────────────── */
function doPaid() {
    var name  = document.getElementById('pp-name').value.trim();
    var phone = document.getElementById('pp-phone').value.trim();
    var pwd   = document.getElementById('pp-pwd').value.trim();
    if (!name || !phone || !pwd) { alert('Please fill in all fields.'); return; }
    if (pwd.length < 6) { alert('Password must be at least 6 characters.'); return; }
    if (!SEL_PKG) { alert('Please select a package.'); return; }

    showOverlay('Payment Request Sent', 'Check your phone for the M-Pesa prompt...', SEL_PKG.price, phone);

    postApi('paid', {phone:phone, full_name:name, password:pwd, package_id:SEL_PKG.id, amount:SEL_PKG.price, mac_address:MAC_ADDRESS, tenant_id:TENANT_ID})
    .then(function(d){
        if (d.success && d.checkout_request_id) {
            startPolling(d.checkout_request_id, d.client_id);
        } else {
            updateOverlay('fail', 'Payment Error', d.message || 'Could not send payment request.');
        }
    }).catch(function(){ updateOverlay('fail', 'Connection Error', 'Please try again.'); });
}

/* ── Polling ───────────────────────────────────────────────── */
function startPolling(checkoutId, clientId) {
    pollCount = 0;
    pollTimer = setInterval(function(){
        pollCount++;
        if (pollCount > 60) {
            clearInterval(pollTimer);
            updateOverlay('fail', 'Timeout', 'No payment received. If you paid, contact support.');
            return;
        }
        fetch('../api/mpesa/hotspot_payment_status.php?checkout_request_id=' + encodeURIComponent(checkoutId) + '&client_id=' + clientId)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.status === 'completed') {
                clearInterval(pollTimer);
                updateOverlay('ok', 'Payment Confirmed!', 'Connecting you to the internet...');
                if (d.username && d.password && LINK_LOGIN) {
                    setTimeout(function(){ doConnect(d.username, d.password, d.portal_token); }, 1400);
                } else {
                    updateOverlay('ok', 'Account Activated', 'You can now sign in with your credentials.');
                }
            } else if (d.status === 'failed') {
                clearInterval(pollTimer);
                updateOverlay('fail', 'Payment Failed', d.message || 'Transaction was declined or cancelled.');
            }
        }).catch(function(){ /* ignore */ });
    }, 3000);
}

/* ── Connect to MikroTik hotspot ───────────────────────────── */
function doConnect(username, password, portalToken) {
    if (!LINK_LOGIN) return;
    var dst = '';
    if (portalToken) {
        dst = SERVER_ORIGIN + '/customer/auto_login.php?token=' + encodeURIComponent(portalToken);
    } else if (LINK_ORIG) {
        dst = LINK_ORIG;
    }
    var url = LINK_LOGIN + '?username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password);
    if (dst) url += '&dst=' + encodeURIComponent(dst);
    window.location.href = url;
}

/* ── Overlay helpers ───────────────────────────────────────── */
function showOverlay(title, msg, amount, phone) {
    document.getElementById('pay-overlay').classList.add('show');
    document.getElementById('ov-spinner').style.display = 'block';
    document.getElementById('ov-icon-ok').style.display = 'none';
    document.getElementById('ov-icon-fail').style.display = 'none';
    document.getElementById('ov-title').textContent = title;
    document.getElementById('ov-msg').textContent   = msg;
    document.getElementById('ov-close').style.display = 'none';
    var amtEl  = document.getElementById('ov-amount');
    var hintEl = document.getElementById('ov-hint');
    if (amount) { amtEl.textContent = 'KES ' + parseFloat(amount).toLocaleString(); amtEl.style.display='block'; }
    else { amtEl.style.display='none'; }
    if (phone) { hintEl.textContent = 'Sending to: ' + phone; hintEl.style.display='block'; }
    else { hintEl.style.display='none'; }
}
function updateOverlay(type, title, msg) {
    document.getElementById('ov-spinner').style.display   = 'none';
    document.getElementById('ov-icon-ok').style.display   = type==='ok'   ? 'flex':'none';
    document.getElementById('ov-icon-fail').style.display = type==='fail' ? 'flex':'none';
    document.getElementById('ov-title').textContent = title;
    document.getElementById('ov-msg').textContent   = msg;
    if (type==='fail') document.getElementById('ov-close').style.display='block';
}
function closeOverlay() {
    clearInterval(pollTimer);
    document.getElementById('pay-overlay').classList.remove('show');
}

/* ── API helper ────────────────────────────────────────────── */
function postApi(action, data) {
    data.action = action;
    return fetch('../api/mpesa/hotspot_stk_push.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    }).then(function(r){ return r.json(); });
}

/* ── Password toggle ───────────────────────────────────────── */
function togglePw(id, icon) {
    var inp = document.getElementById(id);
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}

<?php if ($isMikrotikPortal): ?>
// Auto-open "Get Connected" tab when first-time user reaches the portal
if (!document.cookie.match(/portal_visited/)) {
    document.cookie = 'portal_visited=1;path=/;max-age=3600';
    switchTab('connect');
}
<?php endif; ?>
</script>
</body>
</html>
