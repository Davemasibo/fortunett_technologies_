<?php
require_once 'config/constants.php';
$current_theme = getCurrentTheme();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $current_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - ISP Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
    <?php if (isLoggedIn()): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-wifi me-2"></i>
                <?php 
                $database = new Database();
                $db = $database->getConnection();
                $profile = getISPProfile($db);
                echo htmlspecialchars($profile['business_name']); 
                ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="themeDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-palette"></i> Theme
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item theme-selector" data-theme="light" href="#"><i class="fas fa-sun me-2"></i>Light</a></li>
                            <li><a class="dropdown-item theme-selector" data-theme="dark" href="#"><i class="fas fa-moon me-2"></i>Dark</a></li>
                            <li><a class="dropdown-item theme-selector" data-theme="system" href="#"><i class="fas fa-desktop me-2"></i>System</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
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
    <?php endif; ?>
    <div class="container-fluid">
        <div class="row">