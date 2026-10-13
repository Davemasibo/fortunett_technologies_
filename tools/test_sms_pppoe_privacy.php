<?php
require_once __DIR__.'/../classes/SMSHelper.php';
class PrivacyPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        if (!str_contains($query,'id=? AND tenant_id=?')) throw new RuntimeException('Missing customer/tenant scope');
        return new PrivacyStatement();
    }
}
class PrivacyStatement extends PDOStatement {
    private bool $found=false;
    public function execute(?array $params=null):bool { $this->found=$params===[7,1];return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed { return $this->found ? ['connection_type'=>'pppoe'] : false; }
}
class PrivacySender extends SMSHelper {
    public array $messages=[];
    public function __construct() {
        foreach(['pdo'=>new PrivacyPDO(),'tenant_id'=>1,'config'=>['api_key'=>'TEST_KEY','sender_id'=>'TEST']] as $key=>$value) (new ReflectionProperty(SMSHelper::class,$key))->setValue($this,$value);
    }
    protected function sendProviderHttp($phone,$message) { $this->messages[]=$message;return ['success'=>true]; }
}
$sender=new PrivacySender();
$result=$sender->send('0712345678',"Payment received. Active until tomorrow.\nUsername: saved-user\nPassword: saved-pin\nReceipt ABC987",7,false);
if (!$result['success'] || count($sender->messages)!==1 || str_contains($sender->messages[0],'saved-user') || str_contains($sender->messages[0],'saved-pin') || !str_contains($sender->messages[0],'Receipt ABC987')) throw new RuntimeException('Provider boundary disclosed PPPoE credentials or removed payment details');
$result=$sender->send('0712345678','Hello',8,false);
if ($result['success'] || count($sender->messages)!==1) throw new RuntimeException('Cross-tenant customer reached provider');
echo "PASS: PPPoE queued credential lines removed at provider boundary; payment details retained; unknown customers blocked. No SMS sent.\n";
