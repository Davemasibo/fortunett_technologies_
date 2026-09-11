<?php
function hotspotDeviceMac(string $raw): string {
    $mac = strtoupper(str_replace('-', ':', rawurldecode($raw)));
    return preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ? $mac : '';
}
function hotspotBoundMac(string $raw): string {
    $mac = hotspotDeviceMac($raw);
    if (!$mac || $mac === '00:00:00:00:00:00' || (hexdec(substr($mac,0,2)) & 1)) throw new InvalidArgumentException('Enter the TV Wi-Fi device MAC address; zero, broadcast and multicast addresses are not allowed.');
    return $mac;
}
function rememberHotspotDevice(PDO $pdo, int $tenant, int $client, string $raw): void {
    $mac = hotspotDeviceMac($raw);
    if (!$mac) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_device_context (tenant_id INT NOT NULL, client_id INT NOT NULL, mac_address VARCHAR(17) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(tenant_id,client_id)) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO hotspot_device_context (tenant_id,client_id,mac_address) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mac_address=VALUES(mac_address),updated_at=NOW()')->execute([$tenant,$client,$mac]);
}

/** Log in the paid device even when the captive browser closed during the PIN prompt. */
function connectKnownHotspotDevice($api, string $mac, string $username, string $password, string $expiry): bool {
    $mac = hotspotDeviceMac($mac);
    if (!$mac || strtotime($expiry) <= time()) return false;
    foreach (routerCheckedCommand($api, '/ip/hotspot/active/print', ['?user=' . $username]) as $session) {
        if (($session['user'] ?? '') === $username && strtoupper($session['mac-address'] ?? '') === $mac) return true;
    }
    foreach (routerCheckedCommand($api, '/ip/hotspot/host/print', ['?mac-address=' . $mac]) as $host) {
        if (strtoupper($host['mac-address'] ?? '') !== $mac || !filter_var($host['address'] ?? '', FILTER_VALIDATE_IP)) continue;
        routerCheckedCommand($api, '/ip/hotspot/active/login', ['=user=' . $username, '=password=' . $password, '=ip=' . $host['address'], '=mac-address=' . $mac]);
        foreach (routerCheckedCommand($api, '/ip/hotspot/active/print', ['?user=' . $username]) as $session) {
            if (($session['user'] ?? '') === $username && strtoupper($session['mac-address'] ?? '') === $mac) return true;
        }
    }
    return false;
}

/** Recover paid phones as well as TVs when the browser closed during payment. */
function recoverPaidHotspotSessions(PDO $pdo, $api, int $tenant, int $router, callable $log): void {
    $devices = $pdo->prepare("SELECT DISTINCT c.id FROM clients c
        JOIN router_services rs ON rs.client_id=c.id AND rs.tenant_id=c.tenant_id
        LEFT JOIN hotspot_device_context dc ON dc.client_id=c.id AND dc.tenant_id=c.tenant_id
        WHERE c.tenant_id=? AND rs.router_id=? AND c.connection_type='hotspot'
          AND c.status='active' AND c.expiry_date>NOW() AND rs.status='active'
          AND rs.paid_expiry_at=c.expiry_date AND rs.expiry_policy_version>=3
          AND (c.bound_mac_address IS NOT NULL OR dc.updated_at>NOW()-INTERVAL 1 DAY)");
    $devices->execute([$tenant,$router]);
    foreach ($devices->fetchAll(PDO::FETCH_COLUMN) as $clientId) {
        $key = 'payment-client-' . $tenant . '-' . $clientId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$key]);
        if ((int)$lock->fetchColumn() !== 1) continue;
        try {
            $fresh = $pdo->prepare("SELECT c.*, dc.mac_address AS remembered_mac FROM clients c
                LEFT JOIN hotspot_device_context dc ON dc.client_id=c.id AND dc.tenant_id=c.tenant_id AND dc.updated_at>NOW()-INTERVAL 1 DAY
                WHERE c.id=? AND c.tenant_id=? AND c.status='active' AND c.expiry_date>NOW()");
            $fresh->execute([$clientId,$tenant]); $client = $fresh->fetch(PDO::FETCH_ASSOC);
            if (!$client) continue;
            $mac = ($client['bound_mac_address'] ?? '') ?: ($client['remembered_mac'] ?? '');
            if (connectKnownHotspotDevice($api,$mac,$client['mikrotik_username'],$client['mikrotik_password'],$client['expiry_date'])) {
                $pdo->prepare('UPDATE clients SET last_seen=NOW() WHERE id=? AND tenant_id=?')->execute([$clientId,$tenant]);
            }
        } catch (Throwable $e) { $log('Paid device reconnect pending for client ' . $clientId . ': ' . $e->getMessage()); }
        finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
    }
}

/** Resolve the router actually seeing the device; never guess among several routers. */
function resolveClientRouter(PDO $pdo, array $client, int $tenant): int {
    $mac = $client['bound_mac_address'] ?? '';
    try {
        $st = $pdo->prepare('SELECT mac_address FROM hotspot_device_context WHERE tenant_id=? AND client_id=? AND updated_at>NOW()-INTERVAL 1 DAY');
        $st->execute([$tenant,$client['id']]); $mac = $mac ?: ($st->fetchColumn() ?: '');
    } catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 1146) throw $e; }
    $routers = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE tenant_id=? AND status IN ('active','online') ORDER BY id");
    $routers->execute([$tenant]); $rows = $routers->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) return (int)$rows[0]['id'];
    if ($mac && ($client['connection_type'] ?? '') === 'hotspot') {
        foreach ($rows as $router) {
            $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'], $router['username'], $router['password'], (int)($router['api_port'] ?: 8728));
            try {
                if (!$api->connect()) continue;
                foreach (routerCheckedCommand($api, '/ip/hotspot/host/print', ['?mac-address=' . $mac]) as $host) {
                    if (strtoupper($host['mac-address'] ?? '') === $mac) return (int)$router['id'];
                }
            } catch (Throwable $e) { error_log('Hotspot router discovery: ' . $e->getMessage()); }
            finally { $api->disconnect(); }
        }
        throw new RuntimeException('Waiting for the router serving this hotspot device');
    }
    $assigned = $pdo->prepare('SELECT rs.router_id FROM router_services rs JOIN mikrotik_routers r ON r.id=rs.router_id AND r.tenant_id=rs.tenant_id WHERE rs.tenant_id=? AND rs.client_id=? ORDER BY rs.id DESC LIMIT 1');
    $assigned->execute([$tenant,$client['id']]);
    $id = (int)$assigned->fetchColumn();
    if ($id) return $id;
    throw new RuntimeException('Customer router assignment is required');
}
