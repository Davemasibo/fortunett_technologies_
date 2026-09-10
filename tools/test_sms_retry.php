<?php
require_once __DIR__ . '/../includes/sms_retry.php';
class SmsRetryPDO extends PDO {
    public array $sms = ['id'=>7,'tenant_id'=>1,'client_id'=>3,'status'=>'failed','recipient_phone'=>'254712345678','message'=>'Your original message'];
    public array $attempts = [];
    public bool $locked = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SmsRetryStatement($this, $query); }
    public function lastInsertId(?string $name = null): string|false { return (string)count($this->attempts); }
}
class SmsRetryStatement extends PDOStatement {
    private mixed $row = false;
    public function __construct(private SmsRetryPDO $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->sql, 'GET_LOCK')) {
            $this->row = $this->db->locked ? 0 : 1; $this->db->locked = true;
        } elseif (str_contains($this->sql, 'RELEASE_LOCK')) $this->db->locked = false;
        elseif (str_contains($this->sql, 'SELECT * FROM sms_outbox')) {
            $this->row = $params === [$this->db->sms['id'], $this->db->sms['tenant_id']] ? $this->db->sms : false;
        } elseif (str_contains($this->sql, 'SELECT status')) $this->row = end($this->db->attempts) ?: false;
        elseif (str_starts_with($this->sql, 'INSERT')) $this->db->attempts[] = 'sending';
        elseif (str_starts_with($this->sql, 'UPDATE')) $this->db->attempts[(int)$params[2]-1] = $params[0];
        else throw new RuntimeException('Unexpected SQL');
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->row; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
function smsCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$db = new SmsRetryPDO(); $calls = 0;
$send = function ($phone, $message, $client) use (&$calls, $db) {
    $calls++;
    smsCheck([$phone,$message,$client] === ['254712345678','Your original message',3], 'Retry uses original recipient, message and customer');
    smsCheck(end($db->attempts) === 'sending', 'Attempt is persisted before sending');
    $duplicate = retryFailedSms($db,1,7,fn()=>throw new RuntimeException('Duplicate send'));
    smsCheck(!$duplicate['success'], 'Concurrent retry is blocked');
    return ['success'=>true];
};
smsCheck(retryFailedSms($db,1,7,$send)['success'], 'Successful retry is reported');
smsCheck(retryFailedSms($db,1,7,$send)['success'] && $calls === 1, 'Successful retry cannot be replayed');
smsCheck($db->sms['status'] === 'failed' && $db->attempts === ['sent'], 'Original failure retained alongside retry outcome');
foreach ([['tenant_id',2],['status','sent']] as [$key,$value]) {
    $db = new SmsRetryPDO(); $db->sms[$key] = $value; $denied = false;
    try { retryFailedSms($db,1,7,fn()=>throw new RuntimeException('Must not send')); } catch (InvalidArgumentException $e) { $denied = true; }
    smsCheck($denied && !$db->locked && !$db->attempts, 'Reject ineligible message: '.$key);
}
$db = new SmsRetryPDO();
smsCheck(retryFailedSms($db,1,7,fn()=>['success'=>false,'message'=>'Insufficient balance'])['retryable'], 'Definite failure can be retried');
smsCheck(retryFailedSms($db,1,7,fn()=>['success'=>true])['success'] && $db->attempts === ['failed','sent'], 'Later success preserves each attempt');
$db = new SmsRetryPDO();
$result = retryFailedSms($db,1,7,fn()=>['success'=>false,'uncertain'=>true]);
smsCheck(!$result['retryable'] && $db->attempts === ['unknown'], 'Unconfirmed provider outcome prevents duplicate delivery');
smsCheck(!retryFailedSms($db,1,7,fn()=>throw new RuntimeException('Must not send'))['retryable'], 'Unconfirmed retry cannot be replayed');
$db = new SmsRetryPDO(); $db->attempts = ['sending'];
smsCheck(!retryFailedSms($db,1,7,fn()=>throw new RuntimeException('Must not send'))['retryable'], 'Interrupted request remains protected after its lock is released');
echo "All SMS retry checks passed. No messages sent.\n";
