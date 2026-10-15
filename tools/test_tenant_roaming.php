<?php
require_once __DIR__.'/../includes/tenant_roaming.php';
function roamCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label);echo "PASS: $label\n"; }
$routers=[['id'=>20,'tenant_id'=>14,'vpn_ip'=>'10.200.200.20','roaming_secret'=>'test-secret-20'],['id'=>21,'tenant_id'=>14,'vpn_ip'=>'10.200.200.21','roaming_secret'=>'test-secret-21']];
$server=roamingServerConfig(14,'10.200.200.1',$routers,'test-local');
roamCheck(str_contains($server,'ipaddr = 10.200.200.1') && str_contains($server,'port = 20028'),'Tenant listener binds the server VPN and dedicated port');
roamCheck(str_contains($server,'ipaddr = 10.200.200.20') && str_contains($server,'ipaddr = 10.200.200.21') && !str_contains($server,'0.0.0.0'),'Only registered VPN peers are admitted');
foreach ([array_merge($routers[0],['tenant_id'=>15]),array_merge($routers[0],['vpn_ip'=>'invalid']),$routers[1]] as $invalid) {
    $blocked=false;try { roamingServerConfig(14,'10.200.200.1',[$routers[1],$invalid],'test-local'); } catch (InvalidArgumentException $e) { $blocked=true; }
    roamCheck($blocked,'Foreign tenant, invalid IP or duplicate peer is rejected');
}
class RoamingRouterDouble {
    public array $entries=[['.id'=>'*1','service'=>'hotspot,ppp,login','disabled'=>'false','address'=>'old-radius']];
    public array $calls=[];
    public bool $enabled=false,$ignore=false;
    public function comm($path,$params=[]):array {
        $this->calls[]=[$path,$params];
        if ($path==='/radius/print') return $this->entries;
        if ($path==='/radius/add') {
            $row=['.id'=>'*2'];foreach($params as $param){[$key,$value]=explode('=',substr($param,1),2);$row[$key]=$value;}
            if (!$this->ignore) {$row['disabled']='false';$this->entries[]=$row;}return [];
        }
        if ($path==='/radius/set') { foreach($params as $param) if ($param==='=service=login') $this->entries[0]['service']='login';return []; }
        if ($path==='/ip/hotspot/profile/print') return [['.id'=>'*H','use-radius'=>$this->enabled?'true':'false']];
        if ($path==='/ppp/aaa/print') return [['use-radius'=>$this->enabled?'true':'false']];
        if ($path==='/ip/hotspot/profile/set' || $path==='/ppp/aaa/set') { if (!$this->ignore) $this->enabled=true;return []; }
        throw new RuntimeException('Unexpected router mutation: '.$path);
    }
}
$api=new RoamingRouterDouble();
enableRouterRoaming($api,14,'10.200.200.1','10.200.200.20','TEST');
roamCheck($api->entries[0]['service']==='login','Existing RADIUS management login service is preserved');
roamCheck($api->enabled && count($api->entries)===2,'Hotspot and PPPoE use the new tenant listener');
roamCheck(!array_filter($api->calls,fn($call)=>str_contains($call[0],'/user/') || str_contains($call[0],'/secret/') || str_contains($call[0],'/active/')),'Activation does not remove customer credentials or disconnect sessions');
$api=new RoamingRouterDouble();$api->ignore=true;$blocked=false;
try { enableRouterRoaming($api,14,'10.200.200.1','10.200.200.20','TEST'); } catch (RuntimeException $e) { $blocked=true; }
roamCheck($blocked,'Ignored router writes cannot claim successful activation');
echo "Roaming checks passed. No live router changed.\n";
