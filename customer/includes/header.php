<?php
// Ensure session is started and customer data is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tenant_branding = [
    'company_name' => 'FortuNNet',
    'brand_color' => '#2C5282',
    'system_logo' => ''
];

if (isset($_SESSION['customer_data']['tenant_id'])) {
    require_once __DIR__ . '/../../includes/db_connect.php';
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
    <link rel="stylesheet" href="/fortunett_technologies_/customer/css/customer.css?v=<?php echo time(); ?>">
    <style>
        :root {
            --primary: <?php echo $tenant_branding['brand_color']; ?>;
            --primary-light: <?php echo $tenant_branding['brand_color']; ?>80; /* Fallback or opacity */
        }
        .sidebar-menu a.active {
            border-left-color: var(--primary);
            background: linear-gradient(90deg, <?php echo $tenant_branding['brand_color']; ?>1a 0%, transparent 100%);
        }
        .user-avatar, .package-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary) 100%);
        }
        .btn-primary {
            background: var(--primary);
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
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
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="packages.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i>
                        <span>Packages</span>
                    </a>
                </li>
                <li>
                    <a href="payment.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'payment.php' ? 'active' : ''; ?>">
                        <i class="fas fa-credit-card"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li>
                    <a href="account.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'account.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Account</span>
                    </a>
                </li>
                <!-- NEW: Devices Page -->
                <li>
                    <a href="devices.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'devices.php' ? 'active' : ''; ?>">
                        <i class="fas fa-laptop"></i>
                        <span>Devices</span>
                    </a>
                </li>
                <li>
                    <a href="logout.php" class="logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="menu-toggle" onclick="toggleSidebarMobile()">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
                <div class="topbar-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-details">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['customer_data']['full_name'] ?? $_SESSION['customer_data']['name'] ?? 'Customer'); ?></div>
                            <div class="user-email"><?php echo htmlspecialchars($_SESSION['customer_data']['email'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function toggleSidebarDesk() {
                    const sidebar = document.getElementById('sidebar');
                    const mainContent = document.getElementById('mainContent');
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                }

                function toggleSidebarMobile() {
                    const sidebar = document.getElementById('sidebar');
                    sidebar.classList.toggle('active');
                }
            </script>
            
            <div class="content-wrapper">
