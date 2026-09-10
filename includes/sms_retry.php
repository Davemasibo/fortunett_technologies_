<?php
require_once __DIR__ . '/../classes/SMSHelper.php';

function smsRetrySchema(PDO $pdo): void {
    ensureSmsTables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_retry_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL, outbox_id BIGINT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'sending',
        provider_response TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        INDEX sms_retry_lookup (tenant_id, outbox_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Claim is durable before calling the provider: interrupted sends cannot replay. */
function retryFailedSms(PDO $pdo, int $tenant, int $id, ?callable $sender = null): array {
    if ($tenant < 1 || $id < 1) throw new InvalidArgumentException('Invalid message.');
    $lock = 'sms-retry-' . $tenant . '-' . $id;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$lock]);
    if (!(int)$stmt->fetchColumn()) return ['success' => false, 'retryable' => false, 'message' => 'A retry is already in progress. Refresh the history shortly.'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM sms_outbox WHERE id=? AND tenant_id=?');
        $stmt->execute([$id, $tenant]);
        $sms = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sms || $sms['status'] !== 'failed') throw new InvalidArgumentException('Only failed messages in your account can be retried.');
        $stmt = $pdo->prepare('SELECT status FROM sms_retry_attempts WHERE tenant_id=? AND outbox_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$tenant, $id]);
        $prior = $stmt->fetchColumn();
        if ($prior && $prior !== 'failed') {
            return ['success' => $prior === 'sent', 'retryable' => false, 'message' => $prior === 'sent' ? 'This message was already resent successfully.' : 'Delivery is awaiting confirmation. Check the provider history before sending another message.'];
        }
        $stmt = $pdo->prepare("INSERT INTO sms_retry_attempts (tenant_id,outbox_id,status) VALUES (?,?,'sending')");
        $stmt->execute([$tenant, $id]);
        $attempt = $pdo->lastInsertId();
        try {
            $sender = $sender ?? function ($phone, $message, $client) use ($pdo, $tenant) {
                return (new SMSHelper($pdo, $tenant))->send($phone, $message, $client, false);
            };
            $result = $sender($sms['recipient_phone'], $sms['message'], $sms['client_id']);
            $state = !empty($result['success']) ? 'sent' : (!empty($result['uncertain']) ? 'unknown' : 'failed');
        } catch (Throwable $e) {
            error_log('SMS retry provider: ' . $e->getMessage());
            $state = 'unknown';
            $result = ['success' => false];
        }
        $stmt = $pdo->prepare('UPDATE sms_retry_attempts SET status=?,provider_response=?,finished_at=NOW() WHERE id=? AND tenant_id=?');
        $stmt->execute([$state, json_encode($result), $attempt, $tenant]);
        return ['success' => $state === 'sent', 'retryable' => $state === 'failed', 'message' => $state === 'sent'
            ? 'SMS resend accepted by the provider.'
            : ($state === 'unknown' ? 'Delivery could not be confirmed. Check the provider history before sending another message.' : ($result['message'] ?? 'Retry failed. You can try again.'))];
    } finally {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lock]);
    }
}
