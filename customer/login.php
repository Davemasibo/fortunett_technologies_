<?php
/**
 * Customer Portal Login
 *
 * Standard customer self-service login page.
 * NOT used as a MikroTik captive portal — see login.html for that.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['customer_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';

$error   = '';
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

// ── Handle login form submission ───────────────────────────────────────────────
$loggedInClient = null;
$expiredClient  = null;
$_signinToken   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginId  = trim($_POST['username'] ?? '');
    $loginPwd = trim($_POST['password'] ?? '');

    if (!$loginId || !$loginPwd) {
        $error = 'Please enter your username and password.';
    } elseif (!$tenantId) {
        $error = 'Portal not configured. Contact support.';
    } else {
        try {
            // Normalize phone: 07xx → 254xx
            $loginPhone254 = $loginId;
            if (preg_match('/^0[0-9]{9}$/', $loginId)) {
                $loginPhone254 = '254' . substr($loginId, 1);
            }

            $clSt = $pdo->prepare("
                SELECT c.*, p.name AS pkg_name, p.price AS pkg_price,
                       p.validity_value, p.validity_unit
                FROM clients c
                LEFT JOIN packages p ON p.id = c.package_id
                WHERE c.tenant_id = ?
                  AND (c.username = ? OR c.phone = ? OR c.phone = ? OR c.account_number = ?)
                LIMIT 1
            ");
            $clSt->execute([$tenantId, $loginId, $loginId, $loginPhone254, $loginId]);
            $foundClient = $clSt->fetch(PDO::FETCH_ASSOC);

            if (!$foundClient) {
                $error = 'Account not found. Check your credentials or register a new account.';
            } else {
                $pwMatch = password_verify($loginPwd, $foundClient['auth_password'] ?? '')
                        || ($loginPwd === ($foundClient['mikrotik_password'] ?? "\0NEVER\0"));
                if (!$pwMatch) {
                    $error = 'Incorrect password. Please try again.';
                } elseif ($foundClient['status'] === 'suspended') {
                    $error = 'Account suspended. Contact your ISP.';
                } elseif ($foundClient['status'] === 'pending') {
                    $error = 'Account pending activation. Please pay for a package first.';
                } elseif ($foundClient['status'] === 'inactive'
                       || (!empty($foundClient['expiry_date']) && strtotime($foundClient['expiry_date']) < time())) {
                    $expiredClient = $foundClient;
                } else {
                    $loggedInClient = $foundClient;
                    // Create portal auto-login token
                    try {
                        $_signinToken = bin2hex(random_bytes(16));
                        $pdo->prepare("INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')")
                            ->execute([$loggedInClient['id'], $_signinToken]);
                    } catch (Exception $_e) { $_signinToken = null; }
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

$__proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__origin = $__proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($branding['name']); ?> — Customer Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../css/auth.css?v=5">
<style>
:root{
    --brand:<?php echo $branding['color'];?>;
    --brand-glow:rgba(<?php echo "$r,$g,$b";?>,0.38);
    --brand-gradient:<?php echo $branding['gradient'];?>;
}
body.auth-page { padding: 16px; }

/* Connected banner */
.connected-banner{text-align:center;padding:24px;background:rgba(52,211,153,.08);
    border:1px solid rgba(52,211,153,.2);border-radius:14px;margin-bottom:20px;}
.connected-banner i{font-size:32px;color:#34d399;margin-bottom:10px;display:block;}
.connected-banner h3{font-size:18px;font-weight:700;color:#e8e8e6;margin-bottom:6px;}
.connected-banner p{font-size:13px;color:rgba(255,255,255,.5);}

/* Expired banner */
.expired-banner{text-align:center;padding:22px;background:rgba(248,113,113,.08);
    border:1px solid rgba(248,113,113,.25);border-radius:14px;margin-bottom:20px;}
.expired-banner i{font-size:30px;color:#f87171;margin-bottom:10px;display:block;}
.expired-banner h3{font-size:16px;font-weight:700;color:#fca5a5;margin-bottom:6px;}
.expired-banner p{font-size:13px;color:rgba(255,255,255,.45);margin-bottom:14px;}

/* Inline alerts */
.p-alert{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border-radius:9px;
    font-size:13px;line-height:1.5;margin-bottom:14px;}
.p-alert-err{background:rgba(239,68,68,.12);border-left:4px solid #ef4444;color:#fca5a5;}
.p-alert-ok{background:rgba(34,197,94,.12);border-left:4px solid #22c55e;color:#86efac;}
.p-alert i{flex-shrink:0;margin-top:2px;}

/* Small link button */
.lnk{background:none;border:none;color:var(--brand);filter:brightness(1.5);font-size:13px;
    font-weight:600;cursor:pointer;padding:0;text-decoration:underline;font-family:inherit;}

.sec-title{font-size:16px;font-weight:700;color:#e8e8e6;margin-bottom:4px;}
.sec-sub{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:20px;}
</style>
</head>
<body class="auth-page">
<div class="auth-container">

    <!-- Header -->
    <div class="auth-header">
        <?php if (!empty($branding['logo'])): ?>
            <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo"
                 style="height:48px;margin:0 auto 14px;display:block;object-fit:contain;">
        <?php else: ?>
            <div class="auth-icon-wrap"><i class="fas fa-wifi"></i></div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        <p>Customer Portal</p>
    </div>

    <div class="auth-body">

    <?php if ($loggedInClient): ?>
        <!-- Already logged in — redirect to dashboard -->
        <div class="connected-banner">
            <i class="fas fa-check-circle"></i>
            <h3>Welcome, <?php echo htmlspecialchars(explode(' ', $loggedInClient['full_name'] ?? $loggedInClient['username'])[0]); ?>!</h3>
            <p>Redirecting to your dashboard...</p>
        </div>
        <script>
        (function(){
            var tok    = <?php echo json_encode($_signinToken); ?>;
            var origin = <?php echo json_encode($__origin); ?>;
            if (tok) {
                window.location.href = origin + '/customer/auto_login.php?token=' + encodeURIComponent(tok);
            } else {
                window.location.href = origin + '/customer/dashboard.php';
            }
        })();
        </script>

    <?php elseif ($expiredClient): ?>
        <?php
        $_isInactive = ($expiredClient['status'] === 'inactive');
        $_firstName  = htmlspecialchars(explode(' ', $expiredClient['full_name'] ?? '')[0]);
        ?>
        <!-- Expired / inactive account -->
        <div class="expired-banner">
            <i class="fas fa-<?php echo $_isInactive ? 'hourglass-half' : 'clock'; ?>"></i>
            <?php if ($_isInactive): ?>
                <h3>Account Not Yet Active</h3>
                <p>Hi <?php echo $_firstName; ?>, your account hasn't been activated yet. Contact your ISP to activate your service.</p>
            <?php else: ?>
                <h3>Subscription Expired</h3>
                <p>Hi <?php echo $_firstName; ?>, your internet package expired on
                   <?php echo date('d M Y', strtotime($expiredClient['expiry_date'] ?? 'now')); ?>.</p>
            <?php endif; ?>
        </div>
        <?php if (!$_isInactive): ?>
        <a href="register.html?account=<?php echo urlencode($expiredClient['account_number'] ?? $expiredClient['phone'] ?? ''); ?>"
           class="btn-auth" style="text-decoration:none;display:flex;margin-bottom:14px;">
            <i class="fas fa-sync-alt"></i><span>Renew Subscription</span>
        </a>
        <?php endif; ?>
        <div class="auth-link" style="text-align:center;">
            <button class="lnk" onclick="this.closest('div').previousElementSibling.style.display='none';document.getElementById('login-again-form').style.display='block';this.closest('div').style.display='none';">
                Try signing in again
            </button>
        </div>
        <div id="login-again-form" style="display:none;margin-top:18px;">
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Username / Phone / Account No. <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control-auth" placeholder="e.g. john or 0712345678">
                </div>
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="repwd" class="form-control-auth"
                               placeholder="Password" style="padding-right:44px;">
                        <i class="fas fa-eye password-toggle" onclick="togglePw('repwd',this)"></i>
                    </div>
                </div>
                <button type="submit" class="btn-auth"><span>Sign In</span><i class="fas fa-arrow-right"></i></button>
            </form>
        </div>

    <?php else: ?>

        <!-- Normal login form -->
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

        <div class="auth-link" style="margin-top:18px;text-align:center;">
            New here? <a href="register.html" class="lnk" style="text-decoration:none;">Browse packages &rarr;</a>
        </div>

    <?php endif; ?>

    </div><!-- /.auth-body -->
</div><!-- /.auth-container -->

<script>
function togglePw(id, icon) {
    var inp  = document.getElementById(id);
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye',       !show);
    icon.classList.toggle('fa-eye-slash', show);
}
</script>
</body>
</html>
