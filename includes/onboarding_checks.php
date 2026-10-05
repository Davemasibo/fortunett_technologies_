<?php
/** Missing migration must never prevent router provisioning. */
function onboardingChecks(PDO $pdo, int $tenantId): array {
    try {
        $st=$pdo->prepare('SELECT * FROM router_onboarding_checks WHERE tenant_id=?');
        $st->execute([$tenantId]);
        $rows=[];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['router_id']]=$row;
        return $rows;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return [];
    }
}
function onboardingServices(string $services): string {
    $items=array_values(array_unique(array_filter(array_map('trim',explode(',',$services)),fn($s)=>in_array($s,['hotspot','pppoe'],true))));
    sort($items); return implode(',',$items);
}
function onboardingRouterVerified(array $router, array $check): bool {
    $services=onboardingServices($router['service_types'] ?? '');
    return $services!=='' && !empty($check['verified_at']) && $services===onboardingServices($check['services'] ?? '');
}
function saveOnboardingCheck(PDO $pdo,int $tenantId,int $routerId,string $services,bool $ok,string $failure): void {
    try {
        $st=$pdo->prepare('INSERT INTO router_onboarding_checks (tenant_id,router_id,services,verified_at,checked_at,failure_summary) VALUES (?,?,?,IF(?,NOW(),NULL),NOW(),?) ON DUPLICATE KEY UPDATE services=VALUES(services),verified_at=VALUES(verified_at),checked_at=NOW(),failure_summary=VALUES(failure_summary)');
        $st->execute([$tenantId,$routerId,onboardingServices($services),$ok ? 1 : 0,mb_substr($failure,0,1000)]);
    } catch (PDOException $e) {
        error_log('Could not save onboarding verification: '.$e->getCode());
    }
}
