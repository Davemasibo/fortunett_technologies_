<?php
require_once __DIR__ . '/test_package_deadlines.php';
require_once __DIR__ . '/../includes/hotspot_connection.php';
require_once __DIR__ . '/../includes/hotspot_locations.php';

class SessionRouterDouble {
    public array $sessions = [];
    public function comm($path, $params = []): array { return $this->sessions; }
}
$api = new SessionRouterDouble();
$api->sessions = [['user'=>'254712345678','mac-address'=>'AA:BB:CC:DD:EE:01']];
assertHotspotSessionCapacity($api,'254712345678','AA:BB:CC:DD:EE:01',1);
deadlineCheck(true,'An already connected device can reconnect at the device limit');
try {
    assertHotspotSessionCapacity($api,'254712345678','AA:BB:CC:DD:EE:02',1);
    throw new RuntimeException('Expected a session limit rejection');
} catch (HotspotConnectionException $e) {
    deadlineCheck(hotspotConnectionFailure($e)['code']==='session_limit','A second device receives an explicit session limit message');
}
assertHotspotSessionCapacity($api,'254712345678','AA:BB:CC:DD:EE:02',2);
deadlineCheck(true,'Two-device packages admit the second device');
deadlineCheck(hotspotConnectionFailure(new RuntimeException('failed to add to queue: already have such a name (6)'))['code']==='queue_conflict','Queue conflicts are distinct from exhausted sessions');
foreach (['0712345678','+254712345678','712345678','254712345678'] as $phone) {
    deadlineCheck(hotspotPhoneUsername($phone)==='254712345678','Phone formats resolve to one full username');
}
deadlineRejects(fn()=>hotspotPhoneUsername('not-a-phone'),'Invalid phone usernames cannot be generated');
for ($i=0;$i<100;$i++) {
    if (!preg_match('/^\d{4}$/',hotspotGeneratePin())) throw new RuntimeException('PIN must contain exactly four digits');
}
deadlineCheck(true,'Generated PINs always contain four digits');
$router = new PaidRouterDouble();
$expiry = date('Y-m-d H:i:s',time()+1800);
provisionRouterPaidUser($router,'hotspot','shared','1234','pkg-test','',$expiry,'all','',false,2);
$router->calls=[];
provisionRouterPaidUser($router,'hotspot','shared','1234','pkg-test','',$expiry,'all','',true,2);
deadlineCheck(!array_filter($router->calls,fn($call)=>$call[0]==='kick-hotspot'),'Reconnect provisioning preserves other active devices');
$quota=routerUptimeSeconds($router->data['/ip/hotspot/user']['*1']['limit-uptime']);
deadlineCheck($quota>3500 && $quota<=3600,'Two devices get enough aggregate uptime for a 30-minute wall-clock purchase');
$schedule=$router->data['/system/scheduler']['*1'];
deadlineCheck(str_contains($schedule['on-event'],'/ip hotspot active remove'),'The shared package still has an absolute disconnect deadline');

$report=hotspotLocationSnapshot([
 ['name'=>'ether2','comment'=>'Market','rx-byte'=>'100','tx-byte'=>'200','running'=>'true'],
 ['name'=>'ether3','running'=>'false']
], [
 ['mac-address'=>'AA:BB:CC:DD:EE:01','on-interface'=>'ether2'],
 ['mac-address'=>'AA:BB:CC:DD:EE:02','on-interface'=>'ether2'],
 ['mac-address'=>'AA:BB:CC:DD:EE:02','on-interface'=>'ether3']
], [
 ['user'=>'alice','mac-address'=>'AA:BB:CC:DD:EE:01','bytes-in'=>10,'bytes-out'=>20],
 ['user'=>'bob','mac-address'=>'AA:BB:CC:DD:EE:02'],
 ['user'=>'charlie','mac-address'=>'AA:BB:CC:DD:EE:03']
]);
deadlineCheck($report['locations'][0]['customers']===1 && $report['locations'][0]['session_download_bytes']===20,'Location traffic uses router-observed MAC/interface mapping');
deadlineCheck($report['unmapped_sessions']===2,'Ambiguous or unknown MACs are not attributed to the wrong location');
deadlineCheck($report['locations'][1]['rx_bytes']===null,'Unavailable interface counters are not reported as zero traffic');
