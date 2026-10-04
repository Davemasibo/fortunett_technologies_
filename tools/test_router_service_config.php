<?php
require_once __DIR__.'/../includes/router_service_config.php';
function serviceCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$hotspot=buildRouterServiceCommand(['hotspot'],true,'homelinkfiber.fortunetttech.site','','212.95.34.211','bridge-lan');
serviceCheck(str_contains($hotspot, '/ip hotspot add') && !str_contains($hotspot, '/interface pppoe-server server add'),'Hotspot-only onboarding does not enable PPPoE');
serviceCheck(str_contains($hotspot, ':set bn "bridge-lan"') && str_contains($hotspot, 'interface=$bn'),'Hotspot binds to the selected LAN bridge');
serviceCheck(str_contains($hotspot, 'gateway=10.5.50.1') && str_contains($hotspot, 'shared-users=1'),'Hotspot includes DHCP gateway and sharing choice');
$pppoe=buildRouterServiceCommand(['pppoe'],false,'','','','bridge-lan');
serviceCheck(!str_contains($pppoe, '/ip hotspot') && !str_contains($pppoe, '/ip dhcp-server'),'Adding only PPPoE leaves existing Hotspot and DHCP configuration untouched');
serviceCheck(!str_contains($pppoe, '/ip address remove'),'Adding a service preserves interface IP addresses');
serviceCheck(str_contains($pppoe, ':if ($newBridge) do='),'Existing bridge membership is preserved');
serviceCheck(str_contains($pppoe,'10.10.10.2-10.10.10.254') && !str_contains($pppoe,'10.5.50.2'),'PPPoE uses a separate client pool');
$both=buildRouterServiceCommand(['hotspot','pppoe'],false);
serviceCheck(substr_count($both,'interface=$bn')>=3 && str_contains($both,'Multiple bridges found'),'Both services use the same selected bridge and ambiguous bridge selection stops');
serviceCheck(mergeRouterServiceTypes('hotspot',['pppoe'])==='hotspot,pppoe','Adding PPPoE retains the saved Hotspot service');
serviceCheck(mergeRouterServiceTypes('hotspot,pppoe',['pppoe'])==='hotspot,pppoe','Repeated additions do not duplicate services');
serviceCheck(routerServiceString('bridge";$bad')==='"bridge\\";\\$bad"','Bridge names cannot inject RouterOS commands');
serviceCheck(str_starts_with($hotspot,'{ ') && str_ends_with($hotspot,'; }'),'Provisioning locals stay inside one explicit RouterOS scope');
serviceCheck(str_contains($hotspot,'LAN bridge not found: ') && str_contains($hotspot,'LAN bridge is disabled: '),'Missing and disabled bridges have distinct actionable errors');
serviceCheck(strpos($hotspot,'LAN bridge not found: ') < strpos($hotspot,'/ip hotspot remove'),'Bridge validation runs before existing Hotspot settings are changed');

serviceCheck(strpos($hotspot,'/system device-mode get hotspot') < strpos($hotspot,'/ip hotspot remove'),'Device-mode prerequisites are checked before service changes');
serviceCheck(str_contains(routerDeviceModeGuard(true),'scheduler=yes fetch=yes hotspot=yes') && str_contains(routerDeviceModeGuard(true),'physically power-cycle'),'Device-mode restrictions include exact physical recovery instructions');
serviceCheck(!str_contains(routerDeviceModeGuard(false),'/system device-mode get hotspot'),'Management-only setup does not require Hotspot permission');
