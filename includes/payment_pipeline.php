<?php
/**
 * Payment Pipeline
 *
 * Single entry-point called by every payment callback (STK push, C2B platform,
 * C2B tenant-own) after money is confirmed received by Safaricom.
 *
 * Steps (all post-subscription steps are best-effort — one failure never
 * blocks the others, and we always return ResultCode 0 to Safaricom):
 *
 *  1. Load client + package
 *  2. Extend subscription
 *  3. Mark/upsert payment as completed
 *  4. Mark client invoice paid (create one if none pending)
 *  5. Write ledger entries (debit cash, credit revenue)
 *  6. Record platform commission (hotspot % only; PPPoE billed monthly)
 *  7. Queue ISP payout (platform-collected payments only)
 *  8. Sync RADIUS
 *  9. Send SMS to customer
 * 10. Auto-provision on MikroTik router
 * 11. Write activity log
 *
 * Idempotent: safe to call twice for the same receipt — each step checks
 * whether it already ran before writing.
 */

require_once __DIR__ . '/auto_provision.php';
require_once __DIR__ . '/radius_client.php';

/**
 * @param PDO    $pdo
 * @param int    $clientId
 * @param int    $tenantId
 * @param float  $amount           Amount received (KSH)
 * @param string $receipt          M-Pesa receipt / TransID — idempotency key
 * @param string $paymentMethod    'mpesa' | 'mpesa_paybill' | 'mpesa_c2b_tenant'
 * @param int|null $packageId      Package to extend; NULL = use client's current package
 * @param bool   $platformCollected TRUE when FortuNett's shared paybill collected the
 *                                  money (payout to ISP is required). FALSE for
 *                                  tenant-own paybill (ISP already has the money).
 * @return array  ['expiry_date'=>..., 'steps'=>['radius'=>bool, 'sms'=>bool, ...]]
 */
function process_payment_success(
    PDO    $pdo,
    int    $clientId,
    int    $tenantId,
    float  $amount,
    string $receipt,
    string $paymentMethod   = 'mpesa',
    ?int   $packageId       = null,
    bool   $platformCollected = false
): array {
    $results = [
        'expiry_date'  => null,
        'client_name'  => '',
        'phone'        => '',
        'package_name' => '',
        'steps'        => [],
    ];

    // ── 1. Load client + package ───────────────────────────────────────────────
    $cSt = $pdo->prepare("
        SELECT c.id, c.full_name, c.name, c.phone, c.status,
               c.expiry_date, c.connection_type,
               c.mikrotik_username, c.mikrotik_password,
               COALESCE(?, c.package_id) AS resolved_package_id
        FROM clients c
        WHERE c.id = ? AND c.tenant_id = ?
        LIMIT 1
    ");
    $cSt->execute([$packageId, $clientId, $tenantId]);
    $client = $cSt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        error_log("payment_pipeline: client $clientId not found for tenant $tenantId (receipt $receipt)");
        return $results;
    }

    $resolvedPackageId = (int)$client['resolved_package_id'];

    $package = null;
    if ($resolvedPackageId) {
        $pkgSt = $pdo->prepare("
            SELECT id, name, validity_value, validity_unit,
                   download_speed, upload_speed, mikrotik_profile,
                   COALESCE(NULLIF(type,''), connection_type, 'hotspot') AS conn_type
            FROM packages WHERE id = ? AND tenant_id = ?
        ");
        $pkgSt->execute([$resolvedPackageId, $tenantId]);
        $package = $pkgSt->fetch(PDO::FETCH_ASSOC);
    }

    $results['client_name']  = $client['full_name'] ?: ($client['name'] ?? '');
    $results['phone']        = $client['phone'] ?? '';
    $results['package_name'] = $package['name'] ?? 'Subscription';

    // ── 2. Extend subscription ─────────────────────────────────────────────────
    $expiryDate = null;
    if ($package) {
        $valVal   = max(1, (int)($package['validity_value'] ?? 30));
        $valUnit  = in_array($package['validity_unit'] ?? '', ['hours','days','weeks','months'], true)
                  ? $package['validity_unit'] : 'days';
        // Extend from now if expired; extend from current expiry if still active
        $base = $client['expiry_date'] && strtotime($client['expiry_date']) > time()
              ? $client['expiry_date'] : 'now';
        $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $valVal . ' ' . $valUnit, strtotime($base)));

        $pdo->prepare("
            UPDATE clients SET status = 'active', expiry_date = ?,
                expiry_reminder_3d_sent = 0, expiry_reminder_1d_sent = 0
            WHERE id = ? AND tenant_id = ?
        ")->execute([$expiryDate, $clientId, $tenantId]);
    } else {
        // No package — at least mark the account active
        $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ? AND tenant_id = ?")
            ->execute([$clientId, $tenantId]);
    }
    $results['expiry_date'] = $expiryDate;

    // ── 3. Mark payment as completed ───────────────────────────────────────────
    $paymentId = null;
    try {
        // Try to find an existing pending row by checkout_request_id / receipt
        $pSt = $pdo->prepare("
            SELECT id FROM payments
            WHERE (transaction_id = ? OR transaction_id = ?) AND client_id = ?
            LIMIT 1
        ");
        $pSt->execute([$receipt, $receipt, $clientId]);
        $paymentId = $pSt->fetchColumn() ?: null;

        if ($paymentId) {
            $pdo->prepare("
                UPDATE payments SET status = 'completed', transaction_id = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$receipt, $paymentId]);
        } else {
            $pdo->prepare("
                INSERT INTO payments
                    (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date)
                VALUES (?, ?, ?, ?, ?, 'completed', NOW())
            ")->execute([$clientId, $tenantId, $amount, $paymentMethod, $receipt]);
            $paymentId = (int)$pdo->lastInsertId();
        }
        $results['steps']['payment'] = true;
    } catch (Throwable $e) {
        error_log("pipeline payment record [$receipt]: " . $e->getMessage());
        $results['steps']['payment'] = false;
    }

    // ── 4. Client invoice ──────────────────────────────────────────────────────
    try {
        _pipeline_ensure_tables($pdo);

        // Find an open invoice for this client, or create one and immediately close it
        $invSt = $pdo->prepare("
            SELECT id FROM client_invoices
            WHERE invoice_number = ? LIMIT 1
        ");
        // Invoice number derived from receipt — globally unique, idempotent
        $invoiceNumber = 'INV-' . substr(strtoupper(preg_replace('/[^A-Z0-9]/i', '', $receipt)), 0, 20)
                       . '-' . $tenantId;
        $invSt->execute([$invoiceNumber]);
        if (!$invSt->fetchColumn()) {
            $desc = ($package['name'] ?? 'Internet') . ' — '
                  . ($expiryDate ? 'valid to ' . date('d M Y', strtotime($expiryDate)) : date('d M Y'));
            $pdo->prepare("
                INSERT INTO client_invoices
                    (tenant_id, client_id, payment_id, invoice_number, amount, description, status, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
            ")->execute([$tenantId, $clientId, $paymentId, $invoiceNumber, $amount, $desc]);
        } else {
            // Already exists — mark paid (idempotent)
            $pdo->prepare("
                UPDATE client_invoices SET status = 'paid', payment_id = ?, paid_at = NOW()
                WHERE invoice_number = ? AND status != 'paid'
            ")->execute([$paymentId, $invoiceNumber]);
        }
        $results['steps']['invoice'] = true;
    } catch (Throwable $e) {
        error_log("pipeline invoice [$receipt]: " . $e->getMessage());
        $results['steps']['invoice'] = false;
    }

    // ── 5. Ledger entries (debit cash received / credit revenue) ───────────────
    try {
        // Skip if already written for this receipt
        $ledgerCheck = $pdo->prepare("
            SELECT 1 FROM ledger_entries WHERE reference = ? AND tenant_id = ? LIMIT 1
        ");
        $ledgerCheck->execute([$receipt, $tenantId]);
        if (!$ledgerCheck->fetchColumn()) {
            $desc = 'M-Pesa ' . $receipt . ' — ' . ($package['name'] ?? 'Subscription');
            $pdo->prepare("
                INSERT INTO ledger_entries
                    (tenant_id, client_id, payment_id, entry_type, account, amount, description, reference)
                VALUES
                    (?, ?, ?, 'debit',  'cash',    ?, ?, ?),
                    (?, ?, ?, 'credit', 'revenue', ?, ?, ?)
            ")->execute([
                $tenantId, $clientId, $paymentId, $amount, $desc, $receipt,
                $tenantId, $clientId, $paymentId, $amount, $desc, $receipt,
            ]);
        }
        $results['steps']['ledger'] = true;
    } catch (Throwable $e) {
        error_log("pipeline ledger [$receipt]: " . $e->getMessage());
        $results['steps']['ledger'] = false;
    }

    // ── 6. Platform commission (hotspot payments only) ─────────────────────────
    // PPPoE users are billed a flat per-user monthly fee via cron/monthly_billing.php.
    // Only hotspot revenue carries a per-payment commission.
    $commissionAmount = 0.0;
    $commissionRate   = 0.0;
    try {
        if ($paymentId) {
            $connType = strtolower($client['connection_type'] ?? ($package['conn_type'] ?? 'hotspot'));

            if ($connType === 'hotspot') {
                // Fetch tenant's plan rate
                $rateSt = $pdo->prepare("
                    SELECT COALESCE(p.hotspot_commission_rate, 0.03) AS rate
                    FROM tenants t
                    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
                    WHERE t.id = ? LIMIT 1
                ");
                $rateSt->execute([$tenantId]);
                $commissionRate   = (float)($rateSt->fetchColumn() ?: 0.03);
                $commissionAmount = round($amount * $commissionRate, 2);
                $netAmount        = round($amount - $commissionAmount, 2);

                $pdo->prepare("
                    INSERT INTO platform_commissions
                        (tenant_id, client_id, payment_id, connection_type, gross_amount,
                         commission_rate, commission_amount, net_amount, receipt)
                    VALUES (?, ?, ?, 'hotspot', ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        commission_rate = VALUES(commission_rate),
                        commission_amount = VALUES(commission_amount),
                        net_amount = VALUES(net_amount)
                ")->execute([
                    $tenantId, $clientId, $paymentId, $amount,
                    $commissionRate, $commissionAmount, $netAmount, $receipt,
                ]);

                // Ledger: debit revenue for commission, credit platform-payable
                $pdo->prepare("
                    INSERT INTO ledger_entries
                        (tenant_id, client_id, payment_id, entry_type, account, amount, description, reference)
                    VALUES
                        (?, ?, ?, 'debit',  'revenue',    ?, ?, ?),
                        (?, ?, ?, 'credit', 'commission', ?, ?, ?)
                ")->execute([
                    $tenantId, $clientId, $paymentId,
                    $commissionAmount, 'Platform commission ' . round($commissionRate * 100, 1) . '%', $receipt,
                    $tenantId, $clientId, $paymentId,
                    $commissionAmount, 'Platform commission payable', $receipt,
                ]);
            }
        }
        $results['steps']['commission'] = true;
    } catch (Throwable $e) {
        error_log("pipeline commission [$receipt]: " . $e->getMessage());
        $results['steps']['commission'] = false;
    }

    // ── 7. ISP payout queue (platform-collected only) ─────────────────────────
    // When FortuNett's shared paybill received the money, we owe the ISP
    // (amount - commission). Queue for weekly/monthly disbursement.
    try {
        if ($platformCollected && $paymentId) {
            $netPayout = round($amount - $commissionAmount, 2);
            // Payout typically processed within 3 business days
            $scheduledFor = date('Y-m-d', strtotime('+3 days'));

            $pdo->prepare("
                INSERT INTO isp_payout_queue
                    (tenant_id, payment_id, gross_amount, commission_amount, net_amount, receipt, scheduled_for)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    gross_amount      = VALUES(gross_amount),
                    commission_amount = VALUES(commission_amount),
                    net_amount        = VALUES(net_amount)
            ")->execute([
                $tenantId, $paymentId, $amount, $commissionAmount, $netPayout,
                $receipt, $scheduledFor,
            ]);

            // Ledger: debit ISP-payable, credit payout-queue
            $pdo->prepare("
                INSERT INTO ledger_entries
                    (tenant_id, client_id, payment_id, entry_type, account, amount, description, reference)
                VALUES
                    (?, ?, ?, 'debit',  'isp_payable',   ?, ?, ?),
                    (?, ?, ?, 'credit', 'payout_queued', ?, ?, ?)
            ")->execute([
                $tenantId, $clientId, $paymentId,
                $netPayout, 'ISP payout queued for ' . $scheduledFor, $receipt,
                $tenantId, $clientId, $paymentId,
                $netPayout, 'ISP payout queued for ' . $scheduledFor, $receipt,
            ]);
        }
        $results['steps']['payout_queue'] = true;
    } catch (Throwable $e) {
        error_log("pipeline payout_queue [$receipt]: " . $e->getMessage());
        $results['steps']['payout_queue'] = false;
    }

    // ── 8. RADIUS sync ─────────────────────────────────────────────────────────
    try {
        if ($package && !empty($client['mikrotik_username']) && !empty($client['mikrotik_password'])) {
            $results['steps']['radius'] = radius_sync_client($pdo, [
                'mikrotik_username' => $client['mikrotik_username'],
                'mikrotik_password' => $client['mikrotik_password'],
            ], $package);
        } else {
            $results['steps']['radius'] = false;
        }
    } catch (Throwable $e) {
        error_log("pipeline radius [$receipt]: " . $e->getMessage());
        $results['steps']['radius'] = false;
    }

    // ── 9. SMS notification ────────────────────────────────────────────────────
    try {
        if (!empty($client['phone'])) {
            // Avoid duplicate SMS: check sms_logs if table exists
            $alreadySent = false;
            try {
                $smsCk = $pdo->prepare("
                    SELECT 1 FROM sms_logs
                    WHERE client_id = ? AND tenant_id = ? AND reference = ? LIMIT 1
                ");
                $smsCk->execute([$clientId, $tenantId, $receipt]);
                $alreadySent = (bool)$smsCk->fetchColumn();
            } catch (Throwable $_e) { /* table may not exist */ }

            if (!$alreadySent) {
                $expiryHuman = $expiryDate ? date('d M Y H:i', strtotime($expiryDate)) : 'N/A';
                $pkgName     = $package['name'] ?? 'your plan';
                $firstName   = explode(' ', trim($results['client_name']))[0];
                $msg = "Hi {$firstName}, KSH " . number_format($amount, 2)
                     . " received. {$pkgName} active until {$expiryHuman}. Ref: {$receipt}. Thank you!";

                require_once __DIR__ . '/../classes/SMSHelper.php';
                $sms = new SMSHelper($pdo, $tenantId);
                $smsResult = $sms->send($client['phone'], $msg, $clientId);
                $results['steps']['sms'] = $smsResult['success'] ?? false;

                // Record in sms_logs if the table exists (best-effort)
                try {
                    $pdo->prepare("
                        INSERT INTO sms_logs (client_id, tenant_id, phone, message, status, reference)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $clientId, $tenantId, $client['phone'], $msg,
                        ($smsResult['success'] ?? false) ? 'sent' : 'failed',
                        $receipt,
                    ]);
                } catch (Throwable $_e) { /* sms_logs may not exist */ }
            } else {
                $results['steps']['sms'] = 'already_sent';
            }
        } else {
            $results['steps']['sms'] = 'no_phone';
        }
    } catch (Throwable $e) {
        error_log("pipeline sms [$receipt]: " . $e->getMessage());
        $results['steps']['sms'] = false;
    }

    // ── 10. Auto-provision on MikroTik ─────────────────────────────────────────
    try {
        if ($resolvedPackageId) {
            $provResult = autoProvisionClient($pdo, $clientId, $tenantId);
            $results['steps']['provision'] = $provResult['success'] ?? false;

            // Queue for retry if provisioning failed (router unreachable, etc.)
            if (!($provResult['success'] ?? false)) {
                $failReason = $provResult['message'] ?? 'unknown';
                try {
                    $pdo->prepare("
                        INSERT INTO pending_provisions
                            (tenant_id, client_id, package_id, receipt, fail_reason)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            package_id  = VALUES(package_id),
                            receipt     = VALUES(receipt),
                            fail_reason = VALUES(fail_reason),
                            attempts    = attempts + 1,
                            next_retry_at = NOW() + INTERVAL 5 MINUTE
                    ")->execute([$tenantId, $clientId, $resolvedPackageId, $receipt, $failReason]);
                } catch (Throwable $_pe) {
                    error_log("pipeline: could not queue pending provision for client $clientId: " . $_pe->getMessage());
                }
            } else {
                // Clear any queued retry on success
                try {
                    $pdo->prepare("DELETE FROM pending_provisions WHERE client_id = ? AND tenant_id = ?")->execute([$clientId, $tenantId]);
                } catch (Throwable $_) {}
            }
        } else {
            $results['steps']['provision'] = false;
        }
    } catch (Throwable $e) {
        error_log("pipeline provision [$receipt]: " . $e->getMessage());
        $results['steps']['provision'] = false;
    }

    // ── 11. Activity log ───────────────────────────────────────────────────────
    try {
        $expiryStr = $expiryDate ? ' until ' . date('d M Y', strtotime($expiryDate)) : '';
        $pdo->prepare("
            INSERT INTO customer_activity_log
                (client_id, tenant_id, activity_type, description)
            VALUES (?, ?, 'payment_success', ?)
        ")->execute([
            $clientId, $tenantId,
            'Payment KSH ' . number_format($amount, 2) . ' ref ' . $receipt
            . ($package ? ' — ' . $package['name'] : '')
            . $expiryStr,
        ]);
        $results['steps']['activity_log'] = true;
    } catch (Throwable $e) {
        error_log("pipeline activity_log [$receipt]: " . $e->getMessage());
        $results['steps']['activity_log'] = false;
    }

    return $results;
}

/**
 * Ensure the four pipeline tables exist (CREATE IF NOT EXISTS).
 * Runs once per request; subsequent calls are no-ops due to the static flag.
 * This makes the pipeline self-installing — no manual migration required
 * before the first payment fires.
 */
function _pipeline_ensure_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_invoices (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id      INT           NOT NULL,
            client_id      INT           NOT NULL,
            payment_id     INT           DEFAULT NULL,
            invoice_number VARCHAR(40)   NOT NULL,
            amount         DECIMAL(10,2) NOT NULL,
            description    VARCHAR(255)  DEFAULT NULL,
            status         ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
            due_date       DATE          DEFAULT NULL,
            paid_at        TIMESTAMP     NULL DEFAULT NULL,
            created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_invoice_number (invoice_number),
            INDEX idx_client  (client_id, tenant_id),
            INDEX idx_payment (payment_id),
            INDEX idx_status  (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ledger_entries (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id   INT           NOT NULL,
            client_id   INT           DEFAULT NULL,
            payment_id  INT           DEFAULT NULL,
            entry_type  ENUM('debit','credit') NOT NULL,
            account     VARCHAR(50)   NOT NULL,
            amount      DECIMAL(10,2) NOT NULL,
            description VARCHAR(255)  DEFAULT NULL,
            reference   VARCHAR(100)  DEFAULT NULL,
            created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant    (tenant_id),
            INDEX idx_payment   (payment_id),
            INDEX idx_reference (reference)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS platform_commissions (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id         INT           NOT NULL,
            client_id         INT           NOT NULL,
            payment_id        INT           NOT NULL,
            connection_type   ENUM('hotspot','pppoe') NOT NULL DEFAULT 'hotspot',
            gross_amount      DECIMAL(10,2) NOT NULL,
            commission_rate   DECIMAL(6,5)  NOT NULL DEFAULT 0.03000,
            commission_amount DECIMAL(10,2) NOT NULL,
            net_amount        DECIMAL(10,2) NOT NULL,
            receipt           VARCHAR(50)   DEFAULT NULL,
            collected         TINYINT(1)    NOT NULL DEFAULT 0,
            created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment  (payment_id),
            INDEX idx_tenant   (tenant_id),
            INDEX idx_collected (collected)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS isp_payout_queue (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id         INT           NOT NULL,
            payment_id        INT           NOT NULL,
            gross_amount      DECIMAL(10,2) NOT NULL,
            commission_amount DECIMAL(10,2) NOT NULL,
            net_amount        DECIMAL(10,2) NOT NULL,
            receipt           VARCHAR(50)   DEFAULT NULL,
            status            ENUM('pending','processing','paid','cancelled') NOT NULL DEFAULT 'pending',
            scheduled_for     DATE          DEFAULT NULL,
            processed_at      TIMESTAMP     NULL DEFAULT NULL,
            notes             TEXT          DEFAULT NULL,
            created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment  (payment_id),
            INDEX idx_tenant   (tenant_id),
            INDEX idx_status   (status),
            INDEX idx_schedule (scheduled_for)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
