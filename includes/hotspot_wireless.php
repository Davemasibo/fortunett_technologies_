<?php
require_once __DIR__.'/router_service_config.php';
require_once __DIR__.'/router_expiry.php';

/** SSIDs have a 32-byte limit; never split a UTF-8 character. */
function hotspotTenantSsid(string $company): string {
    $company=trim(preg_replace('/[\x00-\x1f\x7f]/u','',$company) ?? '');
    if ($company==='') $company='Customer Wi-Fi';
    while (strlen($company)>32) $company=preg_replace('/.$/us','',$company);
    return $company;
}

/** $bridgeVariable is a RouterOS variable holding the Hotspot LAN bridge. */
function hotspotWirelessCommand(string $company): string {
    $ssid=routerServiceString(hotspotTenantSsid($company));
    $parts=[':local customerSsid '.$ssid, ':local wirelessCount 0', ':local legacyRadios {}'];
    // Missing driver menus are normal on routers without a wireless radio.
    $parts[]=':do {:set legacyRadios [/interface wireless find]} on-error={}';
    $parts[]=':if ([:len $legacyRadios]>0) do={:local openProfile [/interface wireless security-profiles find where name="FortuNett-Customer-Open"]; :if ([:len $openProfile]=0) do={/interface wireless security-profiles add name="FortuNett-Customer-Open" mode=none} else={/interface wireless security-profiles set $openProfile mode=none}; :foreach radio in=$legacyRadios do={:local oldMode [/interface wireless get $radio mode]; :if ([:pick $oldMode 0 7]!="station") do={:local radioName [/interface wireless get $radio name]; /interface wireless set $radio mode=ap-bridge ssid=$customerSsid hide-ssid=no security-profile="FortuNett-Customer-Open" default-authentication=yes disabled=no; :local ports [/interface bridge port find where interface=$radioName]; :if ([:len $ports]=0) do={/interface bridge port add bridge=$bn interface=$radioName} else={/interface bridge port set $ports bridge=$bn disabled=no}; :set wirelessCount ($wirelessCount+1)} else={:put "Wireless uplink preserved; use a separate customer radio"}}}';
    foreach (['wifi','wifiwave2'] as $menu) {
        $parts[]=':local modernRadios {}';
        $parts[]=':do {:set modernRadios [/interface '.$menu.' find]} on-error={}';
        $parts[]=':foreach radio in=$modernRadios do={:local oldMode [/interface '.$menu.' get $radio configuration.mode]; :if ([:pick $oldMode 0 7]!="station") do={/interface '.$menu.' set $radio configuration.mode=ap configuration.ssid=$customerSsid configuration.hide-ssid=no security.authentication-types="" security.passphrase="" datapath.bridge=$bn disabled=no; :set wirelessCount ($wirelessCount+1)} else={:put "Wireless uplink preserved; use a separate customer radio"}}';
    }
    $parts[]=':put ("Customer Wi-Fi radios configured: " . $wirelessCount . ". SSID: " . $customerSsid)';
    return implode('; ',$parts);
}

/** Deploy to the bridge of an enabled Hotspot, never an arbitrary LAN. */
function deployHotspotWireless($api, string $company): void {
    $bridge='';
    foreach (routerCheckedCommand($api,'/ip/hotspot/print') as $server) {
        if (isset($server['!re']) && ($server['disabled'] ?? 'false')!=='true' && ($server['name'] ?? '')==='hotspot1') {$bridge=$server['interface'] ?? '';break;}
    }
    if (!$bridge) return;
    $source='{ :local bn '.routerServiceString($bridge).'; '.hotspotWirelessCommand($company).'; }';
    $name='FortuNett-Customer-WiFi';$id=null;
    foreach (routerCheckedCommand($api,'/system/script/print',['?name='.$name]) as $row) if (isset($row['.id'])) $id=$row['.id'];
    if ($id) routerCheckedCommand($api,'/system/script/set',['=.id='.$id,'=source='.$source,'=policy=read,write,test,policy']);
    else routerCheckedCommand($api,'/system/script/add',['=name='.$name,'=source='.$source,'=policy=read,write,test,policy']);
    routerCheckedCommand($api,'/system/script/run',['=number='.$name]);
}

/** Return public readiness checks; never expose configured wireless passwords. */
function hotspotWirelessReadiness($api, string $company, string $bridge): array {
    $checks=[];$ssid=hotspotTenantSsid($company);
    $ports=routerCheckedCommand($api,'/interface/bridge/port/print');
    foreach (['wireless','wifi','wifiwave2'] as $menu) {
        // Capability discovery may fail when the corresponding driver is absent.
        try {$radios=routerCheckedCommand($api,'/interface/'.$menu.'/print');} catch (Throwable $e) {continue;}
        $security=[];
        if ($menu==='wireless') foreach (routerCheckedCommand($api,'/interface/wireless/security-profiles/print') as $profile) $security[$profile['name'] ?? '']=$profile['mode'] ?? '';
        foreach ($radios as $radio) {
            if (!isset($radio['!re'])) continue;
            $mode=$radio[$menu==='wireless' ? 'mode' : 'configuration.mode'] ?? '';
            if (str_starts_with($mode,'station')) continue;
            $name=$radio['name'] ?? '';
            $open=$menu==='wireless' ? (($security[$radio['security-profile'] ?? ''] ?? '')==='none')
                : empty($radio['security.authentication-types']) && empty($radio['security.passphrase']);
            $onBridge=false;
            foreach ($ports as $port) if (($port['interface'] ?? '')===$name && ($port['bridge'] ?? '')===$bridge && ($port['disabled'] ?? 'false')!=='true') $onBridge=true;
            $ok=($radio['disabled'] ?? 'false')!=='true' && in_array($mode,['ap','ap-bridge'],true)
                && ($radio[$menu==='wireless' ? 'ssid' : 'configuration.ssid'] ?? '')===$ssid && $open && $onBridge;
            $checks[]=['label'=>'Customer Wi-Fi: '.$name,'ok'=>$ok,'detail'=>$ok ? $ssid.' - open Wi-Fi on '.$bridge : 'Needs enabled AP, tenant SSID, open security and Hotspot bridge membership'];
        }
    }
    return $checks;
}
