<?php
require_once __DIR__ . '/../includes/admin_hotspot_access.php';
date_default_timezone_set('Africa/Nairobi');
$now = new DateTimeImmutable('2026-09-16 12:00:00');
$admin = ['role'=>'admin','tenant_id'=>7];
$client = ['tenant_id'=>7,'connection_type'=>'hotspot','status'=>'expired'];
$check = function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$check(adminHotspotGrantExpiry($admin,$client,'2036-09-16T12:00',$now)==='2036-09-16 12:00:00','Expired owner account can receive ten years without payment');
foreach ([
    [['role'=>'operator','tenant_id'=>7],$client,'2027-09-16T12:00'],
    [$admin,array_merge($client,['tenant_id'=>8]),'2027-09-16T12:00'],
    [$admin,array_merge($client,['connection_type'=>'pppoe']),'2027-09-16T12:00'],
    [$admin,$client,'2036-09-16T12:01'],
    [$admin,$client,'2026-09-16T12:00'],
    [$admin,$client,'2027-02-30T12:00'],
    [$admin,$client,'garbage'],
] as [$actor,$target,$date]) {
    $rejected = false;
    try { adminHotspotGrantExpiry($actor,$target,$date,$now); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    $check($rejected,'Reject unauthorized, cross-tenant, wrong-service or invalid-date grant');
}
