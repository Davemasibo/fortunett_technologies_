<?php
/**
 * Demo Customer Login — Admin Portal
 *
 * Generates a one-time token that logs the admin in as a preview customer
 * so they can inspect the customer portal without using real customer credentials.
 *
 * POST /api/admin/demo_customer_login.php
 * Requires: valid admin session ($_SESSION['user_id'])
 * Returns: { success, login_url, expires_in }
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/app_url.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

try {
    $adminUserId = (int)$_SESSION['user_id'];

    // ── Resolve tenant_id ─────────────────────────────────────────────────────
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    if (!$tenantId) {
        $stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
        $stmt->execute([$adminUserId]);
        $tenantId = (int)$stmt->fetchColumn();
        if ($tenantId) $_SESSION['tenant_id'] = $tenantId;
    }
    if (!$tenantId) {
        echo json_encode(['success' => false, 'message' => 'Tenant not found']);
        exit;
    }

    // ── Find or create demo preview client for this tenant ───────────────────
    $stmt = $pdo->prepare("
        SELECT * FROM clients
        WHERE tenant_id = ? AND username = '__demo_preview__'
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $demoClient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$demoClient) {
        // Pick the first active package (cheapest / free preferred)
        $pkgStmt = $pdo->prepare("
            SELECT id, name FROM packages
            WHERE tenant_id = ? AND status = 'active'
            ORDER BY price ASC LIMIT 1
        ");
        $pkgStmt->execute([$tenantId]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
        $packageId = $pkg['id'] ?? null;

        // Tenant subdomain for account number prefix
        $tStmt = $pdo->prepare("SELECT subdomain FROM tenants WHERE id = ?");
        $tStmt->execute([$tenantId]);
        $subdomain    = $tStmt->fetchColumn() ?: 'demo';
        $accountNum   = strtoupper(substr($subdomain, 0, 4)) . 'DEMO';

        $pdo->prepare("
            INSERT INTO clients
                (tenant_id, full_name, name, username, phone, email,
                 account_number, package_id, subscription_plan,
                 status, expiry_date,
                 auth_password, mikrotik_username, mikrotik_password,
                 connection_type, package_price)
            VALUES (?, 'Demo Preview User', 'Demo Preview User',
                    '__demo_preview__', '0700000000', 'demo@preview.local',
                    ?, ?, ?,
                    'active', DATE_ADD(NOW(), INTERVAL 30 DAY),
                    ?, '__demo_preview__', 'demo1234',
                    'hotspot', 0)
        ")->execute([
            $tenantId,
            $accountNum,
            $packageId,
            $pkg['name'] ?? 'Demo Package',
            password_hash('demo1234', PASSWORD_DEFAULT),
        ]);

        $newId = (int)$pdo->lastInsertId();
        $stmt  = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$newId]);
        $demoClient = $stmt->fetch(PDO::FETCH_ASSOC);

    } else {
        // Keep demo client fresh (active, non-expired)
        $pdo->prepare("
            UPDATE clients
            SET status = 'active', expiry_date = DATE_ADD(NOW(), INTERVAL 30 DAY)
            WHERE id = ?
        ")->execute([$demoClient['id']]);
    }

    // ── Issue a one-time auto-login token (15-minute TTL) ────────────────────
    $token     = bin2hex(random_bytes(24));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $pdo->prepare("
        INSERT INTO payment_auto_logins
            (client_id, tenant_id, login_token, status, expires_at)
        VALUES (?, ?, ?, 'pending', ?)
    ")->execute([$demoClient['id'], $tenantId, $token, $expiresAt]);

    // ── Build customer portal URL ─────────────────────────────────────────────
    // The customer portal is always on the SAME host as the admin portal
    // (same subdomain, /customer/ path). appBase() handles localhost vs production.
    $loginUrl = appOrigin() . appBase() . '/customer/login.html?token=' . $token . '&demo=1';

    echo json_encode([
        'success'    => true,
        'login_url'  => $loginUrl,
        'expires_in' => '15 minutes',
        'demo_user'  => $demoClient['full_name'] ?? 'Demo Preview User',
    ]);

} catch (Throwable $e) {
    error_log('demo_customer_login: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
