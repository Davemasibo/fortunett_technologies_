<?php
require_once __DIR__ . '/auto_provision.php';

// Create outside save transactions: MySQL DDL implicitly commits.
function dashboardSyncSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_sync_jobs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL,
        kind VARCHAR(16) NOT NULL, entity_id INT NOT NULL, router_id INT NOT NULL DEFAULT 0,
        old_username VARCHAR(255) NOT NULL DEFAULT '', old_service VARCHAR(16) NOT NULL DEFAULT '',
        applied_at DATETIME NULL, next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        attempts INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX due_jobs (applied_at, next_retry_at), INDEX tenant_jobs (tenant_id, applied_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dashboardQueueCustomer(PDO $pdo, int $tenant, int $id, array $old = []): void {
    $routers = $pdo->prepare('SELECT DISTINCT router_id FROM router_services WHERE tenant_id=? AND client_id=?');
    $routers->execute([$tenant, $id]);
    $ids = $routers->fetchAll(PDO::FETCH_COLUMN) ?: [0];
    $insert = $pdo->prepare('INSERT INTO dashboard_sync_jobs (tenant_id,kind,entity_id,router_id,old_username,old_service) VALUES (?,\'customer\',?,?,?,?)');
    foreach ($ids as $router) $insert->execute([$tenant, $id, $router, $old['mikrotik_username'] ?? '', $old['connection_type'] ?? '']);
}

function dashboardQueuePackage(PDO $pdo, int $tenant, int $id): void {
    $routers = $pdo->prepare('SELECT id FROM mikrotik_routers WHERE tenant_id=?');
    $routers->execute([$tenant]);
    $insert = $pdo->prepare("INSERT INTO dashboard_sync_jobs (tenant_id,kind,entity_id,router_id) VALUES (?,'package',?,?)");
    foreach ($routers->fetchAll(PDO::FETCH_COLUMN) ?: [0] as $router) $insert->execute([$tenant, $id, $router]);
    $clients = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=? AND package_id=? AND status='active' AND expiry_date>NOW()");
    $clients->execute([$tenant, $id]);
    foreach ($clients->fetchAll(PDO::FETCH_COLUMN) as $client) dashboardQueueCustomer($pdo, $tenant, (int)$client);
}

function dashboardDisableUser($api, string $service, string $username): void {
    if ($username === '') return;
    $hotspot = $service === 'hotspot';
    $users = $hotspot ? '/ip/hotspot/user' : '/ppp/secret';
    foreach (routerCheckedCommand($api, $users . '/print', ['?name=' . $username]) as $row) {
        if (isset($row['.id'])) routerCheckedCommand($api, $users . '/set', ['=.id=' . $row['.id'], '=disabled=yes']);
    }
    foreach (routerCheckedCommand($api, ($hotspot ? '/ip/hotspot/active' : '/ppp/active') . '/print', [($hotspot ? '?user=' : '?name=') . $username]) as $row) {
        if (isset($row['.id'])) routerCheckedCommand($api, ($hotspot ? '/ip/hotspot/active' : '/ppp/active') . '/remove', ['=.id=' . $row['.id']]);
    }
    if ($hotspot) foreach (routerCheckedCommand($api, '/ip/hotspot/cookie/print', ['?user=' . $username]) as $row) {
        if (isset($row['.id'])) routerCheckedCommand($api, '/ip/hotspot/cookie/remove', ['=.id=' . $row['.id']]);
    }
    foreach (routerCheckedCommand($api, $users . '/print', ['?name=' . $username]) as $row) {
        if (isset($row['.id']) && ($row['disabled'] ?? '') !== 'true') throw new RuntimeException('Suspension could not be verified');
    }
    foreach (routerCheckedCommand($api, ($hotspot ? '/ip/hotspot/active' : '/ppp/active') . '/print', [($hotspot ? '?user=' : '?name=') . $username]) as $row) {
        if (isset($row['.id'])) throw new RuntimeException('Session disconnect could not be verified');
    }
}

function dashboardApplyJob(PDO $pdo, array $job): void {
    $tenant = (int)$job['tenant_id'];
    // Deny RADIUS reconnects even while the router itself is unreachable.
    if ($job['kind'] === 'customer') {
        $state = $pdo->prepare('SELECT * FROM clients WHERE tenant_id=? AND id=?');
        $state->execute([$tenant, $job['entity_id']]);
        $current = $state->fetch(PDO::FETCH_ASSOC);
        if ($current && ($current['status'] !== 'active' || empty($current['expiry_date']) || strtotime($current['expiry_date']) <= time())) {
            if (radius_is_available($pdo) && !radius_disable_client($pdo, $current['mikrotik_username'])) throw new RuntimeException('RADIUS suspension failed');
        }
    }
    $stmt = $pdo->prepare('SELECT * FROM mikrotik_routers WHERE tenant_id=? AND (?=0 OR id=?) ORDER BY id LIMIT 1');
    $stmt->execute([$tenant, $job['router_id'], $job['router_id']]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$router) throw new RuntimeException('Router not configured');
    if (isset($current) && $current && $current['status'] === 'active'
        && !empty($current['expiry_date']) && strtotime($current['expiry_date']) > time()
        && (!$job['old_username'] || ($job['old_username'] === $current['mikrotik_username'] && $job['old_service'] === $current['connection_type']))) {
        $result = autoProvisionClient($pdo, (int)$current['id'], $tenant, (int)$router['id'], false);
        if (!$result['success']) throw new RuntimeException($result['message']);
        return;
    }
    $api = new MikrotikAPI($router['vpn_ip'] ?: $router['ip_address'], $router['username'], $router['password'], (int)($router['api_port'] ?: 8728));
    if (!$api->connect()) throw new RuntimeException('Router unavailable');
    try {
        if ($job['kind'] === 'package') {
            $stmt = $pdo->prepare('SELECT * FROM packages WHERE tenant_id=? AND id=?');
            $stmt->execute([$tenant, $job['entity_id']]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$package) return;
            if (!syncPackageProfileToRouter($api, $package['connection_type'] ?: $package['type'], packageProfileName($package), packageRateLimit($package), $package)) throw new RuntimeException('Profile verification failed');
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE tenant_id=? AND id=?');
        $stmt->execute([$tenant, $job['entity_id']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) return;
        $username = $client['mikrotik_username'];
        $service = $client['connection_type'];
        if (!in_array($service, ['hotspot', 'pppoe'], true)) throw new RuntimeException('Unsupported service');
        if ($job['old_username'] && ($job['old_username'] !== $username || $job['old_service'] !== $service)) {
            $owner = $pdo->prepare('SELECT id FROM clients WHERE tenant_id=? AND mikrotik_username=? AND id<>?');
            $owner->execute([$tenant, $job['old_username'], $client['id']]);
            if (!$owner->fetchColumn()) {
                dashboardDisableUser($api, $job['old_service'], $job['old_username']);
                radius_disable_client($pdo, $job['old_username']);
            }
        }
        if ($client['status'] !== 'active' || empty($client['expiry_date']) || strtotime($client['expiry_date']) <= time()) {
            radius_disable_client($pdo, $username);
            dashboardDisableUser($api, $service, $username);
            $serviceStatus = !empty($client['expiry_date']) && strtotime($client['expiry_date']) > time() ? 'suspended' : 'expired';
            $pdo->prepare('UPDATE router_services SET status=? WHERE tenant_id=? AND client_id=? AND router_id=?')->execute([$serviceStatus, $tenant, $client['id'], $router['id']]);
        } else {
            $api->disconnect();
            $result = autoProvisionClient($pdo, (int)$client['id'], $tenant, (int)$router['id'], false);
            if (!$result['success']) throw new RuntimeException($result['message']);
        }
    } finally { $api->disconnect(); }
}

// Both browser polling and the minute cron drain the same durable queue.
function dashboardProcessSync(PDO $pdo, ?int $tenant = null, int $limit = 1): void {
    $stmt = $pdo->prepare('SELECT * FROM dashboard_sync_jobs WHERE applied_at IS NULL AND next_retry_at<=NOW()' . ($tenant === null ? '' : ' AND tenant_id=?') . ' ORDER BY id LIMIT ' . max(1, min(30, $limit)));
    $stmt->execute($tenant === null ? [] : [$tenant]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $lockName = $job['kind'] === 'customer' ? 'payment-client-' . $job['tenant_id'] . '-' . $job['entity_id'] : 'dashboard-package-' . $job['tenant_id'] . '-' . $job['entity_id'];
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) continue;
        try {
            $check = $pdo->prepare('SELECT id FROM dashboard_sync_jobs WHERE id=? AND applied_at IS NULL AND next_retry_at<=NOW()');
            $check->execute([$job['id']]);
            if (!$check->fetchColumn()) continue;
            dashboardApplyJob($pdo, $job);
            $pdo->prepare('UPDATE dashboard_sync_jobs SET applied_at=NOW() WHERE id=?')->execute([$job['id']]);
        } catch (Throwable $e) {
            error_log('Dashboard sync ' . $job['id'] . ': ' . $e->getMessage());
            $pdo->prepare('UPDATE dashboard_sync_jobs SET attempts=attempts+1,next_retry_at=DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE id=?')->execute([$job['id']]);
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    }
}
