<?php
/** A saved placeholder is not configured. Connectivity history survives outages. */
function platformConfiguredRouterCount(PDO $pdo,int $tenantId,?string $period=null):int {
    $until=date('Y-m-01',strtotime(($period??date('Y-m-01')).' +1 month'));
    $st=$pdo->prepare("SELECT COUNT(*) FROM mikrotik_routers WHERE tenant_id=?
        AND last_seen IS NOT NULL AND status IN ('active','online','offline','inactive')
        AND created_at<?");
    $st->execute([$tenantId,$until]);return (int)$st->fetchColumn();
}
function platformRouterBaseCharge(string $status,int $configured,float $rate):array {
    $count=$status==='trial'?0:max(0,$configured);
    $rate=max(0,$rate);
    return ['router_count'=>$count,'router_fee_per_router'=>$count>0?$rate:0.0,'base_fee'=>round($count*$rate,2)];
}
