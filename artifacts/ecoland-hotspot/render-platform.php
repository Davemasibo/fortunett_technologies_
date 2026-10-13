<?php
if(PHP_SAPI!=='cli')exit;chdir('/var/www/html/fortunett_technologies_');session_start();$_SESSION['user_id']=6;$_SESSION['is_super_admin']=true;$_SERVER['SCRIPT_NAME']='/super_admin/diagnostics.php';$_SERVER['HTTP_HOST']='fortunetttech.site';ob_start();include 'super_admin/diagnostics.php';file_put_contents('/root/ecoland-platform-preview.html',ob_get_clean());
