<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/google_bridge.php';
$pdo=new PDO('mysql:host=127.0.0.1;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='fortunett_bridge_test_'.bin2hex(random_bytes(4));$pdo->exec("CREATE DATABASE `$db`");$pdo->exec("USE `$db`");
$count=0;
function checkBridge($condition,$message) {global $count;if(!$condition)throw new Exception($message);$count++;}
function rejectsBridge($call) {try{$call();return false;}catch(Throwable $e){return true;}}
try {
 $pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY,subdomain VARCHAR(80))');
 $pdo->exec("INSERT INTO tenants VALUES(9,'example'),(10,'other')");
 $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,tenant_id INT,email_verified INT,is_super_admin INT)');
 $pdo->exec('INSERT INTO users VALUES(1,9,1,0),(2,10,1,0)');
 $state='original-browser';
 $request=googleBridgeIssue($pdo,9,hash('sha256',$state),'request');
 checkBridge(googleBridgeRequest($pdo,$request)['tenant_id']==9,'request remembers tenant');
 $url=googleBridgeFinish($pdo,$request,['action'=>'login','user'=>['id'=>1]],[]);
 checkBridge(str_starts_with($url,'https://example.fortunetttech.site/google_return.php?ticket='),'destination generated from database');
 parse_str(parse_url($url,PHP_URL_QUERY),$query);$ticket=$query['ticket'];
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,10,$state)),'wrong tenant rejected');
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,'other-browser')),'login CSRF from other browser rejected');
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,'')),'missing browser state rejected');
 checkBridge(googleBridgeConsume($pdo,$ticket,9,$state)['payload']['id']==1,'matching browser logs in correct user');
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,$state)),'ticket replay rejected');
 checkBridge(rejectsBridge(fn()=>googleBridgeRequest($pdo,$request)),'completed request cannot be reused');
 $ticket=googleBridgeIssue($pdo,9,hash('sha256',$state),'login',['user_id'=>2]);
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,$state)),'user belongs to another tenant rejected');
 $ticket=googleBridgeIssue($pdo,9,hash('sha256',$state),'login',['user_id'=>1]);
 $pdo->exec('UPDATE users SET email_verified=0 WHERE id=1');
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,$state)),'verification rechecked at handoff');
 $request=googleBridgeIssue($pdo,9,hash('sha256',$state),'request');
 $identity=['sub'=>'verified-google-sub','email'=>'owner@example.test','expires'=>time()+600];
 $url=googleBridgeFinish($pdo,$request,['action'=>'link'],$identity);parse_str(parse_url($url,PHP_URL_QUERY),$query);
 $result=googleBridgeConsume($pdo,$query['ticket'],9,$state);
 checkBridge($result['action']==='link' && $result['payload']['sub']===$identity['sub'],'first-time linking preserves verified identity without logging in');
 $ticket=googleBridgeIssue($pdo,9,hash('sha256',$state),'link',$identity);
 $pdo->exec('UPDATE google_workspace_handoffs SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
 checkBridge(rejectsBridge(fn()=>googleBridgeConsume($pdo,$ticket,9,$state)),'expired ticket rejected');
 checkBridge(rejectsBridge(fn()=>googleBridgeRequest($pdo,'garbage')),'invalid request rejected');
 checkBridge(!str_contains(file_get_contents(__DIR__.'/../google_return.php'),'$_GET[\'user_id\']'),'callback does not trust URL user identity');
 echo "$count central Google handoff checks passed\n";
} finally {$pdo->exec("DROP DATABASE `$db`");}
