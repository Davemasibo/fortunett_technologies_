<?php
require_once __DIR__ . '/../includes/receipt_reconnect.php';
class ReceiptPDO extends PDO {
    public array $client;
    public function __construct() { $this->client=['status'=>'active','connection_type'=>'hotspot','expiry_date'=>date('Y-m-d H:i:s',time()+1800),'mikrotik_username'=>'test','mikrotik_password'=>'test']; }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if ($query!=='SELECT * FROM clients WHERE id=? AND tenant_id=?') throw new RuntimeException('Reconnect must not rewrite access');
        return new ReceiptStatement($this);
    }
}
class ReceiptStatement extends PDOStatement {
    public function __construct(private ReceiptPDO $db) {}
    public function execute(?array $params=null): bool { if ($params!==[1,9]) throw new RuntimeException('Scope changed'); return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->db->client; }
}
function receiptCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n"; }
$db=new ReceiptPDO();$expiry=$db->client['expiry_date'];$calls=0;
$provision=function()use(&$calls){$calls++;return ['success'=>true];};
for($i=0;$i<3;$i++) receiptCheck(reconnectReceiptClient($db,1,9,'',$provision)['expiry']===$expiry,'Repeated receipt preserves exact purchased expiry');
foreach(['expired','suspended','blocked','inactive','missing_expiry','pppoe','bound_device'] as $case){
    $db=new ReceiptPDO();
    if($case==='expired')$db->client['expiry_date']=date('Y-m-d H:i:s',time());
    elseif($case==='missing_expiry')$db->client['expiry_date']=null;
    elseif($case==='pppoe')$db->client['connection_type']='pppoe';
    elseif($case==='bound_device')$db->client['bound_mac_address']='AA:BB:CC:DD:EE:FF';
    else $db->client['status']=$case;
    $before=$calls;
    receiptCheck(!reconnectReceiptClient($db,1,9,'',$provision)['success'] && $calls===$before,$case.' receipt cannot enable access');
}
$db=new ReceiptPDO();
receiptCheck(!reconnectReceiptClient($db,1,9,'',fn()=>['success'=>false])['success'],'Router failure never reports successful connection');
receiptCheck(!reconnectReceiptClient($db,1,9,'',function()use($db){$db->client['status']='suspended';return ['success'=>true];})['success'],'Concurrent suspension prevents credential handoff');
$db=new ReceiptPDO();
$full=reconnectReceiptClient($db,1,9,'',fn()=>['success'=>false,'code'=>'session_limit','message'=>'All device sessions are in use.']);
receiptCheck($full['code']==='session_limit' && $full['message']==='All device sessions are in use.','Receipt reconnect preserves the actionable session limit message');
$active=reconnectReceiptClient($db,1,9,'',fn()=>['success'=>true,'device_connected'=>true]);
receiptCheck($active['device_connected']===true,'Receipt reconnect tells the portal to skip duplicate login for an active device');
$db->client['expiry_date']=date('Y-m-d H:i:s',time()-1);
receiptCheck(reconnectReceiptClient($db,1,9,'',$provision)['code']==='time_exhausted','Expired time has a distinct customer-facing reason');
