<?php
/** Simulated onboarding integration: actual activation/deadline functions, fake external systems. */
require_once __DIR__ . '/test_payment_connectivity.php';
require_once __DIR__ . '/test_package_deadlines.php';
require_once __DIR__ . '/../includes/stk_reconciliation.php';
require_once __DIR__ . '/../includes/hotspot_device.php';

date_default_timezone_set('Africa/Nairobi');
foreach ([[30,'minutes',1800], [1,'hours',3600], [3,'hours',10800], [6,'hours',21600],
    [8,'hours',28800], [14,'hours',50400], [24,'hours',86400], [4,'days',345600],
    [7,'days',604800], [30,'days',2592000]] as [$value,$unit,$seconds]) {
    $db = new ConnectivityTestPDO();
    $db->client['status'] = 'pending';
    $db->client['expiry_date'] = null;
    $package = ['id'=>9, 'name'=>'Timed access', 'validity_value'=>$value, 'validity_unit'=>$unit,
        'download_speed'=>10,'upload_speed'=>3,'device_limit'=>1];
    $tx = ['checkout_request_id'=>'ws_CO_test_'.$seconds, 'amount'=>20, 'mpesa_receipt_number'=>null];
    $confirmed = stkConfirmedTerms($tx, ['result_code'=>0,'error'=>null,'raw'=>['ResultCode'=>'0']]);
    checkConnectivity($confirmed['receipt'] === $tx['checkout_request_id'], "$value $unit: authoritative query without a receipt can recover checkout");
    $before = time();
    $activation = activatePaidSubscription($db,1,2,$tx['checkout_request_id'],$confirmed['receipt'],$package);
    checkConnectivity($db->client['status']==='active' && strtotime($activation['expiry_date'])-$before === $seconds, "$value $unit: confirmed payment activates precisely the purchased duration");
    $router = new PaidRouterDouble();
    $router->fail = '/system/scheduler/add';
    deadlineRejects(fn()=>provisionRouterPaidUser($router,'hotspot','customer','test-pass','pkg9-timed','',$activation['expiry_date']), "$value $unit: router failure prevents an unbounded grant");
    checkConnectivity(count($db->queue)===1, "$value $unit: paid customer retains durable provisioning work after router failure");
    $router->fail = null;
    checkConnectivity(syncPackageProfileToRouter($router,'hotspot','pkg9-timed','3M/10M',$package), "$value $unit: profile can be installed on recovery");
    provisionRouterPaidUser($router,'hotspot','customer','test-pass','pkg9-timed','',$activation['expiry_date']);
    $user = $router->data['/ip/hotspot/user']['*1'];
    checkConnectivity($user['disabled']==='false' && routerUptimeSeconds($user['limit-uptime']) <= $seconds, "$value $unit: recovered router user has credentials and finite access");
    $again = activatePaidSubscription($db,1,2,$tx['checkout_request_id'],'REAL_RECEIPT_'.$seconds,$package);
    checkConnectivity($again['already_applied'] && $db->client['expiry_date']===$activation['expiry_date'], "$value $unit: late callback does not extend paid time twice");
    $watchdog = array_values(array_filter($router->data['/system/scheduler'], fn($r)=>$r['name']==='fn-paid-expiry-watchdog'));
    checkConnectivity(count($watchdog)===1 && routerUptimeSeconds($watchdog[0]['interval'])===5, "$value $unit: router also retains reboot/missed-event enforcement");
    $db->client['expiry_date'] = date('Y-m-d H:i:s',time());
    checkConnectivity(!autoProvisionClient($db,1,2)['success'], "$value $unit: exact expiry blocks both login and retry");
}
foreach ([['result_code'=>1032], ['result_code'=>0,'error'=>'network error'], ['ResponseCode'=>0]] as $unconfirmed) {
    deadlineRejects(fn()=>stkConfirmedTerms(['checkout_request_id'=>'unconfirmed','amount'=>20],$unconfirmed), 'Unconfirmed/cancelled request cannot grant access');
}
checkConnectivity(hotspotDeviceMac('AA%3ABB%3ACC%3ADD%3AEE%3AFF')==='AA:BB:CC:DD:EE:FF', 'RouterOS encoded MAC resolves the actual hotspot device');
checkConnectivity(hotspotDeviceMac('$(mac)')==='', 'Unexpanded portal placeholders cannot be treated as a device');
class OnboardingRouterAPI extends MikrotikAPI {
    public PaidRouterDouble $router;
    public function __construct() { $this->router = new PaidRouterDouble(); }
    public function comm($command, $params = []) { return $this->router->comm($command,$params); }
    public function kickHotspotSession(string $username): bool { return true; }
}
$api = new OnboardingRouterAPI();
$api->router->data['/ip/hotspot']['*1'] = ['.id'=>'*1','name'=>'hotspot1','profile'=>'hs-server','disabled'=>'false'];
$api->router->data['/ip/hotspot/profile']['*1'] = ['.id'=>'*1','name'=>'hs-server','login-by'=>'http-chap'];
_provisionHotspot($api,'new-customer','test-pass','pkg9-timed','3M/10M','Test','1','hotspot1',$package,date('Y-m-d H:i:s',time()+1800));
checkConnectivity(str_contains($api->router->data['/ip/hotspot/profile']['*1']['login-by'],'http-pap'), 'Complete hotspot provisioning enables the password handoff used by the portal');
checkConnectivity($api->router->data['/ip/hotspot/user']['*1']['disabled']==='false', 'Complete hotspot provisioning enables the user only after profile, login method and deadlines are installed');
class DeviceLoginRouterDouble {
    public bool $loggedIn = false;
    public function comm($path,$params): array {
        if ($path==='/ip/hotspot/host/print') return [['address'=>'192.0.2.10','mac-address'=>'AA:BB:CC:DD:EE:FF']];
        if ($path==='/ip/hotspot/active/login') {
            checkConnectivity(in_array('=ip=192.0.2.10',$params,true) && in_array('=mac-address=AA:BB:CC:DD:EE:FF',$params,true), 'Automatic login uses the router-observed device address');
            $this->loggedIn = true;
        }
        if ($path==='/ip/hotspot/active/print' && $this->loggedIn) return [['user'=>'paid-user','mac-address'=>'AA:BB:CC:DD:EE:FF']];
        return [];
    }
}
$device = new DeviceLoginRouterDouble();
checkConnectivity(connectKnownHotspotDevice($device,'AA:BB:CC:DD:EE:FF','paid-user','test',date('Y-m-d H:i:s',time()+60)), 'Paid device is logged in and active session verified without an open browser');
$device = new DeviceLoginRouterDouble();
checkConnectivity(!connectKnownHotspotDevice($device,'AA:BB:CC:DD:EE:FF','paid-user','test',date('Y-m-d H:i:s',time())) && !$device->loggedIn, 'Automatic device login cannot bypass paid expiry');
echo "Simulated onboarding checks passed. This does not prove live Safaricom delivery or Internet connectivity.\n";
