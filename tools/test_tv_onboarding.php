<?php
require_once __DIR__ . '/test_hotspot_onboarding.php';
foreach (['00:00:00:00:00:00','FF:FF:FF:FF:FF:FF','01:00:5E:00:00:01','not-a-mac'] as $invalid) {
    deadlineRejects(fn()=>hotspotBoundMac($invalid), 'Invalid or non-device MAC cannot create a TV binding');
}
checkConnectivity(hotspotBoundMac('aa-bb-cc-dd-ee-ff')==='AA:BB:CC:DD:EE:FF','TV MAC formats normalize consistently');
$router = new PaidRouterDouble();
$expiry = date('Y-m-d H:i:s',time()+1800);
provisionRouterPaidUser($router,'hotspot','tv-device','test','pkg9-timed','TV',$expiry,'all','AA:BB:CC:DD:EE:FF');
$tv = $router->data['/ip/hotspot/user']['*1'];
checkConnectivity($tv['mac-address']==='AA:BB:CC:DD:EE:FF' && $tv['disabled']==='false','Paid TV credentials are restricted to the purchased device');
checkConnectivity(routerUptimeSeconds($tv['limit-uptime'])<=1800,'TV access never exceeds its purchased 30 minutes');
checkConnectivity(str_contains($router->data['/system/scheduler']['*1']['on-event'],'ip-binding remove'),'TV expiry also clears a bypass tied to the purchased device');
provisionRouterPaidUser($router,'hotspot','tv-device','test','pkg9-timed','TV',$expiry,'all','AA:BB:CC:DD:EE:FF');
checkConnectivity($router->data['/ip/hotspot/user']['*1']['mac-address']==='AA:BB:CC:DD:EE:FF','Provisioning retries preserve the TV MAC restriction');
$watchdogs = array_values(array_filter($router->data['/system/scheduler'],fn($r)=>$r['name']==='fn-paid-expiry-watchdog'));
checkConnectivity(!str_contains($watchdogs[0]['on-event'],'find where disabled=no') && str_contains($watchdogs[0]['on-event'],'ip-binding remove'),'Expiry watchdog also disconnects already-disabled accounts and removes bound-device bypasses');
echo "TV onboarding regression checks passed.\n";
