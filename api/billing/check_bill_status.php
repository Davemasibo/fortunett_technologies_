<?php
/**
 * POST /api/billing/check_bill_status.php
 *
 * Polled by the tenant billing page after an STK push to settle a platform
 * invoice. Speaks the same contract as the hotspot portal's status endpoint so
 * both can be driven by the shared js/stk-push.js component:
 *
 *   {status: 'pending' | 'completed' | 'failed', message?}
 *
 * Two things this used to get wrong:
 *   - it read tenant_bills, which nothing enforces, instead of platform_invoices
 *   - it could only ever say "not paid yet", so a customer who cancelled the
 *     prompt watched a spinner until the page gave up
 *
 * `paid` is still returned alongside `status` for any older caller.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'failed', 'paid' => false, 'message' => 'Your session expired. Please sign in again.']);
    exit;
}

$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$_SESSION['user_id']]);
$tenantId = (int)$uStmt->fetchColumn();

$billId     = (int)($_POST['bill_id'] ?? 0);
$checkoutId = trim($_POST['checkout_id'] ?? '');

if (!$tenantId) {
    echo json_encode(['status' => 'failed', 'paid' => false, 'message' => 'No tenant on this account.']);
    exit;
}

try {
    // ── Settled already? ─────────────────────────────────────────────────────
    if ($billId) {
        $st = $pdo->prepare("SELECT status FROM platform_invoices WHERE id = ? AND tenant_id = ? LIMIT 1");
        $st->execute([$billId, $tenantId]);
        if ($st->fetchColumn() === 'paid') {
            echo json_encode(['status' => 'completed', 'paid' => true]);
            exit;
        }
    }

    // Nothing outstanding at all also counts as done — the payment may have been
    // allocated across several invoices rather than the one we were watching.
    $owe = $pdo->prepare("SELECT COUNT(*) FROM platform_invoices WHERE tenant_id = ? AND status <> 'paid'");
    $owe->execute([$tenantId]);
    if ((int)$owe->fetchColumn() === 0) {
        echo json_encode(['status' => 'completed', 'paid' => true]);
        exit;
    }

    if ($checkoutId === '') {
        echo json_encode(['status' => 'pending', 'paid' => false]);
        exit;
    }

    // ── Transaction state ────────────────────────────────────────────────────
    $tx = null;
    try {
        $txSt = $pdo->prepare("
            SELECT status, result_code, result_desc, created_at, amount
            FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1
        ");
        $txSt->execute([$checkoutId]);
        $tx = $txSt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $_e) {}

    $reasons = [
        1032 => 'You cancelled the payment request.',
        1037 => 'The request timed out — you did not enter your PIN in time.',
        1019 => 'The payment request expired. Please try again.',
        2001 => 'Wrong M-Pesa PIN entered.',
        1    => 'Insufficient M-Pesa balance.',
    ];

    if ($tx && $tx['status'] === 'failed') {
        $code = (int)($tx['result_code'] ?? -1);
        echo json_encode([
            'status'  => 'failed',
            'paid'    => false,
            'message' => $reasons[$code] ?? 'The payment was not completed. You can try again.',
        ]);
        exit;
    }

    // ── Ask Safaricom directly once the callback is overdue ──────────────────
    // Same reasoning as the captive portal: the callback may never arrive, and a
    // cancellation is only ever visible through stkpushquery.
    $ageSec = $tx ? (time() - strtotime($tx['created_at'])) : 0;
    if ($tx && $ageSec >= 25 && ($ageSec % 12) < 6) {
        try {
            require_once __DIR__ . '/../../classes/MpesaAPI.php';
            require_once __DIR__ . '/../../includes/credential_helper.php';
            require_once __DIR__ . '/../../includes/platform_billing.php';

            // Platform invoices are always paid into FortuNett's own shortcode
            $mpesa = new MpesaAPI($pdo, null);
            $plSt  = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1");
            $plat  = $plSt ? $plSt->fetch(PDO::FETCH_ASSOC) : null;
            if ($plat && !empty($plat['consumer_key'])) {
                $mpesa->loadFromArray($plat);
            }

            $q    = $mpesa->stkQuery($checkoutId);
            $code = (int)($q['result_code'] ?? -1);

            if ($code === 0) {
                $pdo->prepare("UPDATE mpesa_transactions SET status='completed', result_code=?, result_desc=?, updated_at=NOW() WHERE checkout_request_id=?")
                    ->execute([$code, $q['result_desc'] ?? 'Confirmed via stkQuery', $checkoutId]);

                $res = applyPlatformPayment(
                    $pdo, $tenantId, (float)($tx['amount'] ?? 0), $checkoutId,
                    '', 'STK', 'stk', ''
                );
                echo json_encode(['status' => 'completed', 'paid' => true, 'message' => $res['message']]);
                exit;
            }

            if ($code !== 1025 && $code !== -1) {
                $pdo->prepare("UPDATE mpesa_transactions SET status='failed', result_code=?, result_desc=?, updated_at=NOW() WHERE checkout_request_id=?")
                    ->execute([$code, $q['result_desc'] ?? 'Failed via stkQuery', $checkoutId]);
                echo json_encode([
                    'status'  => 'failed',
                    'paid'    => false,
                    'message' => $reasons[$code] ?? ('Payment was not completed. ' . ($q['result_desc'] ?? '')),
                ]);
                exit;
            }
        } catch (Throwable $e) {
            error_log('check_bill_status stkQuery: ' . $e->getMessage());
        }
    }

    echo json_encode(['status' => 'pending', 'paid' => false]);

} catch (Throwable $e) {
    error_log('check_bill_status: ' . $e->getMessage());
    echo json_encode(['status' => 'pending', 'paid' => false]);
}
