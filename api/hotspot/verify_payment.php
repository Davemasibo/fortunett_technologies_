<?php
/**
 * POST /api/hotspot/verify_payment.php
 *
 * Public endpoint (no session required) — called by the captive portal's
 * "Reconnect" tab. Verifies an M-Pesa transaction code, re-enables the
 * client's account in the DB, kicks the router into allowing them back in,
 * and returns their MikroTik credentials so the portal can auto-login.
 *
 * POST params:
 *   mpesa_code  — M-Pesa confirmation code (e.g. QH12345678)
 *   tenant_id   — numeric tenant ID (embedded in the captive portal template)
 *   mac         — MAC address of the device (optional, for logging)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';

function fail(string $msg, string $hint = ''): void {
    echo json_encode(['success' => false, 'message' => $msg, 'hint' => $hint]);
    exit;
}

$mpesaCode = strtoupper(trim($_POST['mpesa_code'] ?? ''));
$tenantId  = (int)($_POST['tenant_id'] ?? 0);
$mac       = trim($_POST['mac'] ?? '');

if (!$mpesaCode) fail('No M-Pesa code provided.');
if (!$tenantId)  fail('Invalid tenant.');

// ── Look up the transaction ───────────────────────────────────────────────────
// Check both mpesa_transactions (STK push) and payments (manual/C2B) tables.
$tx = null;
try {
    $st = $pdo->prepare("
        SELECT mt.id, mt.client_id, mt.tenant_id, mt.amount, mt.status,
               mt.transaction_id AS mpesa_receipt
        FROM mpesa_transactions mt
        WHERE mt.transaction_id = ?
          AND mt.tenant_id = ?
          AND mt.status = 'completed'
        LIMIT 1
    ");
    $st->execute([$mpesaCode, $tenantId]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_e) {}

// Fallback: check payments table
if (!$tx) {
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.client_id, p.tenant_id, p.amount, p.status,
                   p.transaction_id AS mpesa_receipt
            FROM payments p
            WHERE p.transaction_id = ?
              AND p.tenant_id = ?
              AND p.status = 'completed'
            LIMIT 1
        ");
        $st->execute([$mpesaCode, $tenantId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $_e) {}
}

if (!$tx) {
    fail(
        'Payment code not found.',
        'Make sure you entered the M-Pesa confirmation code exactly. If you just paid, wait 30 seconds and try again.'
    );
}

$clientId = (int)($tx['client_id'] ?? 0);
if (!$clientId) {
    fail('Payment found but no account is linked to it. Please contact support.');
}

// ── Load the client ───────────────────────────────────────────────────────────
try {
    $cSt = $pdo->prepare("
        SELECT c.id, c.full_name, c.mikrotik_username, c.mikrotik_password,
               c.connection_type, c.status, c.expiry_date, c.package_id,
               p.validity_value, p.validity_unit
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.id = ? AND c.tenant_id = ?
        LIMIT 1
    ");
    $cSt->execute([$clientId, $tenantId]);
    $client = $cSt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fail('Database error looking up account.');
}

if (!$client) {
    fail('Account not found for this tenant.');
}

if (empty($client['mikrotik_username']) || empty($client['mikrotik_password'])) {
    fail('Account has no router credentials. Please contact support.');
}

// ── Extend expiry if account is inactive/expired ──────────────────────────────
$validityVal  = (int)($client['validity_value']  ?? 30);
$validityUnit = strtolower($client['validity_unit'] ?? 'days');

$unitMap = ['minutes' => 'minute', 'hours' => 'hour', 'days' => 'day', 'months' => 'month', 'weeks' => 'week'];
$phpUnit = $unitMap[$validityUnit] ?? 'day';

$currentExpiry = $client['expiry_date'] ? new DateTime($client['expiry_date']) : new DateTime();
$now = new DateTime();
// If already expired, start from now; otherwise extend from current expiry
$base = ($currentExpiry < $now) ? $now : $currentExpiry;
$base->modify('+' . $validityVal . ' ' . $phpUnit);
$newExpiry = $base->format('Y-m-d H:i:s');

try {
    $upd = $pdo->prepare("
        UPDATE clients
        SET status = 'active', expiry_date = ?, updated_at = NOW()
        WHERE id = ? AND tenant_id = ?
    ");
    $upd->execute([$newExpiry, $clientId, $tenantId]);
} catch (Throwable $e) {
    fail('Failed to update account. Please try again.');
}

// ── Re-enable on MikroTik router ──────────────────────────────────────────────
$routerOk = false;
try {
    $rSt = $pdo->prepare("
        SELECT id, ip_address, vpn_ip, username, password, api_port
        FROM mikrotik_routers
        WHERE tenant_id = ? AND status IN ('active','online')
        ORDER BY id ASC LIMIT 1
    ");
    $rSt->execute([$tenantId]);
    $router = $rSt->fetch(PDO::FETCH_ASSOC);

    if ($router) {
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $port = (int)($router['api_port'] ?: 8728);
        $sock = @fsockopen($connectIp, $port, $errno, $errstr, 4);
        if ($sock) {
            fclose($sock);
            $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
            $mk->connect();
            $connType = strtolower($client['connection_type'] ?? 'hotspot');
            $uname = $client['mikrotik_username'];
            if ($connType === 'pppoe') {
                $mk->enablePPPoEUser($uname);
            } else {
                $mk->enableHotspotUser($uname);
            }
            $mk->disconnect();
            $routerOk = true;
        }
    }
} catch (Throwable $_e) {
    // Router might be unreachable — DB is already updated, that's the source of truth
}

echo json_encode([
    'success'   => true,
    'username'  => $client['mikrotik_username'],
    'password'  => $client['mikrotik_password'],
    'expiry'    => $newExpiry,
    'router_ok' => $routerOk,
]);
