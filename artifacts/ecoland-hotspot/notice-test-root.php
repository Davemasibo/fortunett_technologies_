<?php
if(PHP_SAPI!=='cli')exit;
foreach(['DB_USER'=>'root','DB_PASS'=>''] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
chdir('/var/www/html/fortunett_technologies_');require 'tools/test_payment_notifications_mysql.php';
