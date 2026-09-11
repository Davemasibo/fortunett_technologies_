<?php
require_once __DIR__ . '/validity.php';

function hotspotApplyHistoricalTariff(array $event,array $payment,array $tariff): array {
    $amount=(float)$payment['amount'];
    $rule=$amount===floor($amount) ? ($tariff['prices'][(string)(int)$amount] ?? null) : null;
    if (!$rule) return $event;
    if (empty($event['confirmed_at'])) {
        // Legacy confirmation timestamps were overwritten by polling. Use the
        // original purchase record as a conservative anchor, never updated_at/NOW.
        $event['confirmed_at']=$payment['payment_date'];
        $event['time_basis']='legacy payment_date; exact confirmation timestamp unavailable';
    }
    $approved=packageExpiryFrom($rule['validity_value'],$rule['validity_unit'],$event['confirmed_at']);
    $recorded=!empty($event['validity_value']) && !empty($event['validity_unit'])
        ? packageExpiryFrom($event['validity_value'],$event['validity_unit'],$event['confirmed_at']) : null;
    // Owner-approved correction can shorten a recorded grant, never lengthen it.
    if (!$recorded || strtotime($approved)<strtotime($recorded)) {
        $event['validity_value']=$rule['validity_value'];$event['validity_unit']=$rule['validity_unit'];
        $event['terms_basis']=$tariff['source'];
    }
    return $event;
}

function hotspotTariffPackagePlan(PDO $pdo,array $tariff,bool $apply): array {
    $tenant=(int)$tariff['tenant_id'];
    $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');$st->execute([$tenant]);
    if ($st->fetchColumn()!==$tariff['subdomain']) throw new RuntimeException('Tariff tenant identity does not match this deployment');
    $changes=[];
    foreach($tariff['prices'] as $price=>$rule) {
        $st=$pdo->prepare('SELECT * FROM packages WHERE id=? AND tenant_id=?');$st->execute([$rule['package_id'],$tenant]);$package=$st->fetch(PDO::FETCH_ASSOC);
        if (!$package || (float)$package['price']!==(float)$price || (($package['connection_type'] ?? '') ?: ($package['type'] ?? ''))!=='hotspot') throw new RuntimeException('Package identity or price changed: '.$rule['package_id']);
        if ((int)$package['validity_value']===$rule['validity_value'] && packageValidityUnit($package['validity_unit'],true)===$rule['validity_unit']) continue;
        $changes[]=['package_id'=>$rule['package_id'],'price'=>$price,'old_value'=>$package['validity_value'],'old_unit'=>$package['validity_unit'],'new_value'=>$rule['validity_value'],'new_unit'=>$rule['validity_unit']];
    }
    if ($apply) {
        $pdo->beginTransaction();
        try {
            foreach($changes as $change) {
                $st=$pdo->prepare('UPDATE packages SET validity_value=?,validity_unit=? WHERE id=? AND tenant_id=? AND price=? AND validity_value=? AND validity_unit=?');
                $st->execute([$change['new_value'],$change['new_unit'],$change['package_id'],$tenant,$change['price'],$change['old_value'],$change['old_unit']]);
                if ($st->rowCount()!==1) throw new RuntimeException('Package changed concurrently; rerun reconciliation');
                $pdo->prepare('INSERT INTO hotspot_tariff_repairs (tenant_id,package_id,evidence) VALUES (?,?,?)')->execute([$tenant,$change['package_id'],json_encode(['change'=>$change,'source'=>$tariff['source']])]);
                dashboardQueuePackage($pdo,$tenant,(int)$change['package_id']);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack();throw $e; }
    }
    return $changes;
}
