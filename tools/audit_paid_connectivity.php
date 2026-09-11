<?php
/** Read-only live evidence. No credentials, phone numbers or receipt values are printed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/../includes/connectivity_audit.php';
$options = getopt('', ['router:', 'client::', 'all', 'evidence']);
$routerId = (int)($options['router'] ?? 0);
if (!$routerId) exit("Usage: php tools/audit_paid_connectivity.php --router=ID [--client=ID]\n");
$stmt = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE id=?'); $stmt->execute([$routerId]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$router) exit("Router not found\n");
$clientId = (int)($options['client'] ?? 0);
$all = array_key_exists('all', $options);
$clients = $pdo->prepare("SELECT c.id,c.account_number,c.package_id,c.status,c.expiry_date,c.connection_type,c.mikrotik_username,p.validity_value,p.validity_unit,p.mikrotik_profile
    FROM clients c LEFT JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id
    WHERE c.tenant_id=? AND (?=0 OR c.id=?) ORDER BY c.id DESC" . ($all ? "" : " LIMIT 50"));
$clients->execute([$router['tenant_id'],$clientId,$clientId]);
$customerRows = $clients->fetchAll(PDO::FETCH_ASSOC);
$report = ['checked_at'=>date(DATE_ATOM),'router_id'=>$routerId,'tenant_id'=>(int)$router['tenant_id'],'checks'=>[]];
try {
    $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
    if (!$api->connect()) throw new RuntimeException('Router connection failed');
    require_once __DIR__ . '/../includes/router_expiry.php';
    foreach (routerCheckedCommand($api,'/system/clock/print') as $clock) if (isset($clock['date'])) $report['router_clock'] = array_intersect_key($clock,array_flip(['date','time','time-zone-name']));
    $report['watchdog_installed'] = false;
    foreach (routerCheckedCommand($api,'/system/scheduler/print',['?name=fn-paid-expiry-watchdog']) as $row) if (isset($row['.id'])) $report['watchdog_installed'] = ($row['disabled'] ?? '') === 'false';
    foreach ($customerRows as $client) {
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
        try {
            $payments=$pdo->prepare("SELECT COUNT(*) AS confirmed_count,MAX(payment_date) AS latest_payment_at FROM payments WHERE tenant_id=? AND client_id=? AND status='completed'");
            $payments->execute([$router['tenant_id'],$client['id']]);
            $client['payments']=$payments->fetch(PDO::FETCH_ASSOC);
            $client['findings']=[];
            if ($client['expired_but_online']) $client['findings'][]='NOT_ENTITLED_BUT_ONLINE';
            if (!empty($client['payments']['confirmed_count']) && !$client['expiry_date']) $client['findings'][]='PAYMENT_WITHOUT_EXPIRY';
            if (!$client['package_id']) $client['findings'][]='NO_PACKAGE';
            if ($client['entitled_now'] && !$client['router_user']) $client['findings'][]='PAID_ACCESS_NOT_ON_THIS_ROUTER';
            if ($client['entitled_now'] && ($client['router_user']['disabled'] ?? '')==='true') $client['findings'][]='PAID_ROUTER_USER_DISABLED';
            if ($client['entitled_now'] && !$client['active_sessions']) $client['findings'][]='PAID_ACCESS_NO_ACTIVE_SESSION';
            if (empty($client['last_seen'])) $client['findings'][]='NO_RECORDED_CONNECTION';
        } catch (Throwable $e) { $client['payment_audit_unavailable']=true; }
        $client['findings'] = connectivityEvidenceFindings($client);
        if (array_key_exists('evidence', $options)) {
            // Keep transaction references, phone numbers and credentials out of shared reports.
            foreach ([
                'payment_history' => "SELECT id,amount,payment_method,status,payment_date FROM payments WHERE tenant_id=? AND client_id=? ORDER BY payment_date,id",
                'activation_history' => 'SELECT DISTINCT expiry_date,created_at FROM payment_activations WHERE tenant_id=? AND client_id=? ORDER BY created_at',
                'purchased_terms' => 'SELECT package_id,validity_value,validity_unit FROM payment_purchase_terms WHERE tenant_id=? AND client_id=?',
                'stk_history' => 'SELECT amount,status,result_code,created_at,updated_at FROM mpesa_transactions WHERE tenant_id=? AND client_id=? ORDER BY created_at',
                'service_history' => 'SELECT router_id,package_id,status,paid_expiry_at,expiry_policy_version FROM router_services WHERE tenant_id=? AND client_id=?',
            ] as $label => $sql) {
                try {
                    $evidence = $pdo->prepare($sql);
                    $evidence->execute([$router['tenant_id'],$client['id']]);
                    $client[$label] = $evidence->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) { $client[$label] = ['unavailable'=>true]; }
            }
        }
        $schedule = 'fn-exp-'.($hotspot?'hotspot':'pppoe').'-'.substr(hash('sha256',$username),0,24);
        $client['deadline_schedule'] = null;
        foreach (routerCheckedCommand($api,'/system/scheduler/print',['?name='.$schedule]) as $row) if (isset($row['.id'])) $client['deadline_schedule'] = array_intersect_key($row,array_flip(['disabled','start-date','start-time','run-count','next-run']));
        $report['checks'][] = $client;
    }
} catch (Throwable $e) { $report['error'] = $e->getMessage(); }
finally { if (isset($api)) $api->disconnect(); }
$report['customers_checked']=count($report['checks']);
$report['customers_expected']=count($customerRows);
$report['audit_complete']=!isset($report['error']) && count($report['checks'])===count($customerRows);
$report['scope']=$all?'All customers in this tenant against the selected router':'Selected customer or latest 50';
$report['internet_traffic_verified']=false;
echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;
