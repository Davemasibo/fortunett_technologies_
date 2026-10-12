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
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'auth_failure'=>true,'message'=>'Tenant token expired'], ['success'=>false,'uncertain'=>true,'message'=>'Provider timed out']];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck(!empty($result['uncertain']) && str_contains($result['message'], 'Tenant token expired') && str_contains($result['message'], 'Provider timed out'), 'Uncertain fallback preserves both failure reasons');
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'message'=>'Tenant balance depleted'], ['success'=>true]];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck($result['success'] && $result['fell_back'] && $helper->routes === [false,true], 'Confirmed credit rejection falls back to platform');
$helper = new SenderHelperDouble(new SenderConfigPDO(), 7);
$helper->replies = [['success'=>false,'message'=>'Tenant balance depleted'], ['success'=>false,'message'=>'Platform balance depleted']];
$result = $helper->send('0712345678', 'Payment received', null, false);
senderCheck(!isset($result['tenant_auth_failure']) && !empty($result['tenant_failure']), 'Credit failures do not claim tenant token rejection');
