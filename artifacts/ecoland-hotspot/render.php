<?php
if(PHP_SAPI!=='cli')exit;chdir('/var/www/html/fortunett_technologies_');session_start();$_SESSION['user_id']=6;$_SESSION['role']='admin';$_SESSION['tenant_id']=5;$_SESSION['tenant_subdomain']='ecolandattic';$_SERVER['HTTP_HOST']='ecolandattic.fortunetttech.site';$_SERVER['HTTPS']='on';$_SERVER['SCRIPT_NAME']='/hotspot_diagnostics.php';ob_start();include 'hotspot_diagnostics.php';file_put_contents('/root/ecoland-diagnostics-preview.html',ob_get_clean());

