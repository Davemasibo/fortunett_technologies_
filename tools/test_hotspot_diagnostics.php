<?php
require_once __DIR__ . '/../includes/hotspot_diagnostic_snapshot.php';
$host='tenant.example.test';$ips=['203.0.113.10','203.0.113.11'];
$data=[
 'servers'=>[['name'=>'hotspot1','interface'=>'bridge','profile'=>'hs','address-pool'=>'pool','disabled'=>'false','invalid'=>'false']],
 'profiles'=>[['name'=>'hs','hotspot-address'=>'10.5.50.1','html-directory'=>'flash/hotspot']],
 'addresses'=>[['address'=>'10.5.50.1/24','interface'=>'bridge']],
 'dhcp'=>[['name'=>'dhcp','interface'=>'bridge','address-pool'=>'pool']],
 'networks'=>[['address'=>'10.5.50.0/24','gateway'=>'10.5.50.1']],
 'dns'=>[['allow-remote-requests'=>'true']],
 'files'=>[['name'=>'flash/hotspot/login.html','size'=>'1000']],
 'garden'=>[['dst-host'=>$host,'action'=>'allow']],
 'garden_ip'=>array_map(fn($ip)=>['dst-address'=>$ip.'/32','protocol'=>'tcp','dst-port'=>'80,443','action'=>'accept'],$ips)
];
$checks=0;
function diagnosticTest(array $data,string $id,string $expected):void {
 global $host,$ips,$checks;
 $result=hotspotDiagnosticSnapshot($data,$host,$ips);
 $rows=array_column($result['checks'],null,'id');
 if(($rows[$id]['level']??null)!==$expected)throw new RuntimeException("Unexpected $id status: ".json_encode($result));
 $checks++;
}
diagnosticTest($data,'server','ok');diagnosticTest($data,'portal','ok');
$bad=$data;$bad['servers'][0]['invalid']='true';diagnosticTest($bad,'server','fail');
$bad=$data;$bad['servers']=[];diagnosticTest($bad,'server','fail');
$bad=$data;unset($bad['servers']);diagnosticTest($bad,'server','warn');
$bad=$data;$bad['addresses'][0]['interface']='ether2';diagnosticTest($bad,'gateway','fail');
$bad=$data;$bad['dhcp'][0]['invalid']='true';diagnosticTest($bad,'dhcp','fail');
$bad=$data;$bad['dhcp'][0]['address-pool']='factory';diagnosticTest($bad,'dhcp','fail');
$bad=$data;$bad['files'][0]['name']='old-hotspot/login.html';diagnosticTest($bad,'login','fail');
$bad=$data;$bad['files'][0]['size']=0;diagnosticTest($bad,'login','fail');
$bad=$data;$bad['profiles'][0]['html-directory-override']='custom';diagnosticTest($bad,'login','fail');
$bad=$data;$bad['garden'][0]['dst-host']='other.example.test';diagnosticTest($bad,'portal','fail');
$bad=$data;array_pop($bad['garden_ip']);diagnosticTest($bad,'portal','fail');
$bad=$data;$bad['garden_ip'][0]['disabled']='true';diagnosticTest($bad,'portal','fail');
$bad=$data;$bad['garden_ip'][0]['dst-port']='80';diagnosticTest($bad,'portal','fail');
$bad=$data;$bad['garden_ip'][0]['src-address']='10.0.0.5';diagnosticTest($bad,'portal','fail');
$bad=$data;$bad['garden_ip'][0]['server']='different';diagnosticTest($bad,'portal','fail');
echo "$checks hotspot diagnostic evidence checks passed\n";
