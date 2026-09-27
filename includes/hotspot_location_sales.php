<?php
require_once __DIR__ . '/hotspot_device.php';
require_once __DIR__ . '/router_expiry.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

function ensureHotspotLocationSales(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_purchase_locations (
        tenant_id INT NOT NULL, checkout_id VARCHAR(191) NOT NULL, client_id INT NOT NULL,
        router_id INT NOT NULL, interface_name VARCHAR(255) NOT NULL,
        location_name VARCHAR(255) NOT NULL, captured_at DATETIME NOT NULL,
        PRIMARY KEY(tenant_id,checkout_id), INDEX(tenant_id,client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Read the device location BEFORE requesting payment; outages never prevent payment. */
function captureHotspotPurchaseLocation(PDO $pdo, int $tenant, int $clientId, string $rawMac): ?array {
    $api = null;
    try {
        $mac = hotspotDeviceMac($rawMac);
        if (!$mac) return null;
        $st = $pdo->prepare('SELECT * FROM clients WHERE tenant_id=? AND id=?');
        $st->execute([$tenant,$clientId]);
        $client = $st->fetch(PDO::FETCH_ASSOC);
        if (!$client || ($client['connection_type'] ?? '') !== 'hotspot') return null;
        $routerId = resolveClientRouter($pdo,$client,$tenant);
        $st = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? AND id=?');
        $st->execute([$tenant,$routerId]);
        $router = $st->fetch(PDO::FETCH_ASSOC);
        if (!$router) return null;
        $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'], $router['username'], $router['password'], (int)($router['api_port'] ?: 8728));
        if (!$api->isReachable(1)) return null;
        $api->connect();
        $interfaces = [];
        foreach (routerCheckedCommand($api,'/interface/bridge/host/print',['?mac-address='.$mac]) as $host) {
            if (strtoupper($host['mac-address'] ?? '') === $mac && !empty($host['on-interface']) && ($host['local'] ?? '') !== 'true') $interfaces[$host['on-interface']] = true;
        }
        if (count($interfaces) !== 1) return null;
        $interface = array_key_first($interfaces);
        foreach (routerCheckedCommand($api,'/interface/print',['?name='.$interface]) as $row) {
            if (($row['name'] ?? '') === $interface) return ['router_id'=>$routerId,'interface_name'=>$interface,'location_name'=>$row['comment'] ?? '', 'captured_at'=>date('Y-m-d H:i:s')];
        }
    } catch (Throwable $e) { error_log('Hotspot sale location capture: '.$e->getMessage()); }
    finally { if ($api) $api->disconnect(); }
    return null;
}

function recordHotspotPurchaseLocation(PDO $pdo, string $checkout, int $tenant, int $clientId, ?array $location): void {
    if (!$location) return;
    try {
        ensureHotspotLocationSales($pdo);
        // Repeated callbacks or reconnects cannot move a purchase to another AP.
        $pdo->prepare('INSERT IGNORE INTO hotspot_purchase_locations
            (tenant_id,checkout_id,client_id,router_id,interface_name,location_name,captured_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$tenant,$checkout,$clientId,$location['router_id'],$location['interface_name'],$location['location_name'],$location['captured_at']]);
    } catch (Throwable $e) { error_log('Hotspot sale location record: '.$e->getMessage()); }
}

/** Count canonical completed payment rows once, even with duplicate callback rows. */
function hotspotLocationSales(PDO $pdo, int $tenant, int $router, string $from, string $until): array {
    ensureHotspotLocationSales($pdo);
    $st = $pdo->prepare("SELECT assigned.router_id, assigned.interface_name,
            MAX(assigned.location_name) AS location_name, COUNT(*) AS sales, SUM(assigned.amount) AS revenue
        FROM (
            SELECT p.id, p.amount,
                IF(COUNT(DISTINCT CONCAT(l.router_id,':',l.interface_name))=1, MIN(l.router_id), NULL) AS router_id,
                IF(COUNT(DISTINCT CONCAT(l.router_id,':',l.interface_name))=1, MIN(l.interface_name), NULL) AS interface_name,
                MIN(l.location_name) AS location_name
            FROM payments p
            JOIN clients c ON c.id=p.client_id AND c.tenant_id=p.tenant_id AND c.connection_type='hotspot'
            LEFT JOIN mpesa_transactions mt ON mt.tenant_id=p.tenant_id AND mt.client_id=p.client_id
                AND (mt.checkout_request_id=p.transaction_id OR mt.mpesa_receipt_number=p.transaction_id)
            LEFT JOIN hotspot_purchase_locations l ON l.tenant_id=p.tenant_id AND l.client_id=p.client_id
                AND l.checkout_id=COALESCE(mt.checkout_request_id,p.transaction_id)
            WHERE p.tenant_id=? AND p.status='completed' AND p.payment_date>=? AND p.payment_date<?
            GROUP BY p.id,p.amount
        ) assigned WHERE assigned.router_id=? OR assigned.router_id IS NULL
        GROUP BY assigned.router_id, assigned.interface_name");
    $st->execute([$tenant,$from,$until,$router]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
