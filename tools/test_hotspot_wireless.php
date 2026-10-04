<?php
require_once __DIR__.'/../includes/hotspot_wireless.php';
function wifiCheck($ok,$label) {if(!$ok) throw new RuntimeException($label);echo "PASS: $label\n";}
$command=buildRouterServiceCommand(['hotspot'],true,'','','','bridge','HomeLink Fiber');
wifiCheck(str_contains($command,'ssid=$customerSsid') && str_contains($command,'"HomeLink Fiber"') && str_contains($command,'mode=none'),'Tenant company name and open legacy security are provisioned');
wifiCheck(str_contains($command,'configuration.mode=ap') && str_contains($command,'security.authentication-types=""') && str_contains($command,'security.passphrase=""'),'Modern radios use open AP security');
wifiCheck(str_contains($command,'bridge=$bn interface=$radioName') && str_contains($command,'datapath.bridge=$bn'),'Both drivers attach to the selected Hotspot bridge');
wifiCheck(!str_contains(buildRouterServiceCommand(['pppoe'],false,'','','','bridge','HomeLink Fiber'),'customerSsid'),'PPPoE-only changes preserve wireless settings');
wifiCheck(str_contains($command,'station') && str_contains($command,'Wireless uplink preserved'),'Wireless station uplinks are preserved');
wifiCheck(strlen(hotspotTenantSsid(str_repeat("\xC3\xA9",30)))===32 && preg_match('//u',hotspotTenantSsid(str_repeat("\xC3\xA9",30))),'Long Unicode company names fit the SSID byte limit');
wifiCheck(str_contains(hotspotWirelessCommand('";$bad'),'\\";\\$bad'),'Tenant names cannot inject RouterOS script commands');
class WirelessCheckDouble {
 public bool $open=true,$bridged=true,$enabled=true;
 public function comm($path,$args=[]):array {
  if($path==='/interface/bridge/port/print')return [['!re'=>true,'interface'=>'wlan1','bridge'=>$this->bridged?'bridge':'other']];
  if($path==='/interface/wireless/print')return [['!re'=>true,'name'=>'wlan1','mode'=>'ap-bridge','ssid'=>'HomeLink Fiber','disabled'=>$this->enabled?'false':'true','security-profile'=>'open'],['!re'=>true,'name'=>'wlan2','mode'=>'station','ssid'=>'uplink']];
  if($path==='/interface/wireless/security-profiles/print')return [['!re'=>true,'name'=>'open','mode'=>$this->open?'none':'dynamic-keys']];
  return [['!trap'=>true,'message'=>'driver absent']];
 }
}
$api=new WirelessCheckDouble();$checks=hotspotWirelessReadiness($api,'HomeLink Fiber','bridge');wifiCheck(count($checks)===1 && $checks[0]['ok'],'Verification accepts open branded AP and excludes uplinks and absent drivers');
foreach(['open','bridged','enabled'] as $property) {$api=new WirelessCheckDouble();$api->$property=false;wifiCheck(!hotspotWirelessReadiness($api,'HomeLink Fiber','bridge')[0]['ok'],"Verification rejects incorrect $property state");}

wifiCheck(!str_contains($command,'Radios {}') && str_contains($command,'Radios [:toarray ""]'),'Radio discovery uses valid RouterOS empty-array initialization');
