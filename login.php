<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        try {
            $database = new Database();
            $db = $database->getConnection();

            if (!$db) {
                $error = "Unable to connect to the database. Please run the setup script or check your DB settings.";
            } else {
                $query = "SELECT id, username, password_hash, role FROM users WHERE username = :username";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $username);
                $stmt->execute();

                if ($stmt->rowCount() == 1) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (password_verify($password, $row['password_hash'])) {
                        loginUser($row['id'], $row['username'], $row['role']);
                        header("Location: dashboard.php");
                        exit;
                    } else {
                        $error = "Invalid password.";
                    }
                } else {
                    $error = "No account found with that username.";
                }
            }
        } catch (PDOException $e) {
            // If tables are missing (error 1146), show a helpful setup link instead of crashing.
            $msg = $e->getMessage();
            if (strpos($msg, '1146') !== false || stripos($msg, 'Base table or view not found') !== false) {
                $error = 'Required database tables were not found. Please run <a href="setup_db.php">setup_db.php</a> to initialize the database.';
            } else {
                // Avoid exposing full SQL errors in production; show helpful guidance instead.
                $error = "A database error occurred: " . htmlspecialchars($msg);
            }
        }
    } else {
        $error = "Please enter both username and password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-body">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-lg">
                    <div class="card-header bg-primary text-white text-center">
                        <h4><i class="fas fa-wifi me-2"></i><?php echo APP_NAME; ?></h4>
                        <p class="mb-0">ISP Management System</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required 
                                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">Login</button>
                            </div>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <p class="text-muted"><strong>Demo Credentials:</strong><br>
                            Username: admin<br>
                            Password: admin123</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>