<?php
require_once __DIR__.'/../includes/router_wan.php';
function wanAssert(bool $ok,string $label): void {if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n";}
function wanReject(array $input): void {try {routerWanInput($input); throw new RuntimeException('Unsafe input accepted');} catch (InvalidArgumentException $e) {}}
$input=['lan_bridge'=>'bridge-lan'];
$dhcp=routerWanInput($input);
wanAssert($dhcp['mode']==='dhcp' && $dhcp['wan']==='ether1','Ordinary Ethernet defaults to DHCP on ether1');
$url='https://homelinkfiber.fortunetttech.site/api/routers/wan_health.php';
$script=routerWanSetupScript($dhcp,'',$url);
wanAssert(str_contains($script,'add-default-route=yes') && str_contains($script,'status=bound'),'DHCP waits for a bound lease and checks the default route');
wanAssert(!str_contains($script,'/interface bridge port remove') && !str_contains($script,'/interface bridge port add'),'Installer never changes existing bridge membership');
wanAssert(str_contains($script,'Existing unmanaged static WAN') && strpos($script,'Existing unmanaged static WAN')<strpos($script,'/ip address remove'),'Conflicting static WAN settings stop before changes');
$static=routerWanInput($input+['wan_mode'=>'static','wan_address'=>'192.168.10.2/24','wan_gateway'=>'192.168.10.1','wan_dns'=>'1.1.1.1,8.8.8.8','vlan_id'=>'100']);
wanAssert($static['wan']==='fortunett-wan-vlan' && str_contains(routerWanSetupScript($static,'',$url),'192.168.10.1%fortunett-wan-vlan'),'Static VLAN handoff binds addressing and gateway to the logical WAN');
$ppp=routerWanInput($input+['wan_mode'=>'pppoe','wan_username'=>'isp-user','wan_password'=>'secret','vlan_id'=>'200']);
$pppScript=routerWanSetupScript($ppp,'p";$word',$url);
wanAssert($ppp['wan']==='fortunett-wan-pppoe' && str_contains($pppScript,'interface="fortunett-wan-vlan" user="isp-user"'),'ISP PPPoE can run over a tagged handoff');
wanAssert(!array_key_exists('password',$ppp) && str_contains($pppScript,'password="p\\";\\$word"'),'PPPoE password is escaped in the installer and absent from stored settings');
$bridge=routerWanInput(['wan_interface'=>'bridge-wan','lan_bridge'=>'bridge-lan']);
wanAssert($bridge['wan']==='bridge-wan','Dedicated WAN bridge is supported');
function wanBalancedSource(string $source): bool {
    $stack=[];$quoted=false;$escaped=false;$comment=false;
    for ($i=0;$i<strlen($source);$i++) {
        $char=$source[$i];
        if ($comment) {if ($char==="\n") $comment=false; continue;}
        if ($quoted) {
            if ($escaped) {$escaped=false;continue;}
            if ($char==='\\') {$escaped=true;continue;}
            if ($char==='"') $quoted=false;
            continue;
        }
        if ($char==='"') {$quoted=true;continue;}
        if ($char==='#') {$comment=true;continue;}
        if (str_contains('[{(',$char)) $stack[]=$char;
        if (str_contains(']})',$char)) {
            $open=array_pop($stack);
            if ($open===null || strpos('[{(',$open)!==strpos(']})',$char)) return false;
        }
    }
    return !$quoted && !$stack;
}
foreach ([$dhcp,$static,$ppp,$bridge] as $c) wanAssert(wanBalancedSource(routerWanSetupScript($c,'p";$word',$url)),'Installer brackets and escaped strings balance for '.$c['wan']);
foreach ([['lan_bridge'=>'ether1'],['lan_bridge'=>'bridge-lan','vlan_id'=>'4095'],['lan_bridge'=>'bridge-lan','wan_interface'=>'ether1";bad'],['lan_bridge'=>'bridge-lan','wan_mode'=>'static','wan_address'=>'1.2.3.4/99'],['lan_bridge'=>'bridge-lan','wan_mode'=>'pppoe']] as $bad) wanReject($bad);
wanAssert(true,'Invalid VLAN, missing credentials, unsafe names and WAN/LAN overlap are rejected');
foreach ([$dhcp,$static,$ppp,$bridge] as $c) {
    $source=routerWanRegistrationSource($c,'https://example.test/register',['provisioning_token'=>'test','router_password'=>'test']);
    wanAssert(str_contains($source,'interface="'.$c['wan'].'"') && str_contains($source,'[:pick $address 0 [:find $address "/"]]'),'Registration reads '.$c['wan'].' and removes the CIDR prefix');
}
class WanApiDouble {
    public array $calls=[];
    public string $comment='true,true,true,true';
    public bool $trap=false;
    public function comm($path,$params) {
        $this->calls[]=[$path,$params];
        if ($path==='/system/script/add') return [['!done'=>true,'ret'=>'*1']];
        if ($path==='/system/script/run' && $this->trap) return [['!trap'=>true,'message'=>'Fetch blocked']];
        if ($path==='/system/script/print') return [['!re'=>true,'comment'=>$this->comment]];
        return [['!done'=>true]];
    }
}
$record=['config_json'=>json_encode($dhcp),'billing_url'=>$url];
$api=new WanApiDouble();$checks=verifyRouterWan($api,$record);
wanAssert(count($checks)===4 && !array_filter($checks,fn($c)=>!$c['ok']),'All four checks are required for WAN success');
wanAssert(str_starts_with($api->calls[0][1][0],'=name=') && $api->calls[1][1]===['=number=*1'],'API sends RouterOS attribute words correctly');
wanAssert(end($api->calls)[0]==='/system/script/remove','Temporary verification script is removed');
$api->comment='true,true,true,false';$checks=verifyRouterWan($api,$record);
wanAssert(!$checks[3]['ok'],'Billing fetch failure prevents verification');
$api->comment='pending';$checks=verifyRouterWan($api,$record);
wanAssert(count(array_filter($checks,fn($c)=>$c['ok']))===0,'Unfinished or stale diagnostic output cannot pass');
$api->trap=true;
try {verifyRouterWan($api,$record); throw new RuntimeException('Trap was ignored');} catch (RuntimeException $e) {wanAssert($e->getMessage()==='Fetch blocked','Router API errors are surfaced');}
wanAssert(end($api->calls)[0]==='/system/script/remove','Diagnostic cleanup also runs after errors');
