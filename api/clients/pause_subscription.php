<?php
/**
 * POST /api/clients/pause_subscription.php
 * Pause (suspend) or resume an active subscription without altering expiry.
 * Body: client_id, action (pause|resume)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'No tenant assigned']);
    exit;
}

$client_id = (int)($_POST['client_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');

if (!$client_id || !in_array($action, ['pause', 'resume'])) {
    echo json_encode(['success' => false, 'message' => 'client_id and action (pause|resume) required']);
    exit;
}

// Verify ownership and get current status
$chk = $pdo->prepare("SELECT id, status, expiry_date FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
$client = $chk->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    echo json_encode(['success' => false, 'message' => 'Client not found or access denied']);
    exit;
}

try {
    if ($action === 'pause') {
        if ($client['status'] === 'suspended') {
            echo json_encode(['success' => false, 'message' => 'Subscription is already paused']);
            exit;
        }
        $newStatus = 'suspended';
        $msg = 'Subscription paused. Service is suspended; expiry date is preserved.';
    } else {
        // resume — restore to active (or inactive if expiry already passed)
        $expiry = $client['expiry_date'];
        $newStatus = ($expiry && strtotime($expiry) > time()) ? 'active' : 'inactive';
        $msg = ($newStatus === 'active') ? 'Subscription resumed. Service is now active.' : 'Subscription resumed, but expiry has passed — status set to inactive.';
    }

    $upd = $pdo->prepare("UPDATE clients SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
    $upd->execute([$newStatus, $client_id, $tenant_id]);

    // Optionally sync to MikroTik — disable/enable user
    try {
        require_once '../../classes/MikrotikAPI.php';
        $rStmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ? LIMIT 1");
        $rStmt->execute([$tenant_id]);
        $router = $rStmt->fetch(PDO::FETCH_ASSOC);
        if ($router) {
            $cStmt = $pdo->prepare("SELECT mikrotik_username, connection_type FROM clients WHERE id = ?");
            $cStmt->execute([$client_id]);
            $cdata = $cStmt->fetch(PDO::FETCH_ASSOC);
            if ($cdata && !empty($cdata['mikrotik_username'])) {
                $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
                $api = new MikrotikAPI(
                    $connectIp, $router['username'],
                    $router['password'], (int)($router['api_port'] ?? 8728)
                );
                if ($api->connect()) {
                    $uname = $cdata['mikrotik_username'];
                    $isHotspot = strtolower($cdata['connection_type']) === 'hotspot';
                    if ($action === 'pause') {
                        // Disable prevents reconnect; kick drops the live session immediately
                        if ($isHotspot) {
                            $api->disableHotspotUser($uname); // also kicks internally
                        } else {
                            $api->disablePPPoEUser($uname);
                            $api->kickPPPoESession($uname);
                        }
                    } else {
                        if ($isHotspot) {
                            $api->enableHotspotUser($uname);
                        } else {
                            $api->enablePPPoEUser($uname);
                        }
                    }
                    $api->disconnect();
                }
            }
        }
    } catch (Exception $routerErr) {
        // MikroTik sync failure is non-fatal — DB status is already updated
        error_log("pause_subscription MikroTik sync error: " . $routerErr->getMessage());
    }

    echo json_encode([
        'success'    => true,
        'message'    => $msg,
        'new_status' => $newStatus,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
