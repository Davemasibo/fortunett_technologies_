<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/router_wan_profile.php';
try {
    ensureWanProfiles($pdo);
    $rows=$pdo->query("SELECT r.* FROM router_wan_profiles w JOIN mikrotik_routers r ON r.id=w.router_id AND r.tenant_id=w.tenant_id WHERE w.state IN ('pending','applying','verifying') ORDER BY w.checked_at ASC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $domain=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1")->fetchColumn() ?: 'fortunetttech.site';
    foreach($rows as $router){
        $st=$pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');$st->execute([$router['tenant_id']]);$sub=(string)$st->fetchColumn();
        $host=($sub?$sub.'.':'').$domain;
        if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host))continue;
        try{processWanProfile($pdo,$router,'https://'.$host.'/api/routers/wan_health.php');}catch(Throwable $e){error_log('WAN profile worker could not process router '.(int)$router['id']);}
    }
} catch(Throwable $e){error_log('WAN profile worker unavailable');exit(1);}
