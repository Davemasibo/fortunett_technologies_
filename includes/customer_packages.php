<?php
function customerConnectionType(array $client): string {
    $type=strtolower(trim((string)($client['connection_type'] ?? 'hotspot')));
    if (!in_array($type,['hotspot','pppoe'],true)) throw new InvalidArgumentException('Your connection type is not configured. Contact your provider.');
    return $type;
}
function customerPackageType(array $package): string {
    foreach (['connection_type','type','conn_type'] as $key) {
        $type=strtolower(trim((string)($package[$key] ?? '')));
        if ($type!=='') return $type;
    }
    return 'hotspot';
}
function customerPackageTypeSql(): string {
    return "LOWER(TRIM(COALESCE(NULLIF(connection_type,''),NULLIF(type,''),'hotspot')))";
}
function assertCustomerPackageCompatible(array $client,array $package,bool $active=true): void {
    if (isset($package['tenant_id'],$client['tenant_id']) && (int)$package['tenant_id']!==(int)$client['tenant_id']) throw new InvalidArgumentException('Package not found in your account.');
    if (customerPackageType($package)!==customerConnectionType($client)) throw new InvalidArgumentException('Choose a '.strtoupper(customerConnectionType($client)).' package for your connection.');
    if ($active && ($package['status'] ?? '')!=='active') throw new InvalidArgumentException('This package is not available for purchase.');
}
function customerAvailablePackages(PDO $pdo,int $tenant,array $client):array {
    $st=$pdo->prepare("SELECT * FROM packages WHERE tenant_id=? AND status='active' AND ".customerPackageTypeSql().'=? ORDER BY price ASC,name ASC');
    $st->execute([$tenant,customerConnectionType($client)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
