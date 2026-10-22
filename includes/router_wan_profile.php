<?php
require_once __DIR__.'/router_wan.php';
require_once __DIR__.'/env.php';
require_once __DIR__.'/../classes/MikrotikAPI.php';

function ensureWanProfiles(PDO $pdo): void {
    try {$pdo->query('SELECT id FROM router_wan_profiles LIMIT 0');}
    catch (PDOException $e) {if ($e->getCode()!=='42S02') throw $e; $pdo->exec(file_get_contents(__DIR__.'/../sql/migrations/2026-10-11-router-wan-profiles.sql'));}
}
/** Persist a fallback key in the existing, web-protected application environment. */
function wanProfileEnvironmentKey(string $file): string {
    // Never create a new publicly served file; deployment already supplies .env.
    $handle=is_file($file) ? @fopen($file,'r+b') : false;
    if(!$handle)throw new RuntimeException('ISP password storage needs server setup: make the application .env writable by PHP, or configure WAN_CREDENTIAL_KEY in .env.');
    try {
        if(!flock($handle,LOCK_EX))throw new RuntimeException('Could not lock WAN credential storage.');
        $contents=stream_get_contents($handle);$encoded=null;
        if($contents===false)throw new RuntimeException('Could not read application environment.');
        foreach(preg_split('/\r?\n/',$contents) as $line) {
            if(preg_match('/^\s*WAN_CREDENTIAL_KEY\s*=(.*)$/',$line,$match)) {
                $value=trim($match[1]," \t\n\r\0\x0B\"'");
                if($value!==''){$encoded=$value;break;}
            }
        }
        if($encoded===null) {
            $encoded=base64_encode(random_bytes(32));
            // Replace an empty assignment so env.php cannot pick up an older blank.
            $updated=preg_replace('/^[\t ]*WAN_CREDENTIAL_KEY[\t ]*=.*(?:\r?\n|$)/m','',$contents);
            $updated=rtrim($updated,"\r\n")."\nWAN_CREDENTIAL_KEY=".$encoded."\n";
            rewind($handle);
            if(fwrite($handle,$updated)!==strlen($updated) || !fflush($handle) || !ftruncate($handle,strlen($updated)))throw new RuntimeException('Could not persist WAN credential encryption key.');
        }
        $key=base64_decode($encoded,true);
        if($key===false || strlen($key)!==32)throw new RuntimeException('Administrator must configure a valid WAN credential encryption key.');
        putenv('WAN_CREDENTIAL_KEY='.$encoded);$_ENV['WAN_CREDENTIAL_KEY']=$encoded;$_SERVER['WAN_CREDENTIAL_KEY']=$encoded;
        return $key;
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}
function wanProfileKey(): string {
    $configured=(string)get_env_var('WAN_CREDENTIAL_KEY','');
    if ($configured!=='') {
        $key=base64_decode($configured,true);
        if ($key===false || strlen($key)!==32) throw new RuntimeException('Administrator must configure a valid WAN credential encryption key.');
        return $key;
    }
    $appRoot=dirname(__DIR__);
    $webRoot=!empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : $appRoot;
    // Resolve deterministically from the app location so HTTP and CLI workers use
    // the same key. Common shared web roots must also be kept outside this path.
    $secretParent=dirname($appRoot);
    for($ancestor=$appRoot;dirname($ancestor)!==$ancestor;$ancestor=dirname($ancestor)) {
        if(in_array(strtolower(basename($ancestor)),['htdocs','public_html','wwwroot','html'],true)){$secretParent=dirname($ancestor);break;}
    }
    $directory=(string)get_env_var('WAN_SECRET_DIR',$secretParent.'/.fortunett-secrets/'.substr(hash('sha256',$appRoot),0,16));
    if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) {
        if((string)get_env_var('WAN_SECRET_DIR','')!=='')throw new RuntimeException('Administrator must make the configured WAN_SECRET_DIR writable by PHP.');
        return wanProfileEnvironmentKey($appRoot.'/.env');
    }
    $resolved=strtolower(str_replace('\\','/',realpath($directory) ?: $directory));
    foreach ([$appRoot,$webRoot] as $root) {
        $root=strtolower(rtrim(str_replace('\\','/',realpath($root) ?: $root),'/'));
        if ($resolved===$root || str_starts_with($resolved,$root.'/')) throw new RuntimeException('WAN credential key storage must be outside the website directory.');
    }
    $file=$directory.'/wan.key';$handle=@fopen($file,'c+b');
    if (!$handle) throw new RuntimeException('Administrator must make private WAN credential storage writable.');
    try {
        if (!flock($handle,LOCK_EX)) throw new RuntimeException('Could not lock WAN credential storage.');
        @chmod($file,0600);$encoded=trim(stream_get_contents($handle));
        if ($encoded==='') { $encoded=base64_encode(random_bytes(32));rewind($handle);if(fwrite($handle,$encoded)!==strlen($encoded))throw new RuntimeException('Could not save WAN credential encryption key.');fflush($handle); }
        $key=base64_decode($encoded,true);
        if ($key===false || strlen($key)!==32) throw new RuntimeException('WAN credential encryption key is invalid.');
        return $key;
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}
function encryptWanPassword(string $password): string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($password,'aes-256-gcm',wanProfileKey(),OPENSSL_RAW_DATA,$iv,$tag,'fortunett-wan-v1',16);
    if ($cipher===false) throw new RuntimeException('Could not protect ISP credentials.');
    return base64_encode($iv.$tag.$cipher);
}
function decryptWanPassword(string $ciphertext): string {
    $raw=base64_decode($ciphertext,true);
    if ($raw===false || strlen($raw)<29) throw new RuntimeException('Saved ISP credentials are invalid. Enter the password again.');
    $password=openssl_decrypt(substr($raw,28),'aes-256-gcm',wanProfileKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'fortunett-wan-v1');
    if ($password===false) throw new RuntimeException('Saved ISP credentials could not be unlocked. Enter the password again.');
    return $password;
}
function wanProfilePublic(array $record): array {
    return ['config'=>json_decode($record['config_json'],true),'has_password'=>!empty($record['password_ciphertext']),'state'=>$record['state'],'message'=>$record['message'],'checked_at'=>$record['checked_at']];
}
function wanProfileRecord(PDO $pdo,int $tenantId,int $routerId): ?array {
    $st=$pdo->prepare('SELECT * FROM router_wan_profiles WHERE tenant_id=? AND router_id=?');$st->execute([$tenantId,$routerId]);return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** Read-only inspection before remote WAN changes. No customer bridge required. */
function inspectWanProfile($api,array $c): void {
    $rows=routerWanApiCommand($api,'/interface/print');$names=[];
    foreach ($rows as $row) if(isset($row['!re']))$names[$row['name']]=$row;
    if(!isset($names[$c['base']]) || ($names[$c['base']]['disabled'] ?? 'false')==='true')throw new RuntimeException('The selected provider port or WAN bridge is missing or disabled.');
    $uplinks=array_unique([$c['base'],$c['link'],$c['wan']]);
    foreach(routerWanApiCommand($api,'/interface/bridge/port/print') as $p)if(isset($p['!re']) && ($p['disabled'] ?? 'false')!=='true' && in_array($p['interface'] ?? '',$uplinks,true))throw new RuntimeException('The provider port belongs to a bridge. Select its dedicated WAN bridge; a customer bridge cannot be used.');
    foreach(['/ip/hotspot/print','/ip/dhcp-server/print','/interface/pppoe-server/server/print'] as $path)foreach(routerWanApiCommand($api,$path) as $s)if(isset($s['!re']) && ($s['disabled'] ?? 'false')!=='true' && in_array($s['interface'] ?? '',$uplinks,true))throw new RuntimeException('The selected provider connection serves customers. Choose a separate WAN connection.');
}
function wanProfileCommand($api,string $path,array $params=[]): array {
    $words=[];foreach($params as $key=>$value)$words[]=is_int($key)?$value:'='.$key.'='.$value;
    $rows=$api->comm($path,$words);
    foreach($rows as $row)if(isset($row['!trap']) || isset($row['!fatal']))throw new WanProfileRouterRejection('Router rejected the command.');
    return $rows;
}
function syncWanProfileMetadata(PDO $pdo,array $router,array $c,string $billingUrl,bool $verified): void {
    ensureRouterWanStorage($pdo);$tenantId=(int)$router['tenant_id'];$routerId=(int)$router['id'];
    $existing=loadRouterWan($pdo,$tenantId,$routerId);
    $c['lan']=$existing['lan_bridge'] ?? '';
    if($existing){
        $pdo->prepare('UPDATE router_wan_config SET config_json=?,billing_url=?,verified_at=IF(?,NOW(),NULL),updated_at=NOW() WHERE id=? AND tenant_id=?')->execute([json_encode($c),$billingUrl,$verified,$existing['id'],$tenantId]);
    }else{
        $pdo->prepare('INSERT INTO router_wan_config (tenant_id,identity,router_id,config_json,lan_bridge,billing_url,verified_at) VALUES (?,?,?,?,?,?,IF(?,NOW(),NULL))')->execute([$tenantId,$router['name'],$routerId,json_encode($c),'',$billingUrl,$verified]);
    }
}
/** Claim pending work once. Interrupted application is verified, never blindly replayed. */
function processWanProfile(PDO $pdo,array $router,string $billingUrl,$apiOverride=null): ?array {
    $tenantId=(int)$router['tenant_id'];$routerId=(int)$router['id'];
    $record=wanProfileRecord($pdo,$tenantId,$routerId);
    if (!$record || !in_array($record['state'],['pending','applying','verifying'],true)) return $record;
    if($record['state']==='applying') {
        if(strtotime($record['checked_at'] ?? 'now')>time()-60)return $record;
        $pdo->prepare("UPDATE router_wan_profiles SET state='verifying' WHERE id=? AND revision=? AND state='applying'")->execute([$record['id'],$record['revision']]);$record['state']='verifying';
    }
    if($record['state']==='verifying' && strtotime($record['checked_at'] ?? '1970-01-01')>time()-20)return $record;
    $api=$apiOverride ?? new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'],$router['username'],$router['password'],(int)($router['api_port'] ?: 8728));
    if(!$apiOverride && empty($router['vpn_ip']) && filter_var($router['ip_address'],FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
        $pdo->prepare("UPDATE router_wan_profiles SET state='needs_attention',message='ISP details are saved. Connect this device through a management VPN or private management network before applying them.' WHERE id=? AND revision=?")->execute([$record['id'],$record['revision']]);
        return wanProfileRecord($pdo,$tenantId,$routerId);
    }
    if (!$api->isReachable(2)) {
        $pdo->prepare('UPDATE router_wan_profiles SET checked_at=NOW() WHERE id=? AND revision=?')->execute([$record['id'],$record['revision']]);
        return wanProfileRecord($pdo,$tenantId,$routerId);
    }
    $revision=(int)$record['revision'];$c=json_decode($record['config_json'],true,512,JSON_THROW_ON_ERROR);
    $set=function(string $state,string $message)use($pdo,$record,$revision):void {
        $pdo->prepare('UPDATE router_wan_profiles SET state=?,message=?,checked_at=NOW() WHERE id=? AND revision=?')->execute([$state,$message,$record['id'],$revision]);
    };
    try {
        $api->connect();
        // Adopt a working automatic uplink without dispatching WAN changes or
        // requiring an installer marker from a prior platform setup.
        if ($c['mode']==='dhcp') {
            $checks=verifyRouterWan($api,['config_json'=>$record['config_json'],'billing_url'=>$billingUrl]);
            if (!routerWanFailedChecks($checks)) {
                syncWanProfileMetadata($pdo,$router,$c,$billingUrl,true);
                $set('connected','Automatic connection detected. Your router has a bound DHCP address and an active Internet route. Continue to customer services.');
                return wanProfileRecord($pdo,$tenantId,$routerId);
            }
            if ($record['state']==='verifying') {
                $set('needs_attention',implode(' ',array_column(routerWanFailedChecks($checks),'detail')));
                return wanProfileRecord($pdo,$tenantId,$routerId);
            }
        }
        $marker=(string)$record['id'].':'.$revision;
        if ($record['state']==='pending') {
            inspectWanProfile($api,$c);
            $password=$c['mode']==='pppoe' ? decryptWanPassword($record['password_ciphertext']) : '';
            $name='fortunett-wan-apply-'.bin2hex(random_bytes(6));
            $source=rtrim(routerWanSetupScript($c,$password,$billingUrl,true)).';';
            if(!empty($router['provision_id'])) {
                $token=$pdo->prepare('SELECT provisioning_token FROM tenants WHERE id=?');$token->execute([$tenantId]);$token=(string)$token->fetchColumn();
                if($token!=='') {
                    $registration=routerWanRegistrationSource($c,str_replace('wan_health.php','auto_register.php',$billingUrl),['provisioning_token'=>$token,'provision_id'=>$router['provision_id'],'vpn_ip'=>$router['vpn_ip'] ?? '','router_username'=>$router['username'],'router_password'=>$router['password']]);
                    $source.=':if ([:len [/system scheduler find where name="fortunett_heartbeat"]]>0) do={/system scheduler set [find name="fortunett_heartbeat"] on-event='.routerServiceString($registration).'};';
                }
            }
            $source.=':if ([:len [/system script find where name="fortunett-wan-last-apply"]]=0) do={/system script add name="fortunett-wan-last-apply" source="" comment='.routerServiceString($marker).'} else={/system script set [find name="fortunett-wan-last-apply"] comment='.routerServiceString($marker).'};';
            $claim=$pdo->prepare("UPDATE router_wan_profiles SET state='applying',message='Applying saved ISP settings.',checked_at=NOW() WHERE id=? AND revision=? AND state='pending'");
            $claim->execute([$record['id'],$revision]);if(!$claim->rowCount())return wanProfileRecord($pdo,$tenantId,$routerId);
            syncWanProfileMetadata($pdo,$router,$c,$billingUrl,false);
            // Remove credential-bearing scripts stranded by an interrupted prior
            // delivery, only after this worker owns the current revision.
            foreach(routerWanApiCommand($api,'/system/script/print',['?comment=Fortunett-WAN-Apply']) as $orphan) {
                if(isset($orphan['.id']) && str_starts_with($orphan['name'] ?? '','fortunett-wan-apply-'))routerWanApiCommand($api,'/system/script/remove',['numbers'=>$orphan['.id']]);
            }
            $id=null;$runStarted=false;
            try {
                // Add with empty source first so a failed setup does not strand a secret.
                foreach(wanProfileCommand($api,'/system/script/add',['name'=>$name,'source'=>'','policy'=>'read,write,test,ftp,policy','comment'=>'Fortunett-WAN-Apply']) as $row)if(isset($row['ret']))$id=$row['ret'];
                if(!$id)throw new RuntimeException('Configuration script was not created.');
                // The script removes itself, including its credential-bearing source.
                wanProfileCommand($api,'/system/script/set',['.id'=>$id,'source'=>':do {'.$source.'} on-error={/system script remove [find where name='.routerServiceString($name).']; :error "WAN apply stopped; inspect existing uplink conflicts"}; /system script remove [find where name='.routerServiceString($name).'];']);
                $set('verifying','Settings dispatched. Waiting for the device connection to recover and verify.');
                $runStarted=true;wanProfileCommand($api,'/system/script/run',['number'=>$id]);
            } catch(Throwable $e) {
                // Trap means RouterOS explicitly rejected execution, not a transport drop.
                if ($e instanceof WanProfileRouterRejection) $set('needs_attention','The router rejected WAN setup. Check its existing uplink configuration and device permissions.');
                else if (!$runStarted) $set('needs_attention','WAN configuration could not be sent. Check device permissions and try saving again.');
                if($id && (!$runStarted || $e instanceof WanProfileRouterRejection))try{routerWanApiCommand($api,'/system/script/remove',['numbers'=>$id]);}catch(Throwable $_){}
                return wanProfileRecord($pdo,$tenantId,$routerId);
            }
            return wanProfileRecord($pdo,$tenantId,$routerId);
        }
        $completed=false;
        foreach(routerWanApiCommand($api,'/system/script/print',['?name=fortunett-wan-last-apply']) as $row)if(($row['comment'] ?? '')===$marker)$completed=true;
        if(!$completed){$set('needs_attention','The WAN change did not finish. Inspect existing uplink conflicts before saving again.');return wanProfileRecord($pdo,$tenantId,$routerId);}
        $checks=verifyRouterWan($api,['config_json'=>$record['config_json'],'billing_url'=>$billingUrl]);
        if(!routerWanFailedChecks($checks)){syncWanProfileMetadata($pdo,$router,$c,$billingUrl,true);$set('connected','ISP connection verified from the router.');}
        else $set('needs_attention','ISP settings were applied, but Internet checks have not passed. Check provider credentials, VLAN settings, DNS and the uplink cable, then check the connection again.');
    } catch(Throwable $e) {
        if($record['state']==='pending')$set('needs_attention',$e instanceof PDOException ? 'Could not update the connection profile.' : (str_contains($e->getMessage(),'selected provider') || str_contains($e->getMessage(),'provider port') ? $e->getMessage() : 'The device could not be inspected. Check API access and saved device credentials.'));
    } finally {$api->disconnect();}
    return wanProfileRecord($pdo,$tenantId,$routerId);
}
class WanProfileRouterRejection extends RuntimeException {}
