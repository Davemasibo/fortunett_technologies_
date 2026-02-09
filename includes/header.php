<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/db_connect.php';
}
if (!function_exists('getCurrentTheme')) {
    require_once __DIR__ . '/auth.php';
}

$current_theme = getCurrentTheme();
$profile = isLoggedIn() ? getISPProfile($pdo) : ['business_name' => 'ISP Management'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $current_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile['business_name'] ?? 'ISP Management'); ?> - ISP Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/modern-design.css" rel="stylesheet">
    <link href="css/page-layout.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-bg: #f8f9fa;
            --dark-text: #333;
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 72px;
            --navbar-height: 60px;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--light-bg);
        }
        .navbar {
            background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            height: var(--navbar-height);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
        }
        .navbar .container-fluid {
            display: flex;
            align-items: center;
            width: 100%;
        }

        .navbar-brand {
            margin-left: 60px !important;
        }

        .main-layout {
            padding-top: var(--navbar-height);
            display: flex;
            min-height: calc(100vh - var(--navbar-height));
        }
        .sidebar {
            width: var(--sidebar-width);
            background: white;
            position: fixed;
            left: 0;
            top: var(--navbar-height);
            bottom: 0;
            overflow-y: auto;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            z-index: 999;
            transition: width 0.3s ease, transform 0.3s ease;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar.hidden {
            transform: translateX(-100%);
            display: none;
        }

        .sidebar-menu {
            list-style: none;
            padding: 8px 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            overflow: auto;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
            gap: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-menu a:hover {
            background: #f5f5f5;
            border-left-color: var(--primary-color);
        }

        .sidebar-menu a.active {
            background: linear-gradient(135deg, #2C5282 0%, #3B6EA5 100%);
            color: white;
            border-left-color: #fff;
            font-weight: 600;
        }

        .sidebar-menu a i {
            width: 20px;
            text-align: center;
            flex: 0 0 auto;
            font-size: 16px;
        }

        .sidebar-menu a span {
            display: inline-block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar.collapsed .sidebar-menu a span {
            display: none;
        }

        .main-content-wrapper {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 30px 0;
            min-height: calc(100vh - var(--navbar-height));
            background: var(--light-bg);
            display: flex;
            justify-content: center;
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .main-content-wrapper.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }

        .main-content-wrapper.sidebar-hidden {
            margin-left: 0;
            width: 100%;
        }

        .main-content-wrapper > div {
            width: 100%;
            max-width: 1350px;
            padding: 0 40px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content-wrapper {
                margin-left: 0;
                width: 100%;
            }
            .main-content-wrapper > div {
                padding: 0 16px;
            }
        }

        /* Scrollbar styling */
        .sidebar-menu::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 3px;
        }
        .sidebar-menu::-webkit-scrollbar-thumb:hover {
            background: #999;
        }
    </style>
</head>
<body>
<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-wifi me-2"></i>
            <?php echo htmlspecialchars($profile['business_name'] ?? 'ISP Management'); ?>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="profile.php">
                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'User'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="main-layout">
<?php endif; ?>