<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/router_wan_profile.php';
putenv('WAN_CREDENTIAL_KEY='.base64_encode(random_bytes(32)));
function profileAssert(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$cfg=routerWanInput(['wan_mode'=>'pppoe','wan_username'=>'isp-test','wan_password'=>'private-test'],false);
profileAssert($cfg['lan']==='' && $cfg['wan']==='fortunett-wan-pppoe','ISP PPPoE profile does not require a customer LAN bridge');
$cipher=encryptWanPassword('private-test');profileAssert($cipher!=='private-test' && decryptWanPassword($cipher)==='private-test','ISP password uses authenticated encryption');
$raw=base64_decode($cipher);$raw[30]=chr(ord($raw[30])^1);
try{decryptWanPassword(base64_encode($raw));throw new RuntimeException('Tampered secret accepted');}catch(RuntimeException $e){profileAssert(str_contains($e->getMessage(),'could not be unlocked'),'Tampered password ciphertext is rejected');}
class WanProfileApiDouble {
    public array $scripts=[];public int $runs=0;public bool $reachable=true,$customer=false,$dropOnRun=false,$rejectRun=false;public string $marker='';
    public function isReachable($timeout):bool{return $this->reachable;}
    public function connect():void{}
    public function disconnect():void{}
    public function comm($path,$words):array {
        $p=[];foreach($words as $word)if(str_starts_with($word,'=')){[$key,$value]=explode('=',substr($word,1),2);$p[$key]=$value;}
        if($path==='/interface/print')return [['!re'=>true,'name'=>'ether1','disabled'=>'false']];
        if($path==='/ip/dhcp-server/print' && $this->customer)return [['!re'=>true,'interface'=>'ether1','disabled'=>'false']];
        if($path==='/system/script/add'){$id='*'.(count($this->scripts)+1);$this->scripts[$id]=$p;return [['!done'=>true,'ret'=>$id]];}
        if($path==='/system/script/set'){$this->scripts[$p['.id']]['source']=$p['source'];return [['!done'=>true]];}
        if($path==='/system/script/run'){
            $script=&$this->scripts[$p['number']];
            if(str_starts_with($script['name'],'fortunett-wan-apply-')){
                $this->runs++;
                if($this->rejectRun)return [['!trap'=>true,'message'=>'rejected']];
                preg_match('/comment="(\d+:\d+)"/',$script['source'],$m);$this->marker=$m[1] ?? '';
                if($this->dropOnRun)throw new RuntimeException('connection closed');
            }else{$script['comment']='true,true,true,true';}
            return [['!done'=>true]];
        }
        if($path==='/system/script/print'){
            $name=substr($words[0],strlen('?name='));
            if($name==='fortunett-wan-last-apply')return $this->marker!==''?[['!re'=>true,'comment'=>$this->marker]]:[];
            foreach($this->scripts as $script)if($script['name']===$name)return [['!re'=>true,'comment'=>$script['comment'] ?? 'pending']];
        }
        return [['!done'=>true]];
    }
}
ensureWanProfiles($pdo);$tenantId=(int)$pdo->query('SELECT id FROM tenants LIMIT 1')->fetchColumn();
if(!$tenantId)throw new RuntimeException('A local tenant is required.');
$router=['tenant_id'=>$tenantId,'id'=>2147482999,'name'=>'wan-profile-unit-fixture','vpn_ip'=>'10.200.200.254','ip_address'=>'','username'=>'test','password'=>'test','api_port'=>8728];
$url='https://example.test/api/routers/wan_health.php';
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO router_wan_profiles (tenant_id,router_id,config_json,password_ciphertext,state,message) VALUES (?,?,?,?,'pending','Waiting for management')")->execute([$tenantId,$router['id'],json_encode($cfg),$cipher]);
    $reset=function()use($pdo,$tenantId,$router):void{$pdo->prepare("UPDATE router_wan_profiles SET state='pending',checked_at=NULL,revision=revision+1 WHERE tenant_id=? AND router_id=?")->execute([$tenantId,$router['id']]);};
    $age=function()use($pdo,$tenantId,$router):void{$pdo->prepare('UPDATE router_wan_profiles SET checked_at=DATE_SUB(NOW(),INTERVAL 30 SECOND) WHERE tenant_id=? AND router_id=?')->execute([$tenantId,$router['id']]);};
    $api=new WanProfileApiDouble();$api->reachable=false;$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='pending' && $api->runs===0,'Offline device retains saved settings without being marked connected');
    profileAssert(!str_contains(json_encode(wanProfilePublic($r)),'private-test') && !array_key_exists('password_ciphertext',wanProfilePublic($r)),'Profile responses never return plaintext or encrypted passwords');
    $api->reachable=true;$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='verifying' && $api->runs===1,'Reachable device receives WAN configuration through API without a downloaded file');
    $age();$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='connected' && $api->runs===1,'Successful checks confirm the applied revision without replaying WAN changes');
    $canonical=loadRouterWan($pdo,$tenantId,$router['id']);
    profileAssert(json_decode($canonical['config_json'],true)['wan']==='fortunett-wan-pppoe' && !empty($canonical['verified_at']),'Canonical WAN metadata tracks verified logical PPPoE interface');
    $reset();$api=new WanProfileApiDouble();$api->customer=true;$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='needs_attention' && $api->runs===0,'WAN application refuses interfaces already serving customers');
    $reset();$api=new WanProfileApiDouble();$api->dropOnRun=true;$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='verifying' && $api->runs===1,'Management drop during WAN switching remains unconfirmed');
    $age();$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='connected' && $api->runs===1,'Reconnection verifies completion marker rather than applying credentials again');
    $reset();$api=new WanProfileApiDouble();$api->rejectRun=true;$r=processWanProfile($pdo,$router,$url,$api);
    profileAssert($r['state']==='needs_attention','Explicit RouterOS rejection is not reported as applied');
}finally{$pdo->rollBack();}
