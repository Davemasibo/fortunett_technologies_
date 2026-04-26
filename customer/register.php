<?php
/**
 * Customer Self-Registration
 *
 * New hotspot customers can create their own account, pick a package
 * (including any free/trial plan), and get provisioned automatically.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → go to dashboard
if (!empty($_SESSION['customer_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';
require_once __DIR__ . '/../includes/account_number_generator.php';

// ── Resolve tenant from subdomain ─────────────────────────────────────────────
$host      = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = explode('.', $host)[0];
$tenantId  = null;
$branding  = ['name' => 'Customer Portal', 'color' => '#0f3460', 'gradient' => 'linear-gradient(135deg,#1a1a2e 0%,#0f3460 100%)'];

if (!in_array($subdomain, ['localhost', 'www']) && !filter_var($host, FILTER_VALIDATE_IP)) {
    try {
        $tSt = $pdo->prepare("SELECT t.id, t.company_name, ts.setting_key, ts.setting_value
            FROM tenants t LEFT JOIN tenant_settings ts ON ts.tenant_id = t.id
            WHERE t.subdomain = ? LIMIT 20");
        $tSt->execute([$subdomain]);
        $rows = $tSt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $tenantId        = (int)$rows[0]['id'];
            $branding['name'] = $rows[0]['company_name'];
            foreach ($rows as $r) {
                if ($r['setting_key'] === 'brand_color' && $r['setting_value']) {
                    $branding['color']    = $r['setting_value'];
                    $branding['gradient'] = "linear-gradient(135deg,{$r['setting_value']} 0%,{$r['setting_value']}99 100%)";
                }
            }
        }
    } catch (Exception $_e) {}
}

// Fetch available packages for this tenant
$packages = [];
if ($tenantId) {
    try {
        $pkgSt = $pdo->prepare("
            SELECT id, name, price, download_speed, upload_speed, validity_value, validity_unit,
                   COALESCE(connection_type, 'hotspot') AS connection_type, mikrotik_profile, hotspot_server
            FROM packages
            WHERE tenant_id = ? AND status = 'active'
              AND COALESCE(NULLIF(connection_type,''), 'hotspot') = 'hotspot'
            ORDER BY price ASC, name ASC
        ");
        $pkgSt->execute([$tenantId]);
        $packages = $pkgSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $_e) {}
}

$error   = '';
$success = '';

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenantId) {
    $fullName  = trim($_POST['full_name']  ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $username  = trim($_POST['username']   ?? '');
    $password  = trim($_POST['password']   ?? '');
    $packageId = (int)($_POST['package_id'] ?? 0);

    if (!$fullName || !$phone || !$username || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        // Check username / phone uniqueness within tenant
        $dupSt = $pdo->prepare(
            "SELECT id FROM clients WHERE tenant_id = ? AND (username = ? OR phone = ?) LIMIT 1"
        );
        $dupSt->execute([$tenantId, $username, $phone]);
        if ($dupSt->fetchColumn()) {
            $error = 'That username or phone number is already registered. Please sign in instead.';
        } else {
            try {
                // Generate account number
                $gen           = new AccountNumberGenerator($pdo);
                $accountNumber = $gen->generateAccountNumber($tenantId);

                // Verify selected package belongs to tenant
                $selectedPkg = null;
                if ($packageId) {
                    foreach ($packages as $p) {
                        if ((int)$p['id'] === $packageId) { $selectedPkg = $p; break; }
                    }
                }

                $isFree   = $selectedPkg && (float)$selectedPkg['price'] == 0;
                $status   = $isFree ? 'active' : 'pending';
                $expiry   = null;
                if ($isFree && $selectedPkg) {
                    $val  = (int)($selectedPkg['validity_value'] ?? 30);
                    $unit = $selectedPkg['validity_unit'] ?? 'days';
                    $map  = ['hours' => 'hour', 'days' => 'day', 'weeks' => 'week', 'months' => 'month'];
                    $u    = $map[$unit] ?? 'day';
                    $expiry = date('Y-m-d H:i:s', strtotime("+$val $u"));
                }

                // Create client
                $pdo->prepare("
                    INSERT INTO clients
                        (tenant_id, full_name, name, phone, username, auth_password,
                         account_number, package_id, connection_type, status, expiry_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'hotspot', ?, ?)
                ")->execute([
                    $tenantId, $fullName, $fullName, $phone, $username,
                    password_hash($password, PASSWORD_BCRYPT),
                    $accountNumber,
                    $selectedPkg ? $selectedPkg['id'] : null,
                    $status,
                    $expiry,
                ]);
                $clientId = (int)$pdo->lastInsertId();

                // Auto-provision to router if free/trial package
                if ($isFree) {
                    try {
                        require_once __DIR__ . '/../includes/auto_provision.php';
                        autoProvisionClient($pdo, $clientId, $tenantId);
                    } catch (Throwable $_provEx) {
                        error_log('[register] provision failed: ' . $_provEx->getMessage());
                    }
                }

                // Auto-login the new customer
                $token = bin2hex(random_bytes(16));
                $pdo->prepare(
                    "INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 SECOND), 'pending')"
                )->execute([$clientId, $token]);

                $auth   = new CustomerAuth($pdo);
                $result = $auth->autoLogin($token);
                if ($result['success']) {
                    $_SESSION['customer_token'] = $result['session_token'];
                    $_SESSION['customer_data']  = $result['client'] ?? [];
                    header('Location: dashboard.php');
                    exit;
                }

                $success = 'Account created! You can now sign in.';

            } catch (Throwable $e) {
                error_log('[register] ' . $e->getMessage());
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$hex = ltrim($branding['color'], '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up — <?php echo htmlspecialchars($branding['name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../css/auth.css?v=3">
<style>
:root{
    --brand:<?php echo $branding['color'];?>;
    --brand-glow:rgba(<?php echo "$r,$g,$b";?>,0.38);
    --brand-gradient:<?php echo $branding['gradient'];?>;
}
.pkg-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px;}
@media(max-width:400px){.pkg-grid{grid-template-columns:1fr;}}
.pkg-card{
    position:relative;border:1px solid rgba(255,255,255,.08);border-radius:12px;
    padding:12px;cursor:pointer;transition:all .18s;background:rgb(27,27,26);
    box-shadow:inset 2px 2px 5px rgb(10,10,9),inset -1px -1px 4px rgb(42,42,40);
}
.pkg-card:hover{border-color:rgba(255,255,255,.18);}
.pkg-card.selected{border-color:var(--brand);box-shadow:0 0 0 2px var(--brand),inset 2px 2px 5px rgb(10,10,9);}
.pkg-card input[type=radio]{position:absolute;opacity:0;width:0;height:0;}
.pkg-card-name{font-weight:700;font-size:13px;color:#e2e2e0;margin-bottom:3px;}
.pkg-card-meta{font-size:11px;color:rgba(255,255,255,.4);}
.pkg-card-price{font-size:15px;font-weight:800;color:var(--brand);margin-top:6px;}
.pkg-card-free{font-size:11px;color:#34d399;font-weight:700;margin-top:4px;}
.pkg-card-check{
    position:absolute;top:8px;right:8px;
    width:18px;height:18px;border-radius:50%;
    border:2px solid rgba(255,255,255,.2);
    background:transparent;transition:all .15s;
    display:flex;align-items:center;justify-content:center;
}
.pkg-card.selected .pkg-card-check{border-color:var(--brand);background:var(--brand);}
.pkg-card.selected .pkg-card-check::after{content:'✓';color:#fff;font-size:10px;font-weight:700;}
</style>
</head>
<body class="auth-page">
<div class="auth-container" style="max-width:480px;">
    <div class="auth-header">
        <div class="auth-icon-wrap">
            <i class="fas fa-user-plus"></i>
        </div>
        <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        <p>Create your internet account</p>
    </div>
    <div class="auth-body">
        <div class="auth-subtitle">
            <h2>New Account</h2>
            <p>Fill in your details to get started</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" id="regForm">
            <!-- Package selection -->
            <?php if (!empty($packages)): ?>
            <div class="form-group">
                <label>Choose a Package <span class="required">*</span></label>
                <div class="pkg-grid">
                    <?php foreach ($packages as $pkg):
                        $isFree = (float)$pkg['price'] == 0;
                        $dur    = ($pkg['validity_value'] ?? 1) . ' ' . ucfirst($pkg['validity_unit'] ?? 'days');
                        $speed  = !empty($pkg['download_speed']) ? $pkg['download_speed'] . ' Mbps' : '';
                        $posted = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
                        $sel    = ($posted === (int)$pkg['id']);
                    ?>
                    <label class="pkg-card<?php echo $sel ? ' selected' : ''; ?>" id="pkg-<?php echo $pkg['id']; ?>">
                        <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>"
                               onchange="selectPkg(this)"
                               <?php echo $sel ? 'checked' : ''; ?>>
                        <div class="pkg-card-check"></div>
                        <div class="pkg-card-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
                        <div class="pkg-card-meta"><?php echo htmlspecialchars($speed . ($speed ? ' · ' : '') . $dur); ?></div>
                        <?php if ($isFree): ?>
                        <div class="pkg-card-free"><i class="fas fa-gift"></i> Free</div>
                        <?php else: ?>
                        <div class="pkg-card-price">KES <?php echo number_format((float)$pkg['price'], 0); ?></div>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:11px;color:rgba(255,255,255,.35);margin-top:4px;">
                    Free packages are activated immediately. Paid packages require an M-Pesa payment after registration.
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" class="form-control-auth" required
                       placeholder="e.g. John Kamau"
                       value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label>Phone Number <span class="required">*</span></label>
                <input type="tel" name="phone" class="form-control-auth" required
                       placeholder="e.g. 0712345678"
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
            </div>

            <div class="form-group">
                <label>Username <span class="required">*</span></label>
                <input type="text" name="username" class="form-control-auth" required
                       placeholder="Choose a username"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>

            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password" class="form-control-auth"
                           required placeholder="At least 6 characters" style="padding-right:44px;">
                    <i class="fas fa-eye password-toggle" onclick="togglePw('password',this)"></i>
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <span>Create Account</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div class="auth-link">
            Already have an account? <a href="login.php" style="color:var(--brand);font-weight:600;">Sign in</a>
        </div>
    </div>
</div>

<script>
function selectPkg(radio) {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('pkg-' + radio.value).classList.add('selected');
}
function togglePw(id, icon) {
    const inp  = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !show);
    icon.classList.toggle('fa-eye-slash', show);
}
</script>
</body>
</html>
