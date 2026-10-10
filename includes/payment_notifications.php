<?php
require_once __DIR__ . '/hotspot_connection.php';

/** Caller holds the customer payment lock. Keep credentials stable during outages. */
function ensurePaidLoginCredentials(PDO $pdo, int $tenant, int $client): array {
    $st = $pdo->prepare('SELECT phone, connection_type, mikrotik_username, mikrotik_password FROM clients WHERE id=? AND tenant_id=?');
    $st->execute([$client, $tenant]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Payment customer not found');
    $hotspot = ($row['connection_type'] ?? 'hotspot') !== 'pppoe';
    $username = $row['mikrotik_username'] ?: ($hotspot ? hotspotPhoneUsername($row['phone'] ?? '') : 'user_' . $client);
    $password = $row['mikrotik_password'] ?: ($hotspot ? hotspotGeneratePin() : bin2hex(random_bytes(4)));
    $pdo->prepare('UPDATE clients SET mikrotik_username=?, mikrotik_password=? WHERE id=? AND tenant_id=?')->execute([$username, $password, $client, $tenant]);
    return ['username'=>$username, 'password'=>$password];
}

function paymentNotificationSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL, client_id INT NOT NULL, payment_id INT NOT NULL,
        phone VARCHAR(40) NOT NULL, message TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error TEXT NULL, config_hash CHAR(64) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY payment_notice (tenant_id, payment_id), INDEX due_notice (status, next_retry_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    require_once __DIR__ . '/schema_guard.php';
    ensureColumn($pdo, 'payment_notifications', 'config_hash', 'CHAR(64) NULL');
}

function paymentNotificationConfigHash(PDO $pdo, int $tenant): string {
    require_once __DIR__ . '/sms_config.php';
    [$config] = smsResolveConfig($pdo, $tenant);
    return hash('sha256', json_encode($config ? [
        trim((string)($config['api_key'] ?? '')),
        trim((string)($config['sender_id'] ?? '')),
        smsNormalizeApiUrl($config['api_url'] ?? null),
    ] : []));
}

/** Queue before router/network I/O. Old failed log entries never count as delivery. */
function queuePaymentNotification(PDO $pdo, int $tenant, int $client, int $payment, string $receipt, string $phone, string $message): int {
    paymentNotificationSchema($pdo);
    require_once __DIR__ . '/schema_guard.php';
    ensureSmsTables($pdo);
    $st = $pdo->prepare("SELECT 1 FROM sms_logs WHERE tenant_id=? AND client_id=? AND reference=? AND status='sent' AND (message LIKE '%Login:%' OR message LIKE '%Username:%') LIMIT 1");
    $st->execute([$tenant, $client, $receipt]);
    $state = $st->fetchColumn() ? 'sent' : 'pending';
    $pdo->prepare('INSERT IGNORE INTO payment_notifications (tenant_id,client_id,payment_id,phone,message,status) VALUES (?,?,?,?,?,?)')
        ->execute([$tenant,$client,$payment,$phone,$message,$state]);
    $st = $pdo->prepare('SELECT id FROM payment_notifications WHERE tenant_id=? AND payment_id=?');
    $st->execute([$tenant,$payment]);
    return (int)$st->fetchColumn();
}

/** Durable sending state prevents another worker replaying an uncertain send. */
function deliverPaymentNotification(PDO $pdo, int $id, ?callable $sender = null): string {
    $st = $pdo->prepare("UPDATE payment_notifications SET status='sending',attempts=attempts+1 WHERE id=? AND status IN ('pending','failed') AND next_retry_at<=NOW()");
    $st->execute([$id]);
    if (!$st->rowCount()) return 'skipped';
    $st = $pdo->prepare('SELECT * FROM payment_notifications WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $configHash = null;
    $missingConfig = false;
    try {
        if (!$sender) {
            require_once __DIR__ . '/../classes/SMSHelper.php';
            $helper = new SMSHelper($pdo, (int)$row['tenant_id']);
            $configHash = paymentNotificationConfigHash($pdo, (int)$row['tenant_id']);
            $missingConfig = !$helper->hasConfig();
            $sender = fn($phone,$message,$client) => $helper->send($phone,$message,$client);
        }
        $res = $sender($row['phone'],$row['message'],(int)$row['client_id']);
        $state = !empty($res['success']) ? 'sent' : (!empty($res['uncertain']) ? 'unknown' : 'failed');
        if ($state === 'failed' && ($missingConfig || !empty($res['sender_failure']) || !empty($res['auth_failure']))) {
            $state = 'blocked_config';
        }
    } catch (Throwable $e) {
        $state = 'unknown';
        $res = ['message'=>'SMS outcome uncertain: ' . $e->getMessage()];
    }
    $pdo->prepare('UPDATE payment_notifications SET status=?,last_error=?,config_hash=?,next_retry_at=NOW()+INTERVAL 1 MINUTE WHERE id=?')
        ->execute([$state,$res['message'] ?? null,$configHash,$id]);
    return $state;
}

function retryPaymentNotifications(PDO $pdo, ?callable $sender = null): void {
    paymentNotificationSchema($pdo);
    // An unapproved/discontinued sender needs configuration repair, not repeated
    // API requests. Resume automatically when its effective settings change.
    $blocked = $pdo->query("SELECT DISTINCT tenant_id,config_hash FROM payment_notifications WHERE status='blocked_config'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($blocked as $row) {
        $currentHash = paymentNotificationConfigHash($pdo, (int)$row['tenant_id']);
        if ($row['config_hash'] !== null && $currentHash !== $row['config_hash']) {
            $pdo->prepare("UPDATE payment_notifications SET status='pending',next_retry_at=NOW() WHERE tenant_id=? AND status='blocked_config' AND config_hash=?")
                ->execute([$row['tenant_id'],$row['config_hash']]);
        }
    }
    $ids = $pdo->query("SELECT id FROM payment_notifications WHERE status IN ('pending','failed') AND next_retry_at<=NOW() ORDER BY next_retry_at,id LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        try { deliverPaymentNotification($pdo, (int)$id, $sender); }
        catch (Throwable $e) { error_log('Payment notification recovery: ' . $e->getMessage()); }
    }
}
