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
$check(adminCustomerExpiry($admin,'2030-10-25T10:59')==='2030-10-25 10:59:00','Manual expiry allows extending paid customer access');
$check(adminCustomerExpiry($admin,'2020-01-01T00:00')==='2020-01-01 00:00:00','Manual expiry still permits shortening access');
foreach (['2027-02-30T12:00', 'garbage', '', '2027-01-01T25:00'] as $invalid) {
    $rejected = false;
    try { adminCustomerExpiry($admin, $invalid); } catch (InvalidArgumentException $e) { $rejected = true; }
    $check($rejected, 'Reject invalid manual expiry');
}
$rejected = false;
try { adminCustomerExpiry(['role'=>'customer'], '2030-01-01T12:00'); } catch (InvalidArgumentException $e) { $rejected = true; }
$check($rejected, 'Customer cannot grant themselves more time');
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
