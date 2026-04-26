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

// If already logged in, go straight to dashboard
if (!empty($_SESSION['customer_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/CustomerAuth.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

// ── Resolve tenant from subdomain ─────────────────────────────────────────────
$host      = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = explode('.', $host)[0];
$tenantId  = null;
$branding  = ['name' => 'Customer Portal', 'color' => '#0f3460'];

try {
    $tSt = $pdo->prepare("SELECT id, company_name, brand_color FROM tenants WHERE subdomain = ? LIMIT 1");
    $tSt->execute([$subdomain]);
    $tenant = $tSt->fetch(PDO::FETCH_ASSOC);
    if ($tenant) {
        $tenantId        = (int)$tenant['id'];
        $branding['name']  = $tenant['company_name'] ?: $branding['name'];
        $branding['color'] = $tenant['brand_color']  ?: $branding['color'];
    }
} catch (Exception $_e) {}

$mac = trim($_GET['mac'] ?? '');

// ── Attempt auto-login by MAC ──────────────────────────────────────────────────
$client    = null;
$loginDone = false;

if ($tenantId && $mac) {
    try {
        // Fetch all active routers for this tenant
        $rSt = $pdo->prepare(
            "SELECT id, ip_address, vpn_ip, username, password, api_port
             FROM mikrotik_routers
             WHERE tenant_id = ? AND status IN ('active','online')
             ORDER BY id ASC"
        );
        $rSt->execute([$tenantId]);
        $routers = $rSt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routers as $router) {
            $port      = (int)($router['api_port'] ?: 8728);
            $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];

            // Quick reachability probe before full API handshake
            $fp = @fsockopen($connectIp, $port, $errno, $errstr, 2);
            if (!$fp) continue;
            fclose($fp);

            try {
                $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                $mk->connect();
                $sessions = $mk->comm('/ip/hotspot/active/print');
                $mk->disconnect();

                $hotspotUser = null;
                foreach ($sessions as $sess) {
                    if (!isset($sess['!re'])) continue;
                    $sessMac = strtolower(str_replace(['-', ':'], '', $sess['mac-address'] ?? ''));
                    $reqMac  = strtolower(str_replace(['-', ':', '%3A', '%3a'], '', $mac));
                    if ($sessMac === $reqMac) {
                        $hotspotUser = $sess['user'] ?? null;
                        break;
                    }
                }

                if ($hotspotUser) {
                    // Find the client record by their MikroTik username
                    $cSt = $pdo->prepare(
                        "SELECT * FROM clients WHERE mikrotik_username = ? AND tenant_id = ? LIMIT 1"
                    );
                    $cSt->execute([$hotspotUser, $tenantId]);
                    $client = $cSt->fetch(PDO::FETCH_ASSOC);
                    break;
                }
            } catch (Exception $_apiEx) {
                error_log('[hotspot_landing] router ' . $router['id'] . ': ' . $_apiEx->getMessage());
            }
        }

        // Create a short-lived auto-login token and use it to build a portal session
        if ($client) {
            $token = bin2hex(random_bytes(16));
            try {
                $pdo->prepare(
                    "INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 SECOND), 'pending')"
                )->execute([$client['id'], $token]);
            } catch (Exception $_e) {
                // Table may have extra columns — try minimal insert
                $pdo->prepare(
                    "INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 SECOND), 'pending')"
                )->execute([$client['id'], $token]);
            }

            $auth   = new CustomerAuth($pdo);
            $result = $auth->autoLogin($token, $_SERVER['REMOTE_ADDR'] ?? null, $mac);

            if ($result['success']) {
                $_SESSION['customer_token'] = $result['session_token'];
                $_SESSION['customer_data']  = $result['client'] ?? [];
                $loginDone = true;
            }
        }
    } catch (Exception $_e) {
        error_log('[hotspot_landing] ' . $_e->getMessage());
    }
}

// ── Redirect or show fallback ──────────────────────────────────────────────────
if ($loginDone) {
    header('Location: dashboard.php');
    exit;
}

// Auto-login failed — show a friendly page with a manual login link
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
<link rel="stylesheet" href="../css/auth.css?v=3">
<style>
:root{
  --brand:<?php echo $branding['color'];?>;
  --brand-glow:rgba(<?php echo "$r,$g,$b";?>,0.38);
  --brand-gradient:linear-gradient(135deg,<?php echo $branding['color'];?> 0%,<?php echo $branding['color'];?>99 100%);
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
        <p>You're connected to the internet</p>
    </div>
    <div class="auth-body">
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:12px;padding:16px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-check-circle" style="color:#34d399;font-size:20px;"></i>
            <div>
                <div style="font-weight:700;color:#6ee7b7;font-size:14px;">Connected Successfully</div>
                <div style="font-size:12px;color:rgba(255,255,255,.5);margin-top:2px;">Sign in below to manage your account</div>
            </div>
        </div>
        <div style="text-align:center;margin-bottom:18px;color:rgba(255,255,255,.45);font-size:13px;">
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
