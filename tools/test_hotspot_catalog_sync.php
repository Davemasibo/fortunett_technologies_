<?php
require_once __DIR__.'/../includes/hotspot_sync.php';
class CatalogRouterDouble {
    public bool $ignore=false;public array $files=['*1'=>'old','*2'=>'older'];
    public function comm($path,$params=[]): array {
        if ($path==='/ip/hotspot/print') return [['!re'=>true,'profile'=>'hsprof1','disabled'=>'false']];
        if ($path==='/ip/hotspot/profile/print') return [['!re'=>true,'name'=>'hsprof1','html-directory'=>'flash/hotspot']];
        if ($path==='/file/print') return [['!re'=>true,'.id'=>'*1','name'=>'flash/hotspot/login.html'],['!re'=>true,'.id'=>'*2','name'=>'hotspot/login.html']];
        if ($path==='/file/set') {if (!$this->ignore) $this->files[substr($params[0],5)]=substr($params[1],10);return [['!done'=>true]];}
        if ($path==='/file/get') return [['!done'=>true,'ret'=>$this->files[substr($params[0],8)]]];
        throw new RuntimeException('Unexpected mutation '.$path);
    }
}
$api=new CatalogRouterDouble();$html='<html>New package</html>';
if(syncHotspotCatalogPage($api,$html,'https://example.test')!==2 || count(array_unique($api->files))!==1 || $api->files['*1']!==$html) throw new RuntimeException('All portal copies must update');
echo "PASS: catalog refresh updates and verifies every login copy without service or customer changes\n";
$api=new CatalogRouterDouble();$api->ignore=true;$rejected=false;
try {syncHotspotCatalogPage($api,$html,'https://example.test');}catch(RuntimeException $e){$rejected=true;}
if(!$rejected) throw new RuntimeException('Ignored writes must remain pending');
echo "PASS: failed readback is rejected for retry\n";
