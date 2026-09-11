<?php
require_once __DIR__ . '/auto_provision.php';
require_once __DIR__ . '/hotspot_device.php';

function reconnectReceiptClient(PDO $pdo, int $clientId, int $tenantId, string $mac, ?callable $provision = null): array {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=?');
    $st->execute([$clientId,$tenantId]); $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) return ['success'=>false,'message'=>'Account not found for this tenant.'];
    if (($client['connection_type'] ?? '') !== 'hotspot') return ['success'=>false,'message'=>'This payment belongs to a different connection type. Contact your ISP.'];
    if (in_array($client['status'], ['suspended','blocked'], true)) return ['success'=>false,'message'=>'This account is suspended. Contact your ISP; do not pay again.'];
    if (empty($client['expiry_date'])) return ['success'=>false,'message'=>'Payment found but access has not been activated. Contact your ISP; do not pay again.'];
    if (strtotime($client['expiry_date']) <= time()) return ['success'=>false,'message'=>'The access time associated with this account has expired. A receipt cannot extend purchased time.'];
    if ($client['status'] !== 'active') return ['success'=>false,'message'=>'Payment found but the account is inactive. Contact your ISP; do not pay again.'];
    if (!empty($client['bound_mac_address']) && hotspotDeviceMac($mac) !== hotspotDeviceMac($client['bound_mac_address'])) {
        return ['success'=>false,'message'=>'This purchase is bound to your TV or device. Connect that device to this Wi-Fi.'];
    }
    if (hotspotDeviceMac($mac)) rememberHotspotDevice($pdo,$tenantId,$clientId,$mac);
    $provision = $provision ?? fn()=>autoProvisionClient($pdo,$clientId,$tenantId,0,false);
    $result = $provision();
    if (empty($result['success'])) return ['success'=>false,'message'=>'Payment found. Router connection setup is still pending; do not pay again.'];
    $st->execute([$clientId,$tenantId]); $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client || $client['status'] !== 'active' || strtotime($client['expiry_date'] ?? '') <= time()) return ['success'=>false,'message'=>'Access is no longer active.'];
    if (empty($client['mikrotik_username']) || empty($client['mikrotik_password'])) return ['success'=>false,'message'=>'Payment found. Login credentials are not ready; contact your ISP.'];
    return ['success'=>true,'username'=>$client['mikrotik_username'],'password'=>$client['mikrotik_password'],'expiry'=>$client['expiry_date'],'router_ok'=>true];
}
