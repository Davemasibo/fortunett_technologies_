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
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    company VARCHAR(255),
    mikrotik_username VARCHAR(50) UNIQUE,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive',
    user_type ENUM('pppoe','hotspot') DEFAULT 'pppoe',
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

-- Create packages table for service packages
CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('hotspot','pppoe') DEFAULT 'hotspot',
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    duration VARCHAR(50),
    features TEXT,
    download_speed INT DEFAULT 0,
    upload_speed INT DEFAULT 0,
    data_limit BIGINT DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create messages/notifications table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    message_type ENUM('reminder', 'payment', 'credential', 'general', 'alert') DEFAULT 'general',
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread', 'read', 'archived') DEFAULT 'unread',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- Insert default ISP profile
INSERT IGNORE INTO isp_profile (business_name, email, phone, subscription_expiry) 
VALUES ('Fortunnet Technologies', 'admin@fortunnet.com', '+254700000000', DATE_ADD(CURDATE(), INTERVAL 30 DAY));

-- Insert admin user (password: admin123)
INSERT IGNORE INTO users (username, password_hash, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@fortunnet.com', 'admin');

-- Insert sample clients with name field populated
INSERT IGNORE INTO clients (full_name, name, email, phone, address, company, mikrotik_username, status, subscription_plan, data_limit, download_speed, upload_speed, monthly_fee, last_payment_date, next_payment_date) VALUES
('John Kamau', 'John Kamau', 'john@email.com', '+254711000001', 'Nairobi, Kenya', 'Johns Company', 'johnk', 'active', 'Premium', 107374182400, 20, 10, 2500.00, '2024-01-01', '2024-02-01'),
('Mary Wanjiku', 'Mary Wanjiku', 'mary@email.com', '+254711000002', 'Mombasa, Kenya', 'Marys Company', 'maryw', 'active', 'Standard', 53687091200, 10, 5, 1500.00, '2024-01-05', '2024-02-05'),
('Peter Otieno', 'Peter Otieno', 'peter@email.com', '+254711000003', 'Kisumu, Kenya', 'Peters Company', 'petero', 'inactive', 'Basic', 26843545600, 5, 2, 800.00, '2023-12-20', '2024-01-20'),
('Grace Achieng', 'Grace Achieng', 'grace@email.com', '+254711000004', 'Nakuru, Kenya', 'Graces Company', 'gracea', 'suspended', 'Premium', 107374182400, 20, 10, 2500.00, '2023-11-15', '2023-12-15');

-- Insert sample payments
INSERT IGNORE INTO payments (client_id, amount, payment_method, payment_date, transaction_id, status) VALUES
(1, 2500.00, 'mpesa', '2024-01-01', 'MPE123456', 'completed'),
(2, 1500.00, 'cash', '2024-01-05', 'CASH001', 'completed'),
(1, 2500.00, 'mpesa', '2023-12-01', 'MPE123455', 'completed'),
(3, 800.00, 'bank_transfer', '2023-12-20', 'BT001', 'completed');

-- Insert sample packages
INSERT IGNORE INTO packages (name, description, price, duration, features, download_speed, upload_speed, data_limit, status) VALUES
('Basic', 'Perfect for home users with light browsing', 800.00, '30 days', 'Unlimited browsing,Email support,Fair usage policy', 5, 2, 26843545600, 'active'),
('Standard', 'Ideal for small businesses and streaming', 1500.00, '30 days', 'Unlimited browsing,Priority support,No data caps,HD streaming', 10, 5, 53687091200, 'active'),
('Premium', 'Best for heavy users and businesses', 2500.00, '30 days', 'Unlimited browsing,24/7 support,No data caps,4K streaming,Static IP', 20, 10, 107374182400, 'active'),
('Enterprise', 'Custom solutions for large organizations', 5000.00, '30 days', 'Unlimited browsing,Dedicated support,Multiple static IPs,Priority bandwidth,SLA guarantee', 50, 25, 214748364800, 'active');

-- Insert sample messages
INSERT IGNORE INTO messages (client_id, message_type, subject, message, status, priority, created_at) VALUES
(1, 'payment', 'Payment Received - Thank You!', 'Dear John Kamau,\n\nYour payment of KES 2,500.00 has been successfully received via M-PESA (Transaction: MPE123456).\n\nYour subscription for Premium package has been renewed until February 1, 2024.\n\nThank you for your continued trust in FortuNNet Technologies.\n\nBest regards,\nFortuNNet Technologies', 'read', 'normal', DATE_SUB(NOW(), INTERVAL 5 DAY)),

(2, 'credential', 'Your Internet Login Credentials', 'Dear Mary Wanjiku,\n\nWelcome to FortuNNet Technologies!\n\nYour internet connection has been activated. Here are your login credentials:\n\nUsername: maryw\nPassword: [Contact support for password]\nPackage: Standard (10Mbps/5Mbps)\n\nFor PPPoE connections, please configure your router with the above credentials.\n\nFor any assistance, contact us at +254700000000\n\nBest regards,\nFortuNNet Technologies', 'read', 'high', DATE_SUB(NOW(), INTERVAL 10 DAY)),

(3, 'reminder', 'Payment Reminder - Subscription Expiring Soon', 'Dear Peter Otieno,\n\nThis is a friendly reminder that your subscription will expire on January 20, 2024.\n\nPackage: Basic\nAmount Due: KES 800.00\n\nTo avoid service interruption, please make your payment before the expiry date.\n\nPayment Methods:\n- M-PESA: Paybill 123456, Account: petero\n- Cash: Visit our office\n- Bank Transfer: Account details available on request\n\nThank you,\nFortuNNet Technologies', 'unread', 'high', DATE_SUB(NOW(), INTERVAL 2 DAY)),

(4, 'alert', 'Account Suspended - Payment Overdue', 'Dear Grace Achieng,\n\nYour account has been temporarily suspended due to non-payment.\n\nPackage: Premium\nAmount Overdue: KES 2,500.00\nDue Date: December 15, 2023\n\nTo reactivate your service, please clear the outstanding balance immediately.\n\nOnce payment is confirmed, your service will be restored within 30 minutes.\n\nFor payment assistance, call +254700000000\n\nFortuNNet Technologies', 'unread', 'urgent', DATE_SUB(NOW(), INTERVAL 1 DAY)),

(1, 'reminder', 'Subscription Renewal Due in 7 Days', 'Dear John Kamau,\n\nYour Premium package subscription will expire in 7 days (February 1, 2024).\n\nPackage Details:\n- Speed: 20Mbps Download / 10Mbps Upload\n- Data: Unlimited\n- Monthly Fee: KES 2,500.00\n\nRenew now to enjoy uninterrupted internet service!\n\nPayment Methods:\n- M-PESA Paybill: 123456 (Account: johnk)\n- Online Payment: www.fortunnet.com/pay\n- Office Payment: Visit our branch\n\nThank you for choosing FortuNNet Technologies!\n\nBest regards,\nSupport Team', 'unread', 'normal', NOW()),

(2, 'general', 'Network Maintenance Notification', 'Dear Mary Wanjiku,\n\nScheduled Maintenance Notice\n\nWe will be performing routine network maintenance to improve service quality.\n\nDate: January 15, 2024\nTime: 2:00 AM - 4:00 AM\nDuration: Approximately 2 hours\n\nYou may experience brief service interruptions during this period. We apologize for any inconvenience.\n\nFor urgent support, contact +254700000000\n\nThank you for your patience.\n\nFortuNNet Technologies', 'unread', 'normal', DATE_SUB(NOW(), INTERVAL 3 HOUR)),

(1, 'payment', 'Payment Confirmation Required', 'Dear John Kamau,\n\nWe have received a payment notification but need to confirm the transaction details.\n\nTransaction Details:\n- Amount: KES 2,500.00\n- Reference: MPE123456\n- Date: January 1, 2024\n\nIf you made this payment, please reply with:\n1. M-PESA confirmation message\n2. Transaction date and time\n\nOnce verified, your subscription will be activated immediately.\n\nContact: +254700000000\nEmail: admin@fortunnet.com\n\nThank you,\nAccounts Department', 'read', 'high', DATE_SUB(NOW(), INTERVAL 6 DAY)),

(3, 'credential', 'Password Reset - Security Update', 'Dear Peter Otieno,\n\nYour password has been reset as per your request.\n\nTemporary Password: TempPass123\n\nFor security reasons, please change your password immediately after logging in.\n\nUsername: petero\nLogin Portal: https://portal.fortunnet.com\n\nIf you did not request this change, please contact support immediately at +254700000000\n\nFortuNNet Technologies\nTechnical Support', 'unread', 'urgent', DATE_SUB(NOW(), INTERVAL 4 HOUR)),

(2, 'reminder', 'Data Usage Alert - 80% Consumed', 'Dear Mary Wanjiku,\n\nData Usage Alert\n\nYou have consumed 80% of your monthly data allocation.\n\nPackage: Standard\nData Limit: 50GB\nUsed: 40GB\nRemaining: 10GB\nResets: February 5, 2024\n\nTips to manage your data:\n- Avoid HD video streaming\n- Schedule large downloads for off-peak hours\n- Consider upgrading to Premium for unlimited data\n\nTo upgrade or purchase extra data, call +254700000000\n\nFortuNNet Technologies', 'unread', 'normal', DATE_SUB(NOW(), INTERVAL 1 HOUR)),

(4, 'general', 'Special Offer - Reconnection Discount', 'Dear Grace Achieng,\n\nWe value your business!\n\nSpecial Reconnection Offer:\n- Clear your balance and get 20% OFF next month\n- Free installation of upgraded equipment\n- Priority support for 3 months\n\nOffer valid until: January 31, 2024\n\nAmount to Clear: KES 2,500.00\nDiscount on Next Payment: KES 500.00\n\nDon\'t miss this opportunity to get back online with great savings!\n\nContact us: +254700000000\nVisit: www.fortunnet.com\n\nFortuNNet Technologies\nCustomer Retention Team', 'unread', 'normal', DATE_SUB(NOW(), INTERVAL 6 HOUR));