<?php
/**
 * API Endpoint: Import existing MikroTik users (PPPoE secrets or Hotspot users)
 * into the dashboard as client records.
 *
 * Users already in the DB (matched by mikrotik_username + tenant) are skipped.
 * Since RouterOS never returns passwords, imported users get a placeholder password
 * that the admin can update, or they can keep using their existing router password.
 *
 * POST params:
 *   connection_type  pppoe | hotspot  (default: pppoe)
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t_stmt->fetchColumn();
if (!$tenant_id) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No tenant assigned']);
    exit;
}

$connection_type = $_POST['connection_type'] ?? 'pppoe';
if (!in_array($connection_type, ['pppoe', 'hotspot'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid connection_type']);
    exit;
}

// Get active router
$router_stmt = $pdo->prepare(
    "SELECT id, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ? LIMIT 1"
);
$router_stmt->execute([$tenant_id]);
$router = $router_stmt->fetch(PDO::FETCH_ASSOC);
if (!$router) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No active router found for this tenant']);
    exit;
}

try {
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
    $api->connect();

    // Fetch users from router
    $routerUsers = ($connection_type === 'hotspot') ? $api->getHotspotUsers() : $api->getPPPoEUsers();
    $api->disconnect();

    // Fetch existing usernames already in the DB for this tenant
    $existing_stmt = $pdo->prepare(
        "SELECT mikrotik_username FROM clients WHERE tenant_id = ? AND mikrotik_username != '' AND mikrotik_username IS NOT NULL"
    );
    $existing_stmt->execute([$tenant_id]);
    $existingUsernames = array_flip($existing_stmt->fetchAll(PDO::FETCH_COLUMN));

    $imported = 0;
    $skipped  = 0;
    $failed   = 0;

    $insert_stmt = $pdo->prepare("
        INSERT INTO clients
            (tenant_id, full_name, name, mikrotik_username, mikrotik_password,
             username, connection_type, status, created_at)
        VALUES (?, ?, ?, ?, '', ?, ?, 'active', NOW())
    ");

    foreach ($routerUsers as $u) {
        $routerName = $u['name'] ?? '';
        if (empty($routerName)) continue;

        if (isset($existingUsernames[strtolower($routerName)]) || isset($existingUsernames[$routerName])) {
            $skipped++;
            continue;
        }

        // Use the RouterOS comment as a display name if available
        $displayName = !empty($u['comment']) ? $u['comment'] : $routerName;

        try {
            $insert_stmt->execute([
                $tenant_id,
                $displayName,
                $displayName,
                $routerName,
                $routerName, // username = same as mikrotik_username for portal login
                $connection_type,
            ]);
            $existingUsernames[$routerName] = true; // prevent duplicate within same batch
            $imported++;
        } catch (Exception $e) {
            error_log("import_users: failed for '$routerName': " . $e->getMessage());
            $failed++;
        }
    }

    ob_clean();
    echo json_encode([
        'success'  => true,
        'imported' => $imported,
        'skipped'  => $skipped,
        'failed'   => $failed,
        'message'  => "Import complete: $imported imported, $skipped already existed, $failed failed.",
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
