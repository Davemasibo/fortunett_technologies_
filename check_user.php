<?php
require_once 'includes/db_master.php';

echo "User Details:\n";
echo "====================\n";

$stmt = $pdo->query("SELECT id, username, email, email_verified, tenant_id FROM users WHERE username = 'ecco'");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    foreach ($user as $key => $value) {
        echo "$key: " . ($value ?? 'NULL') . "\n";
    }
} else {
    echo "User not found\n";
}
