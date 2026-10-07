<?php
// CLI-only integration test; all writes are rolled back.
if (PHP_SAPI!=='cli') exit;
session_start();
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/../includes/router_wan.php';
$user=$pdo->query('SELECT id,tenant_id FROM users WHERE tenant_id IS NOT NULL AND tenant_id>0 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('A local tenant user is required for this integration check.');
$_SESSION['user_id']=$user['id'];$_SESSION['wan_csrf']=bin2hex(random_bytes(16));
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['csrf'=>$_SESSION['wan_csrf'],'identity'=>'wan-test-'.bin2hex(random_bytes(6)),'lan_bridge'=>'bridge-lan','wan_mode'=>'pppoe','wan_username'=>'test-isp','wan_password'=>'not-persisted'];
$_REQUEST=$_POST;
$pdo->beginTransaction();
try {
    ob_start();require __DIR__.'/../api/routers/wan_setup.php';$output=ob_get_clean();
    $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    if (($result['status'] ?? '')!=='success') throw new RuntimeException($result['message'] ?? 'WAN preparation failed');
    $st=$pdo->prepare('SELECT * FROM router_wan_config WHERE tenant_id=? AND identity=?');$st->execute([$user['tenant_id'],$_POST['identity']]);$record=$st->fetch(PDO::FETCH_ASSOC);
    if (!$record || $record['verified_at']!==null || str_contains($record['config_json'],'not-persisted')) throw new RuntimeException('WAN settings must be pending and must not retain PPPoE passwords.');
    $pdo->prepare('UPDATE router_wan_config SET router_id=2147483000 WHERE id=?')->execute([$record['id']]);
    if (!loadRouterWan($pdo,(int)$user['tenant_id'],2147483000)) throw new RuntimeException('Router association failed');
    if (loadRouterWan($pdo,(int)$user['tenant_id']+100000,2147483000)) throw new RuntimeException('Cross-tenant WAN settings leaked');
    echo "PASS: authenticated WAN preparation persists pending settings, excludes ISP passwords and isolates router settings by tenant.\n";
} finally {
    $pdo->rollBack();
}
