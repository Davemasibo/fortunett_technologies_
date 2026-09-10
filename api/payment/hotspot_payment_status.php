<?php
/**
 * Hotspot Payment Status Polling Endpoint
 * Called every few seconds by the captive portal after STK push.
 * Returns payment status + MikroTik credentials when payment is confirmed.
 *
 * GET params:
 *   checkout_request_id — Safaricom CheckoutRequestID
 *   client_id           — (preferred) client row ID returned by hotspot_stk_push.php
 *   tenant_id           — (fallback) used when client_id is not yet known
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/stk_reconciliation.php';
header('Cache-Control: no-store');

$checkoutId = trim($_GET['checkout_request_id'] ?? '');
$clientId   = (int)($_GET['client_id']  ?? 0);
$tenantId   = (int)($_GET['tenant_id']  ?? 0);

if (!$checkoutId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing checkout_request_id']);
    exit;
}

try {
    // Look up transaction — match by checkout_request_id.
    // Use client_id when provided (more precise); otherwise use tenant_id as scope guard.
    if ($clientId) {
        $txSt = $pdo->prepare("
            SELECT mt.*
            FROM mpesa_transactions mt
            WHERE mt.checkout_request_id = ? AND mt.client_id = ?
            LIMIT 1
        ");
        $txSt->execute([$checkoutId, $clientId]);
    } elseif ($tenantId) {
        $txSt = $pdo->prepare("
            SELECT mt.*
            FROM mpesa_transactions mt
            WHERE mt.checkout_request_id = ? AND mt.tenant_id = ?
            LIMIT 1
        ");
        $txSt->execute([$checkoutId, $tenantId]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Missing client_id or tenant_id']);
        exit;
    }

    $tx = $txSt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $scope = $clientId ? 'p.client_id=?' : 'p.tenant_id=?';
        $scopeId = $clientId ?: $tenantId;
        $base = "SELECT p.client_id,p.tenant_id,p.amount,p.status,p.payment_date AS created_at,
            p.transaction_id AS mpesa_receipt_number, ? AS checkout_request_id,
            CASE WHEN p.status='completed' THEN 0 ELSE NULL END AS result_code, '' AS result_desc FROM payments p ";
        $legacy = $pdo->prepare($base . " WHERE p.transaction_id=? AND $scope AND p.payment_method IN ('mpesa','mpesa_stk') LIMIT 1");
        $legacy->execute([$checkoutId,$checkoutId,$scopeId]);
        $tx = $legacy->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$tx) try {
            $legacy = $pdo->prepare($base . " JOIN payment_activations receipt ON receipt.tenant_id=p.tenant_id AND receipt.client_id=p.client_id AND receipt.activation_key=p.transaction_id
                JOIN payment_activations checkout ON checkout.tenant_id=receipt.tenant_id AND checkout.client_id=receipt.client_id AND checkout.expiry_date=receipt.expiry_date
                WHERE checkout.activation_key=? AND $scope AND p.payment_method IN ('mpesa','mpesa_stk') LIMIT 1");
            $legacy->execute([$checkoutId,$checkoutId,$scopeId]);
            $tx = $legacy->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 1146) throw $e; }
    }

    if (!$tx) {
        // Transaction not yet written — callback hasn't arrived yet
        echo json_encode(['status' => 'pending']);
        exit;
    }

    try {
        $tx['status'] = reconcileCustomerStk($pdo, $tx);
    } catch (Throwable $e) {
        error_log('Payment activation recovery: ' . $e->getMessage());
        echo json_encode(['status'=>'processing', 'message'=>'We are recovering your payment and connection. Do not pay again.']);
        exit;
    }

    if ($tx['status'] === 'completed') {
        $resolvedClientId = $clientId ?: (int)$tx['client_id'];

        // Fetch client credentials
        $clSt = $pdo->prepare("
            SELECT *
            FROM clients WHERE id = ? LIMIT 1
        ");
        $clSt->execute([$resolvedClientId]);
        $client = $clSt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            echo json_encode(['status' => 'pending']);
            exit;
        }

        if (!empty($client['expiry_date']) && strtotime($client['expiry_date']) <= time()) {
            echo json_encode(['status'=>'expired', 'message'=>'The access time from this payment has ended.']); exit;
        }
        if (in_array($client['status'], ['suspended','blocked'], true)) {
            echo json_encode(['status'=>'blocked', 'message'=>'This account is suspended. Contact your ISP; do not pay again.']); exit;
        }
        // A completed historic payment never reactivates an expired subscription.
        if ($client['status'] !== 'active'
            || (!empty($client['expiry_date']) && strtotime($client['expiry_date']) <= time())) {
            echo json_encode(['status' => 'processing', 'payment_confirmed' => true, 'message' => 'Payment recorded. Waiting for an active subscription.']);
            exit;
        }

        $resolvedTenantId = (int)($client['tenant_id'] ?? $tx['tenant_id'] ?? 0);
        $provisioned = false;
        try {
            $psSt = $pdo->prepare("
                SELECT 1 FROM router_services
                WHERE client_id = ? AND tenant_id = ? AND status = 'active' AND paid_expiry_at = ?
                  AND NOT EXISTS (SELECT 1 FROM pending_provisions pp
                      WHERE pp.client_id = router_services.client_id AND pp.tenant_id = router_services.tenant_id) LIMIT 1
            ");
            $psSt->execute([$resolvedClientId, $resolvedTenantId, $client['expiry_date']]);
            $provisioned = (bool)$psSt->fetchColumn();
        } catch (Throwable $_e) {
            // Missing metadata requires a verified provisioning attempt.
            $provisioned = false;
        }

        if (!$provisioned && $resolvedTenantId) {
            // The pipeline may still be running, or the router was briefly
            // unreachable. autoProvisionClient() is idempotent, so retrying here
            // is safe and usually completes on the first poll.
            try {
                require_once __DIR__ . '/../../includes/auto_provision.php';
                $prov = autoProvisionClient($pdo, $resolvedClientId, $resolvedTenantId, 0, false);
                $provisioned = (bool)($prov['success'] ?? false);
            } catch (Throwable $e) {
                error_log("hotspot_payment_status provision retry [$resolvedClientId]: " . $e->getMessage());
            }
        }

        if (!$provisioned) {
            echo json_encode([
                'status'  => 'processing',
                'payment_confirmed' => true,
                'message' => 'Payment confirmed. Setting up your connection…',
            ]);
            exit;
        }

        // Re-read credentials — provisioning may have just written them
        $clSt->execute([$resolvedClientId]);
        $client = $clSt->fetch(PDO::FETCH_ASSOC) ?: $client;

        if (empty($client['mikrotik_username']) || empty($client['mikrotik_password'])) {
            echo json_encode([
                'status'  => 'processing',
                'payment_confirmed' => true,
                'message' => 'Payment confirmed. Preparing your credentials…',
            ]);
            exit;
        }

        if (!empty($client['bound_mac_address'])) {
            require_once __DIR__ . '/../../includes/auto_provision.php';
            $tv = $prov ?? autoProvisionClient($pdo, $resolvedClientId, $resolvedTenantId, 0, false);
            echo json_encode(!empty($tv['device_connected'])
                ? ['status'=>'completed','device_only'=>true,'message'=>'Your TV / device is connected.']
                : ['status'=>'processing','payment_confirmed'=>true,'message'=>'Payment received. Keep the TV connected to this Wi-Fi; we are retrying its connection.']);
            exit;
        }

        // Create auto-login token for customer portal
        $portalToken = null;
        try {
            $portalToken = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')")
                ->execute([$resolvedClientId, $portalToken]);
        } catch (Exception $_e) { $portalToken = null; }

        echo json_encode([
            'status'       => 'completed',
            'username'     => $client['mikrotik_username'],
            'password'     => $client['mikrotik_password'],
            'portal_token' => $portalToken,
        ]);
        exit;
    }

    if ($tx['status'] === 'failed') {
        $desc = (string)($tx['result_desc'] ?? '');
        $msg  = str_contains($desc, '1032') ? 'Payment cancelled by user.'
              : (str_contains($desc, '1037') ? 'Request timed out. Please try again.'
              : 'Payment failed. Please try again.');
        echo json_encode(['status' => 'failed', 'message' => $msg]);
        exit;
    }

    echo json_encode(['status' => 'pending', 'message' => 'Waiting for Safaricom confirmation. Do not send another payment.']);
} catch (Throwable $e) {
    error_log('Hotspot payment status: ' . $e->getMessage());
    echo json_encode(['status'=>'processing', 'message'=>'We are checking your payment and connection. Do not pay again.']);
}
