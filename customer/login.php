<?php
/**
 * Customer Portal Login
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['customer_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';

$error   = '';
$success = '';

if (isset($_GET['logged_out']))      $success = 'You have been logged out successfully.';
if (isset($_GET['session_expired'])) $error   = 'Your session has expired. Please log in again.';

// Resolve tenant branding from subdomain
$branding = [
    'name'       => 'Customer Portal',
    'color'      => '#0f3460',
    'gradient'   => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)',
];

$host      = $_SERVER['HTTP_HOST'];
$hostParts = explode('.', $host);
$subdomain = $hostParts[0];

if (!in_array($subdomain, ['localhost', 'www']) && !filter_var($host, FILTER_VALIDATE_IP)) {
    try {
        $st = $pdo->prepare("SELECT t.id, t.company_name, ts.setting_key, ts.setting_value
            FROM tenants t
            LEFT JOIN tenant_settings ts ON ts.tenant_id = t.id
            WHERE t.subdomain = ? LIMIT 20");
        $st->execute([$subdomain]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $branding['name'] = $rows[0]['company_name'];
            foreach ($rows as $r) {
                if ($r['setting_key'] === 'brand_color' && $r['setting_value']) {
                    $branding['color']    = $r['setting_value'];
                    $branding['gradient'] = "linear-gradient(135deg, {$r['setting_value']} 0%, {$r['setting_value']}99 100%)";
                }
            }
        }
    } catch (Exception $e) {}
}

// Handle POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$username || !$password) {
        $error = 'Please enter your username and password.';
    } else {
        $auth   = new CustomerAuth($pdo);
        $result = $auth->login($username, $password);

        if ($result['success']) {
            $_SESSION['customer_token'] = $result['session_token'];
            $_SESSION['customer_data']  = $result['client'] ?? [];
            $redirect = $_GET['redirect'] ?? 'dashboard.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['message'] ?? 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login — <?php echo htmlspecialchars($branding['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/auth.css?v=3">
    <?php
        $hex = ltrim($branding['color'], '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    ?>
    <style>
        :root {
            --brand:          <?php echo $branding['color']; ?>;
            --brand-glow:     rgba(<?php echo "$r,$g,$b"; ?>, 0.38);
            --brand-gradient: <?php echo $branding['gradient']; ?>;
        }
    </style>
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-icon-wrap">
                <i class="fas fa-wifi"></i>
            </div>
            <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
            <p>Customer Self-Service Portal</p>
        </div>

        <div class="auth-body">
            <div class="auth-subtitle">
                <h2>Welcome Back</h2>
                <p>Sign in to your account</p>
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

            <form method="POST">
                <div class="form-group">
                    <label>Username / Phone / Email <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control-auth" required autofocus
                           placeholder="e.g. A-0001 or 0712345678"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="form-control-auth"
                               required placeholder="Enter your password" style="padding-right:44px;">
                        <i class="fas fa-eye password-toggle" onclick="togglePw('password', this)"></i>
                    </div>
                </div>

                <div class="forgot-row">
                    <a href="#">Having trouble? Contact your ISP</a>
                </div>

                <button type="submit" class="btn-auth">
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="auth-link">
                New customer? <a href="register.php" style="color:var(--brand);font-weight:600;">Create an account</a>
            </div>
        </div>
    </div>

    <script>
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
