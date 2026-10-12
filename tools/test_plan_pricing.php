<?php
require_once __DIR__.'/../includes/plan_pricing.php';
function planCheck(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n"; }
$input=['name'=>'Standard','pppoe_fee_per_user'=>'25.00','hotspot_commission_rate'=>'3','base_monthly_fee'=>'500','sms_rate'=>'1.2500','max_clients'=>'0','max_routers'=>'','trial_days'=>'30'];
planCheck(planPricingValidationError($input)===null,'All pricing and unlimited limits validate');
foreach ([['sms_rate','-1'],['sms_rate','not-a-number'],['sms_rate','1.23456'],['hotspot_commission_rate','101'],['max_clients','-1'],['max_routers','1.5'],['trial_days','366']] as [$key,$value]) {
    planCheck(planPricingValidationError(array_replace($input,[$key=>$value]))!==null,'Reject invalid '.$key.' '.$value);
}
planCheck(planPricingValidationError(array_replace($input,['sms_rate'=>'0','hotspot_commission_rate'=>'0']))===null,'Zero SMS and commission rates are allowed');
