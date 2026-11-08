<?php
require_once __DIR__.'/../includes/pppoe_health.php';
class HealthRouterDouble{
    public array $commands=[];
    public function __construct(public array $rows){}
    public function comm($command,$params){$this->commands[]=$command;return $this->rows[$command]??[];}
}
function healthCheck($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$rows=['/ppp/aaa/print'=>[['use-radius'=>'yes','accounting'=>'yes','interim-update'=>'5m']],
    '/radius/print'=>[['service'=>'ppp','disabled'=>'false','address'=>'10.0.0.2','secret'=>'DO-NOT-PRINT']],
    '/ppp/secret/print'=>[['name'=>'customer-a','password'=>'DO-NOT-PRINT','service'=>'pppoe','disabled'=>'false']],
    '/ppp/active/print'=>[['name'=>'customer-a','service'=>'pppoe'],['name'=>'customer-a','service'=>'pppoe']],
    '/interface/pppoe-server/server/print'=>[['disabled'=>'false','keepalive-timeout'=>'0']]];
$api=new HealthRouterDouble($rows);$report=pppoeHealthReport($api);
healthCheck($report['authentication_configuration']==='radius_configured_with_local_secrets','mixed configuration identified without claiming live RADIUS acceptance');
healthCheck(!str_contains(json_encode($report),'DO-NOT-PRINT') && !str_contains(json_encode($report),'customer-a'),'passwords, shared secrets and customer usernames excluded from report');
healthCheck($report['live_pppoe_sessions']===2 && $report['usernames_with_multiple_live_sessions']===1,'multiple live sessions detected without clearing them');
healthCheck(count($report['findings'])===3,'local precedence, keepalive and multiple-session findings reported');
healthCheck(count(array_filter($api->commands,fn($c)=>!str_ends_with($c,'/print')))===0,'diagnostic issues only print commands');
$rows['/radius/print'][0]['disabled']='true';$report=pppoeHealthReport(new HealthRouterDouble($rows));
healthCheck($report['authentication_configuration']==='radius_enabled_without_ppp_server','disabled RADIUS configuration is not treated as available');
$rows['/ppp/aaa/print'][0]['use-radius']='no';$report=pppoeHealthReport(new HealthRouterDouble($rows));
healthCheck($report['authentication_configuration']==='local_secrets','local-only configuration detected');
$blocked=false;try{pppoeHealthRead($api,'/ppp/active/remove',['.id']);}catch(InvalidArgumentException $e){$blocked=true;}
healthCheck($blocked,'mutation commands rejected by allowlist');
$rows['/ppp/aaa/print']=[['!trap'=>true,'message'=>'permission denied']];$blocked=false;
try{pppoeHealthReport(new HealthRouterDouble($rows));}catch(RuntimeException $e){$blocked=true;}
healthCheck($blocked,'unreadable configuration is not mistaken for local authentication');
