<?php
require_once __DIR__.'/router_expiry.php';

/** Simple queues cannot enforce caps on FastTracked traffic. */
function ensurePackageQueuePath($api): void {
    foreach (routerCheckedCommand($api,'/ip/firewall/filter/print',['?action=fasttrack-connection']) as $rule) {
        if (isset($rule['.id']) && ($rule['disabled'] ?? 'false')!=='true') routerCheckedCommand($api,'/ip/firewall/filter/set',['=.id='.$rule['.id'],'=disabled=yes']);
    }
    foreach (routerCheckedCommand($api,'/ip/firewall/filter/print',['?action=fasttrack-connection']) as $rule) {
        if (isset($rule['.id']) && ($rule['disabled'] ?? 'false')!=='true') throw new RuntimeException('FastTrack bypass remains enabled; package cap cannot be enforced');
    }
    // Disabling the rule does not remove already FastTracked connections.
    foreach (routerCheckedCommand($api,'/ip/firewall/connection/print',['?fasttrack=true','=.proplist=.id,fasttrack']) as $flow) {
        if (isset($flow['.id']) && ($flow['fasttrack'] ?? 'false')==='true') routerCheckedCommand($api,'/ip/firewall/connection/remove',['=.id='.$flow['.id']]);
    }
}

function packageSpeedBits(string $value): int {
    if (!preg_match('/^(\d+(?:\.\d+)?)([kmg]?)$/i',trim($value),$match)) return -1;
    return (int)round((float)$match[1]*[''=>1,'k'=>1000,'m'=>1000000,'g'=>1000000000][strtolower($match[2])]);
}
function packageQueueTargetsAddress(string $targets,string $address): bool {
    if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) return false;
    foreach (explode(',',$targets) as $target) {
        $parts=explode('/',trim($target));$network=ip2long($parts[0]);$bits=isset($parts[1])?(int)$parts[1]:32;
        if ($network===false || $bits<0 || $bits>32) continue;
        $mask=$bits===0?0:(-1 << (32-$bits));
        if ((ip2long($address)&$mask)===($network&$mask)) return true;
    }
    return false;
}

/** Verify the assigned profile and the FIRST queue matching each live session. */
function auditCustomerPackageSpeed($api,string $service,string $username,array $package): array {
    $profile=packageProfileName($package);$expected=packageRateLimit($package);
    $out=['username'=>$username,'package'=>$package['name'],'expected'=>$expected,'profile'=>$profile,'ok'=>true,'live_verified'=>false,'issues'=>[],'sessions'=>[]];
    $fail=function(string $message) use (&$out) {$out['ok']=false;$out['issues'][]=$message;};
    $base=$service==='hotspot'?'/ip/hotspot/user':'/ppp/secret';
    $profiles=routerCheckedCommand($api,$service==='hotspot'?'/ip/hotspot/user/profile/print':'/ppp/profile/print',['?name='.$profile]);
    $found=false;foreach($profiles as $row) if(($row['name']??'')===$profile) {$found=true;if(($row['rate-limit']??'')!==$expected)$fail('Package profile speed differs from the purchased package');}
    if(!$found)$fail('Package profile missing');
    $found=false;foreach(routerCheckedCommand($api,$base.'/print',['?name='.$username]) as $row) if(($row['name']??'')===$username){$found=true;if(($row['profile']??'')!==$profile)$fail('Customer assigned to a different profile');if(!empty($row['rate-limit']) && $row['rate-limit']!==$expected)$fail('Per-customer speed override differs from package');}
    if(!$found)$fail('Customer router account missing');
    $active=routerCheckedCommand($api,$service==='hotspot'?'/ip/hotspot/active/print':'/ppp/active/print',[$service==='hotspot'?'?user='.$username:'?name='.$username]);
    $queues=routerCheckedCommand($api,'/queue/simple/print');
    $caps=array_map('packageSpeedBits',explode('/',$expected));
    foreach($active as $session){if(!isset($session['.id']))continue;$address=$session['address']??'';$matched=null;
        foreach($queues as $queue){if(($queue['disabled']??'false')==='true')continue;
            if(packageQueueTargetsAddress($queue['target']??'',$address) || ($service==='pppoe' && in_array('<pppoe-'.$username.'>',explode(',',$queue['target']??''),true))){$matched=$queue;break;}
        }
        $good=false;if($matched){$limits=array_map('packageSpeedBits',explode('/',$matched['max-limit']??''));$bursts=array_map('packageSpeedBits',explode('/',$matched['burst-limit']??'0/0'));$good=$limits===$caps && count($bursts)===2 && $bursts[0]===0 && $bursts[1]===0;}
        if(!$good)$fail('Live session lacks the exact package queue cap, or has a burst override');
        $out['sessions'][]=['address'=>$address,'queue'=>$matched['name']??null,'max_limit'=>$matched['max-limit']??null,'rate'=>$matched['rate']??null,'ok'=>$good];
    }
    $out['live_verified']=$out['ok'] && count($out['sessions'])>0;
    return $out;
}
