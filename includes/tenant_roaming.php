<?php
require_once __DIR__.'/router_expiry.php';

function roamingRadiusString(string $value): string {
    if (preg_match('/[\x00-\x1f\x7f]/',$value)) throw new InvalidArgumentException('Invalid RADIUS configuration value.');
    return '"'.strtr($value,['\\'=>'\\\\','"'=>'\\"','$'=>'\\$']).'"';
}

function roamingPorts(int $tenant): array {
    if ($tenant < 1 || $tenant > 22000) throw new InvalidArgumentException('Tenant ID cannot be assigned a roaming port.');
    return [20000 + 2*$tenant,20001 + 2*$tenant];
}

function roamingSessionSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_roaming_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL, router_ip VARCHAR(45) NOT NULL,
        username VARCHAR(253) NOT NULL, session_id VARCHAR(128) NOT NULL,
        started_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, stopped_at DATETIME NULL,
        session_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
        input_octets BIGINT UNSIGNED NOT NULL DEFAULT 0, output_octets BIGINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY roaming_session (tenant_id,router_ip,session_id),
        INDEX roaming_active (tenant_id,username,stopped_at,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Live customer state avoids the existing global, username-only radcheck store. */
function roamingCustomerPredicate(int $tenant): string {
    if ($tenant < 1) throw new InvalidArgumentException('Tenant required.');
    return "c.tenant_id=$tenant AND p.tenant_id=c.tenant_id AND c.status='active'
        AND c.expiry_date>DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 HOUR)
        AND c.mikrotik_username='%{SQL-User-Name}' AND c.mikrotik_password<>''
        AND c.connection_type IN ('hotspot','pppoe') AND p.download_speed>0
        AND (COALESCE(c.bound_mac_address,'')='' OR
            UPPER(REPLACE(c.bound_mac_address,'-',':'))=UPPER(REPLACE('%{Calling-Station-Id}','-',':')))";
}

function roamingSqlConfig(int $tenant, array $db): string {
    $name='fortunett_roaming_'.$tenant;
    $where=preg_replace('/\s+/',' ',roamingCustomerPredicate($tenant));
    $from="FROM clients c JOIN packages p ON p.id=c.package_id WHERE $where";
    $check="SELECT c.id,c.mikrotik_username,'Cleartext-Password',c.mikrotik_password,':=' $from UNION ALL SELECT c.id,c.mikrotik_username,'Simultaneous-Use',CAST(GREATEST(1,COALESCE(p.device_limit,1)) AS CHAR),':=' $from";
    $reply="SELECT c.id,c.mikrotik_username,'Session-Timeout',CAST(TIMESTAMPDIFF(SECOND,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 HOUR),c.expiry_date) AS CHAR),':=' $from UNION ALL SELECT c.id,c.mikrotik_username,'Mikrotik-Rate-Limit',CONCAT(IF(p.upload_speed>0,p.upload_speed,p.download_speed),'M/',p.download_speed,'M'),':=' $from UNION ALL SELECT c.id,c.mikrotik_username,'Mikrotik-Shared-Users',CAST(GREATEST(1,COALESCE(p.device_limit,1)) AS CHAR),':=' $from UNION ALL SELECT c.id,c.mikrotik_username,'Acct-Interim-Interval','60',':=' $from";
    $select="SELECT COUNT(*) FROM tenant_roaming_sessions WHERE tenant_id=$tenant AND username='%{SQL-User-Name}' AND stopped_at IS NULL AND updated_at>UTC_TIMESTAMP()-INTERVAL 2 MINUTE";
    $insert="INSERT INTO tenant_roaming_sessions (tenant_id,router_ip,username,session_id,started_at,updated_at,session_seconds,input_octets,output_octets) VALUES ($tenant,'%{Packet-Src-IP-Address}','%{SQL-User-Name}','%{Acct-Session-Id}',UTC_TIMESTAMP(),UTC_TIMESTAMP(),CAST('%{%{Acct-Session-Time}:-0}' AS UNSIGNED),(CAST('%{%{Acct-Input-Gigawords}:-0}' AS UNSIGNED)<<32)+CAST('%{%{Acct-Input-Octets}:-0}' AS UNSIGNED),(CAST('%{%{Acct-Output-Gigawords}:-0}' AS UNSIGNED)<<32)+CAST('%{%{Acct-Output-Octets}:-0}' AS UNSIGNED)) ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP(),session_seconds=VALUES(session_seconds),input_octets=VALUES(input_octets),output_octets=VALUES(output_octets)";
    $stop="UPDATE tenant_roaming_sessions SET stopped_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE tenant_id=$tenant AND router_ip='%{Packet-Src-IP-Address}' AND session_id='%{Acct-Session-Id}'";
    $reset="UPDATE tenant_roaming_sessions SET stopped_at=UTC_TIMESTAMP() WHERE tenant_id=$tenant AND router_ip='%{Packet-Src-IP-Address}' AND stopped_at IS NULL";
    $q=fn($v)=>roamingRadiusString((string)$v);
    return "sql $name {\n driver = \"rlm_sql_mysql\"\n dialect = \"mysql\"\n server = ".$q($db['host'])."\n port = ".(int)$db['port']."\n login = ".$q($db['user'])."\n password = ".$q($db['password'])."\n radius_db = ".$q($db['database'])."\n read_clients = no\n read_groups = no\n auto_escape = yes\n sql_user_name = \"%{User-Name}\"\n authorize_check_query = ".$q($check)."\n authorize_reply_query = ".$q($reply)."\n simul_count_query = ".$q($select)."\n pool {\n start = 1\n min = 1\n max = 5\n spare = 1\n}\n accounting {\n reference = \"%{tolower:type.%{%{Acct-Status-Type}:-%{Request-Processing-Stage}}.query}\"\n type {\n start {\n query = ".$q($insert)."\n}\n interim-update {\n query = ".$q($insert)."\n}\n stop {\n query = ".$q($stop)."\n}\n accounting-on {\n query = ".$q($reset)."\n}\n accounting-off {\n query = ".$q($reset)."\n}\n}\n}\n}\n";
}

function roamingServerConfig(int $tenant,string $address,array $routers,string $testSecret): string {
    if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Server VPN IPv4 address required.');
    [$auth,$acct]=roamingPorts($tenant); $name='fortunett_roaming_'.$tenant;
    $clients=" client local_test {\n ipaddr = 127.0.0.1\n secret = ".roamingRadiusString($testSecret)."\n}\n";
    $seen=[];
    foreach ($routers as $router) {
        $ip=$router['vpn_ip'] ?? '';
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || isset($seen[$ip]) || (int)$router['tenant_id']!==$tenant) throw new InvalidArgumentException('Each selected router must have a unique VPN address and belong to this tenant.');
        $seen[$ip]=true;
        $clients.=' client router_'.(int)$router['id']." {\n ipaddr = $ip\n secret = ".roamingRadiusString($router['roaming_secret'])."\n}\n";
    }
    if (!$routers) throw new InvalidArgumentException('At least one tenant router is required.');
    return "server $name {\n$clients listen {\n type = auth\n ipaddr = $address\n port = $auth\n}\n listen {\n type = acct\n ipaddr = $address\n port = $acct\n}\n authorize {\n chap\n mschap\n $name\n if (notfound || noop) {\n reject\n}\n pap\n}\n authenticate {\n Auth-Type PAP {\n pap\n}\n Auth-Type CHAP {\n chap\n}\n Auth-Type MS-CHAP {\n mschap\n}\n}\n accounting {\n $name\n}\n session {\n $name\n}\n}\n";
}

/** Existing local subscribers remain intact; other RADIUS services remain intact. */
function enableRouterRoaming($api,int $tenant,string $address,string $source,string $secret): array {
    [$auth,$acct]=roamingPorts($tenant);
    $tag='FortuNett-Roaming-'.$tenant;
    $entries=routerCheckedCommand($api,'/radius/print');
    $id=null;
    foreach ($entries as $row) if (($row['comment'] ?? '')===$tag) $id=$row['.id'] ?? null;
    $params=['=address='.$address,'=src-address='.$source,'=service=hotspot,ppp','=secret='.$secret,'=authentication-port='.$auth,'=accounting-port='.$acct,'=timeout=2s','=disabled=no','=comment='.$tag];
    routerCheckedCommand($api,$id ? '/radius/set' : '/radius/add',$id ? array_merge(['=.id='.$id],$params) : $params);
    foreach ($entries as $row) {
        if (!isset($row['.id']) || ($row['comment'] ?? '')===$tag || ($row['disabled'] ?? 'false')==='true') continue;
        $services=array_filter(explode(',',$row['service'] ?? ''),fn($service)=>!in_array($service,['ppp','hotspot'],true));
        if (implode(',',$services)===($row['service'] ?? '')) continue;
        routerCheckedCommand($api,'/radius/set',array_merge(['=.id='.$row['.id']],$services ? ['=service='.implode(',',$services)] : ['=disabled=yes']));
    }
    $profiles=routerCheckedCommand($api,'/ip/hotspot/profile/print');
    foreach ($profiles as $profile) if (isset($profile['.id'])) {
        routerCheckedCommand($api,'/ip/hotspot/profile/set',['=.id='.$profile['.id'],'=use-radius=yes','=radius-accounting=yes','=radius-interim-update=1m']);
    }
    routerCheckedCommand($api,'/ppp/aaa/set',['=use-radius=yes','=accounting=yes','=interim-update=1m']);
    $verified=false;
    foreach (routerCheckedCommand($api,'/radius/print') as $entry) {
        if (($entry['comment'] ?? '')===$tag && ($entry['address'] ?? '')===$address && (int)($entry['authentication-port'] ?? 0)===$auth && ($entry['disabled'] ?? 'false')==='false') $verified=true;
    }
    if (!$verified) throw new RuntimeException('Roaming RADIUS configuration could not be verified.');
    foreach (routerCheckedCommand($api,'/ip/hotspot/profile/print') as $profile) if (isset($profile['.id']) && ($profile['use-radius'] ?? '')!=='true') throw new RuntimeException('Hotspot RADIUS flag could not be verified.');
    $ppp=false; foreach (routerCheckedCommand($api,'/ppp/aaa/print') as $row) if (($row['use-radius'] ?? '')==='true') $ppp=true;
    if (!$ppp) throw new RuntimeException('PPPoE RADIUS flag could not be verified.');
    return ['auth_port'=>$auth,'accounting_port'=>$acct];
}

/** PAP probe checks the server before router authentication is changed. */
function roamingRadiusProbe(string $address,int $port,string $secret,string $username,string $password,string $mac=''): bool {
    if (strlen($username)>253 || strlen($password)>128) throw new InvalidArgumentException('Credentials exceed RADIUS limits.');
    $auth=random_bytes(16); $id=random_int(0,255);
    $padded=str_pad($password,max(16,(int)ceil(strlen($password)/16)*16),"\0"); $cipher='';$previous=$auth;
    for ($i=0;$i<strlen($padded);$i+=16) { $block=substr($padded,$i,16)^md5($secret.$previous,true);$cipher.=$block;$previous=$block; }
    $attr=fn($type,$value)=>chr($type).chr(strlen($value)+2).$value;
    $attrs=$attr(1,$username).$attr(2,$cipher).($mac!=='' ? $attr(31,$mac) : '').$attr(80,str_repeat("\0",16));
    $request=pack('CCn',1,$id,20+strlen($attrs)).$auth.$attrs;
    $request=substr($request,0,-16).hash_hmac('md5',$request,$secret,true);
    $context=stream_context_create(['socket'=>['bindto'=>'127.0.0.1:0']]);
    $socket=stream_socket_client("udp://$address:$port",$errno,$error,3,STREAM_CLIENT_CONNECT,$context);
    if (!$socket) throw new RuntimeException('RADIUS probe socket could not be opened.');
    try {
        stream_set_timeout($socket,3); fwrite($socket,$request); $reply=fread($socket,4096);
        if (strlen($reply)<20) throw new RuntimeException('RADIUS probe timed out.');
        $header=unpack('Ccode/Cid/nlength',substr($reply,0,4));
        if ($header['id']!==$id || $header['length']!==strlen($reply) || !hash_equals(substr($reply,4,16),md5(substr($reply,0,4).$auth.substr($reply,20).$secret,true))) throw new RuntimeException('RADIUS reply could not be authenticated.');
        if (!in_array($header['code'],[2,3],true)) throw new RuntimeException('Unexpected RADIUS probe result.');
        return $header['code']===2;
    } finally { fclose($socket); }
}
