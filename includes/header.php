<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/db_connect.php';
}
if (!function_exists('getCurrentTheme')) {
    require_once __DIR__ . '/auth.php';
}

$current_theme = 'light';
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $tenant_id = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $tSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $current_theme = $tSettings['app_theme'] ?? 'light';
    $brand_color = $tSettings['brand_color'] ?? '#3B6EA5';
    $brand_font = $tSettings['brand_font'] ?? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    // Calculate darker shade for sidebar gradient/hover
    // Simple hex darken logic
    $hex = str_replace('#', '', $brand_color);
    if(strlen($hex) == 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Darken by 20%
    $r_dark = max(0, min(255, $r - 40));
    $g_dark = max(0, min(255, $g - 40));
    $b_dark = max(0, min(255, $b - 40));
    $brand_color_dark = sprintf("#%02x%02x%02x", $r_dark, $g_dark, $b_dark);

    // Lighten by 20%
    $r_light = max(0, min(255, $r + 40));
    $g_light = max(0, min(255, $g + 40));
    $b_light = max(0, min(255, $b + 40));
    $brand_color_light = sprintf("#%02x%02x%02x", $r_light, $g_light, $b_light);
}

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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Work+Sans:wght@300;400;500;600;700&display=swap');

        :root {
            --primary-color: <?php echo $brand_color; ?>;
            --primary: <?php echo $brand_color; ?>;
            --primary-dark: <?php echo $brand_color_dark ?? '#2C5282'; ?>;
            --primary-light: <?php echo $brand_color_light ?? '#4A90E2'; ?>;
            --brand-font: '<?php echo $brand_font; ?>', sans-serif;
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
        
        /* Force Bootstrap to use Brand Color */
        .btn-primary { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; color: white !important; }
        .btn-outline-primary { color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
        .btn-outline-primary:hover { background-color: var(--primary-color) !important; color: white !important; }
        .text-primary { color: var(--primary-color) !important; }
        .bg-primary { background-color: var(--primary-color) !important; }
        .border-primary { border-color: var(--primary-color) !important; }
        
        /* Override other button variants to use brand color - with high specificity */
        .btn.btn-success, 
        button.btn-success,
        a.btn-success { 
            background-color: var(--primary-color) !important; 
            border-color: var(--primary-color) !important; 
            color: white !important; 
        }
        .btn.btn-success:hover,
        button.btn-success:hover,
        a.btn-success:hover {
            background-color: var(--primary-dark) !important; 
            border-color: var(--primary-dark) !important; 
        }
        .btn.btn-info, 
        button.btn-info,
        a.btn-info { 
            background-color: var(--primary-color) !important; 
            border-color: var(--primary-color) !important; 
            color: white !important; 
        }
        .btn.btn-info:hover,
        button.btn-info:hover,
        a.btn-info:hover {
            background-color: var(--primary-dark) !important; 
            border-color: var(--primary-dark) !important; 
        }
        .btn.btn-warning, 
        button.btn-warning,
        a.btn-warning { 
            background-color: var(--primary-color) !important; 
            border-color: var(--primary-color) !important; 
            color: white !important; 
        }
        .btn.btn-warning:hover,
        button.btn-warning:hover,
        a.btn-warning:hover {
            background-color: var(--primary-dark) !important; 
            border-color: var(--primary-dark) !important; 
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #aaa; }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: var(--brand-font);
            background: var(--light-bg);
        }
        .navbar {
            background: var(--primary-color) !important;
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
            font-weight: 700;
            letter-spacing: 0.5px;
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
            background: var(--primary-color);
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
            padding-bottom: 60px; /* Space for footer */
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
            <?php if(!empty($tSettings['system_logo'])): ?>
                <img src="<?php echo htmlspecialchars($tSettings['system_logo']); ?>" alt="Logo" height="36" class="me-2 rounded">
            <?php else: ?>
                <i class="fas fa-wifi me-2"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($tSettings['company_name'] ?? $tenant['company_name'] ?? $profile['business_name'] ?? 'ISP Management'); ?>
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