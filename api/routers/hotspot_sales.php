<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/auth.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
$userId = (int)$_SESSION['user_id'];
session_write_close();
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/hotspot_location_sales.php';
try {
    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$start || $start->format('Y-m-d') !== $date) {
        http_response_code(422); echo json_encode(['success'=>false,'message'=>'Choose a valid date.']); exit;
    }
    $st = $pdo->prepare('SELECT r.id,r.tenant_id FROM mikrotik_routers r JOIN users u ON u.tenant_id=r.tenant_id WHERE u.id=? AND r.id=?');
    $st->execute([$userId,(int)($_GET['router_id'] ?? 0)]);
    $router = $st->fetch(PDO::FETCH_ASSOC);
    if (!$router) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Router not found.']); exit; }
    echo json_encode(['success'=>true,'date'=>$date,'sales'=>hotspotLocationSales($pdo,(int)$router['tenant_id'],(int)$router['id'],$start->format('Y-m-d H:i:s'),$start->modify('+1 day')->format('Y-m-d H:i:s'))]);
} catch (Throwable $e) {
    error_log('Hotspot sales report: '.$e->getMessage());
    http_response_code(503); echo json_encode(['success'=>false,'message'=>'Could not load location sales. Please retry.']);
}
