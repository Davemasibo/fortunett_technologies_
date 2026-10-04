<?php
/** Resolve tenant from the portal host; never accept a tenant ID from a login URL. */
function customerHostTenant(PDO $pdo): ?int {
    $host=strtolower(explode(':',$_SERVER['HTTP_HOST']??'')[0]);
    if(in_array($host,['localhost','127.0.0.1','::1',''],true))return null;
    if(!preg_match('/^([a-z0-9-]+)\.fortunetttech\.site$/D',$host,$m))return 0;
    $st=$pdo->prepare('SELECT id FROM tenants WHERE subdomain=?');$st->execute([$m[1]]);
    return (int)$st->fetchColumn();
}
