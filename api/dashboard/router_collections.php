<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__.'/../../includes/auth.php';
if(empty($_SESSION['user_id'])){http_response_code(401);echo json_encode(['success'=>false]);exit;}
$user=(int)$_SESSION['user_id'];session_write_close();
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/analytics_range.php';
require_once __DIR__.'/../../includes/hotspot_location_sales.php';
require_once __DIR__.'/../../includes/router_collections.php';
try{
 $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');$st->execute([$user]);$tenant=(int)$st->fetchColumn();
 if(!$tenant){http_response_code(403);echo json_encode(['success'=>false]);exit;}
 ensureHotspotLocationSales($pdo);
 $range=analyticsRange($_GET['range']??'7d');
 $st=$pdo->prepare('SELECT id,name FROM mikrotik_routers WHERE tenant_id=? ORDER BY name');$st->execute([$tenant]);
 $groups=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$groups[(string)$r['id']]=['id'=>(string)$r['id'],'name'=>$r['name'],'rows'=>[],'total'=>0,'sales'=>0];
 $groups['unattributed']=['id'=>'unattributed','name'=>'Unattributed','rows'=>[],'total'=>0,'sales'=>0];
 foreach(routerCollectionRows($pdo,$tenant,$range) as $row){
   $key=$row['router_id']===null?'unattributed':(string)$row['router_id'];
   if(!isset($groups[$key]))$groups[$key]=['id'=>$key,'name'=>'Removed router #'.$key,'rows'=>[],'total'=>0,'sales'=>0];
   $groups[$key]['rows'][]=['k'=>$row['bucket'],'v'=>$row['revenue']];$groups[$key]['total']+=(float)$row['revenue'];$groups[$key]['sales']+=(int)$row['sales'];
 }
 foreach($groups as &$g){$g['data']=analyticsSeries($range,$g['rows'])['data'];unset($g['rows']);}unset($g);
 echo json_encode(['success'=>true,'labels'=>array_column($range['buckets'],'label'),'groups'=>array_values($groups)]);
}catch(Throwable $e){error_log('Router collections: '.$e->getMessage());http_response_code(503);echo json_encode(['success'=>false,'message'=>'Collections unavailable. Retry shortly.']);}
