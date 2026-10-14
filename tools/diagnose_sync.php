<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
$subdomain = $argv[1] ?? 'homelinkfiber';
$tenant = $pdo->prepare('SELECT id,company_name FROM tenants WHERE subdomain=?');
$tenant->execute([$subdomain]); $row = $tenant->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "Tenant not found in this database.\n"; exit; }
echo json_encode(['tenant'=>$row,'retry_worker'=>cron_last_run($pdo,'retry_provisions')],JSON_PRETTY_PRINT)."\n";
try {
    $hasError = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dashboard_sync_jobs' AND COLUMN_NAME='last_error'")->fetchColumn();
    $st = $pdo->prepare('SELECT j.id,j.kind,j.entity_id,j.router_id,r.name AS router_name,j.attempts,j.created_at,j.next_retry_at'.($hasError ? ',j.last_error' : '').' FROM dashboard_sync_jobs j LEFT JOIN mikrotik_routers r ON r.id=j.router_id AND r.tenant_id=j.tenant_id WHERE j.tenant_id=? AND j.applied_at IS NULL ORDER BY j.next_retry_at,j.id');
    $st->execute([$row['id']]); echo json_encode($st->fetchAll(PDO::FETCH_ASSOC),JSON_PRETTY_PRINT)."\n";
} catch (Throwable $e) { echo "Sync queue unavailable: ".$e->getMessage()."\n"; }
