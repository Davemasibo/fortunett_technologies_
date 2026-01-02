<?php
require_once __DIR__ . '/includes/config.php';

try {
    echo "Starting database update...\n";

    // 1. Create mikrotik_routers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS mikrotik_routers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100),
        ip_address VARCHAR(50),
        api_port INT DEFAULT 8728,
        username VARCHAR(100),
        password VARCHAR(255),
        use_ssl BOOLEAN DEFAULT FALSE,
        status VARCHAR(20) DEFAULT 'active',
        last_connected DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Created mikrotik_routers table.\n";

    // 2. Create mpesa_transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        client_id INT,
        phone_number VARCHAR(15),
        amount DECIMAL(10,2),
        merchant_request_id VARCHAR(100),
        checkout_request_id VARCHAR(100),
        transaction_id VARCHAR(100),
        result_code INT,
        result_desc TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        callback_data TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
    )");
    echo "Created mpesa_transactions table.\n";

    // 3. Add MikroTik fields to packages
    $columns = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('mikrotik_profile', $columns)) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN mikrotik_profile VARCHAR(100)");
        echo "Added mikrotik_profile to packages.\n";
    }
    if (!in_array('connection_type', $columns)) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN connection_type ENUM('pppoe', 'hotspot') DEFAULT 'pppoe'");
        echo "Added connection_type to packages.\n";
    }
    if (!in_array('rate_limit', $columns)) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN rate_limit VARCHAR(50)");
        echo "Added rate_limit to packages.\n";
    }

    // 4. Add MikroTik fields to clients
    $clientColumns = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('mikrotik_username', $clientColumns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN mikrotik_username VARCHAR(100)");
        echo "Added mikrotik_username to clients.\n";
    }
    if (!in_array('mikrotik_password', $clientColumns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN mikrotik_password VARCHAR(100)");
        echo "Added mikrotik_password to clients.\n";
    }
    if (!in_array('connection_type', $clientColumns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN connection_type ENUM('pppoe', 'hotspot') DEFAULT 'pppoe'");
        echo "Added connection_type to clients.\n";
    }
    if (!in_array('router_id', $clientColumns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN router_id INT");
        echo "Added router_id to clients.\n";
    }

    // Insert default router if not exists (using placeholder data)
    $stmt = $pdo->query("SELECT COUNT(*) FROM mikrotik_routers");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO mikrotik_routers (name, ip_address, username, password) VALUES 
            ('Default Router', '192.168.88.1', 'admin', '')");
        echo "Inserted default router record.\n";
    }

    echo "Database update completed successfully!\n";

} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}
