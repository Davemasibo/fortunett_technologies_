<?php
/** Run as root on the VPS. Default is a read-only plan; --apply activates it. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/tenant_roaming.php';
require_once __DIR__.'/../classes/MikrotikAPI.php';
$options=getopt('', ['tenant:','server:','interface:','apply','rollback']);
$subdomain=$options['tenant'] ?? 'homelinkfiber';
$address=$options['server'] ?? '10.200.200.1';
$interface=$options['interface'] ?? 'wg0';
if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !preg_match('/^[a-zA-Z0-9_-]+$/D',$interface)) throw new InvalidArgumentException('Valid VPN address and interface required.');
$st=$pdo->prepare('SELECT id,company_name FROM tenants WHERE subdomain=?');$st->execute([$subdomain]);$tenant=$st->fetch(PDO::FETCH_ASSOC);
if (!$tenant) { fwrite(STDERR,"Tenant not found.\n");exit(1); }
$tid=(int)$tenant['id'];[$auth,$acct]=roamingPorts($tid);
$st=$pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? ORDER BY id');$st->execute([$tid]);$routers=$st->fetchAll(PDO::FETCH_ASSOC);
if (count($routers)<2) { fwrite(STDERR,"At least two tenant routers are required.\n");exit(1); }
$seen=[];
foreach ($routers as $router) {
    $ip=$router['vpn_ip'] ?? '';
    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || isset($seen[$ip])) { fwrite(STDERR,"Every router needs a unique VPN IPv4 address.\n");exit(1); }
    $seen[$ip]=true;
}
echo json_encode(['tenant'=>$tenant['company_name'],'server'=>$address,'auth_port'=>$auth,'accounting_port'=>$acct,'routers'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'vpn_ip'=>$r['vpn_ip']],$routers)],JSON_PRETTY_PRINT)."\n";
if (!isset($options['apply']) && !isset($options['rollback'])) { echo "Read-only plan. Add --apply to install, test, and activate.\n";exit; }
if (PHP_OS_FAMILY!=='Linux' || !function_exists('posix_geteuid') || posix_geteuid()!==0) { fwrite(STDERR,"Activation and rollback must run as root on the VPS.\n");exit(1); }
if (!is_dir('/etc/freeradius/3.0/mods-enabled') || !is_dir('/etc/freeradius/3.0/sites-enabled')) { fwrite(STDERR,"FreeRADIUS 3 configuration is required.\n");exit(1); }

function roamingCommand(array $args): array {
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Could not start server configuration command.');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    return [proc_close($p),$stdout.$stderr];
}
function roamingPrivateFile(string $path,string $contents,bool $radius=false): void {
    $tmp=$path.'.new';$handle=fopen($tmp,'wb');if (!$handle) throw new RuntimeException('Cannot write private configuration file.');
    chmod($tmp,$radius ? 0640 : 0600);
    try { if (fwrite($handle,$contents)!==strlen($contents)) throw new RuntimeException('Configuration write was incomplete.'); }
    finally { fclose($handle); }
    if ($radius && !chgrp($tmp,'freerad')) { unlink($tmp);throw new RuntimeException('FreeRADIUS group permissions could not be set.'); }
    if (!rename($tmp,$path)) throw new RuntimeException('Configuration file could not be installed.');
}
function roamingRouterApi(array $router): MikrotikAPI {
    $api=new MikrotikAPI($router['vpn_ip'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));$api->connect();return $api;
}
function restoreRoamingRouters(array $routers,array $state,int $tenant): void {
    foreach ($routers as $router) {
        $snapshot=$state['routers'][$router['id']] ?? null;if (!$snapshot) continue;
        $api=roamingRouterApi($router);
        try {
            foreach (routerCheckedCommand($api,'/radius/print') as $entry) if (($entry['comment'] ?? '')==='FortuNett-Roaming-'.$tenant && isset($entry['.id'])) routerCheckedCommand($api,'/radius/remove',['=.id='.$entry['.id']]);
            foreach ($snapshot['radius'] as $entry) if (isset($entry['.id'])) {
                $params=['=.id='.$entry['.id']];
                foreach (['service','disabled'] as $key) if (isset($entry[$key])) $params[]='='.$key.'='.$entry[$key];
                routerCheckedCommand($api,'/radius/set',$params);
            }
            foreach ($snapshot['profiles'] as $profile) if (isset($profile['.id'])) {
                $params=['=.id='.$profile['.id']];
                foreach (['use-radius','radius-accounting','radius-interim-update'] as $key) if (isset($profile[$key])) $params[]='='.$key.'='.$profile[$key];
                routerCheckedCommand($api,'/ip/hotspot/profile/set',$params);
            }
            foreach ($snapshot['ppp'] as $ppp) if (isset($ppp['use-radius'])) {
                $params=[];foreach (['use-radius','accounting','interim-update'] as $key) if (isset($ppp[$key])) $params[]='='.$key.'='.$ppp[$key];
                routerCheckedCommand($api,'/ppp/aaa/set',$params);
            }
        } finally { $api->disconnect(); }
    }
}
function restoreRoamingFiles(array $state): void {
    foreach ($state['backups'] as $path=>$contents) {
        if (!str_starts_with($path,'/etc/freeradius/3.0/')) throw new RuntimeException('Invalid backup path.');
        if ($contents===null) { if (file_exists($path)) unlink($path); }
        else roamingPrivateFile($path,$contents,true);
    }
}
if (!is_dir('/etc/fortunett')) mkdir('/etc/fortunett',0700,true);
$manifest='/etc/fortunett/roaming-'.$tid.'.json';
$state=file_exists($manifest) ? json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR) : null;
if ($state && !isset($options['rollback']) && !empty($state['enabled'])) {
    echo "This tenant's roaming activation is already recorded. Run tools/roaming_preflight.php to inspect current router settings.\n";exit;
}
if (isset($options['rollback'])) {
    if (!$state) { fwrite(STDERR,"No activation backup exists for this tenant.\n");exit(1); }
    restoreRoamingRouters($routers,$state,$tid);restoreRoamingFiles($state);
    $pdo->prepare("DELETE FROM tenant_settings WHERE tenant_id=? AND setting_key='roaming_radius_enabled'")->execute([$tid]);
    [$exit]=roamingCommand(['/usr/bin/systemctl','restart','freeradius']);
    if ($exit!==0) { fwrite(STDERR,"Router settings restored, but FreeRADIUS restart failed. Inspect its service status.\n");exit(1); }
    $state['enabled']=false;roamingPrivateFile($manifest,json_encode($state,JSON_PRETTY_PRINT));
    echo "Tenant roaming rolled back.\n";exit;
}
$name='fortunett_roaming_'.$tid;
$module='/etc/freeradius/3.0/mods-enabled/'.$name;
$site='/etc/freeradius/3.0/sites-enabled/'.$name;
$installed=false;$changedRouters=false;
try {
    [$exit,$addresses]=roamingCommand(['/usr/sbin/ip','-4','address','show','dev',$interface]);
    if ($exit || !str_contains($addresses,'inet '.$address.'/')) throw new RuntimeException('The requested server IP is not on the selected VPN interface.');
    $duplicates=$pdo->prepare("SELECT mikrotik_username FROM clients WHERE tenant_id=? AND mikrotik_username<>'' GROUP BY mikrotik_username HAVING COUNT(*)>1 LIMIT 1");$duplicates->execute([$tid]);
    if ($duplicates->fetchColumn()) throw new RuntimeException('Duplicate customer usernames must be corrected within this tenant before activation.');
    $test=$pdo->prepare("SELECT c.* FROM clients c JOIN packages p ON p.id=c.package_id AND p.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.connection_type IN ('hotspot','pppoe') AND c.status='active' AND c.expiry_date>NOW() AND c.mikrotik_username<>'' AND c.mikrotik_password<>'' AND p.download_speed>0 ORDER BY c.id LIMIT 1");$test->execute([$tid]);$testClient=$test->fetch(PDO::FETCH_ASSOC);
    if (!$testClient) throw new RuntimeException('One active, unexpired customer with credentials and a valid package is required for the activation test.');
    $database=get_env_var('DB_NAME','fortunnet_technologies');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$database)) throw new RuntimeException('Database name is not supported by this installer.');
    if (!$state) {
        if (file_exists($module) || file_exists($site)) throw new RuntimeException('Existing roaming configuration has no rollback manifest. Review it before activation.');
        $state=['tenant_id'=>$tid,'server'=>$address,'database_password'=>bin2hex(random_bytes(24)),'test_secret'=>bin2hex(random_bytes(24)),'routers'=>[],'backups'=>[$module=>null,$site=>null],'enabled'=>false];
    }
    if ((int)$state['tenant_id']!==$tid || $state['server']!==$address) throw new RuntimeException('Activation manifest belongs to a different tenant or VPN address.');
    foreach ($routers as &$router) {
        $api=roamingRouterApi($router);
        try {
            if (!isset($state['routers'][$router['id']])) $state['routers'][$router['id']]=['vpn_ip'=>$router['vpn_ip'],'secret'=>bin2hex(random_bytes(24)),'radius'=>routerCheckedCommand($api,'/radius/print'),'profiles'=>routerCheckedCommand($api,'/ip/hotspot/profile/print'),'ppp'=>routerCheckedCommand($api,'/ppp/aaa/print')];
            if ($state['routers'][$router['id']]['vpn_ip']!==$router['vpn_ip']) throw new RuntimeException('Router VPN address changed since the backup. Review it before activation.');
            $router['roaming_secret']=$state['routers'][$router['id']]['secret'];
        } finally { $api->disconnect(); }
    } unset($router);
    roamingPrivateFile($manifest,json_encode($state,JSON_PRETTY_PRINT));
    roamingSessionSchema($pdo);
    $dbUser='ft_roaming_'.$tid;
    foreach (['127.0.0.1','localhost'] as $dbHost) {
        $account=$pdo->quote($dbUser).'@'.$pdo->quote($dbHost);
        $pdo->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$pdo->quote($state['database_password']));
        $pdo->exec('ALTER USER '.$account.' IDENTIFIED BY '.$pdo->quote($state['database_password']));
        foreach (['clients','packages'] as $table) $pdo->exec("GRANT SELECT ON `$database`.`$table` TO $account");
        $pdo->exec("GRANT SELECT,INSERT,UPDATE ON `$database`.`tenant_roaming_sessions` TO $account");
    }
    $installed=true;
    roamingPrivateFile($module,roamingSqlConfig($tid,['host'=>'127.0.0.1','port'=>3306,'user'=>$dbUser,'password'=>$state['database_password'],'database'=>$database]),true);
    roamingPrivateFile($site,roamingServerConfig($tid,$address,$routers,$state['test_secret']),true);
    [$exit,$validation]=roamingCommand(['/usr/sbin/freeradius','-C']);
    roamingPrivateFile('/etc/fortunett/roaming-'.$tid.'-validation.log',$validation);
    if ($exit!==0) throw new RuntimeException('FreeRADIUS validation failed; router authentication has not been changed. Check the private validation log in /etc/fortunett.');
    [$exit]=roamingCommand(['/usr/bin/systemctl','restart','freeradius']);
    if ($exit!==0) throw new RuntimeException('FreeRADIUS could not start with the new configuration.');
    if (!roamingRadiusProbe($address,$auth,$state['test_secret'],$testClient['mikrotik_username'],$testClient['mikrotik_password'],$testClient['bound_mac_address'] ?? '')) throw new RuntimeException('The active customer was rejected by the new RADIUS listener.');
    if (roamingRadiusProbe($address,$auth,$state['test_secret'],'unknown-'.bin2hex(random_bytes(8)),'invalid')) throw new RuntimeException('Unknown-user rejection test failed.');
    $foreign=$pdo->prepare("SELECT c.mikrotik_username,c.mikrotik_password,c.bound_mac_address FROM clients c WHERE c.tenant_id<>? AND c.status='active' AND c.mikrotik_username<>'' AND c.mikrotik_password<>'' AND NOT EXISTS (SELECT 1 FROM clients own WHERE own.tenant_id=? AND own.mikrotik_username=c.mikrotik_username) LIMIT 1");$foreign->execute([$tid,$tid]);$foreignClient=$foreign->fetch(PDO::FETCH_ASSOC);
    if ($foreignClient && roamingRadiusProbe($address,$auth,$state['test_secret'],$foreignClient['mikrotik_username'],$foreignClient['mikrotik_password'],$foreignClient['bound_mac_address'] ?? '')) throw new RuntimeException('Cross-tenant isolation test failed.');
    echo "RADIUS validated: active customer accepted; unknown user rejected.\n";
    if ($foreignClient) echo "Foreign tenant credentials rejected.\n";
    [$exit,$ufw]=roamingCommand(['/usr/sbin/ufw','status']);
    if ($exit===0 && preg_match('/^Status: active/m',$ufw)) {
        foreach ($routers as $router) {
            [$exit]=roamingCommand(['/usr/sbin/ufw','allow','in','on',$interface,'proto','udp','from',$router['vpn_ip'],'to',$address,'port',"$auth,$acct"]);
            if ($exit) throw new RuntimeException('VPN firewall rule could not be installed.');
        }
    }
    foreach ($routers as $router) {
        $api=roamingRouterApi($router);
        try { $changedRouters=true;enableRouterRoaming($api,$tid,$address,$router['vpn_ip'],$router['roaming_secret']); }
        finally { $api->disconnect(); }
        echo $router['name']." configured and readback verified.\n";
    }
    $pdo->prepare("DELETE FROM tenant_settings WHERE tenant_id=? AND setting_key='roaming_radius_enabled'")->execute([$tid]);
    $pdo->prepare("INSERT INTO tenant_settings (tenant_id,setting_key,setting_value) VALUES (?,'roaming_radius_enabled','1')")->execute([$tid]);
    $state['enabled']=true;roamingPrivateFile($manifest,json_encode($state,JSON_PRETTY_PRINT));
    echo "Tenant roaming enabled. Test the same customer's sign-in at both locations to verify the router-to-server path.\n";
} catch (Throwable $e) {
    fwrite(STDERR,($e instanceof PDOException ? 'Database setup failed; check database privileges and required customer columns.' : $e->getMessage())."\n");
    try {
        if ($changedRouters) restoreRoamingRouters($routers,$state,$tid);
        if ($installed) { restoreRoamingFiles($state);roamingCommand(['/usr/bin/systemctl','restart','freeradius']); }
        if ($changedRouters || $installed) echo "Activation stopped and previous configuration restored.\n";
    } catch (Throwable $rollbackError) { fwrite(STDERR,"Automatic rollback could not finish. Restore using the private manifest before retrying.\n"); }
    exit(1);
}
