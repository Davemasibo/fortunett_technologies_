
<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();

// Auto-provision tables needed by this page
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `platform_settings` (
        `id`            INT NOT NULL AUTO_INCREMENT,
        `setting_key`   VARCHAR(100) NOT NULL,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `platform_email_config` (
        `id`            INT NOT NULL DEFAULT 1,
        `smtp_host`     VARCHAR(255) DEFAULT NULL,
        `smtp_port`     INT DEFAULT 587,
        `smtp_username` VARCHAR(255) DEFAULT NULL,
        `smtp_password` TEXT DEFAULT NULL,
        `from_email`    VARCHAR(255) DEFAULT NULL,
        `from_name`     VARCHAR(100) DEFAULT 'FortuNett Technologies',
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO platform_email_config (id) VALUES (1)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `platform_sms_config` (
        `id`        INT NOT NULL DEFAULT 1,
        `provider`  VARCHAR(50) DEFAULT 'talksasa',
        `api_key`   VARCHAR(255) DEFAULT NULL,
        `sender_id` VARCHAR(50) DEFAULT NULL,
        `api_url`   VARCHAR(255) DEFAULT 'https://api.talksasa.com/v1/sms/send',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO platform_sms_config (id) VALUES (1)");
} catch (Exception $e) {}

// ── Helper: upsert platform_settings ─────────────────────────────────────────
function platformSet($pdo, $key, $value) {
    $stmt = $pdo->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

function platformGet($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$flash = '';
$activeTab = $_GET['tab'] ?? 'identity';

// ── Handle POST saves ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Platform Identity
    if ($action === 'save_identity') {
        $activeTab = 'identity';
        platformSet($pdo, 'platform_name',     trim($_POST['platform_name'] ?? 'FortuNett Technologies'));
        platformSet($pdo, 'platform_tagline',  trim($_POST['platform_tagline'] ?? ''));
        platformSet($pdo, 'platform_domain',   trim($_POST['platform_domain'] ?? ''));
        platformSet($pdo, 'support_email',     trim($_POST['support_email'] ?? ''));
        platformSet($pdo, 'support_phone',     trim($_POST['support_phone'] ?? ''));
        platformSet($pdo, 'platform_logo_url', trim($_POST['platform_logo_url'] ?? ''));
        $flash = 'success|Platform identity updated.';
    }

    // 2. Email / SMTP
    elseif ($action === 'save_email') {
        $activeTab = 'email';
        $stmt = $pdo->prepare("
            UPDATE platform_email_config SET
                smtp_host     = ?,
                smtp_port     = ?,
                smtp_username = ?,
                smtp_password = ?,
                from_email    = ?,
                from_name     = ?,
                is_active     = ?
            WHERE id = 1
        ");
        $stmt->execute([
            trim($_POST['smtp_host'] ?? ''),
            (int)($_POST['smtp_port'] ?? 587),
            trim($_POST['smtp_username'] ?? ''),
            trim($_POST['smtp_password'] ?? ''),
            trim($_POST['from_email'] ?? ''),
            trim($_POST['from_name'] ?? 'FortuNett Technologies'),
            isset($_POST['email_active']) ? 1 : 0,
        ]);
        $flash = 'success|Email / SMTP settings saved.';
    }

    // 3. SMS
    elseif ($action === 'save_sms') {
        $activeTab = 'sms';
        $stmt = $pdo->prepare("
            UPDATE platform_sms_config SET
                provider  = ?,
                api_key   = ?,
                sender_id = ?,
                api_url   = ?,
                is_active = ?
            WHERE id = 1
        ");
        $stmt->execute([
            trim($_POST['sms_provider'] ?? 'talksasa'),
            trim($_POST['sms_api_key'] ?? ''),
            trim($_POST['sms_sender_id'] ?? ''),
            trim($_POST['sms_api_url'] ?? 'https://api.talksasa.com/v1/sms/send'),
            isset($_POST['sms_active']) ? 1 : 0,
        ]);
        $flash = 'success|SMS settings saved.';
    }

    // 4. Registration Defaults
    elseif ($action === 'save_registration') {
        $activeTab = 'registration';
        platformSet($pdo, 'default_trial_days',      (int)($_POST['default_trial_days'] ?? 30));
        platformSet($pdo, 'require_email_verify',    isset($_POST['require_email_verify']) ? '1' : '0');
        platformSet($pdo, 'auto_assign_plan_slug',   trim($_POST['auto_assign_plan_slug'] ?? 'starter'));
        platformSet($pdo, 'signup_enabled',          isset($_POST['signup_enabled']) ? '1' : '0');
        platformSet($pdo, 'signup_welcome_message',  trim($_POST['signup_welcome_message'] ?? ''));
        $flash = 'success|Registration settings saved.';
    }
}

// ── Load current values ───────────────────────────────────────────────────────
$identity = [
    'platform_name'    => platformGet($pdo, 'platform_name', 'FortuNett Technologies'),
    'platform_tagline' => platformGet($pdo, 'platform_tagline', 'Multi-Tenant ISP Management'),
    'platform_domain'  => platformGet($pdo, 'platform_domain', 'fortunetttech.site'),
    'support_email'    => platformGet($pdo, 'support_email', 'support@fortunetttech.site'),
    'support_phone'    => platformGet($pdo, 'support_phone', ''),
    'platform_logo_url'=> platformGet($pdo, 'platform_logo_url', ''),
];

$emailCfg = $pdo->query("SELECT * FROM platform_email_config WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$smsCfg   = $pdo->query("SELECT * FROM platform_sms_config WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];

$regDefaults = [
    'default_trial_days'   => platformGet($pdo, 'default_trial_days', 30),
    'require_email_verify' => platformGet($pdo, 'require_email_verify', '1'),
    'auto_assign_plan_slug'=> platformGet($pdo, 'auto_assign_plan_slug', 'starter'),
    'signup_enabled'       => platformGet($pdo, 'signup_enabled', '1'),
    'signup_welcome_message'=> platformGet($pdo, 'signup_welcome_message', ''),
];

$plans = $pdo->query("SELECT slug, name FROM platform_subscription_plans WHERE is_active=1 ORDER BY pppoe_fee_per_user DESC")->fetchAll(PDO::FETCH_ASSOC);

[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings — FortuNett Super Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="css/dark.css?v=2" rel="stylesheet">
<style>
:root{--sa-dark:#0f3460;--sa-mid:#16213e;--sa-accent:#e94560;--sidebar-w:240px;
      --neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);--neu-text:#e2e2e0;--neu-muted:#9a9a95;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--neu-bg);display:flex;min-height:100vh;color:var(--neu-text);}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#111827 0%,#16213e 55%,#0f3460 100%);color:#fff;flex-shrink:0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;box-shadow:4px 0 24px rgba(0,0,0,.5);border-right:1px solid rgba(255,255,255,.06);}
.sidebar-brand{padding:24px 20px 20px;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-brand .badge-sa{background:linear-gradient(135deg,#e94560,#c0305a);color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;letter-spacing:.8px;box-shadow:0 2px 8px rgba(233,69,96,.35);}
.sidebar-brand h2{font-size:16px;font-weight:700;margin-top:8px;color:#fff;}
.sidebar-brand p{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.sidebar-menu{list-style:none;padding:12px 0;flex:1;overflow-y:auto;}
.sidebar-menu a{display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(255,255,255,.72);text-decoration:none;transition:all .2s;font-size:14px;border-left:3px solid transparent;}
.sidebar-menu a:hover{background:rgba(255,255,255,.08);color:#fff;border-left-color:rgba(255,255,255,.25);}
.sidebar-menu a.active{background:rgba(255,255,255,.13);color:#fff;border-left-color:#e94560;}
.sidebar-menu a i{width:18px;text-align:center;font-size:15px;}
.sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:rgba(255,255,255,.45);}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;background:var(--neu-bg);}
.topbar{background:var(--neu-surf);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--neu-border);position:sticky;top:0;z-index:99;box-shadow:0 2px 20px rgba(0,0,0,.4);}
.topbar h1{font-size:18px;font-weight:700;color:#fff;}
.topbar .user-info{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--neu-muted);}
.content{padding:28px;max-width:1100px;margin:0 auto;background:var(--neu-bg);}
/* Tabs */
.tab-nav{display:flex;gap:4px;background:var(--neu-s2);border-radius:12px;padding:6px;border:1px solid var(--neu-border);box-shadow:8px 8px 20px rgba(0,0,0,.4),-4px -4px 10px rgba(255,255,255,.025);margin-bottom:24px;flex-wrap:wrap;}
.tab-btn{padding:9px 18px;border:none;background:transparent;border-radius:8px;font-size:14px;font-weight:600;color:var(--neu-muted);cursor:pointer;transition:all .18s;display:flex;align-items:center;gap:7px;}
.tab-btn:hover{background:rgba(255,255,255,.07);color:var(--neu-text);}
.tab-btn.active{background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;box-shadow:0 2px 10px rgba(0,0,0,.3);}
.tab-pane{display:none;}
.tab-pane.active{display:block;}
/* Card */
.card{background:var(--neu-s2);border-radius:14px;border:1px solid var(--neu-border);box-shadow:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px var(--neu-border);margin-bottom:20px;overflow:hidden;}
.card-head{padding:16px 22px;border-bottom:1px solid var(--neu-border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02);}
.card-head h3{font-size:15px;font-weight:700;color:#fff;margin:0;}
.card-head .icon-wrap{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;background:rgba(255,255,255,.08);}
.card-body{padding:22px 22px 26px;}
/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-grid.thirds{grid-template-columns:1fr 1fr 1fr;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group.full{grid-column:1/-1;}
.form-group label{font-size:12px;font-weight:700;color:var(--neu-muted);text-transform:uppercase;letter-spacing:.04em;}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1px solid rgba(255,255,255,.08);border-radius:8px;font-size:14px;color:var(--neu-text);background:#1a1a19;box-shadow:inset 3px 3px 7px rgba(0,0,0,.3);}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#3B6EA5;}
.form-group .hint{font-size:11px;color:var(--neu-muted);margin-top:3px;}
/* Toggle */
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--neu-border);}
.toggle-row:last-child{border-bottom:none;padding-bottom:0;}
.toggle-row .label-wrap{flex:1;}
.toggle-row .label-wrap strong{font-size:14px;font-weight:600;color:var(--neu-text);display:block;}
.toggle-row .label-wrap span{font-size:12px;color:var(--neu-muted);}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;cursor:pointer;inset:0;background:rgba(255,255,255,.12);border-radius:24px;transition:.2s;}
.toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;}
.toggle-switch input:checked + .toggle-slider{background:#3B6EA5;}
.toggle-switch input:checked + .toggle-slider:before{transform:translateX(20px);}
/* Btn */
.btn-save{padding:10px 26px;background:linear-gradient(135deg,#16213e,#0f3460);color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:opacity .18s;}
.btn-save:hover{opacity:.88;}
.btn-test{padding:10px 20px;background:rgba(255,255,255,.07);color:var(--neu-text);border:1px solid var(--neu-border);border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .18s;}
.btn-test:hover{background:rgba(255,255,255,.13);color:#fff;}
.form-actions{display:flex;gap:12px;align-items:center;margin-top:22px;padding-top:18px;border-top:1px solid var(--neu-border);}
/* Alerts */
.alert{padding:12px 16px;border-radius:9px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:14px;}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;}
/* Info banner */
.info-banner{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:9px;padding:12px 16px;font-size:13px;color:#93c5fd;display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;}
/* Status dot */
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;}
.dot-active{background:#22c55e;}
.dot-inactive{background:#6b7280;}
@media(max-width:860px){.form-grid,.form-grid.thirds{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.form-grid,.form-grid.thirds{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="badge-sa">SUPER ADMIN</div>
        <h2><i class="fas fa-shield-alt"></i> FortuNett</h2>
        <p>Platform Administration</p>
    </div>
    <ul class="sidebar-menu">
        <li><a href="index.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
        <li><a href="tenants.php"><i class="fas fa-building"></i><span>Tenants</span></a></li>
        <li><a href="billing.php"><i class="fas fa-file-invoice-dollar"></i><span>Platform Billing</span></a></li>
        <li><a href="plans.php"><i class="fas fa-layer-group"></i><span>Subscription Plans</span></a></li>
        <li><a href="mpesa.php"><i class="fas fa-mobile-alt"></i><span>Platform M-Pesa</span></a></li>
        <li><a href="settings.php" class="active"><i class="fas fa-cogs"></i><span>System Settings</span></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
    </ul>
    <div class="sidebar-footer">Logged in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></div>
</div>

<!-- Main -->
<div class="main">
    <div class="topbar">
        <h1><i class="fas fa-cogs" style="color:#e94560;margin-right:8px;"></i>System Settings</h1>
        <div class="user-info">
            <span>Platform-wide defaults</span>
            <div style="width:34px;height:34px;background:rgba(255,255,255,.12);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;border:1.5px solid rgba(255,255,255,.15);"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        </div>
    </div>

    <div class="content">

        <?php if ($flashMsg): ?>
        <div class="alert alert-<?= $flashType === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $flashType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($flashMsg) ?>
        </div>
        <?php endif; ?>

        <div class="info-banner">
            <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
            <div>These settings are the <strong>platform-wide defaults</strong>. Individual tenants may override email and SMS with their own credentials in their Settings page. Identity and registration settings always apply platform-wide.</div>
        </div>

        <!-- Tab Nav -->
        <div class="tab-nav">
            <button class="tab-btn <?= $activeTab==='identity' ? 'active':'' ?>" onclick="switchTab('identity')">
                <i class="fas fa-building"></i> Platform Identity
            </button>
            <button class="tab-btn <?= $activeTab==='email' ? 'active':'' ?>" onclick="switchTab('email')">
                <i class="fas fa-envelope"></i> Email / SMTP
                <?php if (!empty($emailCfg['smtp_host']) && $emailCfg['is_active']): ?>
                <span class="status-dot dot-active"></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?= $activeTab==='sms' ? 'active':'' ?>" onclick="switchTab('sms')">
                <i class="fas fa-sms"></i> SMS
                <?php if (!empty($smsCfg['api_key']) && $smsCfg['is_active']): ?>
                <span class="status-dot dot-active"></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?= $activeTab==='registration' ? 'active':'' ?>" onclick="switchTab('registration')">
                <i class="fas fa-user-plus"></i> Registration
            </button>
        </div>

        <!-- Tab: Platform Identity -->
        <div class="tab-pane <?= $activeTab==='identity' ? 'active':'' ?>" id="tab-identity">
            <div class="card">
                <div class="card-head">
                    <div class="icon-wrap" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-building"></i></div>
                    <h3>Platform Identity</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="settings.php?tab=identity">
                        <input type="hidden" name="action" value="save_identity">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Platform Name <span style="color:#e94560;">*</span></label>
                                <input type="text" name="platform_name" value="<?= htmlspecialchars($identity['platform_name']) ?>" required maxlength="100">
                                <div class="hint">Shown in all emails, login pages, and branding</div>
                            </div>
                            <div class="form-group">
                                <label>Tagline</label>
                                <input type="text" name="platform_tagline" value="<?= htmlspecialchars($identity['platform_tagline']) ?>" maxlength="150">
                                <div class="hint">Short description under the logo</div>
                            </div>
                            <div class="form-group">
                                <label>Primary Domain</label>
                                <input type="text" name="platform_domain" value="<?= htmlspecialchars($identity['platform_domain']) ?>" placeholder="fortunetttech.site" maxlength="255">
                                <div class="hint">Root domain for subdomain generation (e.g. fortunetttech.site)</div>
                            </div>
                            <div class="form-group">
                                <label>Logo URL</label>
                                <input type="url" name="platform_logo_url" value="<?= htmlspecialchars($identity['platform_logo_url']) ?>" placeholder="https://...">
                                <div class="hint">Publicly accessible image URL for the platform logo</div>
                            </div>
                            <div class="form-group">
                                <label>Support Email</label>
                                <input type="email" name="support_email" value="<?= htmlspecialchars($identity['support_email']) ?>" placeholder="support@fortunetttech.site">
                                <div class="hint">Shown in email footers sent to tenants and their customers</div>
                            </div>
                            <div class="form-group">
                                <label>Support Phone</label>
                                <input type="text" name="support_phone" value="<?= htmlspecialchars($identity['support_phone']) ?>" placeholder="+254 700 000 000" maxlength="50">
                                <div class="hint">Optional — shown in billing emails and support pages</div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Identity</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab: Email / SMTP -->
        <div class="tab-pane <?= $activeTab==='email' ? 'active':'' ?>" id="tab-email">
            <div class="card">
                <div class="card-head">
                    <div class="icon-wrap" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-envelope"></i></div>
                    <h3>Platform Email / SMTP</h3>
                </div>
                <div class="card-body">
                    <p style="font-size:13px;color:#64748b;margin-bottom:18px;">
                        This is the default SMTP used for all platform emails (welcome, invoices, suspension notices) and as the fallback for tenants who have not configured their own SMTP.
                    </p>
                    <form method="POST" action="settings.php?tab=email">
                        <input type="hidden" name="action" value="save_email">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars($emailCfg['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="form-group">
                                <label>SMTP Port</label>
                                <input type="number" name="smtp_port" value="<?= (int)($emailCfg['smtp_port'] ?? 587) ?>" min="1" max="65535">
                            </div>
                            <div class="form-group">
                                <label>SMTP Username</label>
                                <input type="text" name="smtp_username" value="<?= htmlspecialchars($emailCfg['smtp_username'] ?? '') ?>" autocomplete="off" placeholder="you@gmail.com">
                            </div>
                            <div class="form-group">
                                <label>SMTP Password</label>
                                <input type="password" name="smtp_password" value="<?= htmlspecialchars($emailCfg['smtp_password'] ?? '') ?>" autocomplete="new-password" placeholder="App password / secret">
                                <div class="hint">For Gmail use an App Password (not your account password)</div>
                            </div>
                            <div class="form-group">
                                <label>From Email</label>
                                <input type="email" name="from_email" value="<?= htmlspecialchars($emailCfg['from_email'] ?? '') ?>" placeholder="noreply@fortunetttech.site">
                            </div>
                            <div class="form-group">
                                <label>From Name</label>
                                <input type="text" name="from_name" value="<?= htmlspecialchars($emailCfg['from_name'] ?? 'FortuNett Technologies') ?>" maxlength="100">
                            </div>
                        </div>
                        <div class="toggle-row" style="margin-top:16px;">
                            <div class="label-wrap">
                                <strong>Enable Platform SMTP</strong>
                                <span>If disabled, PHP mail() fallback is used (not recommended for production)</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_active" <?= !empty($emailCfg['is_active']) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Email Settings</button>
                            <button type="button" class="btn-test" onclick="testEmail()">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                            <span id="testEmailStatus" style="font-size:13px;"></span>
                        </div>
                    </form>
                </div>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:14px 18px;font-size:13px;color:#92400e;display:flex;gap:10px;align-items:flex-start;">
                <i class="fas fa-lightbulb" style="margin-top:1px;flex-shrink:0;"></i>
                <span>
                    <strong>Gmail tip:</strong> Enable 2-factor auth on your Google account, then generate an <em>App Password</em> at myaccount.google.com/apppasswords. Use port <strong>587</strong> (TLS) or <strong>465</strong> (SSL).
                    Recommended <code>from_email</code> matches your SMTP username.
                </span>
            </div>
        </div>

        <!-- Tab: SMS -->
        <div class="tab-pane <?= $activeTab==='sms' ? 'active':'' ?>" id="tab-sms">
            <div class="card">
                <div class="card-head">
                    <div class="icon-wrap" style="background:#faf5ff;color:#7c3aed;"><i class="fas fa-sms"></i></div>
                    <h3>Platform SMS Configuration</h3>
                </div>
                <div class="card-body">
                    <p style="font-size:13px;color:#64748b;margin-bottom:18px;">
                        Default SMS gateway used for payment confirmations, expiry reminders, and suspension alerts.
                        Tenants can override with their own credentials in their Settings page.
                    </p>
                    <form method="POST" action="settings.php?tab=sms">
                        <input type="hidden" name="action" value="save_sms">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Provider</label>
                                <select name="sms_provider">
                                    <option value="talksasa" <?= ($smsCfg['provider'] ?? '') === 'talksasa' ? 'selected' : '' ?>>TalkSasa</option>
                                    <option value="africastalking" <?= ($smsCfg['provider'] ?? '') === 'africastalking' ? 'selected' : '' ?>>Africa's Talking</option>
                                    <option value="infobip" <?= ($smsCfg['provider'] ?? '') === 'infobip' ? 'selected' : '' ?>>Infobip</option>
                                    <option value="custom" <?= ($smsCfg['provider'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom API</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sender ID</label>
                                <input type="text" name="sms_sender_id" value="<?= htmlspecialchars($smsCfg['sender_id'] ?? '') ?>" placeholder="FortuNett" maxlength="11">
                                <div class="hint">Max 11 alphanumeric characters</div>
                            </div>
                            <div class="form-group full">
                                <label>API Key / Token</label>
                                <input type="password" name="sms_api_key" value="<?= htmlspecialchars($smsCfg['api_key'] ?? '') ?>" autocomplete="new-password" placeholder="Your SMS provider API key">
                            </div>
                            <div class="form-group full">
                                <label>API Endpoint URL</label>
                                <input type="url" name="sms_api_url" value="<?= htmlspecialchars($smsCfg['api_url'] ?? 'https://api.talksasa.com/v1/sms/send') ?>" placeholder="https://api.talksasa.com/v1/sms/send">
                                <div class="hint">TalkSasa default: https://api.talksasa.com/v1/sms/send</div>
                            </div>
                        </div>
                        <div class="toggle-row" style="margin-top:16px;">
                            <div class="label-wrap">
                                <strong>Enable Platform SMS</strong>
                                <span>Tenants without their own SMS config will use this gateway</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="sms_active" <?= !empty($smsCfg['is_active']) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save SMS Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab: Registration -->
        <div class="tab-pane <?= $activeTab==='registration' ? 'active':'' ?>" id="tab-registration">
            <div class="card">
                <div class="card-head">
                    <div class="icon-wrap" style="background:#fef3c7;color:#d97706;"><i class="fas fa-user-plus"></i></div>
                    <h3>Tenant Registration Defaults</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="settings.php?tab=registration">
                        <input type="hidden" name="action" value="save_registration">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Default Trial Days</label>
                                <input type="number" name="default_trial_days" value="<?= (int)$regDefaults['default_trial_days'] ?>" min="0" max="365">
                                <div class="hint">New tenants get this many free days (0 = no trial)</div>
                            </div>
                            <div class="form-group">
                                <label>Default Plan for New Tenants</label>
                                <select name="auto_assign_plan_slug">
                                    <?php foreach ($plans as $p): ?>
                                    <option value="<?= htmlspecialchars($p['slug']) ?>" <?= $regDefaults['auto_assign_plan_slug'] === $p['slug'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">Plan automatically assigned on signup</div>
                            </div>
                            <div class="form-group full">
                                <label>Welcome Message (shown on signup success page)</label>
                                <textarea name="signup_welcome_message" rows="3" placeholder="Welcome! Your ISP workspace is ready. Check your email to verify your account and get started."><?= htmlspecialchars($regDefaults['signup_welcome_message']) ?></textarea>
                            </div>
                        </div>
                        <div class="toggle-row" style="margin-top:16px;">
                            <div class="label-wrap">
                                <strong>Require Email Verification</strong>
                                <span>New tenant accounts must verify their email before logging in</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="require_email_verify" <?= $regDefaults['require_email_verify'] === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="label-wrap">
                                <strong>Allow New Signups</strong>
                                <span>When disabled, the signup page shows a "registration closed" message</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="signup_enabled" <?= $regDefaults['signup_enabled'] !== '0' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Registration Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Platform Settings Reference -->
            <div class="card">
                <div class="card-head">
                    <div class="icon-wrap" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-list-check"></i></div>
                    <h3>All Platform Settings (Reference)</h3>
                </div>
                <div class="card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <table style="width:100%;min-width:500px;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr>
                                <th style="padding:10px 16px;background:#f8fafc;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;">Key</th>
                                <th style="padding:10px 16px;background:#f8fafc;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;">Value</th>
                                <th style="padding:10px 16px;background:#f8fafc;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;text-align:left;border-bottom:1px solid #f1f5f9;">Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        try {
                            $allSettings = $pdo->query("SELECT setting_key, setting_value, updated_at FROM platform_settings ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($allSettings as $row):
                                $displayVal = in_array($row['setting_key'], ['smtp_password','sms_api_key']) ? '••••••' : htmlspecialchars($row['setting_value']);
                        ?>
                        <tr>
                            <td style="padding:10px 16px;border-bottom:1px solid #f8fafc;font-family:monospace;color:#374151;"><?= htmlspecialchars($row['setting_key']) ?></td>
                            <td style="padding:10px 16px;border-bottom:1px solid #f8fafc;color:#64748b;max-width:320px;word-break:break-all;"><?= $displayVal ?: '<em style="color:#cbd5e1;">—</em>' ?></td>
                            <td style="padding:10px 16px;border-bottom:1px solid #f8fafc;color:#94a3b8;"><?= date('d M Y H:i', strtotime($row['updated_at'])) ?></td>
                        </tr>
                        <?php endforeach;
                            if (empty($allSettings)): ?>
                        <tr><td colspan="3" style="padding:28px;text-align:center;color:#94a3b8;">No platform settings saved yet. Fill in the tabs above and save.</td></tr>
                        <?php endif;
                        } catch (Exception $e) {} ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    history.replaceState({}, '', url);
}

function testEmail() {
    const status = document.getElementById('testEmailStatus');
    status.textContent = 'Sending…';
    status.style.color = '#64748b';
    fetch('<?= htmlspecialchars(rtrim(dirname($_SERVER['PHP_SELF']), '/')) ?>/api_test_email.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'to=<?= urlencode($_SESSION['email'] ?? 'admin@fortunetttech.site') ?>'
    })
    .then(r => r.json())
    .then(d => {
        status.textContent = d.success ? '✓ Email sent!' : '✗ ' + (d.message || 'Failed');
        status.style.color = d.success ? '#16a34a' : '#dc2626';
    })
    .catch(() => {
        status.textContent = '✗ Request failed';
        status.style.color = '#dc2626';
    });
}
</script>
</body>
</html>
