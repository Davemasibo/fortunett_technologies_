<?php
// Real RS256 verification against a local test key, plus isolated account tests.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/google_auth.php';
require __DIR__ . '/../includes/auth.php';
$checks=0;
function googleCheck($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS $message\n"; }
function googleReject(callable $fn) { try {$fn();return false;} catch(Throwable $e) {return true;} }
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>'C:/xampp/apache/conf/openssl.cnf']);
$details=openssl_pkey_get_details($key);
$b64=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
$certs=json_encode(['keys'=>[['kty'=>'RSA','alg'=>'RS256','kid'=>'local-test','n'=>$b64($details['rsa']['n']),'e'=>$b64($details['rsa']['e'])]]]);

$claims=['iss'=>'https://accounts.google.com','aud'=>'test.apps.googleusercontent.com','sub'=>'123456789',
    'email'=>'owner@gmail.com','email_verified'=>true,'nonce'=>'session-nonce','iat'=>time()-1,'exp'=>time()+300];
$sign=fn($c)=>Firebase\JWT\JWT::encode($c,$key,'RS256','local-test');
$verify=fn($c)=>googleVerifyWithKeys($sign($c),'session-nonce','test.apps.googleusercontent.com',json_decode($certs,true));
googleCheck((bool)$verify($claims),'Google library accepts correctly signed token with matching audience');
googleCheck(googleReject(fn()=>$verify(array_merge($claims,['aud'=>'other-client']))),'wrong audience rejected');
googleCheck(googleReject(fn()=>$verify(array_merge($claims,['iss'=>'https://evil.example']))),'wrong issuer rejected');
googleCheck(googleReject(fn()=>$verify(array_merge($claims,['exp'=>time()-3600]))),'expired token rejected');
$signed=$sign($claims);$parts=explode('.',$signed);$parts[2][0]=$parts[2][0]==='a'?'b':'a';
googleCheck(googleReject(fn()=>googleVerifyWithKeys(implode('.',$parts),'session-nonce',$claims['aud'],json_decode($certs,true))),'forged signature rejected');
googleCheck(googleReject(fn()=>googleValidateClaims($claims,'another-session')),'nonce from another session rejected');
googleCheck(googleReject(fn()=>googleValidateClaims(array_merge($claims,['email_verified'=>false]),'session-nonce')),'unverified Google email rejected');
$identity=googleValidateClaims($claims,'session-nonce');
googleCheck($identity['authoritative'],'Gmail verification recognized');
googleCheck(!googleValidateClaims(array_merge($claims,['email'=>'person@example.test']),'session-nonce')['authoritative'],'third-party email still requires application verification');
$_SESSION=['google_signup'=>['expires'=>time()-1]];
googleCheck(googlePending('google_signup')===null,'expired signup identity removed');
$pdo=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='fortunett_google_test_'.bin2hex(random_bytes(4));$pdo->exec("CREATE DATABASE `$schema`");$pdo->exec("USE `$schema`");
try {
    $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,email VARCHAR(255),tenant_id INT,email_verified TINYINT,is_super_admin TINYINT DEFAULT 0)');
    $pdo->exec("INSERT INTO users(id,email,tenant_id,email_verified) VALUES(1,'owner@gmail.com',9,1),(2,'second@gmail.com',10,1)");
    $pdo->exec('CREATE TABLE platform_settings(setting_key VARCHAR(100),setting_value VARCHAR(100))');
    $pdo->exec("INSERT INTO platform_settings VALUES('signup_enabled','1')");
    $pdo->exec("CREATE TABLE tenants(id INT PRIMARY KEY,subdomain VARCHAR(100))");
    $pdo->exec("INSERT INTO tenants VALUES(9,'ghettohlink'),(10,'ecolandattic')");
    ensureGoogleIdentitySchema($pdo);
    googleCheck(googleResolveIdentity($pdo,$identity,null)['action']==='workspace','unlinked tenant identity on main domain is directed to own workspace');
    googleCheck(googleResolveIdentity($pdo,$identity,9)['action']==='link','existing email requires password linking');
    googleCheck(googleReject(fn()=>googleResolveIdentity($pdo,$identity,10)),'wrong workspace rejected before linking');
    googleLinkIdentity($pdo,1,$identity);
    googleCheck(googleResolveIdentity($pdo,$identity,null)['action']==='workspace','linked tenant identity cannot log in on main domain');
    googleCheck(googleResolveIdentity($pdo,$identity,9)['action']==='login','linked identity signs in to correct workspace');
    googleCheck(googleReject(fn()=>googleResolveIdentity($pdo,$identity,10)),'linked identity cannot cross workspace boundary');
    googleCheck(googleReject(fn()=>googleLinkIdentity($pdo,2,$identity)),'identity cannot be reassigned to another user');
    googleLinkIdentity($pdo,1,$identity);
    googleCheck($pdo->query('SELECT COUNT(*) FROM google_identities')->fetchColumn()==1,'repeated linking is idempotent');
    $new=array_merge($identity,['sub'=>'new-sub','email'=>'new@gmail.com']);
    googleCheck(googleResolveIdentity($pdo,$new,null)['action']==='signup','new root identity goes to workspace setup');
    googleCheck(googleReject(fn()=>googleResolveIdentity($pdo,$new,9)),'new identity cannot register into another tenant');
    $pdo->exec("UPDATE platform_settings SET setting_value='0'");
    googleCheck(googleReject(fn()=>googleResolveIdentity($pdo,$new,null)),'closed registrations also block Google signup');
    $pdo->exec('UPDATE users SET email_verified=0 WHERE id=1');
    googleCheck(googleReject(fn()=>googleResolveIdentity($pdo,$identity,9)),'linked but unverified account cannot bypass verification');
    $_SESSION=['user_id'=>1,'tenant_id'=>9,'tenant_subdomain'=>'ghettohlink'];
    $_SERVER['HTTP_HOST']='ghettohlink.fortunetttech.site';
    googleCheck(isLoggedIn(),'session accepted on own workspace');
    $_SERVER['HTTP_HOST']='ecolandattic.fortunetttech.site';
    googleCheck(!isLoggedIn(),'session rejected on another workspace');
    $_SERVER['HTTP_HOST']='www.fortunetttech.site';
    googleCheck(!isLoggedIn(),'tenant session rejected on main domain');
    echo "$checks Google authentication checks passed.\n";
} finally {$pdo->exec("DROP DATABASE `$schema`");}
