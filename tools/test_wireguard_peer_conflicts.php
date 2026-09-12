<?php
require_once __DIR__.'/../classes/WireGuardManager.php';
$peers=['installed-key'=>['allowed_ips'=>'10.200.200.7/32']];
WireGuardManager::assertPeerAddressAvailable('installed-key','10.200.200.7',$peers);
WireGuardManager::assertPeerAddressAvailable('new-key','10.200.200.8',$peers);
foreach (['10.200.200.7/32','10.200.200.8/32,10.200.200.7/32','10.200.200.7'] as $addresses) {
    try {
        WireGuardManager::assertPeerAddressAvailable('new-key','10.200.200.7',['installed-key'=>['allowed_ips'=>$addresses]]);
        throw new Exception('Address collision was accepted');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'No VPN changes were applied')) throw $e;
    }
}
echo "PASS: existing peer and unused address accepted; three conflicting address forms blocked\n";
