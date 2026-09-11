<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/router_expiry.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$st = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
$st->execute([$_SESSION['user_id']]);
$tenant = (int)$st->fetchColumn();
session_write_close();
if (!$tenant) { http_response_code(403); echo json_encode(['success'=>false]); exit; }
$st = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id=? AND status IN ('active','online')");
$st->execute([$tenant]);
$routers = $st->fetchAll(PDO::FETCH_ASSOC);
$online=[]; $details=[]; $unavailable=[];
foreach ($routers as $router) {
    // Failure to read PPPoE must not prevent checking this router's hotspot.
    foreach (['hotspot','pppoe'] as $service) {
        $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
        try {
            if (!$api->connect()) throw new RuntimeException('Router connection failed');
            $rows = routerCheckedCommand($api,$service==='hotspot'?'/ip/hotspot/active/print':'/ppp/active/print');
            foreach ($rows as $row) {
                $username = $service==='hotspot' ? ($row['user'] ?? '') : ($row['name'] ?? '');
                if (!$username || !isset($row['.id'])) continue;
                $online[]=$username;
                $details[strtolower($username)] = ['uptime'=>$row['uptime'] ?? '', 'bytes_in'=>$row['bytes-in'] ?? 0,
                    'bytes_out'=>$row['bytes-out'] ?? 0, 'address'=>$row['address'] ?? '', 'service'=>$service];
            }
        } catch (Throwable $e) {
            $unavailable[$service][]=(int)$router['id'];
            error_log('Online status router '.$router['id'].' '.$service.': '.$e->getMessage());
        } finally { $api->disconnect(); }
    }
}
$online=array_values(array_unique($online));
if ($online) {
    try {
        $names=array_map('strtolower',$online);
        $placeholders=implode(',',array_fill(0,count($names),'?'));
        $pdo->prepare("UPDATE clients SET last_seen=NOW() WHERE tenant_id=? AND LOWER(mikrotik_username) IN ($placeholders)")->execute(array_merge([$tenant],$names));
    } catch (Throwable $e) { error_log('Last seen update: '.$e->getMessage()); }
}
echo json_encode(['success'=>true,'online'=>$online,'details'=>$details,'unavailable'=>$unavailable,'no_routers'=>!$routers]);
