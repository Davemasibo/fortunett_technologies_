<?php
require_once __DIR__ . '/../includes/customer_sms.php';
class CustomerSmsPDO extends PDO {
    public array $client = ['id'=>7,'tenant_id'=>1,'full_name'=>'Test Customer','phone'=>'254712345678','package_price'=>50];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'c.tenant_id=?') || !str_contains($query, 'p.tenant_id=c.tenant_id')) throw new RuntimeException('Missing tenant scope');
        return new CustomerSmsStatement($this);
    }
}
class CustomerSmsStatement extends PDOStatement {
    private mixed $row = false;
    public function __construct(private CustomerSmsPDO $db) {}
    public function execute(?array $params = null): bool {
        $this->row = $params === [7,1] ? $this->db->client : false; return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
class CustomerSmsHelper extends SMSHelper {
    public array $calls = [];
    public array $result = ['success'=>true];
    public function __construct() {}
    public function send($phone, $message, $clientId = null, $log = true) {
        $this->calls[] = [$phone,$message,$clientId]; return $this->result;
    }
}
function customerSmsCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$db = new CustomerSmsPDO(); $helper = new CustomerSmsHelper();
$result = sendCustomerSms($db,1,7,'Hello {name}, KES {amount}',$helper);
customerSmsCheck($result['success'] && $helper->calls === [['254712345678','Hello Test Customer, KES 50.00',7]], 'Send uses saved recipient and resolves real customer/package template details');
customerSmsCheck(str_contains($result['message'], 'accepted by the provider'), 'Success does not claim handset delivery');
customerSmsCheck(!sendCustomerSms($db,2,7,'Hello',$helper)['success'] && count($helper->calls) === 1, 'Other tenants cannot send to this customer');
customerSmsCheck(!sendCustomerSms($db,1,7,'  ',$helper)['success'] && count($helper->calls) === 1, 'Blank message does not reach provider');
$db->client['phone'] = '';
customerSmsCheck(!sendCustomerSms($db,1,7,'Hello',$helper)['success'] && count($helper->calls) === 1, 'Missing phone returns actionable error without sending');
$db->client['phone'] = '254712345678';
$helper->result = ['success'=>false,'message'=>'Insufficient balance'];
$result = sendCustomerSms($db,1,7,'Hello',$helper);
customerSmsCheck($result['retryable'] && $result['message'] === 'Insufficient balance', 'Provider rejection is preserved for the customer form');
$helper->result = ['success'=>false,'uncertain'=>true,'message'=>'Provider timed out'];
customerSmsCheck(!sendCustomerSms($db,1,7,'Hello',$helper)['retryable'], 'Unconfirmed send is not offered for immediate retry');
echo "All customer SMS checks passed. No messages sent.\n";
