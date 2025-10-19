<?php if (isLoggedIn()): ?>
<aside class="sidebar">
    <style>
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 16px;
            color: #333;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-menu a:hover {
            background: #f0f0f0;
            border-left-color: var(--primary-color);
        }
        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-left-color: var(--secondary-color);
            font-weight: 600;
        }
        .sidebar-menu a i {
            width: 24px;
            margin-right: 8px;
            text-align: center;
            flex: 0 0 auto;
        }
        .sidebar-menu a span {
            display: inline-block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        @media (max-width: 991.98px) {
            .sidebar-menu a {
                padding: 12px 14px;
            }
        }
    </style>

    <?php
    // determine current file once and helper to mark active links (accepts string or array)
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    function isActive($current, $files) {
        if (!is_array($files)) $files = [$files];
        return in_array($current, $files) ? 'active' : '';
    }
    ?>

    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php" class="<?php echo isActive($current, 'dashboard.php'); ?>">
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="clients.php" class="<?php echo isActive($current, ['clients.php','user.php']); ?>">
                <i class="fas fa-users"></i> <span>Clients</span>
            </a>
        </li>
        <li>
            <a href="packages.php" class="<?php echo isActive($current, 'packages.php'); ?>">
                <i class="fas fa-box"></i> <span>Packages</span>
            </a>
        </li>
        <li>
            <a href="payments.php" class="<?php echo isActive($current, 'payments.php'); ?>">
                <i class="fas fa-money-bill-wave"></i> <span>Payments</span>
            </a>
        </li>
        <li>
            <a href="sms.php" class="<?php echo isActive($current, 'sms.php'); ?>">
                <i class="fas fa-sms"></i> <span>SMS</span>
            </a>
        </li>
        <li>
            <a href="emails.php" class="<?php echo isActive($current, 'emails.php'); ?>">
                <i class="fas fa-envelope"></i> <span>Emails</span>
            </a>
        </li>
        <li>
            <a href="subscription.php" class="<?php echo isActive($current, 'subscription.php'); ?>">
                <i class="fas fa-crown"></i> <span>Subscription</span>
            </a>
        </li>
        <li>
            <a href="mikrotik.php" class="<?php echo isActive($current, 'mikrotik.php'); ?>">
                <i class="fas fa-server"></i> <span>MikroTik</span>
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo isActive($current, 'reports.php'); ?>">
                <i class="fas fa-chart-bar"></i> <span>Reports</span>
            </a>
        </li>
        
        <li>
            <a href="settings.php" class="<?php echo isActive($current, 'settings.php'); ?>">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </a>
        </li>
    </ul>
</aside>
<?php endif; ?>