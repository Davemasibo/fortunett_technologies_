<?php
/** Read-only live evidence. No credentials, phone numbers or receipt values are printed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';
$options = getopt('', ['router:', 'client::']);
$routerId = (int)($options['router'] ?? 0);
if (!$routerId) exit("Usage: php tools/audit_paid_connectivity.php --router=ID [--client=ID]\n");
$stmt = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=?'); $stmt->execute([$routerId]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$router) exit("Router not found\n");
$clientId = (int)($options['client'] ?? 0);
$clients = $pdo->prepare("SELECT c.id,c.status,c.expiry_date,c.connection_type,c.mikrotik_username,p.validity_value,p.validity_unit,p.mikrotik_profile
    FROM clients c LEFT JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id
    WHERE c.tenant_id=? AND (?=0 OR c.id=?) ORDER BY c.id DESC LIMIT 50");
$clients->execute([$router['tenant_id'],$clientId,$clientId]);
$report = ['checked_at'=>date(DATE_ATOM),'router_id'=>$routerId,'tenant_id'=>(int)$router['tenant_id'],'checks'=>[]];
try {
    $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
    if (!$api->connect()) throw new RuntimeException('Router connection failed');
    require_once __DIR__ . '/../includes/router_expiry.php';
    foreach (routerCheckedCommand($api,'/system/clock/print') as $clock) if (isset($clock['date'])) $report['router_clock'] = array_intersect_key($clock,array_flip(['date','time','time-zone-name']));
    $report['watchdog_installed'] = false;
    foreach (routerCheckedCommand($api,'/system/scheduler/print',['?name=fn-paid-expiry-watchdog']) as $row) if (isset($row['.id'])) $report['watchdog_installed'] = ($row['disabled'] ?? '') === 'false';
    foreach ($clients->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $hotspot = $client['connection_type']==='hotspot';
        $username = $client['mikrotik_username']; unset($client['mikrotik_username']);
        $client['entitled_now'] = $client['status']==='active' && !empty($client['expiry_date']) && strtotime($client['expiry_date'])>time();
        $base = $hotspot ? '/ip/hotspot/user' : '/ppp/secret';
        $client['router_user'] = null;
        foreach (routerCheckedCommand($api,$base.'/print',['?name='.$username]) as $row) if (isset($row['.id'])) $client['router_user'] = array_intersect_key($row,array_flip(['disabled','profile','uptime','limit-uptime']));
        $live = routerCheckedCommand($api,($hotspot?'/ip/hotspot/active':'/ppp/active').'/print',[($hotspot?'?user=':'?name=').$username]);
        $client['active_sessions'] = count(array_filter($live,fn($row)=>isset($row['.id'])));
        $client['expired_but_online'] = !$client['entitled_now'] && $client['active_sessions']>0;
        try {
            $details = $pdo->prepare('SELECT last_seen FROM clients WHERE id=? AND tenant_id=?');
            $details->execute([$client['id'],$router['tenant_id']]);
            $client['last_seen'] = $details->fetchColumn();
            $details = $pdo->prepare('SELECT attempts,fail_reason,next_retry_at FROM pending_provisions WHERE client_id=? AND tenant_id=?');
            $details->execute([$client['id'],$router['tenant_id']]);
            $client['provisioning_retry'] = $details->fetch(PDO::FETCH_ASSOC) ?: null;
            $details = $pdo->prepare('SELECT mac_address FROM hotspot_device_context WHERE client_id=? AND tenant_id=? AND updated_at>NOW()-INTERVAL 1 DAY');
            $details->execute([$client['id'],$router['tenant_id']]);
            $mac = $details->fetchColumn();
            $client['device_context_available'] = (bool)$mac;
            $client['device_visible_on_router'] = $mac ? count(array_filter(routerCheckedCommand($api,'/ip/hotspot/host/print',['?mac-address='.$mac]),fn($row)=>isset($row['.id'])))>0 : false;
        } catch (Throwable $e) { $client['recovery_metadata_available'] = false; }
        $schedule = 'fn-exp-'.($hotspot?'hotspot':'pppoe').'-'.substr(hash('sha256',$username),0,24);
        $client['deadline_schedule'] = null;
        foreach (routerCheckedCommand($api,'/system/scheduler/print',['?name='.$schedule]) as $row) if (isset($row['.id'])) $client['deadline_schedule'] = array_intersect_key($row,array_flip(['disabled','start-date','start-time','run-count','next-run']));
        $report['checks'][] = $client;
    }
} catch (Throwable $e) { $report['error'] = $e->getMessage(); }
finally { if (isset($api)) $api->disconnect(); }
echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;
