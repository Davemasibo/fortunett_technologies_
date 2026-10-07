<?php
require_once __DIR__.'/router_service_config.php';

function ensureRouterWanStorage(PDO $pdo): void {
    try {$pdo->query('SELECT id FROM router_wan_config LIMIT 0');}
    catch (PDOException $e) {
        if ($e->getCode()!=='42S02') throw $e;
        $pdo->exec(file_get_contents(__DIR__.'/../sql/migrations/2026-10-07-router-wan.sql'));
    }
}

function routerWanInput(array $input): array {
    $name = function(string $key, string $default = '') use ($input): string {
        $value=trim((string)($input[$key] ?? $default));
        if (!preg_match('/^[A-Za-z0-9_. -]{1,64}$/D',$value)) throw new InvalidArgumentException('Invalid '.$key.'. Use letters, numbers, spaces, dots, underscores or hyphens.');
        return $value;
    };
    $mode=(string)($input['wan_mode'] ?? 'dhcp');
    if (!in_array($mode,['dhcp','static','pppoe'],true)) throw new InvalidArgumentException('Choose DHCP, static IP or PPPoE.');
    $base=$name('wan_interface','ether1');
    $lan=$name('lan_bridge');
    $vlan=trim((string)($input['vlan_id'] ?? ''));
    if ($vlan!=='' && (!ctype_digit($vlan) || (int)$vlan<1 || (int)$vlan>4094)) throw new InvalidArgumentException('VLAN ID must be between 1 and 4094.');
    $link=$vlan!=='' ? 'fortunett-wan-vlan' : $base;
    $wan=$mode==='pppoe' ? 'fortunett-wan-pppoe' : $link;
    if (in_array($lan,[$base,$link,$wan],true) || str_starts_with($base,'fortunett-wan-')) throw new InvalidArgumentException('Select a separate customer LAN bridge and an existing uplink interface.');
    $cfg=['mode'=>$mode,'base'=>$base,'vlan'=>$vlan==='' ? null : (int)$vlan,'link'=>$link,'wan'=>$wan,'lan'=>$lan,'address'=>'','gateway'=>'','dns'=>'','username'=>''];
    $dns=trim((string)($input['wan_dns'] ?? ''));
    if ($mode==='static' && $dns==='') throw new InvalidArgumentException('Static WAN requires DNS servers.');
    if ($dns!=='') {
        foreach (explode(',',$dns) as $ip) if (!filter_var(trim($ip),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Enter comma-separated IPv4 DNS servers.');
        $cfg['dns']=implode(',',array_map('trim',explode(',',$dns)));
    }
    if ($mode==='static') {
        $address=trim((string)($input['wan_address'] ?? ''));
        $pieces=explode('/',$address);
        if (count($pieces)!==2 || !filter_var($pieces[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !ctype_digit($pieces[1]) || (int)$pieces[1]<1 || (int)$pieces[1]>32) throw new InvalidArgumentException('Enter a static IPv4 address with prefix, for example 192.168.1.2/24.');
        $gateway=trim((string)($input['wan_gateway'] ?? ''));
        if (!filter_var($gateway,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new InvalidArgumentException('Enter the upstream IPv4 gateway.');
        $cfg['address']=$address; $cfg['gateway']=$gateway;
    }
    if ($mode==='pppoe') {
        $cfg['username']=(string)($input['wan_username'] ?? '');
        if ($cfg['username']==='' || strlen($cfg['username'])>128 || empty($input['wan_password']) || strlen((string)$input['wan_password'])>128) throw new InvalidArgumentException('Enter the ISP PPPoE username and password.');
    }
    return $cfg;
}

function loadRouterWan(PDO $pdo,int $tenantId,int $routerId): ?array {
    $st=$pdo->prepare('SELECT * FROM router_wan_config WHERE tenant_id=? AND router_id=? LIMIT 1');
    $st->execute([$tenantId,$routerId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Validate topology before any service or WAN mutation. Never detach bridge ports. */
function routerWanTopologyGuard(array $c): string {
    $q='routerServiceString';
    return ':local wb '.$q($c['base']).'; :local wl '.$q($c['lan']).'; '
        .':if ([:len [/interface find where name=$wb disabled=no]]=0) do={:error "WAN uplink interface missing or disabled"}; '
        .':if ([:len [/interface bridge find where name=$wl disabled=no]]=0) do={:error "Create or select an enabled customer LAN bridge first"}; '
        .':if ($wb=$wl) do={:error "WAN and customer LAN must be separate"}; '
        .':if ([:len [/interface bridge port find where interface=$wb disabled=no]]>0) do={:error "Uplink is a bridge port. Select its dedicated WAN bridge; do not select the customer LAN bridge"}; '
        .':foreach p in=[/interface bridge port find where bridge=$wl disabled=no] do={:local pn [/interface bridge port get $p interface]; :if (($pn=$wb) || ($pn='.$q($c['link']).') || ($pn='.$q($c['wan']).')) do={:error "Customer bridge contains a WAN interface"}}; '
        .':if ([:len [/interface bridge port find where interface='.$q($c['link']).' disabled=no]]>0) do={:error "WAN link must not be a bridge port"}; '
        .':if ([:len [/interface bridge port find where interface='.$q($c['wan']).' disabled=no]]>0) do={:error "WAN interface must not be a bridge port"}; ';
}

/** Checks run on the router; API reachability alone is not Internet verification. */
function routerWanProbeSource(array $c,string $billingUrl,string $resultName=''): string {
    $q='routerServiceString';
    $host=parse_url($billingUrl,PHP_URL_HOST);
    if (!$host || parse_url($billingUrl,PHP_URL_SCHEME)!=='https') throw new InvalidArgumentException('Billing health URL must use HTTPS.');
    $parts=[':local wa false',':local wr false',':local wd false',':local wh false'];
    $modeGuard='';
    if ($c['vlan']!==null) $modeGuard.=':if ([:len [/interface vlan find where name='.$q($c['link']).' interface='.$q($c['base']).' vlan-id='.$c['vlan'].' disabled=no]]=0) do={:error "WAN VLAN mismatch"}; ';
    if ($c['mode']==='dhcp') $modeGuard.=':if ([:len [/ip dhcp-client find where interface='.$q($c['link']).' status=bound disabled=no]]=0) do={:error "WAN DHCP lease not bound"}; ';
    if ($c['mode']==='pppoe') $modeGuard.=':if ([:len [/interface pppoe-client find where name='.$q($c['wan']).' interface='.$q($c['link']).' running=yes disabled=no]]=0) do={:error "ISP PPPoE client not running on selected uplink"}; ';
    $addressSelector=$c['mode']==='static' ? ' address='.$q($c['address']) : '';
    $parts[]=':do {'.routerWanTopologyGuard($c).$modeGuard.':set wa ([:len [/ip address find where interface='.$q($c['wan']).$addressSelector.' disabled=no invalid=no]]>0)} on-error={}';
    $parts[]=':foreach r in=[/ip route find where dst-address="0.0.0.0/0" active=yes routing-table=main] do={:local gw [/ip route get $r gateway]; :local ig ([/ip route get $r immediate-gw].","); :if (($gw='.$q($c['wan']).') || ([:typeof [:find $ig '.$q('%'.$c['wan'].',').']]!="nil")) do={:set wr true}}';
    $parts[]=':do {:local resolved [:resolve '.$q($host).']; :set wd ([:len [:tostr $resolved]]>0)} on-error={}';
    $parts[]=':do {:local response [/tool fetch url='.$q($billingUrl).' check-certificate=yes duration=5s output=user as-value]; :set wh ((($response->"status")="finished") && (($response->"data")="fortunett-wan-ok"))} on-error={}';
    if ($resultName!=='') {
        $parts[]='/system script set [find where name='.$q($resultName).'] comment=([:tostr $wa].",".[:tostr $wr].",".[:tostr $wd].",".[:tostr $wh])';
    } else {
        $parts[]=':if (!$wa) do={:error "WAN address or topology check failed"}';
        $parts[]=':if (!$wr) do={:error "No active main default route through the configured WAN"}';
        $parts[]=':if (!$wd) do={:error "Billing server DNS resolution failed"}';
        $parts[]=':if (!$wh) do={:error "Billing server HTTPS check failed; check Internet access and router clock/certificates"}';
    }
    return '{ '.implode('; ',$parts).'; }';
}

function routerWanSetupScript(array $c,string $password,string $billingUrl): string {
    $q='routerServiceString';
    $parts=[routerDeviceModeGuard(),routerWanTopologyGuard($c)];
    // Reserved interfaces can only be reused if created by this installer.
    foreach (['/interface vlan'=>'fortunett-wan-vlan','/interface pppoe-client'=>'fortunett-wan-pppoe'] as $path=>$name) {
        $parts[]=':foreach existing in=['.$path.' find where name='.$q($name).'] do={:if (['.$path.' get $existing comment]!="Fortunett-WAN") do={:error "Reserved WAN interface exists and is not managed by Fortunett"}}';
    }
    // Existing static addressing or a separate ISP dialer needs operator review,
    // rather than silently leaving the desk uplink as a competing default route.
    foreach (array_unique([$c['base'],$c['link'],$c['wan']]) as $interface) {
        $parts[]=':foreach a in=[/ip address find where interface='.$q($interface).' dynamic=no disabled=no] do={:if ([/ip address get $a comment]!="Fortunett-WAN") do={:error "Existing unmanaged static WAN address. Review it in WinBox before applying WAN setup"}}';
        $parts[]=':foreach p in=[/interface pppoe-client find where interface='.$q($interface).' disabled=no] do={:if ([/interface pppoe-client get $p comment]!="Fortunett-WAN") do={:error "Existing unmanaged ISP PPPoE client. Review it before applying WAN setup"}}';
    }
    if ($c['vlan']!==null) {
        $parts[]=':if ([:len [/interface vlan find where name="fortunett-wan-vlan"]]=0) do={/interface vlan add name="fortunett-wan-vlan" interface='.$q($c['base']).' vlan-id='.$c['vlan'].' comment="Fortunett-WAN"} else={/interface vlan set [find name="fortunett-wan-vlan"] interface='.$q($c['base']).' vlan-id='.$c['vlan'].' disabled=no}';
    }
    $parts[]=':foreach p in=[/interface pppoe-client find where comment="Fortunett-WAN"] do={/interface pppoe-client set $p disabled=yes}';
    $parts[]='/ip address remove [find where comment="Fortunett-WAN"]';
    $parts[]='/ip route remove [find where comment="Fortunett-WAN"]';
    // Reuse an existing DHCP client on the exact WAN link; leave unrelated WANs alone.
    $parts[]=':foreach d in=[/ip dhcp-client find where comment="Fortunett-WAN"] do={/ip dhcp-client set $d disabled=yes}';
    if ($c['vlan']!==null) $parts[]='/ip dhcp-client set [find where interface='.$q($c['base']).'] disabled=yes';
    if ($c['mode']==='dhcp') {
        $settings='interface='.$q($c['link']).' add-default-route=yes default-route-distance=1 use-peer-dns='.($c['dns']===''?'yes':'no').' disabled=no comment="Fortunett-WAN"';
        $parts[]=':if ([:len [/ip dhcp-client find where interface='.$q($c['link']).']]=0) do={/ip dhcp-client add '.$settings.'} else={/ip dhcp-client set [find where interface='.$q($c['link']).'] '.$settings.'}';
    } else {
        $parts[]='/ip dhcp-client set [find where interface='.$q($c['link']).'] disabled=yes';
        if ($c['mode']==='static') {
            $parts[]='/ip address add address='.$q($c['address']).' interface='.$q($c['wan']).' comment="Fortunett-WAN"';
            $parts[]='/ip route add dst-address=0.0.0.0/0 gateway='.$q($c['gateway'].'%'.$c['wan']).' distance=1 comment="Fortunett-WAN"';
        } else {
            $settings='interface='.$q($c['link']).' user='.$q($c['username']).' password='.$q($password).' add-default-route=yes default-route-distance=1 use-peer-dns='.($c['dns']===''?'yes':'no').' disabled=no';
            $parts[]=':if ([:len [/interface pppoe-client find where name="fortunett-wan-pppoe"]]=0) do={/interface pppoe-client add name="fortunett-wan-pppoe" '.$settings.' comment="Fortunett-WAN"} else={/interface pppoe-client set [find name="fortunett-wan-pppoe"] '.$settings.'}';
        }
    }
    $parts[]='/ip dns set servers='.$q($c['dns']);
    $parts[]=':if ([:len [/interface list find where name="WAN"]]>0) do={:if ([:len [/interface list member find where list="WAN" interface='.$q($c['wan']).']]=0) do={/interface list member add list="WAN" interface='.$q($c['wan']).' comment="Fortunett-WAN"}}';
    $parts[]=':delay 15s';
    $parts[]=routerWanProbeSource($c,$billingUrl);
    $parts[]=':put "WAN connectivity verified. Continue with management and customer services."';
    return "# Fortunett WAN installer. Import through a customer LAN port.\n# Existing bridge membership is preserved. PPPoE credentials may be included.\n{ ".implode('; ',array_map(fn($p)=>rtrim($p,"; "),$parts))."; }\n";
}

function routerWanApiCommand($api,string $path,array $params=[]): array {
    $words=[];
    foreach ($params as $key=>$value) $words[]=is_int($key) ? $value : '='.$key.'='.$value;
    $rows=$api->comm($path,$words);
    foreach ($rows as $r) if (isset($r['!trap']) || isset($r['!fatal'])) throw new RuntimeException($r['message'] ?? 'Router command failed');
    return $rows;
}

function verifyRouterWan($api,array $record): array {
    $c=json_decode($record['config_json'],true,512,JSON_THROW_ON_ERROR);
    $name='fortunett-wan-check-'.bin2hex(random_bytes(6));
    $id=null;
    try {
        $rows=routerWanApiCommand($api,'/system/script/add',['name'=>$name,'source'=>routerWanProbeSource($c,$record['billing_url'],$name),'policy'=>'read,write,test,ftp','comment'=>'pending']);
        foreach ($rows as $r) if (isset($r['ret'])) $id=$r['ret'];
        if (!$id) throw new RuntimeException('WAN diagnostic script was not created.');
        routerWanApiCommand($api,'/system/script/run',['number'=>$id]);
        $rows=routerWanApiCommand($api,'/system/script/print',['?name='.$name]);
        $values=[];
        foreach ($rows as $r) if (isset($r['!re'])) $values=explode(',',$r['comment'] ?? '');
        $checks=[];
        foreach (['WAN address and LAN separation','Default route through '.$c['wan'],'Billing DNS resolution','Billing server HTTPS access'] as $i=>$label) {
            $ok=in_array($values[$i] ?? '',['true','yes','1'],true);
            $checks[]=['label'=>$label,'ok'=>$ok,'detail'=>$ok?'Verified from router':'Failed; inspect WAN settings, uplink, DNS and router clock'];
        }
        return $checks;
    } finally {
        if ($id) {try {routerWanApiCommand($api,'/system/script/remove',['numbers'=>$id]);} catch (Throwable $e) {error_log('WAN diagnostic cleanup failed');}}
    }
}

function routerWanRegistrationSource(array $c,string $url,array $fields): string {
    $q='routerServiceString';
    return '{ :local ids [/ip address find where interface='.$q($c['wan']).' disabled=no invalid=no]; :local address ""; '
        .':if ([:len $ids]>0) do={:set address [/ip address get ($ids->0) address]; :set address [:pick $address 0 [:find $address "/"]]}; '
        .':local mac [/interface ethernet get ([find]->0) mac-address]; '
        .'/tool fetch url='.$q($url).' http-method=post http-data=('.$q(http_build_query($fields).'&router_ip=').' . $address . "&router_mac=" . $mac . "&router_identity=" . [/system identity get name]) keep-result=no; }';
}
