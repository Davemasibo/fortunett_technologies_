<?php
/**
 * Safe Migration Runner — SaaS Upgrade
 * Visit this in your browser ONCE, then delete the file.
 */
require_once __DIR__ . '/includes/db_master.php';

// Only allow from localhost
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    die('Access denied. Run from localhost only.');
}

$results = [];

function runSql(PDO $pdo, string $label, string $sql, bool $optional = false): array {
    try {
        $pdo->exec($sql);
        return ['label' => $label, 'status' => 'ok', 'msg' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $harmless = [
            '1060', // Duplicate column name
            '1061', // Duplicate key name
            '1050', // Table already exists
            '1826', // Duplicate FK constraint name
            '1826', // FK already exists
        ];
        foreach ($harmless as $code) {
            if (strpos($msg, $code) !== false) {
                return ['label' => $label, 'status' => 'skip', 'msg' => 'Already exists — skipped'];
            }
        }
        if ($optional && strpos($msg, '1146') !== false) {
            return ['label' => $label, 'status' => 'skip', 'msg' => 'Table not present — skipped (optional)'];
        }
        return ['label' => $label, 'status' => 'error', 'msg' => $msg];
    }
}

// ── 1. platform_subscription_plans ───────────────────────────────
$results[] = runSql($pdo, 'Create platform_subscription_plans', "
CREATE TABLE IF NOT EXISTS platform_subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    pppoe_fee_per_user DECIMAL(10,2) NOT NULL DEFAULT 25.00,
    hotspot_commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0300,
    base_monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_clients INT DEFAULT NULL,
    max_routers INT DEFAULT NULL,
    trial_days INT NOT NULL DEFAULT 30,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$results[] = runSql($pdo, 'Seed subscription plans (Starter/Growth/Enterprise)', "
INSERT IGNORE INTO platform_subscription_plans
    (name, slug, pppoe_fee_per_user, hotspot_commission_rate, base_monthly_fee, max_clients, max_routers, trial_days)
VALUES
    ('Starter',    'starter',    25.00, 0.0300, 0.00,    200,  3,   30),
    ('Growth',     'growth',     20.00, 0.0250, 500.00,  1000, 10,  30),
    ('Enterprise', 'enterprise', 15.00, 0.0200, 2000.00, NULL, NULL, 30)");

// ── 2. platform_invoices ──────────────────────────────────────────
$results[] = runSql($pdo, 'Create platform_invoices', "
CREATE TABLE IF NOT EXISTS platform_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) UNIQUE NOT NULL,
    tenant_id INT NOT NULL,
    billing_period DATE NOT NULL,
    plan_id INT DEFAULT NULL,
    pppoe_user_count INT NOT NULL DEFAULT 0,
    pppoe_fee_per_user DECIMAL(10,2) NOT NULL DEFAULT 25.00,
    pppoe_subtotal DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count * pppoe_fee_per_user) STORED,
    hotspot_collections DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    hotspot_commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0300,
    hotspot_commission DECIMAL(15,2) GENERATED ALWAYS AS (hotspot_collections * hotspot_commission_rate) STORED,
    base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_due DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count * pppoe_fee_per_user + hotspot_collections * hotspot_commission_rate + base_fee) STORED,
    status ENUM('pending','paid','overdue','waived','cancelled') NOT NULL DEFAULT 'pending',
    due_date DATE NOT NULL,
    paid_at TIMESTAMP NULL,
    payment_method VARCHAR(50) NULL,
    transaction_ref VARCHAR(100) NULL,
    notes TEXT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES platform_subscription_plans(id) ON DELETE SET NULL,
    UNIQUE KEY unique_tenant_period (tenant_id, billing_period),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── 3. Extend tenants table ───────────────────────────────────────
$tenantCols = [
    ['Add tenants.subscription_plan_id', "ALTER TABLE tenants ADD COLUMN subscription_plan_id INT DEFAULT NULL"],
    ['Add tenants.notes',                "ALTER TABLE tenants ADD COLUMN notes TEXT DEFAULT NULL"],
    ['Add tenants.suspended_at',         "ALTER TABLE tenants ADD COLUMN suspended_at TIMESTAMP NULL"],
    ['Add tenants.suspended_reason',     "ALTER TABLE tenants ADD COLUMN suspended_reason VARCHAR(255) NULL"],
    ['Add tenants.contact_phone',        "ALTER TABLE tenants ADD COLUMN contact_phone VARCHAR(30) NULL"],
];
foreach ($tenantCols as [$label, $sql]) {
    $results[] = runSql($pdo, $label, $sql);
}

$results[] = runSql($pdo, 'Default existing tenants to Starter plan',
    "UPDATE tenants SET subscription_plan_id = 1 WHERE subscription_plan_id IS NULL");

try {
    $pdo->exec("ALTER TABLE tenants ADD CONSTRAINT fk_tenant_plan FOREIGN KEY (subscription_plan_id) REFERENCES platform_subscription_plans(id) ON DELETE SET NULL");
    $results[] = ['label' => 'FK tenants → plans', 'status' => 'ok', 'msg' => 'OK'];
} catch (PDOException $e) {
    $results[] = ['label' => 'FK tenants → plans', 'status' => 'skip', 'msg' => 'Already exists'];
}

// ── 4. users table additions ──────────────────────────────────────
$userCols = [
    ['Add users.email_verified',     "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0"],
    ['Add users.is_verified',        "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0"],
    ['Add users.verification_token', "ALTER TABLE users ADD COLUMN verification_token VARCHAR(80) NULL"],
    ['Add users.is_super_admin',     "ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0"],
];
foreach ($userCols as [$label, $sql]) {
    $results[] = runSql($pdo, $label, $sql);
}

// ── 5–9. Optional tables (skip if table doesn't exist) ───────────
$optionalAlters = [
    ['messages: add tenant_id',            "ALTER TABLE messages ADD COLUMN tenant_id INT DEFAULT NULL"],
    ['messages: add index',                "ALTER TABLE messages ADD INDEX idx_messages_tenant (tenant_id)"],
    ['messages: backfill',                 "UPDATE messages m INNER JOIN clients c ON c.id = m.client_id SET m.tenant_id = c.tenant_id WHERE m.tenant_id IS NULL AND c.tenant_id IS NOT NULL"],
    ['vouchers: add tenant_id',            "ALTER TABLE vouchers ADD COLUMN tenant_id INT DEFAULT NULL"],
    ['vouchers: add index',                "ALTER TABLE vouchers ADD INDEX idx_vouchers_tenant (tenant_id)"],
    ['vouchers: backfill',                 "UPDATE vouchers v INNER JOIN packages p ON p.id = v.package_id SET v.tenant_id = p.tenant_id WHERE v.tenant_id IS NULL AND p.tenant_id IS NOT NULL"],
    ['customer_sessions: add tenant_id',   "ALTER TABLE customer_sessions ADD COLUMN tenant_id INT DEFAULT NULL"],
    ['customer_sessions: add index',       "ALTER TABLE customer_sessions ADD INDEX idx_cs_tenant (tenant_id)"],
    ['customer_sessions: backfill',        "UPDATE customer_sessions cs INNER JOIN clients c ON c.id = cs.client_id SET cs.tenant_id = c.tenant_id WHERE cs.tenant_id IS NULL AND c.tenant_id IS NOT NULL"],
    ['payment_auto_logins: add tenant_id', "ALTER TABLE payment_auto_logins ADD COLUMN tenant_id INT DEFAULT NULL"],
    ['payment_auto_logins: add index',     "ALTER TABLE payment_auto_logins ADD INDEX idx_pal_tenant (tenant_id)"],
    ['payment_auto_logins: backfill',      "UPDATE payment_auto_logins pal INNER JOIN clients c ON c.id = pal.client_id SET pal.tenant_id = c.tenant_id WHERE pal.tenant_id IS NULL AND c.tenant_id IS NOT NULL"],
    ['customer_activity_log: add tenant_id',"ALTER TABLE customer_activity_log ADD COLUMN tenant_id INT DEFAULT NULL"],
    ['customer_activity_log: add index',   "ALTER TABLE customer_activity_log ADD INDEX idx_cal_tenant (tenant_id)"],
    ['customer_activity_log: backfill',    "UPDATE customer_activity_log cal INNER JOIN clients c ON c.id = cal.client_id SET cal.tenant_id = c.tenant_id WHERE cal.tenant_id IS NULL AND c.tenant_id IS NOT NULL"],
];
foreach ($optionalAlters as [$label, $sql]) {
    $results[] = runSql($pdo, $label, $sql, true);
}

// ── Platform M-Pesa Config ────────────────────────────────────────
$results[] = runSql($pdo, 'Create platform_mpesa_config', "
CREATE TABLE IF NOT EXISTS platform_mpesa_config (
    id INT NOT NULL DEFAULT 1,
    consumer_key VARCHAR(255) DEFAULT NULL,
    consumer_secret TEXT DEFAULT NULL,
    passkey TEXT DEFAULT NULL,
    shortcode VARCHAR(20) DEFAULT NULL,
    environment VARCHAR(20) DEFAULT 'sandbox',
    callback_url VARCHAR(500) DEFAULT NULL,
    c2b_confirmation_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$results[] = runSql($pdo, 'Seed platform_mpesa_config row',
    "INSERT IGNORE INTO platform_mpesa_config (id) VALUES (1)");

$results[] = runSql($pdo, 'Add payments.collection_type column',
    "ALTER TABLE payments ADD COLUMN collection_type ENUM('direct','platform') NOT NULL DEFAULT 'direct'");

// ── Platform Email Config ─────────────────────────────────────────
$results[] = runSql($pdo, 'Create platform_email_config', "
CREATE TABLE IF NOT EXISTS platform_email_config (
    id INT NOT NULL DEFAULT 1,
    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255) DEFAULT NULL,
    smtp_password TEXT DEFAULT NULL,
    from_email VARCHAR(255) DEFAULT NULL,
    from_name VARCHAR(100) DEFAULT 'FortuNett Technologies',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$results[] = runSql($pdo, 'Seed platform_email_config row',
    "INSERT IGNORE INTO platform_email_config (id) VALUES (1)");

// ── Platform SMS Config ───────────────────────────────────────────
$results[] = runSql($pdo, 'Create platform_sms_config', "
CREATE TABLE IF NOT EXISTS platform_sms_config (
    id INT NOT NULL DEFAULT 1,
    provider VARCHAR(50) DEFAULT 'talksasa',
    api_key VARCHAR(255) DEFAULT NULL,
    sender_id VARCHAR(50) DEFAULT NULL,
    api_url VARCHAR(255) DEFAULT 'https://api.talksasa.com/v1/sms/send',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$results[] = runSql($pdo, 'Seed platform_sms_config row',
    "INSERT IGNORE INTO platform_sms_config (id) VALUES (1)");

// ── Tally ──────────────────────────────────────────────────────────
$ok    = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$skip  = count(array_filter($results, fn($r) => $r['status'] === 'skip'));
$errors= array_filter($results, fn($r) => $r['status'] === 'error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration — FortuNett Technologies</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;padding:32px}
.card{background:#fff;border-radius:12px;padding:32px;max-width:900px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,.07)}
h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}
p.sub{color:#6B7280;font-size:13px;margin-bottom:20px}
.summary{display:flex;gap:12px;margin-bottom:22px;flex-wrap:wrap}
.badge{padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700}
.badge.ok   {background:#D1FAE5;color:#065F46}
.badge.skip {background:#FEF3C7;color:#92400E}
.badge.error{background:#FEE2E2;color:#991B1B}
table{width:100%;border-collapse:collapse;font-size:13px}
th{padding:8px 14px;background:#F9FAFB;text-align:left;font-weight:600;color:#6B7280;border-bottom:1px solid #E5E7EB;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
td{padding:8px 14px;border-bottom:1px solid #F3F4F6;vertical-align:top}
.s-ok   {color:#059669;font-weight:600}
.s-skip {color:#D97706}
.s-error{color:#DC2626;font-weight:700}
.emsg{font-family:monospace;font-size:11px;color:#DC2626;word-break:break-all}
.box{margin-top:20px;padding:14px 18px;border-radius:8px;font-size:13px}
.box.success{background:#D1FAE5;border:1px solid #6EE7B7;color:#065F46}
.box.warn   {background:#FEF3C7;border:1px solid #FCD34D;color:#92400E}
.box a{color:inherit;font-weight:700}
</style>
</head>
<body>
<div class="card">
    <h1>🛠 SaaS Upgrade Migration</h1>
    <p class="sub">fortunett_technologies — run once, then delete this file</p>

    <div class="summary">
        <span class="badge ok">✅ <?= $ok ?> succeeded</span>
        <span class="badge skip">⏭ <?= $skip ?> skipped/already-existed</span>
        <?php if (count($errors)): ?>
        <span class="badge error">❌ <?= count($errors) ?> failed</span>
        <?php endif; ?>
    </div>

    <table>
        <thead><tr><th style="width:45%">Step</th><th style="width:10%">Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['label']) ?></td>
            <td class="s-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></td>
            <td class="<?= $r['status']==='error'?'emsg':'' ?>"><?= htmlspecialchars($r['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($errors) === 0): ?>
    <div class="box success">
        <strong>✅ Migration complete!</strong> All critical tables are ready.<br><br>
        Next:
        <ol style="margin:8px 0 0 18px;line-height:1.8">
            <li><a href="super_admin/setup.php">Create your super admin account →</a></li>
            <li>Then login at <a href="super_admin/login.php">super_admin/login.php</a></li>
            <li><strong>Delete this file</strong> (<code>run_migration.php</code>) and <code>super_admin/setup.php</code> when done.</li>
        </ol>
    </div>
    <?php else: ?>
    <div class="box warn">
        <strong>⚠ <?= count($errors) ?> critical step(s) failed.</strong>
        Skipped rows (yellow) are safe to ignore — they mean optional tables don't exist in your DB yet.
        Red errors need attention before the super admin dashboard will work.
    </div>
    <?php endif; ?>

    <div class="box warn" style="margin-top:10px">
        ⚠ <strong>Security:</strong> Delete <code>run_migration.php</code> from your server after use.
    </div>
</div>
</body>
</html>
