<?php
require_once __DIR__ . '/payment_notifications.php';
require_once __DIR__ . '/sms_templates.php';
function customerWelcomeSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_welcome_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL, client_id INT NOT NULL,
        phone VARCHAR(40) NOT NULL, message TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error TEXT NULL, config_hash CHAR(64) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY welcome_customer (tenant_id,client_id), INDEX due_welcome (status,next_retry_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function customerWelcomeMessage(array $client, array $context): string {
    // Registration explicitly includes portal credentials for both service types.
    $renderClient = array_merge($client, ['connection_type'=>'hotspot']);
    return smsRenderCustomerTemplate("Welcome to {network_name}, {name}!\nUsername: {username}\nPassword: {password}\nAccount: {account_number}\nPackage: {package_name}\n{payment_instructions}\nCustomer portal: {portal_url}\n{support_line}", $renderClient, $context);
}
function queueCustomerWelcome(PDO $pdo, int $tenant, int $client): int {
    $st = $pdo->prepare('SELECT c.*, p.name AS package_name, p.price AS package_price FROM clients c LEFT JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id WHERE c.id=? AND c.tenant_id=?');
    $st->execute([$client,$tenant]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || trim($row['phone'] ?? '') === '') throw new InvalidArgumentException('A customer phone number is required for the welcome SMS.');
    $message = customerWelcomeMessage($row, smsTenantTemplateContext($pdo,$tenant));
    $pdo->prepare('INSERT IGNORE INTO customer_welcome_notifications (tenant_id,client_id,phone,message) VALUES (?,?,?,?)')->execute([$tenant,$client,$row['phone'],$message]);
    $pdo->prepare("UPDATE customer_welcome_notifications SET phone=?,message=?,status='pending',last_error=NULL,next_retry_at=NOW() WHERE tenant_id=? AND client_id=? AND status='preparing'")->execute([$row['phone'],$message,$tenant,$client]);
    $st = $pdo->prepare('SELECT id FROM customer_welcome_notifications WHERE tenant_id=? AND client_id=?');
    $st->execute([$tenant,$client]); return (int)$st->fetchColumn();
}
function retryCustomerWelcomes(PDO $pdo, ?callable $sender = null): void {
    customerWelcomeSchema($pdo);
    foreach ($pdo->query("SELECT id,tenant_id,client_id FROM customer_welcome_notifications WHERE status='preparing' AND next_retry_at<=NOW() ORDER BY next_retry_at,id LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $notice) {
        try {
            $account = $pdo->prepare('SELECT account_number FROM clients WHERE id=? AND tenant_id=?');
            $account->execute([$notice['client_id'],$notice['tenant_id']]);
            if (!$account->fetchColumn()) {
                require_once __DIR__ . '/account_number_generator.php';
                $number = (new AccountNumberGenerator($pdo))->generateAccountNumber((int)$notice['tenant_id']);
                $pdo->prepare("UPDATE clients SET account_number=? WHERE id=? AND tenant_id=? AND (account_number IS NULL OR account_number='')")->execute([$number,$notice['client_id'],$notice['tenant_id']]);
            }
            queueCustomerWelcome($pdo,(int)$notice['tenant_id'],(int)$notice['client_id']);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE customer_welcome_notifications SET last_error=?,next_retry_at=NOW()+INTERVAL 1 MINUTE WHERE id=?')->execute([substr($e->getMessage(),0,2000),$notice['id']]);
        }
    }
    $blocked = $pdo->query("SELECT DISTINCT tenant_id,config_hash FROM customer_welcome_notifications WHERE status='blocked_config'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($blocked as $row) {
        if ($row['config_hash'] !== null && paymentNotificationConfigHash($pdo,(int)$row['tenant_id']) !== $row['config_hash']) {
            $pdo->prepare("UPDATE customer_welcome_notifications SET status='pending',next_retry_at=NOW() WHERE tenant_id=? AND status='blocked_config' AND config_hash=?")->execute([$row['tenant_id'],$row['config_hash']]);
        }
    }
    foreach ($pdo->query("SELECT id FROM customer_welcome_notifications WHERE status IN ('pending','failed') AND next_retry_at<=NOW() ORDER BY next_retry_at,id LIMIT 30")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { deliverPaymentNotification($pdo,(int)$id,$sender,true); }
        catch (Throwable $e) { error_log('Welcome SMS retry: '.$e->getMessage()); }
    }
}
