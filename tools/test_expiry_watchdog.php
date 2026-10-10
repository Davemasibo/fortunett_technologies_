<?php
require_once __DIR__ . '/test_package_deadlines.php';
$router=new PaidRouterDouble();
installPaidExpiryWatchdog($router);
$scripts=$router->data['/system/script'];
$schedules=$router->data['/system/scheduler'];
deadlineCheck(count($scripts)===1 && count($schedules)===1,'One named watchdog script and one scheduler are installed');
$writesBefore=count(array_filter($router->calls,fn($call)=>preg_match('~/(add|set)$~',$call[0])));
installPaidExpiryWatchdog($router);
$writesAfter=count(array_filter($router->calls,fn($call)=>preg_match('~/(add|set)$~',$call[0])));
deadlineCheck($writesBefore===$writesAfter,'Unchanged watchdog installation does not rewrite router configuration');
$router->data['/system/script']['*1']['source']='old workload';
$router->fail='/system/script/set';
deadlineRejects(fn()=>installPaidExpiryWatchdog($router),'Rejected named-script repair is surfaced before granting access');
$router=new PaidRouterDouble();
$router->fail='/system/script/add';
deadlineRejects(fn()=>provisionRouterPaidUser($router,'hotspot','paid-test','secret','pkg8-half-hour','',date('Y-m-d H:i:s',time()+1800)),'Missing watchdog script prevents paid user enablement');
deadlineCheck($router->data['/ip/hotspot/user']['*1']['disabled']==='true','Failed watchdog installation leaves the user safely disabled');
echo "Watchdog installation checks passed. Native script behavior requires live RouterOS verification.\n";
