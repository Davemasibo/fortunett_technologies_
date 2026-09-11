<?php
require_once __DIR__ . '/../includes/hotspot_access_policy.php';
require_once __DIR__ . '/../includes/hotspot_expiry_reconciliation.php';
date_default_timezone_set('Africa/Nairobi');
function policyCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$paid=['price'=>150,'validity_value'=>7,'validity_unit'=>'days'];
policyCheck(hotspotRegistrationAccess($paid,'hotspot')===['status'=>'pending','expiry'=>null],'Paid hotspot creation never grants access before payment');
policyCheck(hotspotRegistrationAccess($paid,'pppoe')===null,'Hotspot policy does not change PPPoE registration');
$client=['connection_type'=>'hotspot','expiry_date'=>'2026-09-14 09:13:12','status'=>'active','package_id'=>30];
foreach([['action'=>'renew'],['extend_days'=>90],['expiry_date'=>'2026-12-07'],['status'=>'active','package_id'=>31]] as $body){
    $failed=false;try{assertHotspotApiProfileOnly($client,$body);}catch(InvalidArgumentException $e){$failed=true;}
    policyCheck($failed,'Alternative API cannot grant or alter hotspot entitlement');
}
assertHotspotApiProfileOnly($client,['full_name'=>'Updated contact']);
policyCheck(true,'Ordinary contact edits remain available');
$events=[['identity'=>'one','confirmed_at'=>'2026-09-07 09:13:12','validity_value'=>7,'validity_unit'=>'days']];
policyCheck(hotspotPurchasedDeadline($events)['expiry']==='2026-09-14 09:13:12','Historical weekly purchase is rebuilt from confirmation, never current time');
$events[]=['identity'=>'two','confirmed_at'=>'2026-09-08 10:00:00','validity_value'=>30,'validity_unit'=>'minutes'];
policyCheck(hotspotPurchasedDeadline($events)['expiry']==='2026-09-14 09:43:12','Independent renewal adds only purchased remaining time');
$events[]=['identity'=>'three','confirmed_at'=>'2026-09-20 10:00:00','validity_value'=>3,'validity_unit'=>'hours'];
policyCheck(hotspotPurchasedDeadline($events)['expiry']==='2026-09-20 13:00:00','Purchase after an expired period starts from its historical activation');
policyCheck(hotspotPurchasedDeadline(array_reverse($events))===hotspotPurchasedDeadline($events),'Unordered payment records reconstruct deterministically');
$events[]=$events[0];
policyCheck(!hotspotPurchasedDeadline($events)['repairable'],'Duplicate payment identity never grants time twice');
policyCheck(!hotspotPurchasedDeadline([['identity'=>'legacy']])['repairable'],'Missing historical terms cannot trigger guessed bulk changes');
policyCheck(!hotspotPurchasedDeadline([])['repairable'],'No payments is a review case, not a fabricated deadline');
