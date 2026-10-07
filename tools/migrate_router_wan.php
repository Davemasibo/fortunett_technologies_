<?php
if (PHP_SAPI!=='cli') {http_response_code(403); exit;}
require_once __DIR__.'/../includes/db_master.php';
$pdo->exec(file_get_contents(__DIR__.'/../sql/migrations/2026-10-07-router-wan.sql'));
echo "Router WAN configuration table ready.\n";
