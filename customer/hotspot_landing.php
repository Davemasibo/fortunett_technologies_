<?php
/**
 * Hotspot Post-Auth Landing Page
 *
 * MikroTik redirects here after successful hotspot authentication.
 * We look up the client by their MAC address from the router's active
 * hotspot sessions, create a short-lived token, and log them into
 * the customer portal automatically.
 *
 * URL: /customer/hotspot_landing.php?mac={mac-esc}
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/includes/auth.php';
// A server-issued token proves payment/credential authentication. URL usernames
// and MAC addresses alone must never establish a customer account session.
if(!empty($_GET['token'])){
    header('Location: auto_login.php?token='.rawurlencode((string)$_GET['token']));exit;
}
if(getCurrentCustomer()){header('Location: dashboard.php');exit;}
$tenantId=customerHostTenant($pdo);
$branding=['name'=>'Customer Portal','color'=>'#0f3460'];
if($tenantId){$st=$pdo->prepare('SELECT company_name FROM tenants WHERE id=?');$st->execute([$tenantId]);$branding['name']=$st->fetchColumn()?:$branding['name'];}

// Auto-login failed — show a friendly page with a manual login link
require_once __DIR__ . '/includes/theme.php';
$customerAppearance = customerThemeData($pdo, (int)($tenantId ?? 0));
$branding['color'] = $customerAppearance['accent'];
$branding['gradient'] = $customerAppearance['accent'];
if ($customerAppearance['logo'] !== '') $branding['logo'] = $customerAppearance['logo'];
$hex = ltrim($branding['color'], '#');
if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
$r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connected — <?php echo htmlspecialchars($branding['name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- The shared customer design system. Loaded before auth.css so the
         auth card styling still wins where the two overlap, and so these
         pages stop re-declaring components that already exist. -->
    <link rel="stylesheet" href="css/customer.css?v=<?php echo @filemtime(__DIR__ . '/css/customer.css') ?: 1; ?>">
    <link rel="stylesheet" href="../css/auth.css?v=3">
<style>
:root{
  --brand:<?php echo $branding['color'];?>;
  --brand-glow:rgba(<?php echo "$r,$g,$b";?>,0.38);
  --brand-gradient:linear-gradient(135deg,<?php echo $branding['color'];?> 0%,<?php echo $branding['color'];?>99 100%);
}
</style>
<?php require_once __DIR__ . '/includes/theme.php'; customerThemeHead($pdo, (int)($tenantId ?? 0)); ?>
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-header">
        <div class="auth-icon-wrap">
            <i class="fas fa-wifi"></i>
        </div>
        <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        <p>You're connected to the internet</p>
    </div>
    <div class="auth-body">
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:12px;padding:16px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-check-circle" style="color:#34d399;font-size:20px;"></i>
            <div>
                <div style="font-weight:700;color:#6ee7b7;font-size:14px;">Connected Successfully</div>
                <div style="font-size:12px;color:var(--ink-dim);margin-top:2px;">Sign in below to manage your account</div>
            </div>
        </div>
        <div style="text-align:center;margin-bottom:18px;color:var(--ink-dim);font-size:13px;">
            Sign in to your customer account to view your subscription, usage, and make payments.
        </div>
        <a href="login.php" class="btn-auth" style="display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
            <span>Sign In to My Account</span>
            <i class="fas fa-arrow-right"></i>
        </a>
        <div class="auth-link" style="margin-top:16px;">
            New customer? <a href="register.php" style="color:var(--brand);font-weight:600;">Create an account</a>
        </div>
    </div>
</div>
</body>
</html>
