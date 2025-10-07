CREATE DATABASE IF NOT EXISTS fortunnet_technologies;
USE fortunnet_technologies;

CREATE TABLE IF NOT EXISTS isp_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    subscription_expiry DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'operator') DEFAULT 'operator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    mikrotik_username VARCHAR(50) UNIQUE,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive',
    subscription_plan VARCHAR(50),
    data_limit BIGINT DEFAULT 0,
    download_speed INT DEFAULT 0,
    upload_speed INT DEFAULT 0,
    monthly_fee DECIMAL(10,2) DEFAULT 0,
    last_payment_date DATE,
    next_payment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('mpesa', 'cash', 'bank_transfer', 'card') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    transaction_id VARCHAR(100),
    status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mikrotik_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    port INT DEFAULT 8728,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default ISP profile
INSERT IGNORE INTO isp_profile (business_name, email, phone, subscription_expiry) 
VALUES ('Fortunnet Technologies', 'admin@fortunnet.com', '+254700000000', DATE_ADD(CURDATE(), INTERVAL 30 DAY));

-- Insert admin user (password: admin123)
INSERT IGNORE INTO users (username, password_hash, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@fortunnet.com', 'admin');

-- Insert sample clients
INSERT IGNORE INTO clients (full_name, email, phone, address, mikrotik_username, status, subscription_plan, data_limit, download_speed, upload_speed, monthly_fee, last_payment_date, next_payment_date) VALUES
('John Kamau', 'john@email.com', '+254711000001', 'Nairobi, Kenya', 'johnk', 'active', 'Premium', 107374182400, 20, 10, 2500.00, '2024-01-01', '2024-02-01'),
('Mary Wanjiku', 'mary@email.com', '+254711000002', 'Mombasa, Kenya', 'maryw', 'active', 'Standard', 53687091200, 10, 5, 1500.00, '2024-01-05', '2024-02-05'),
('Peter Otieno', 'peter@email.com', '+254711000003', 'Kisumu, Kenya', 'petero', 'inactive', 'Basic', 26843545600, 5, 2, 800.00, '2023-12-20', '2024-01-20'),
('Grace Achieng', 'grace@email.com', '+254711000004', 'Nakuru, Kenya', 'gracea', 'suspended', 'Premium', 107374182400, 20, 10, 2500.00, '2023-11-15', '2023-12-15');

-- Insert sample payments
INSERT IGNORE INTO payments (client_id, amount, payment_method, payment_date, transaction_id, status) VALUES
(1, 2500.00, 'mpesa', '2024-01-01', 'MPE123456', 'completed'),
(2, 1500.00, 'cash', '2024-01-05', 'CASH001', 'completed'),
(1, 2500.00, 'mpesa', '2023-12-01', 'MPE123455', 'completed'),
(3, 800.00, 'bank_transfer', '2023-12-20', 'BT001', 'completed');