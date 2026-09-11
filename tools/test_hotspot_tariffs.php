<?php
require_once __DIR__ . '/../includes/hotspot_tariffs.php';
require_once __DIR__ . '/../includes/hotspot_expiry_reconciliation.php';
date_default_timezone_set('Africa/Nairobi');
$tariff=json_decode(file_get_contents(__DIR__.'/../config/ghettohlink_hotspot_tariffs.json'),true,512,JSON_THROW_ON_ERROR);
function tariffCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
foreach([5=>1800,10=>3600,15=>10800,20=>21600,25=>28800,30=>50400,40=>86400,100=>345600,150=>604800,700=>2592000] as $price=>$seconds){
    $event=hotspotApplyHistoricalTariff(['identity'=>'payment-'.$price],['amount'=>$price,'payment_date'=>'2026-07-01 10:00:00'],$tariff);
    $plan=hotspotPurchasedDeadline([$event]);
    tariffCheck(strtotime($plan['expiry'])-strtotime('2026-07-01 10:00:00')===$seconds,'KES '.$price.' grants exactly the approved duration');
}
$event=hotspotApplyHistoricalTariff(['confirmed_at'=>'2026-09-01 10:00:00','validity_value'=>25,'validity_unit'=>'hours'],['amount'=>40,'payment_date'=>'2026-09-01 09:59:40'],$tariff);
tariffCheck($event['validity_value']===24 && $event['confirmed_at']==='2026-09-01 10:00:00','Approved 24-hour correction preserves recorded activation timestamp');
$event=hotspotApplyHistoricalTariff(['confirmed_at'=>'2026-09-01 10:00:00','validity_value'=>2,'validity_unit'=>'hours'],['amount'=>15,'payment_date'=>'2026-09-01 09:59:40'],$tariff);
tariffCheck($event['validity_value']===2,'Historical shorter purchase is not lengthened to current tariff');
tariffCheck(hotspotApplyHistoricalTariff(['identity'=>'unknown'],['amount'=>50,'payment_date'=>'2026-09-01 10:00:00'],$tariff)===['identity'=>'unknown'],'Unlisted price cannot be guessed');
tariffCheck(hotspotApplyHistoricalTariff(['identity'=>'fractional'],['amount'=>5.5,'payment_date'=>'2026-09-01 10:00:00'],$tariff)===['identity'=>'fractional'],'Payment amount must match exactly');
$event=['identity'=>'confirmed-checkout','payment_id'=>1,'amount'=>'5.00','canonical_stk'=>true,'validity_value'=>30,'validity_unit'=>'minutes','confirmed_at'=>'2026-09-11 10:00:00'];
$alias=array_merge($event,['payment_id'=>2,'confirmed_at'=>'2026-09-11 10:00:20']);
$collapsed=hotspotConfirmedLedgerEvents([$event,$alias]);
tariffCheck(count($collapsed)===1 && $collapsed[0]['ledger_payment_ids']===[1,2] && hotspotPurchasedDeadline($collapsed)['expiry']==='2026-09-11 10:30:00','Confirmed checkout and receipt ledger aliases grant one duration with both ledger IDs retained');
tariffCheck(!hotspotPurchasedDeadline(hotspotConfirmedLedgerEvents([$event,array_merge($alias,['amount'=>'10.00'])]))['repairable'],'Conflicting duplicate amounts still require review');
tariffCheck(!hotspotPurchasedDeadline(hotspotConfirmedLedgerEvents([array_merge($event,['canonical_stk'=>false]),$alias]))['repairable'],'Unproven identity duplication cannot be silently collapsed');
tariffCheck(count(hotspotConfirmedLedgerEvents([$event,array_merge($alias,['identity'=>'another-confirmed-checkout'])]))===2,'Two independent confirmed purchases stay separate');
