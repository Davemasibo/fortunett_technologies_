<?php
require_once __DIR__ . '/../includes/kenyan_phone.php';
require_once __DIR__ . '/../includes/router_expiry.php';
require_once __DIR__ . '/../includes/hotspot_device.php';
function recoveryCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
foreach (['0712345678'=>'254712345678','0112345678'=>'254112345678','712345678'=>'254712345678','112345678'=>'254112345678','+254 712 345 678'=>'254712345678','2540112345678'=>'254112345678','0799345678'=>'254799345678','0100345678'=>'254100345678'] as $raw=>$expected) {
    recoveryCheck(kenyanMobileNumber((string)$raw)===$expected, 'Mobile format accepted: '.$raw);
}
foreach (['','071234567','07123456789','254212345678','abc0712345678'] as $raw) {
    $rejected = false;
    try { kenyanMobileNumber($raw); } catch (InvalidArgumentException $e) { $rejected=true; }
    recoveryCheck($rejected, 'Malformed mobile rejected');
}
class RecoveryPDO extends PDO {
    public bool $expired=false, $busy=false;
    public int $seen=0, $released=0;
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new RecoveryStatement($this,$query); }
}
class RecoveryStatement extends PDOStatement {
    private mixed $result=false;
    public function __construct(private RecoveryPDO $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        if (str_contains($this->sql,'SELECT DISTINCT')) {
            recoveryCheck($params===[1,9] && str_contains($this->sql,'rs.paid_expiry_at=c.expiry_date'), 'Recovery scopes router and tenant to current paid provisioning');
            $this->result=[7];
        } elseif (str_contains($this->sql,'GET_LOCK')) $this->result=$this->db->busy?0:1;
        elseif (str_contains($this->sql,'RELEASE_LOCK')) $this->db->released++;
        elseif (str_contains($this->sql,'SELECT c.*')) $this->result=$this->db->expired?false:['bound_mac_address'=>null,'remembered_mac'=>'AA:BB:CC:DD:EE:FF','mikrotik_username'=>'paid','mikrotik_password'=>'test','expiry_date'=>date('Y-m-d H:i:s',time()+60)];
        elseif (str_contains($this->sql,'UPDATE clients')) $this->db->seen++;
        else throw new RuntimeException('Unexpected SQL');
        return true;
    }
    public function fetchColumn(int $column=0): mixed { return $this->result; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->result; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->result; }
}
class RecoveryRouter {
    public bool $online=false,$host=true,$reject=false;
    public int $logins=0;
    public function comm($path,$args=[]): array {
        if ($path==='/ip/hotspot/active/print') return $this->online?[['user'=>'paid','mac-address'=>'AA:BB:CC:DD:EE:FF']]:[];
        if ($path==='/ip/hotspot/host/print') return $this->host?[['mac-address'=>'AA:BB:CC:DD:EE:FF','address'=>'10.0.0.2']]:[];
        if ($path==='/ip/hotspot/active/login') { $this->logins++; if (!$this->reject) $this->online=true; }
        return [];
    }
}
$db=new RecoveryPDO(); $router=new RecoveryRouter();
recoverPaidHotspotSessions($db,$router,1,9,fn($message)=>null);
recoveryCheck($router->online && $db->seen===1 && $db->released===1, 'Paid phone reconnects with browser closed and updates last seen only after verification');
recoverPaidHotspotSessions($db,$router,1,9,fn($message)=>null);
recoveryCheck($router->logins===1,'Existing active session is not logged in again');
foreach (['expired','busy','absent','reject'] as $case) {
    $db=new RecoveryPDO(); $router=new RecoveryRouter();
    if ($case==='expired') $db->expired=true;
    if ($case==='busy') $db->busy=true;
    if ($case==='absent') $router->host=false;
    if ($case==='reject') $router->reject=true;
    recoverPaidHotspotSessions($db,$router,1,9,fn($message)=>null);
    recoveryCheck(!$router->online && $db->seen===0, $case . ': no false online status or expired access');
}
echo "Paid phone recovery checks passed. No live payment or router changes made.\n";
