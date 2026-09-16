<?php
/** Offline provider doubles: no messages leave the machine. */
require_once __DIR__ . '/../classes/SMSHelper.php';
class SenderConfigPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SenderConfigStatement(); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new SenderConfigStatement(); }
}
class SenderConfigStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return ['api_key'=>'offline-double', 'sender_id'=>'APPROVED', 'api_url'=>SMS_API_URL_DEFAULT];
    }
}
class SenderHelperDouble extends SMSHelper {
    public array $replies = [];
    public array $routes = [];
    protected function sendViaTalkSasa($phone, $message) {
        $this->routes[] = $this->isUsingPlatform();
        return array_shift($this->replies);
    }
}
function senderCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
foreach (['Originator TALKSASA is not authorized to send this message', 'Invalid sender ID', 'Sender ID is not approved'] as $message) {
    senderCheck(smsSenderRejected($message), 'Explicit sender rejection recognized');
}
foreach (['Insufficient credits', 'Response timed out', 'Unauthenticated.'] as $message) {
    senderCheck(!smsSenderRejected($message), 'Other failures stay distinct');
}
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'sender_failure'=>true], ['success'=>true]];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck($result['success'] && $result['fell_back'] && $helper->routes === [false,true], 'Rejected tenant sender falls back with platform configuration');
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'sender_failure'=>true], ['success'=>false,'message'=>'Platform sender rejected']];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck(!$result['success'] && $result['tenant_sender_failure'] && !isset($result['tenant_auth_failure']), 'Failed fallback reports sender problem honestly');
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'uncertain'=>true,'sender_failure'=>true]];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck(!$result['success'] && $helper->routes === [false], 'Uncertain delivery never triggers a duplicate fallback send');
