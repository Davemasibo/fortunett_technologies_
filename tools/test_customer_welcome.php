<?php
require_once __DIR__ . '/../includes/customer_welcome.php';
require_once __DIR__ . '/../classes/SMSHelper.php';
function welcomeCheck(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$client = ['full_name'=>'Jane','mikrotik_username'=>'jane','mikrotik_password'=>'secret-pin','account_number'=>'HL001','package_name'=>'Home 10Mbps','package_price'=>1500,'connection_type'=>'pppoe'];
$context = ['company'=>'Homelink','payment_kind'=>'paybill','payment_number'=>'123456','portal_url'=>'https://example.test/customer/login.php','support'=>'0712345678'];
foreach (['pppoe','hotspot'] as $type) {
    $message = customerWelcomeMessage(array_merge($client,['connection_type'=>$type]),$context);
    foreach (['Username: jane','Password: secret-pin','HL001','Home 10Mbps','123456','1500.00','https://example.test/customer/login.php','0712345678'] as $expected) {
        welcomeCheck(str_contains($message,$expected), "$type welcome contains $expected");
    }
    welcomeCheck(!str_contains($message,'{'),'Welcome contains no unresolved placeholders');
}
class WelcomePDO extends PDO {
    public array $row = ['id'=>1,'tenant_id'=>2,'client_id'=>3,'phone'=>'0712345678','message'=>'Username: jane Password: secret-pin','status'=>'pending'];
    public bool $due = true;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new WelcomeStatement($this,$query); }
}
class WelcomeStatement extends PDOStatement {
    private int $affected = 0;
    public function __construct(private WelcomePDO $db, private string $sql) {}
    public function execute(?array $params=null): bool {
        if (!str_contains($this->sql,'customer_welcome_notifications')) throw new RuntimeException('Wrong queue');
        if (str_contains($this->sql,"SET status='sending'")) {
            $this->affected = (int)(in_array($this->db->row['status'],['pending','failed'],true) && $this->db->due);
            if ($this->affected) $this->db->row['status']='sending';
        } elseif (str_contains($this->sql,'SET status=?')) {
            $this->db->row['status']=$params[0]; $this->db->due=false;
        }
        return true;
    }
    public function rowCount():int { return $this->affected; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed { return $this->db->row; }
}
$db = new WelcomePDO(); $calls = 0;
$sender = function($phone,$text,$client) use (&$calls) { $calls++; return ['success'=>true]; };
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='sent','Welcome accepted by provider');
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='skipped' && $calls===1,'Accepted welcome does not send twice');
$db = new WelcomePDO();
welcomeCheck(deliverPaymentNotification($db,1,fn()=>['success'=>false],true)==='failed','Rejected welcome can retry');
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='skipped','Retry delay is respected');
$db->due=true;
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='sent','Rejected welcome recovers');
$db = new WelcomePDO();
welcomeCheck(deliverPaymentNotification($db,1,fn()=>['uncertain'=>true],true)==='unknown','Unknown provider outcome is retained');
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='skipped','Uncertain send does not automatically duplicate');
$db = new WelcomePDO(); $db->row['status']='sending';
welcomeCheck(deliverPaymentNotification($db,1,$sender,true)==='skipped','Interrupted send is held for confirmation');
class WelcomeFlagSender extends SMSHelper {
    public function __construct() {}
    public function send($phone,$message,$clientId=null,$log=true) {
        welcomeCheck((new ReflectionProperty(SMSHelper::class,'welcome_credentials'))->getValue($this),'Welcome explicitly allows registration credentials');
        throw new RuntimeException('Simulated provider interruption');
    }
}
$helper = new WelcomeFlagSender();
try { $helper->sendWelcomeCredentials('0712345678','test',3); } catch (RuntimeException $e) {}
welcomeCheck(!(new ReflectionProperty(SMSHelper::class,'welcome_credentials'))->getValue($helper),'Credential exception is cleared after interrupted welcome');
echo "Welcome checks passed. No SMS sent.\n";
