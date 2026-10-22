<?php
require_once __DIR__.'/../classes/MikrotikAPI.php';
class PortalFtpAccessDouble extends MikrotikAPI {
    public array $calls=[];
    public bool $wg=true;
    public bool $reject=false;
    public function prepare(): ?string {return $this->portalFtpVpnRule(21);}
    public function comm($path,$params=[]) {
        $this->calls[]=[$path,$params];
        return match($path) {
            '/ip/address/print'=>[['address'=>'10.200.200.19/24','interface'=>'wg-fortunett']],
            '/interface/wireguard/print'=>$this->wg ? [['name'=>'wg-fortunett']] : [],
            '/ip/firewall/filter/print'=>[['.id'=>'*dynamic','dynamic'=>'true'],['.id'=>'*other-transfer','comment'=>'Fortunett-Portal-FTP-Temporary-other'],['.id'=>'*first','dynamic'=>'false']],
            '/ip/firewall/filter/add'=>$this->reject ? [['!trap'=>true,'message'=>'Denied']] : [['ret'=>'*temporary']],
            default=>throw new RuntimeException('Unexpected command'),
        };
    }
}
function ftpAssert($ok,$label) {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$api=new PortalFtpAccessDouble('10.200.200.19','test','test');
ftpAssert($api->prepare()==='*temporary','Transfer access records the exact rule ID for cleanup');
$params=end($api->calls)[1];
foreach(['=src-address=10.200.200.1/32','=dst-address=10.200.200.19/32','=in-interface=wg-fortunett','=protocol=tcp','=dst-port=21,1024-65535','=place-before=*first'] as $word)ftpAssert(in_array($word,$params,true),'VPN rule scope: '.$word);
$api=new PortalFtpAccessDouble('102.210.153.251','test','test');
ftpAssert($api->prepare()===null && !$api->calls,'Public IP connections never create a firewall exception');
$api=new PortalFtpAccessDouble('10.200.200.19','test','test');$api->wg=false;
ftpAssert($api->prepare()===null,'Non-WireGuard interfaces never get a transfer exception');
$api->wg=true;$api->reject=true;$failed=false;
try{$api->prepare();}catch(RuntimeException $e){$failed=$e->getMessage()==='Denied';}
ftpAssert($failed,'Rejected access rules fail explicitly');
