<?php
if (PHP_SAPI!=='cli') {http_response_code(403); exit;}
require_once __DIR__.'/../includes/db_master.php';
$sql=file_get_contents(__DIR__.'/../sql/migrations/2026-10-05-self-service-onboarding.sql');
$pdo->exec($sql);
echo "Self-service onboarding tables ready.\n";
