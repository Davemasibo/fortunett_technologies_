<?php
// Ensure session is started and customer data is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tenant_branding = [
    'company_name' => 'FortuNNet',
    'brand_color' => '#0f3460',
    'system_logo' => ''
];

if (isset($_SESSION['customer_data']['tenant_id'])) {
    require_once __DIR__ . '/../../includes/db_master.php';
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $stmt->execute([$_SESSION['customer_data']['tenant_id']]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (!empty($settings['company_name'])) $tenant_branding['company_name'] = $settings['company_name'];
    if (!empty($settings['brand_color'])) $tenant_branding['brand_color'] = $settings['brand_color'];
    if (!empty($settings['system_logo'])) $tenant_branding['system_logo'] = $settings['system_logo'];
    if (!empty($settings['support_number'])) $tenant_branding['support_number'] = $settings['support_number'];
    if (!empty($settings['support_email'])) $tenant_branding['support_email'] = $settings['support_email'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal - <?php echo htmlspecialchars($tenant_branding['company_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/customer/css/customer.css?v=<?php echo filemtime(__DIR__.'/../css/customer.css'); ?>">
    <style>
        :root {
            --primary:       <?php echo $tenant_branding['brand_color']; ?>;
            --primary-light: <?php
                // Lighten the brand colour by bumping each hex channel +40 (clamped to ff)
                $hex = ltrim($tenant_branding['brand_color'], '#');
                if (strlen($hex) === 6) {
                    $r = min(255, hexdec(substr($hex,0,2)) + 40);
                    $g = min(255, hexdec(substr($hex,2,2)) + 40);
                    $b = min(255, hexdec(substr($hex,4,2)) + 40);
                    echo sprintf('#%02x%02x%02x', $r, $g, $b);
                } else {
                    echo $tenant_branding['brand_color'];
                }
            ?>;
            --primary-dark:  <?php
                $hex = ltrim($tenant_branding['brand_color'], '#');
                if (strlen($hex) === 6) {
                    $r = max(0, hexdec(substr($hex,0,2)) - 40);
                    $g = max(0, hexdec(substr($hex,2,2)) - 40);
                    $b = max(0, hexdec(substr($hex,4,2)) - 40);
                    echo sprintf('#%02x%02x%02x', $r, $g, $b);
                } else {
                    echo $tenant_branding['brand_color'];
                }
            ?>;
            --primary-glow:  <?php echo $tenant_branding['brand_color']; ?>40;
        }
        .sidebar-menu a.active {
            border-left-color: var(--primary-light);
            background: linear-gradient(90deg, var(--primary)1a 0%, transparent 100%);
            /* Fallback for browsers that don't interpolate hex+alpha correctly */
            background: linear-gradient(90deg, <?php echo $tenant_branding['brand_color']; ?>1a 0%, transparent 100%);
        }
        .user-avatar, .package-icon {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-light) 100%);
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .topbar {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="portal-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <?php if(!empty($tenant_branding['system_logo'])): ?>
                        <img src="../../<?php echo htmlspecialchars($tenant_branding['system_logo']); ?>" alt="Logo" style="height: 32px; border-radius: 4px;">
                    <?php else: ?>
                        <i class="fas fa-wifi"></i>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($tenant_branding['company_name']); ?></span>
                </div>
                <button class="sidebar-toggle" onclick="toggleSidebarDesk()">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" data-label="Dashboard"
                       class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="packages.php" data-label="Packages"
                       class="<?php echo basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i>
                        <span>Packages</span>
                    </a>
                </li>
                <li>
                    <a href="payment.php" data-label="Payments"
                       class="<?php echo basename($_SERVER['PHP_SELF']) == 'payment.php' ? 'active' : ''; ?>">
                        <i class="fas fa-credit-card"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li>
                    <a href="account.php" data-label="Account"
                       class="<?php echo basename($_SERVER['PHP_SELF']) == 'account.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Account</span>
                    </a>
                </li>
                <li>
                    <a href="devices.php" data-label="Devices"
                       class="<?php echo basename($_SERVER['PHP_SELF']) == 'devices.php' ? 'active' : ''; ?>">
                        <i class="fas fa-laptop"></i>
                        <span>Devices</span>
                    </a>
                </li>
                <li>
                    <a href="logout.php" data-label="Logout" class="logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>

            <!-- Sidebar profile — links to account page -->
            <?php
            $acctNum  = $_SESSION['customer_data']['account_number'] ?? null;
            $custName = $_SESSION['customer_data']['full_name'] ?? $_SESSION['customer_data']['name'] ?? null;
            $initial  = strtoupper(substr($custName ?? 'C', 0, 1));
            $isAcctPage = basename($_SERVER['PHP_SELF']) === 'account.php';
            ?>
            <a href="account.php" class="sidebar-profile-card <?= $isAcctPage ? 'active' : '' ?>" title="My Account">
                <div class="sp-avatar"><?= $initial ?></div>
                <div class="sp-info">
                    <?php if ($custName): ?>
                    <div class="sp-name"><?= htmlspecialchars($custName) ?></div>
                    <?php endif; ?>
                    <?php if ($acctNum): ?>
                    <div class="sp-acct"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($acctNum) ?></div>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-right sp-arrow"></i>
            </a>
        </aside>

        <!-- Mobile overlay for sidebar -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebarMobile()"></div>
        
        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="menu-toggle" onclick="toggleSidebarMobile()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <span class="page-title" id="topbar-page-title"></span>
                </div>
                <div class="topbar-right"></div>
            </div>

            <script>
                const CUST_SIDEBAR_KEY = 'customer-sidebar-collapsed';

                function toggleSidebarDesk() {
                    const sidebar = document.getElementById('sidebar');
                    const mainContent = document.getElementById('mainContent');
                    const collapsed = sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded', collapsed);
                    localStorage.setItem(CUST_SIDEBAR_KEY, collapsed ? '1' : '0');
                }

                function toggleSidebarMobile() {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    const open = sidebar.classList.toggle('active');
                    if (overlay) overlay.classList.toggle('active', open);
                }

                function closeSidebarMobile() {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                }

                // Restore sidebar collapsed state on desktop
                document.addEventListener('DOMContentLoaded', function() {
                    if (window.innerWidth > 768) {
                        const stored = localStorage.getItem(CUST_SIDEBAR_KEY);
                        if (stored === '1') {
                            document.getElementById('sidebar').classList.add('collapsed');
                            document.getElementById('mainContent').classList.add('expanded');
                        }
                    }
                    // Set topbar page title from active sidebar link
                    const activeLink = document.querySelector('.sidebar-menu a.active');
                    if (activeLink) {
                        const label = activeLink.querySelector('span');
                        const titleEl = document.getElementById('topbar-page-title');
                        if (label && titleEl) titleEl.textContent = label.textContent.trim();
                    }
                });
            </script>
            
            <div class="content-wrapper">
