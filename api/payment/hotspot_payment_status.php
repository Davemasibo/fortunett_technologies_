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
            SELECT mt.status, mt.result_desc, mt.client_id, mt.tenant_id, mt.created_at, mt.amount
            FROM mpesa_transactions mt
            WHERE mt.checkout_request_id = ? AND mt.client_id = ?
            LIMIT 1
        ");
        $txSt->execute([$checkoutId, $clientId]);
    } elseif ($tenantId) {
        $txSt = $pdo->prepare("
            SELECT mt.status, mt.result_desc, mt.client_id, mt.tenant_id, mt.created_at, mt.amount
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
        // Transaction not yet written — callback hasn't arrived yet
        echo json_encode(['status' => 'pending']);
        exit;
    }

    if ($tx['status'] === 'completed') {
        $resolvedClientId = $clientId ?: (int)$tx['client_id'];

        // Fetch client credentials
        $clSt = $pdo->prepare("
            SELECT mikrotik_username, mikrotik_password, status, account_number, tenant_id
            FROM clients WHERE id = ? LIMIT 1
        ");
        $clSt->execute([$resolvedClientId]);
        $client = $clSt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            echo json_encode(['status' => 'pending']);
            exit;
        }

        // Activate if callback hasn't done it yet
        if ($client['status'] === 'pending' || $client['status'] === 'inactive') {
            try {
                $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?")
                    ->execute([$resolvedClientId]);
                $client['status'] = 'active';
            } catch (Throwable $_e) {}
        }

        // ── Do not hand out credentials before the router knows them ──────────
        // callback.php flips mpesa_transactions.status to 'completed' BEFORE the
        // pipeline provisions the router. Returning credentials in that window
        // made the portal auto-submit a login for a hotspot user that did not
        // exist yet — RouterOS rejected it and the customer landed back on the
        // sign-in page, which is exactly the "not authenticated automatically"
        // complaint. Report 'processing' until provisioning is confirmed.
        $resolvedTenantId = (int)($client['tenant_id'] ?? $tx['tenant_id'] ?? 0);
        $provisioned = false;
        try {
            $psSt = $pdo->prepare("
                SELECT 1 FROM router_services
                WHERE client_id = ? AND status = 'active' LIMIT 1
            ");
            $psSt->execute([$resolvedClientId]);
            $provisioned = (bool)$psSt->fetchColumn();
        } catch (Throwable $_e) {
            // router_services missing on this deployment — fall back to assuming
            // the pipeline handled it rather than blocking the customer forever.
            $provisioned = true;
        }

        if (!$provisioned && $resolvedTenantId) {
            // The pipeline may still be running, or the router was briefly
            // unreachable. autoProvisionClient() is idempotent, so retrying here
            // is safe and usually completes on the first poll.
            try {
                require_once __DIR__ . '/../../includes/auto_provision.php';
                $prov = autoProvisionClient($pdo, $resolvedClientId, $resolvedTenantId);
                $provisioned = (bool)($prov['success'] ?? false);
            } catch (Throwable $e) {
                error_log("hotspot_payment_status provision retry [$resolvedClientId]: " . $e->getMessage());
            }
        }

        if (!$provisioned) {
            echo json_encode([
                'status'  => 'processing',
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
                'message' => 'Payment confirmed. Preparing your credentials…',
            ]);
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

    // ── Still pending: ask Safaricom directly rather than waiting on a callback ─
    //
    // The callback is not guaranteed to arrive — a WAF/CDN in front of the
    // callback URL, a transient Safaricom failure, or a customer who simply
    // cancels all leave the row 'pending' forever, and the portal spun for the
    // full five minutes with no explanation. stkpushquery gives the authoritative
    // outcome, including 1032 "cancelled by user", within seconds.
    //
    // cron/stk_poll.php does the same thing every 2 minutes as a backstop, but a
    // customer standing at the router should not wait 2 minutes to be told they
    // cancelled.
    $createdAt = strtotime($tx['created_at'] ?? 'now');
    $ageSec    = time() - $createdAt;

    // Give the real callback ~25s first, then query at most once every ~12s.
    if ($ageSec >= 25 && ($ageSec % 12) < 5) {
        try {
            require_once __DIR__ . '/../../classes/MpesaAPI.php';
            require_once __DIR__ . '/../../includes/credential_helper.php';

            $txTenantId = (int)($tx['tenant_id'] ?? 0);
            $hasTenantCreds = false;
            if ($txTenantId) {
                $gwCheck = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type='mpesa_api' AND is_active=1 ORDER BY is_default DESC LIMIT 1");
                $gwCheck->execute([$txTenantId]);
                if ($gwRow = $gwCheck->fetch(PDO::FETCH_ASSOC)) {
                    $gwCreds = decrypt_gateway_credentials($gwRow['credentials']);
                    $hasTenantCreds = !empty($gwCreds['consumer_key']) && !empty($gwCreds['consumer_secret'])
                                   && !empty($gwCreds['passkey']) && !empty($gwCreds['shortcode']);
                }
            }

            $mpesa = new MpesaAPI($pdo, $hasTenantCreds ? $txTenantId : null);
            if (!$hasTenantCreds) {
                $plSt = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1");
                $platCreds = $plSt ? $plSt->fetch(PDO::FETCH_ASSOC) : null;
                if ($platCreds && !empty($platCreds['consumer_key'])) {
                    $mpesa->loadFromArray($platCreds);
                }
            }

            $q    = $mpesa->stkQuery($checkoutId);
            $code = (int)($q['result_code'] ?? -1);

            // 1025 = still being processed, -1 = our own lookup failed. Keep waiting.
            if ($code !== 1025 && $code !== -1) {
                if ($code === 0) {
                    // Paid. Let the pipeline do the real work, then report next poll —
                    // this keeps activation logic in exactly one place.
                    $pdo->prepare("
                        UPDATE mpesa_transactions
                        SET status = 'completed', result_code = ?, result_desc = ?, updated_at = NOW()
                        WHERE checkout_request_id = ?
                    ")->execute([$code, $q['result_desc'] ?? 'Confirmed via stkQuery', $checkoutId]);

                    try {
                        require_once __DIR__ . '/../../includes/payment_pipeline.php';
                        $cl = $pdo->prepare("SELECT tenant_id, package_id FROM clients WHERE id = ? LIMIT 1");
                        $cl->execute([(int)$tx['client_id']]);
                        if ($clRow = $cl->fetch(PDO::FETCH_ASSOC)) {
                            // $hasTenantCreds above is the same test that chose
                            // the credentials this stkQuery ran against, so it is
                            // the truth about whose till received the money. The
                            // literal `false` that used to sit here booked every
                            // platform-collected hotspot payment as 'direct' —
                            // overwriting the correct tag hotspot_stk_push.php had
                            // already written on the pending row — so the ISP was
                            // shown as already holding cash FortuNett had, and no
                            // payout was ever queued for it.
                            process_payment_success(
                                $pdo, (int)$tx['client_id'], (int)$clRow['tenant_id'],
                                (float)($tx['amount'] ?? 0), $checkoutId, 'mpesa_stk',
                                $clRow['package_id'] ? (int)$clRow['package_id'] : null,
                                !$hasTenantCreds
                            );
                        }
                    } catch (Throwable $e) {
                        error_log("stkQuery pipeline [$checkoutId]: " . $e->getMessage());
                    }

                    echo json_encode(['status' => 'processing', 'message' => 'Payment confirmed. Setting up your connection…']);
                    exit;
                }

                // Anything else is terminal — tell the customer which, by name.
                $reasons = [
                    1032 => 'You cancelled the payment request.',
                    1037 => 'The request timed out — you did not enter your PIN in time.',
                    1019 => 'The payment request expired. Please try again.',
                    2001 => 'Wrong M-Pesa PIN entered.',
                    1    => 'Insufficient M-Pesa balance.',
                ];
                $msg = $reasons[$code] ?? ('Payment was not completed. ' . ($q['result_desc'] ?? ''));

                $pdo->prepare("
                    UPDATE mpesa_transactions
                    SET status = 'failed', result_code = ?, result_desc = ?, updated_at = NOW()
                    WHERE checkout_request_id = ?
                ")->execute([$code, $q['result_desc'] ?? 'Failed via stkQuery', $checkoutId]);

                $pdo->prepare("UPDATE payments SET status = 'failed' WHERE transaction_id = ? AND status = 'pending'")
                    ->execute([$checkoutId]);

                echo json_encode(['status' => 'failed', 'message' => $msg]);
                exit;
            }
        } catch (Throwable $e) {
            error_log("hotspot_payment_status stkQuery [$checkoutId]: " . $e->getMessage());
            // Fall through and keep polling
        }
    }

    echo json_encode(['status' => 'pending']);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error.']);
}
