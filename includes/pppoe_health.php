<?php
/** Only these read commands and fields are used by the PPPoE diagnostic. */
function pppoeHealthRead($api,string $path,array $fields):array {
    $allowed=['/ppp/aaa/print','/radius/print','/ppp/secret/print','/ppp/active/print','/interface/pppoe-server/server/print'];
    if(!in_array($path,$allowed,true))throw new InvalidArgumentException('Diagnostic command is not read-only.');
    $rows=$api->comm($path,['=.proplist='.implode(',',$fields)]);$result=[];
    foreach($rows as $row){
        if(isset($row['!trap']) || isset($row['!fatal']) || isset($row['message']))throw new RuntimeException('Router command could not be read.');
        if(is_array($row) && $row)$result[]=array_intersect_key($row,array_flip($fields));
    }
    return array_values(array_filter($result));
}
function pppoeHealthEnabled($value):bool{return in_array(strtolower((string)$value),['true','yes','1'],true);}

function pppoeHealthReport($api):array {
    $aaa=pppoeHealthRead($api,'/ppp/aaa/print',['use-radius','accounting','interim-update']);
    $radius=pppoeHealthRead($api,'/radius/print',['address','service','disabled','authentication-port','accounting-port']);
    $secrets=pppoeHealthRead($api,'/ppp/secret/print',['name','service','disabled']);
    $sessions=pppoeHealthRead($api,'/ppp/active/print',['name','service']);
    $servers=pppoeHealthRead($api,'/interface/pppoe-server/server/print',['disabled','keepalive-timeout','one-session-per-host','default-profile','service-name']);
    $aaa=$aaa[0]??[];$local=0;$names=[];
    foreach($secrets as $secret)if(!pppoeHealthEnabled($secret['disabled']??'false') && in_array($secret['service']??'any',['any','pppoe'],true))$local++;
    $pppRadius=array_values(array_filter($radius,fn($r)=>!pppoeHealthEnabled($r['disabled']??'false') && in_array('ppp',explode(',',$r['service']??''),true)));
    foreach($sessions as $session)if(($session['service']??'')==='pppoe'){$name=(string)($session['name']??'');$names[$name]=($names[$name]??0)+1;}
    $radiusEnabled=pppoeHealthEnabled($aaa['use-radius']??'false');$findings=[];
    $mode=$radiusEnabled && $pppRadius ? ($local?'radius_configured_with_local_secrets':'radius_configured') : ($radiusEnabled?'radius_enabled_without_ppp_server':($local?'local_secrets':'unconfirmed'));
    if($radiusEnabled && $local)$findings[]='Enabled local secrets can take precedence over RADIUS. Check a test customer to verify the authentication path.';
    if($radiusEnabled && !$pppRadius)$findings[]='PPP uses RADIUS but no enabled PPP RADIUS server was found.';
    if($radiusEnabled && !pppoeHealthEnabled($aaa['accounting']??'false'))$findings[]='PPP RADIUS accounting is not enabled.';
    foreach($servers as $server)if(!pppoeHealthEnabled($server['disabled']??'false') && in_array($server['keepalive-timeout']??'',['0','0s','00:00:00'],true)){$findings[]='An enabled PPPoE server has keepalive timeout set to zero. Review its dead-session detection.';break;}
    $duplicates=count(array_filter($names,fn($count)=>$count>1));
    if($duplicates)$findings[]='Some usernames have multiple live PPPoE sessions. Verify the intended session limit before changing it.';
    return ['authentication_configuration'=>$mode,'ppp_aaa'=>$aaa,'enabled_ppp_radius_servers'=>$pppRadius,
        'enabled_local_pppoe_secrets'=>$local,'live_pppoe_sessions'=>array_sum($names),'usernames_with_multiple_live_sessions'=>$duplicates,
        'pppoe_servers'=>$servers,'findings'=>$findings,'verification'=>'Configuration only; successful authentication and outage recovery still require a controlled customer test.'];
}
