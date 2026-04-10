<?php
/**
 * Demo Seed Script
 * Creates a complete demo tenant + admin user + customer so you can
 * test the full user flow without real sign-ups.
 *
 * Usage:
 *   1. Open in browser: https://fortunetttech.site/tools/seed_demo.php
 *      (or http://localhost/fortunett_technologies_/tools/seed_demo.php)
 *   2. Read the credentials printed on screen.
 *   3. DELETE or restrict this file after use.
 *
 * Re-running is safe — it detects existing demo records and skips them.
 */

// --- Basic auth guard: only run locally or with a secret token ----------
$secret = 'fortunett_demo_2026';
$token  = $_GET['token'] ?? '';
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '212.95.34.211']);

if (!$isLocal && $token !== $secret) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2><p>Add <code>?token=' . $secret . '</code> to the URL to run this script.</p>');
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/tenant.php';

$log   = [];
$creds = [];

function logMsg(&$log, $msg, $ok = true) {
    $log[] = ['ok' => $ok, 'msg' => $msg];
}

// ── 1. Ensure required tables / columns exist ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `platform_subscription_plans` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(50) NOT NULL UNIQUE,
        pppoe_fee_per_user DECIMAL(10,2) DEFAULT 25.00,
        hotspot_commission_rate DECIMAL(5,4) DEFAULT 0.0300,
        base_monthly_fee DECIMAL(10,2) DEFAULT 0.00,
        max_clients INT DEFAULT NULL,
        max_routers INT DEFAULT NULL,
        trial_days INT DEFAULT 30,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO platform_subscription_plans (name, slug, pppoe_fee_per_user, hotspot_commission_rate, trial_days) VALUES ('Starter','starter',25.00,0.0300,30)");
    logMsg($log, 'platform_subscription_plans ensured');
} catch (Exception $e) {
    logMsg($log, 'Plans table: ' . $e->getMessage(), false);
}

// ── 2. Check / create demo tenant ───────────────────────────────────────────
$demoSubdomain = 'demo';
$demoCompany   = 'Demo ISP';
$adminUser     = 'demoadmin';
$adminEmail    = 'demoadmin@demo.fortunetttech.site';
$adminPassword = 'Demo@1234';

$existingTenant = null;
try {
    $st = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
    $st->execute([$demoSubdomain]);
    $existingTenant = $st->fetchColumn();
} catch (Exception $e) {}

$tenantId = $existingTenant;

if (!$existingTenant) {
    // Create admin user first
    $hash  = password_hash($adminPassword, PASSWORD_DEFAULT);
    $token_ver = bin2hex(random_bytes(16));
    try {
        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, is_verified, email_verified, verification_token)
             VALUES (?, ?, ?, 'admin', 1, 1, ?)"
        )->execute([$adminUser, $adminEmail, $hash, $token_ver]);
        $adminId = (int)$pdo->lastInsertId();
        logMsg($log, "Created admin user: $adminUser (id=$adminId)");
    } catch (Exception $e) {
        // May already exist
        $r = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $r->execute([$adminUser, $adminEmail]);
        $adminId = (int)($r->fetchColumn() ?: 0);
        logMsg($log, "Admin user already exists (id=$adminId)", true);
    }

    // Create tenant
    $tenantManager = TenantManager::getInstance($pdo);
    if ($tenantManager->isSubdomainAvailable($demoSubdomain)) {
        $tenantId = $tenantManager->createTenant($demoSubdomain, $demoCompany, $adminId);
        logMsg($log, "Created tenant: $demoSubdomain (id=$tenantId)");
    } else {
        $st = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
        $st->execute([$demoSubdomain]);
        $tenantId = (int)$st->fetchColumn();
        logMsg($log, "Tenant already exists (id=$tenantId)", true);
    }

    if ($tenantId && $adminId) {
        // Link user → tenant
        $prefix = 'D';
        $pdo->prepare("UPDATE users SET tenant_id=?, account_prefix=? WHERE id=?")->execute([$tenantId, $prefix, $adminId]);
        // Assign starter plan
        $planId = $pdo->query("SELECT id FROM platform_subscription_plans WHERE slug='starter' LIMIT 1")->fetchColumn();
        if ($planId) $pdo->prepare("UPDATE tenants SET subscription_plan_id=? WHERE id=?")->execute([$planId, $tenantId]);
    }
} else {
    // Fetch existing admin id
    try {
        $adminId = (int)$pdo->query("SELECT admin_user_id FROM tenants WHERE id=$existingTenant LIMIT 1")->fetchColumn();
    } catch (Exception $e) { $adminId = 0; }
    logMsg($log, "Using existing tenant id=$tenantId", true);
}

// ── 3. Create a demo package ─────────────────────────────────────────────────
$packageId = null;
try {
    $pkgCheck = $pdo->prepare("SELECT id FROM packages WHERE tenant_id=? AND name='Demo 10Mbps' LIMIT 1");
    $pkgCheck->execute([$tenantId]);
    $packageId = $pkgCheck->fetchColumn();

    if (!$packageId) {
        $pdo->prepare("
            INSERT INTO packages (name, type, price, download_speed, upload_speed, validity_value, validity_unit, status, tenant_id)
            VALUES ('Demo 10Mbps', 'pppoe', 1500.00, 10, 5, 30, 'days', 'active', ?)
        ")->execute([$tenantId]);
        $packageId = (int)$pdo->lastInsertId();
        logMsg($log, "Created package: Demo 10Mbps (id=$packageId)");
    } else {
        logMsg($log, "Package already exists (id=$packageId)", true);
    }
} catch (Exception $e) {
    logMsg($log, 'Package: ' . $e->getMessage(), false);
}

// ── 4. Create demo customer accounts ─────────────────────────────────────────
$customers = [
    [
        'full_name'    => 'Alice Wanjiru',
        'username'     => 'alice',
        'email'        => 'alice@example.com',
        'phone'        => '0712345678',
        'password'     => 'alice1234',
        'status'       => 'active',
        'connection'   => 'pppoe',
        'expiry_days'  => 20,
        'balance'      => 0,
    ],
    [
        'full_name'    => 'Bob Kamau',
        'username'     => 'bob',
        'email'        => 'bob@example.com',
        'phone'        => '0723456789',
        'password'     => 'bob12345',
        'status'       => 'active',
        'connection'   => 'hotspot',
        'expiry_days'  => 5,   // nearly expired — good for testing warnings
        'balance'      => 500,
    ],
    [
        'full_name'    => 'Carol Njeri',
        'username'     => 'carol',
        'email'        => 'carol@example.com',
        'phone'        => '0734567890',
        'password'     => 'carol123',
        'status'       => 'inactive',
        'connection'   => 'pppoe',
        'expiry_days'  => -3,  // already expired
        'balance'      => 0,
    ],
];

// Get the highest existing account number with prefix D
$maxAcct = 0;
try {
    $r = $pdo->prepare("SELECT account_number FROM clients WHERE tenant_id=? AND account_number LIKE 'D%' ORDER BY account_number DESC LIMIT 1");
    $r->execute([$tenantId]);
    $last = $r->fetchColumn();
    if ($last) $maxAcct = (int)ltrim(substr($last, 1), '0');
} catch (Exception $e) {}

$createdCustomers = [];

foreach ($customers as $c) {
    // Check if already exists
    try {
        $ex = $pdo->prepare("SELECT id, account_number FROM clients WHERE username=? AND tenant_id=? LIMIT 1");
        $ex->execute([$c['username'], $tenantId]);
        $existing = $ex->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            // Update password in case it changed
            $pdo->prepare("UPDATE clients SET auth_password=? WHERE id=?")->execute([password_hash($c['password'], PASSWORD_DEFAULT), $existing['id']]);
            $createdCustomers[] = array_merge($c, ['account_number' => $existing['account_number'], 'existed' => true]);
            logMsg($log, "Customer '{$c['username']}' already exists (account {$existing['account_number']})", true);
            continue;
        }
    } catch (Exception $e) {}

    $maxAcct++;
    $acctNum    = 'D' . str_pad($maxAcct, 3, '0', STR_PAD_LEFT);
    $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $c['expiry_days'] . ' days'));
    $hashPw     = password_hash($c['password'], PASSWORD_DEFAULT);

    try {
        $pdo->prepare("
            INSERT INTO clients
                (full_name, name, username, email, phone, status, connection_type, user_type,
                 auth_password, mikrotik_password, account_number, tenant_id,
                 package_id, package_price, expiry_date, account_balance, monthly_fee, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1500.00,?,?,1500.00,NOW())
        ")->execute([
            $c['full_name'], $c['full_name'], $c['username'],
            $c['email'], $c['phone'],
            $c['status'], $c['connection'], $c['connection'],
            $hashPw, $c['password'],   // auth_password (hashed) + mikrotik_password (plain, for backward compat)
            $acctNum, $tenantId,
            $packageId, $expiryDate,
            $c['balance'],
        ]);
        $createdCustomers[] = array_merge($c, ['account_number' => $acctNum, 'existed' => false]);
        logMsg($log, "Created customer: {$c['username']} / account $acctNum");
    } catch (Exception $e) {
        logMsg($log, "Customer '{$c['username']}': " . $e->getMessage(), false);
    }
}

// ── 5. Build output ───────────────────────────────────────────────────────────
$scheme        = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host          = $_SERVER['HTTP_HOST'] ?? 'localhost';
$tenantBaseUrl = $scheme . '://demo.' . preg_replace('/^demo\./', '', $host);
$adminLoginUrl = $tenantBaseUrl . '/login.php';
$custLoginUrl  = $tenantBaseUrl . '/customer/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demo Seed — FortuNett</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;padding:24px;color:#1e293b;}
.wrap{max-width:860px;margin:0 auto;}
h1{font-size:22px;font-weight:800;margin-bottom:6px;}
.subtitle{color:#64748b;font-size:14px;margin-bottom:24px;}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;overflow:hidden;}
.card-head{padding:14px 20px;background:#0f3460;color:#fff;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{text-align:left;padding:8px 12px;background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase;font-weight:700;border-bottom:1px solid #e2e8f0;}
td{padding:10px 12px;border-bottom:1px solid #f8fafc;vertical-align:top;}
tr:last-child td{border-bottom:none;}
code{background:#f1f5f9;padding:2px 8px;border-radius:5px;font-size:13px;font-family:monospace;}
.badge-ok{background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-warn{background:#fef9c3;color:#92400e;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-err{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.log-item{display:flex;gap:10px;align-items:flex-start;padding:6px 0;font-size:13px;border-bottom:1px solid #f1f5f9;}
.log-item:last-child{border-bottom:none;}
.icon{width:18px;text-align:center;flex-shrink:0;margin-top:1px;}
.warning{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#92400e;display:flex;gap:10px;align-items:flex-start;}
.btn{display:inline-block;padding:10px 22px;background:#0f3460;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;margin:4px;}
.btn-red{background:#dc2626;}
.link-wrap{word-break:break-all;}
</style>
</head>
<body>
<div class="wrap">
    <h1>🌱 Demo Data Seeded</h1>
    <p class="subtitle">A complete test tenant with customers has been created. Use the credentials below to test every part of the portal.</p>

    <div class="warning">
        <span style="font-size:18px;">⚠️</span>
        <div><strong>Delete this file after testing.</strong> It is accessible without login and creates real database records. Path: <code>/tools/seed_demo.php</code></div>
    </div>

    <!-- Admin credentials -->
    <div class="card">
        <div class="card-head">🔐 Tenant Admin Login</div>
        <div class="card-body">
            <table>
                <tr><th>Item</th><th>Value</th></tr>
                <tr><td>Dashboard URL</td><td class="link-wrap"><a href="<?= htmlspecialchars($adminLoginUrl) ?>" target="_blank"><?= htmlspecialchars($adminLoginUrl) ?></a></td></tr>
                <tr><td>Username</td><td><code><?= htmlspecialchars($adminUser) ?></code></td></tr>
                <tr><td>Password</td><td><code><?= htmlspecialchars($adminPassword) ?></code></td></tr>
                <tr><td>Subdomain</td><td><code><?= htmlspecialchars($demoSubdomain) ?>.<?= htmlspecialchars(preg_replace('/^demo\./','',$host)) ?></code></td></tr>
                <tr><td>Tenant ID</td><td><code><?= (int)$tenantId ?></code></td></tr>
            </table>
            <div style="margin-top:14px;">
                <a href="<?= htmlspecialchars($adminLoginUrl) ?>" target="_blank" class="btn">Open Admin Dashboard →</a>
            </div>
        </div>
    </div>

    <!-- Customer credentials -->
    <div class="card">
        <div class="card-head">👤 Customer Portal Logins</div>
        <div class="card-body">
            <p style="font-size:13px;color:#64748b;margin-bottom:14px;">Login at: <a href="<?= htmlspecialchars($custLoginUrl) ?>" target="_blank"><?= htmlspecialchars($custLoginUrl) ?></a></p>
            <table>
                <tr>
                    <th>Name</th><th>Username</th><th>Password</th><th>Account #</th><th>Status</th><th>Expiry</th><th>Balance</th>
                </tr>
                <?php foreach ($createdCustomers as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['full_name']) ?></td>
                    <td><code><?= htmlspecialchars($c['username']) ?></code></td>
                    <td><code><?= htmlspecialchars($c['password']) ?></code></td>
                    <td><code><?= htmlspecialchars($c['account_number']) ?></code></td>
                    <td>
                        <?php if ($c['status'] === 'active'): ?>
                            <span class="badge-ok">Active</span>
                        <?php else: ?>
                            <span class="badge-warn">Inactive</span>
                        <?php endif; ?>
                        <?php if (isset($c['existed']) && $c['existed']): ?>
                            <span class="badge-warn">Pre-existing</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['expiry_days'] < 0): ?>
                            <span class="badge-err">Expired <?= abs($c['expiry_days']) ?>d ago</span>
                        <?php elseif ($c['expiry_days'] <= 7): ?>
                            <span class="badge-warn">Expires in <?= $c['expiry_days'] ?>d</span>
                        <?php else: ?>
                            <span class="badge-ok">+<?= $c['expiry_days'] ?> days</span>
                        <?php endif; ?>
                    </td>
                    <td>KES <?= number_format($c['balance'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div style="margin-top:14px;">
                <a href="<?= htmlspecialchars($custLoginUrl) ?>" target="_blank" class="btn">Open Customer Portal →</a>
            </div>
        </div>
    </div>

    <!-- What to test -->
    <div class="card">
        <div class="card-head">✅ Testing Checklist</div>
        <div class="card-body">
            <table>
                <tr><th>#</th><th>Test</th><th>How</th></tr>
                <tr><td>1</td><td>Tenant admin login</td><td>Login with <code>demoadmin / Demo@1234</code> at the admin URL above</td></tr>
                <tr><td>2</td><td>Subdomain isolation</td><td>Confirm you only see "Demo ISP" data — no other tenants</td></tr>
                <tr><td>3</td><td>Customer portal login</td><td>Login as <code>alice / alice1234</code> at the customer URL above</td></tr>
                <tr><td>4</td><td>Expiry warning</td><td>Login as <code>bob / bob12345</code> — should show "5 days left" warning</td></tr>
                <tr><td>5</td><td>Expired subscription</td><td>Login as <code>carol / carol123</code> — should show expired state</td></tr>
                <tr><td>6</td><td>Payment initiation</td><td>As alice, go to Payments → initiate STK push (sandbox will respond)</td></tr>
                <tr><td>7</td><td>Admin: add router</td><td>In admin dashboard → Routers → Add Router (use any test IP)</td></tr>
                <tr><td>8</td><td>Admin: add client</td><td>Admin → Clients → New Client → verify auto account number (D004+)</td></tr>
                <tr><td>9</td><td>Super admin view</td><td>Login at <code>/super_admin/login.php</code> → Tenants → confirm "Demo ISP" appears</td></tr>
                <tr><td>10</td><td>System settings</td><td>Super Admin → System Settings → configure SMTP → test email</td></tr>
            </table>
        </div>
    </div>

    <!-- Seed log -->
    <div class="card">
        <div class="card-head">📋 Seed Log</div>
        <div class="card-body">
            <?php foreach ($log as $entry): ?>
            <div class="log-item">
                <span class="icon"><?= $entry['ok'] ? '✓' : '✗' ?></span>
                <span style="color:<?= $entry['ok'] ? '#166534' : '#991b1b' ?>;"><?= htmlspecialchars($entry['msg']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <p style="font-size:12px;color:#94a3b8;margin-top:16px;">
        Generated at <?= date('Y-m-d H:i:s') ?> &bull; Server: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?>
    </p>
</div>
</body>
</html>
