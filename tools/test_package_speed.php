<?php
require_once __DIR__.'/../includes/package_profile.php';
function speedCheck($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
class SpeedRouterDouble {
 public bool $fasttrack=true,$ignore=false,$flow=true;public string $profile='pkg43-test5minutes',$rate='5M/5M';public array $queues=[['.id'=>'*Q','name'=>'hotspot-user','target'=>'10.5.50.2/32','max-limit'=>'5M/5M','burst-limit'=>'0/0']];public array $calls=[];
 public function comm($path,$params=[]):array{
  $this->calls[]=[$path,$params];
  if($path==='/ip/firewall/filter/print')return [['.id'=>'*F','action'=>'fasttrack-connection','disabled'=>$this->fasttrack?'false':'true']];
  if($path==='/ip/firewall/filter/set'){if(!$this->ignore)$this->fasttrack=false;return [['!done'=>true]];}
  if($path==='/ip/firewall/connection/print')return $this->flow?[['.id'=>'*C','fasttrack'=>'true']]:[];
  if($path==='/ip/firewall/connection/remove'){$this->flow=false;return [['!done'=>true]];}
  if($path==='/ip/hotspot/user/profile/print')return [['!re'=>true,'name'=>'pkg43-test5minutes','rate-limit'=>$this->rate]];
  if($path==='/ip/hotspot/user/print')return [['!re'=>true,'name'=>'customer','profile'=>$this->profile]];
  if($path==='/ip/hotspot/active/print')return [['.id'=>'*A','user'=>'customer','address'=>'10.5.50.2']];
  if($path==='/queue/simple/print')return $this->queues;
  throw new RuntimeException('Unexpected command '.$path);
 }
}
$api=new SpeedRouterDouble();ensurePackageQueuePath($api);speedCheck(!$api->fasttrack && !$api->flow,'Enabled FastTrack and previously tracked flows are removed');
$api=new SpeedRouterDouble();$api->ignore=true;$failed=false;try{ensurePackageQueuePath($api);}catch(Throwable $e){$failed=true;}speedCheck($failed,'Ignored FastTrack write cannot be reported as enforced');
$package=['id'=>43,'name'=>'Test 5 Minutes','mikrotik_profile'=>'pkg43-test5minutes','download_speed'=>5,'upload_speed'=>5];
$api=new SpeedRouterDouble();speedCheck(auditCustomerPackageSpeed($api,'hotspot','customer',$package)['live_verified'],'Assigned customer profile and live 5M/5M queue match the purchase');
foreach(['wrong_profile','wrong_profile_cap','missing_queue','burst','overlapping_queue','uncapped_queue'] as $case){$api=new SpeedRouterDouble();
 if($case==='wrong_profile')$api->profile='default';
 if($case==='wrong_profile_cap')$api->rate='10M/10M';
 if($case==='missing_queue')$api->queues=[];
 if($case==='burst')$api->queues[0]['burst-limit']='10M/10M';
 if($case==='overlapping_queue')array_unshift($api->queues,['name'=>'earlier broad queue','target'=>'10.5.50.0/24','max-limit'=>'10M/10M']);
 if($case==='uncapped_queue')$api->queues[0]['max-limit']='0/0';
 speedCheck(!auditCustomerPackageSpeed($api,'hotspot','customer',$package)['live_verified'],"$case is rejected by live customer verification");
}
speedCheck(packageRateLimit(['upload_speed'=>2,'download_speed'=>5])==='2M/5M','Asymmetric package speeds use router upload/download order');
speedCheck(packageSpeedBits('5000000')===packageSpeedBits('5M'),'Queue bit rates are compared numerically');
