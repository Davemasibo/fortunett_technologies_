<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal - FortuNNet Technologies</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/fortunett_technologies_/customer/css/customer.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="portal-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-wifi"></i>
                    <span>FortuNNet</span>
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
