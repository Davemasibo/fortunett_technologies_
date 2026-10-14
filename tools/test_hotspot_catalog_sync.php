<?php
require_once __DIR__.'/../includes/hotspot_sync.php';
class PortalVersionRouterDouble {
    public array $files=[['!re'=>true,'.id'=>'*3','name'=>'fortunett-portal.ver']];
    public function comm($path,$params=[]):array {
        if($path==='/file/print')return $this->files;
        if($path==='/file/get')return [['!done'=>true,'ret'=>"b06ffe245681\n"]];
        throw new RuntimeException('Unexpected version command');
    }
}
$versionRouter=new PortalVersionRouterDouble();
if(readHotspotPortalVersion($versionRouter)!=='b06ffe245681')throw new RuntimeException('Version contents omitted from print were not read');
$versionRouter->files=[];
if(readHotspotPortalVersion($versionRouter)!==null)throw new RuntimeException('Missing marker claimed current');
echo "PASS: explicit build read handles omitted contents and absent markers\n";
class CatalogRouterDouble {
    public bool $ignore=false;public array $files=['*1'=>'old','*2'=>'older'];
    public array $names=['*1'=>'flash/hotspot/login.html','*2'=>'hotspot/login.html'];
    public function comm($path,$params=[]): array {
        if ($path==='/ip/hotspot/print') return [['!re'=>true,'profile'=>'hsprof1','disabled'=>'false']];
        if ($path==='/ip/hotspot/profile/print') return [['!re'=>true,'name'=>'hsprof1','html-directory'=>'flash/hotspot']];
        if ($path==='/file/print') {
            $rows=[]; foreach($this->names as $id=>$name) if (!$params || ($params[0] ?? '')==='?name='.$name) $rows[]=['!re'=>true,'.id'=>$id,'name'=>$name,'size'=>strlen($this->files[$id])];
            return $rows;
        }
        if ($path==='/file/add') { if (!$this->ignore) {$id='*'.(count($this->files)+1);$this->names[$id]=substr($params[0],6);$this->files[$id]=substr($params[1],10);}return [['!done'=>true]]; }
        if ($path==='/file/set') {if (!$this->ignore) $this->files[substr($params[0],5)]=substr($params[1],10);return [['!done'=>true]];}
        if ($path==='/file/get') return [['!done'=>true,'ret'=>$this->files[substr($params[0],8)]]];
        throw new RuntimeException('Unexpected mutation '.$path);
    }
}
$api=new CatalogRouterDouble();$html='<html>New package</html>';
if(syncHotspotCatalogPage($api,$html,'https://example.test')!==2 || $api->files['*2']!==$html || $api->files['*1']!==$html) throw new RuntimeException('All portal copies must update');
echo "PASS: catalog refresh updates and verifies every login copy without service or customer changes\n";
$api=new CatalogRouterDouble();$api->ignore=true;$rejected=false;
try {syncHotspotCatalogPage($api,$html,'https://example.test');}catch(RuntimeException $e){$rejected=true;}
if(!$rejected) throw new RuntimeException('Ignored writes must remain pending');
echo "PASS: failed readback is rejected for retry\n";
$api=new CatalogRouterDouble();
if(ensureHotspotServletFiles($api,['flash/hotspot'])!==4) throw new RuntimeException('Missing redirect/support servlets were not installed');
if(ensureHotspotServletFiles($api,['flash/hotspot'])!==0) throw new RuntimeException('Existing servlet files were replaced');
foreach($api->names as $id=>$name)if($name==='flash/hotspot/rlogin.html' && !str_contains($api->files[$id],'http-status == 302'))throw new RuntimeException('Redirect must issue a native HTTP redirect');
$api=new CatalogRouterDouble();$api->ignore=true;$rejected=false;
try{ensureHotspotServletFiles($api,['flash/hotspot']);}catch(RuntimeException $e){$rejected=true;}
if(!$rejected)throw new RuntimeException('Ignored servlet writes claimed success');
echo "PASS: redirect/support files added and verified; existing files preserved; failed writes rejected\n";
if (hotspotCatalogDirectory('flash/flash/hotspot',['flash/hotspot/login.html']) !== 'flash/hotspot') throw new RuntimeException('Duplicate flash alias did not resolve');
if (hotspotCatalogDirectory('hotspot',['flash/hotspot/login.html']) !== 'flash/hotspot') throw new RuntimeException('Relative directory did not resolve');
if (hotspotCatalogDirectory('custom',['custom/login.html']) !== 'custom') throw new RuntimeException('Custom profile directory was lost');
echo "PASS: flash aliases resolve to actual listed files; custom directories preserved\n";
class LegacyCatalogReader {
    public string $html;
    public int $ftpCalls=0;
    public function __construct() { $this->html=str_repeat('large content ',6000); }
    public function comm($path,$params=[]):array {
        if ($path==='/file/read') return [['!trap'=>true,'message'=>'no such command']];
        if ($path==='/file/get') return [['ret'=>substr($this->html,0,4096)]];
        throw new RuntimeException('Unexpected legacy command');
    }
    public function readFileViaFtp($name):string { $this->ftpCalls++; return $this->html; }
}
$legacy=new LegacyCatalogReader();
if(readHotspotCatalogFile($legacy,'flash/hotspot/login.html','*1',strlen($legacy->html))!==$legacy->html || $legacy->ftpCalls!==1) throw new RuntimeException('Legacy file reader did not verify full FTP contents');
$legacy->html=str_repeat('medium content ',1000);
if(readHotspotCatalogFile($legacy,'flash/hotspot/login.html','*1',strlen($legacy->html))!==$legacy->html || $legacy->ftpCalls!==2) throw new RuntimeException('Truncated legacy get did not use full readback');
echo "PASS: legacy RouterOS unsupported file/read and truncated contents recover through full FTP readback\n";
class ChunkCatalogReader extends LegacyCatalogReader {
    public function comm($path,$params=[]):array {
        if ($path !== '/file/read') return parent::comm($path,$params);
        return [['data'=>substr($this->html,(int)substr($params[1],8),32768)]];
    }
}
$modern = new ChunkCatalogReader();
if(readHotspotCatalogFile($modern,'flash/hotspot/login.html','*1',strlen($modern->html))!==$modern->html || $modern->ftpCalls) throw new RuntimeException('Modern chunk reads failed');
echo "PASS: modern RouterOS verifies full API chunks without FTP\n";
class FtpRecoveryRouter extends CatalogRouterDouble {
    public bool $corrupt=false;
    public function writeFileViaFtp($name,$html):void {
        $id=array_search($name,$this->names,true);
        if ($id === false) throw new RuntimeException('Unknown portal file');
        $this->files[$id]=$this->corrupt ? 'corrupted' : $html;
    }
    public function readFileViaFtp($name):string { return $this->files[array_search($name,$this->names,true)]; }
}
$recovery=new FtpRecoveryRouter();
// Simulate an API write accepted but not applied. Existing servlets avoid
// mixing the transfer regression with installation of missing support files.
ensureHotspotServletFiles($recovery,['flash/hotspot']);
$recovery->ignore=true;
if(syncHotspotCatalogPage($recovery,$html,'https://example.test')!==2 || $recovery->files['*1']!==$html) throw new RuntimeException('Full FTP upload did not repair ignored API writes');
echo "PASS: ignored API writes recover through verified full FTP upload\n";
$recovery=new FtpRecoveryRouter();$recovery->ignore=true;$recovery->corrupt=true;$rejected=false;
try {syncHotspotCatalogPage($recovery,$html,'https://example.test');}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'FTP verification failed');}
if(!$rejected) throw new RuntimeException('Corrupted FTP upload must remain pending');
echo "PASS: corrupted FTP uploads remain pending rather than claiming success\n";
