<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/router_wan_profile.php';
function storageAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$file=tempnam(sys_get_temp_dir(),'wan-env-');
$prior=get_env_var('WAN_CREDENTIAL_KEY','');
try {
    $original="# Existing configuration\nDB_HOST=localhost\nWAN_CREDENTIAL_KEY=\n";
    file_put_contents($file,$original);
    $key=wanProfileEnvironmentKey($file);
    $saved=file_get_contents($file);
    storageAssert(strlen($key)===32 && str_contains($saved,'DB_HOST=localhost'),'Fallback persists a valid key and preserves environment settings');
    storageAssert(substr_count($saved,'WAN_CREDENTIAL_KEY=')===1,'Blank configuration is replaced with exactly one usable key');
    $encrypted=encryptWanPassword('test-provider-password');
    putenv('WAN_CREDENTIAL_KEY');unset($_ENV['WAN_CREDENTIAL_KEY'],$_SERVER['WAN_CREDENTIAL_KEY']);
    storageAssert(wanProfileEnvironmentKey($file)===$key && file_get_contents($file)===$saved,'Independent requests reuse the persisted key without rotating it');
    storageAssert(decryptWanPassword($encrypted)==='test-provider-password','Saved ISP credentials remain decryptable after reloading storage');
    storageAssert(!str_contains($saved,'test-provider-password'),'Environment contains the encryption key rather than ISP credentials');
    file_put_contents($file,"WAN_CREDENTIAL_KEY=invalid\n");
    try{wanProfileEnvironmentKey($file);throw new LogicException('Invalid key accepted');}catch(RuntimeException $e){storageAssert(str_contains($e->getMessage(),'valid WAN'),'Invalid existing key is rejected rather than replaced');}
    unlink($file);
    try{wanProfileEnvironmentKey($file);throw new LogicException('Missing file created');}catch(RuntimeException $e){storageAssert(!file_exists($file),'Fallback never creates a new environment file');}
} finally {
    if(is_file($file))unlink($file);
    putenv('WAN_CREDENTIAL_KEY='.$prior);$_ENV['WAN_CREDENTIAL_KEY']=$prior;$_SERVER['WAN_CREDENTIAL_KEY']=$prior;
}
