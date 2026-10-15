<?php
require_once __DIR__.'/../includes/tenant_roaming.php';
$process=proc_open(['python','-u',__DIR__.'/roaming_radius_test_server.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) throw new RuntimeException('Protocol test server failed to start');
try {
    fclose($pipes[0]);$port=(int)fgets($pipes[1]);
    if (!$port) throw new RuntimeException('Protocol test server did not return a port');
    if (!roamingRadiusProbe('127.0.0.1',$port,'TEST-ONLY-SECRET','test-user','right-password')) throw new RuntimeException('Valid PAP probe rejected');
    echo "PASS: PAP credentials and Message-Authenticator are encoded correctly; valid server reply verified\n";
    if (roamingRadiusProbe('127.0.0.1',$port,'TEST-ONLY-SECRET','test-user','wrong-password')) throw new RuntimeException('Invalid password was accepted');
    echo "PASS: Authenticated Access-Reject is distinguished from acceptance\n";
    $blocked=false;
    try { roamingRadiusProbe('127.0.0.1',$port,'TEST-ONLY-SECRET','tampered','right-password'); }
    catch (RuntimeException $e) { $blocked=str_contains($e->getMessage(),'could not be authenticated'); }
    if (!$blocked) throw new RuntimeException('Forged RADIUS response was accepted');
    echo "PASS: Forged server reply cannot authorize router activation\n";
    fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Protocol test server failed: '.$errors);
    $process=null;
} finally { if (is_resource($process)) {proc_terminate($process);proc_close($process);} }
