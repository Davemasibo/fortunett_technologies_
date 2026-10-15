<?php
/** CLI-only, read-only inventory. Never prints passwords, tokens or RADIUS secrets. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../classes/MikrotikAPI.php';
$subdomain = $argv[1] ?? 'homelinkfiber';
$st = $pdo->prepare('SELECT id,company_name,subdomain FROM tenants WHERE subdomain=?');
$st->execute([$subdomain]); $tenant=$st->fetch(PDO::FETCH_ASSOC);
if (!$tenant) { fwrite(STDERR,"Tenant not found.\n"); exit(1); }
echo json_encode(['tenant'=>$tenant],JSON_PRETTY_PRINT)."\n";
$st = $pdo->prepare('SELECT connection_type,status,COUNT(*) AS customers FROM clients WHERE tenant_id=? GROUP BY connection_type,status');
$st->execute([$tenant['id']]); echo json_encode(['customers'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_PRETTY_PRINT)."\n";
$st = $pdo->prepare("SELECT COUNT(*) FROM clients c JOIN clients other ON other.mikrotik_username=c.mikrotik_username AND other.tenant_id<>c.tenant_id WHERE c.tenant_id=? AND c.mikrotik_username<>''");
$st->execute([$tenant['id']]); echo json_encode(['cross_tenant_username_collisions'=>(int)$st->fetchColumn()])."\n";
$st = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? ORDER BY id');
$st->execute([$tenant['id']]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $router) {
    $result=['id'=>(int)$router['id'],'name'=>$router['name'],'status'=>$router['status'],'vpn_ip'=>$router['vpn_ip'] ?? null];
    $api=new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
    try {
        $api->connect();
        foreach ($api->comm('/system/resource/print') as $row) if (isset($row['version'])) $result['routeros']=$row['version'];
        $result['radius']=[];
        foreach ($api->comm('/radius/print') as $row) {
            if (isset($row['!trap'])) throw new RuntimeException('RADIUS configuration cannot be read');
            if (isset($row['address'])) $result['radius'][]=array_intersect_key($row,array_flip(['address','service','disabled','src-address','authentication-port','accounting-port','protocol']));
        }
        $result['hotspot_profiles']=[];
        foreach ($api->comm('/ip/hotspot/profile/print') as $row) if (isset($row['name'])) $result['hotspot_profiles'][]=array_intersect_key($row,array_flip(['name','use-radius','radius-accounting','radius-default-domain']));
        foreach ($api->comm('/ppp/aaa/print') as $row) if (isset($row['use-radius'])) $result['ppp_radius']=$row['use-radius'];
    } catch (Throwable $e) { $result['error']=$e->getMessage(); }
    finally { $api->disconnect(); }
    echo json_encode(['router'=>$result],JSON_PRETTY_PRINT)."\n";
}
