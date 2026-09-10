<?php
require_once __DIR__ . '/payment_pipeline.php';
require_once __DIR__ . '/../classes/MpesaAPI.php';
require_once __DIR__ . '/credential_helper.php';

/** A query confirms the checkout, but need not contain callback receipt metadata. */
function stkConfirmedTerms(array $tx, array $query): array {
    if (!empty($query['error']) || !isset($query['result_code']) || (string)$query['result_code'] !== '0') {
        throw new RuntimeException('Payment is not confirmed by Safaricom');
    }
    $receipt = (string)($tx['mpesa_receipt_number'] ?? '');
    $amount = (float)$tx['amount'];
    foreach ($query['raw']['CallbackMetadata']['Item'] ?? [] as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = (string)$item['Value'];
        if (($item['Name'] ?? '') === 'Amount') $amount = (float)$item['Value'];
    }
    if ($amount <= 0 || empty($tx['checkout_request_id'])) throw new RuntimeException('Missing checkout payment terms');
    return ['amount' => $amount, 'receipt' => $receipt ?: $tx['checkout_request_id']];
}

function stkGateway(PDO $pdo, int $tenant, string $checkout): MpesaAPI {
    $route = $pdo->prepare('SELECT collection_type FROM payments WHERE tenant_id=? AND transaction_id=? LIMIT 1');
    $route->execute([$tenant, $checkout]);
    $collection = $route->fetchColumn();
    $gw = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id=? AND gateway_type='mpesa_api' AND is_active=1 ORDER BY is_default DESC LIMIT 1");
    $gw->execute([$tenant]);
    $credentials = $gw->fetchColumn();
    $credentials = $credentials ? decrypt_gateway_credentials($credentials) : [];
    $own = $collection !== 'platform' && !empty($credentials['consumer_key']) && !empty($credentials['consumer_secret']) && !empty($credentials['shortcode']) && !empty($credentials['passkey']);
    $mpesa = new MpesaAPI($pdo, $own ? $tenant : null);
    if (!$own) {
        $platform = $pdo->query('SELECT * FROM platform_mpesa_config LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($platform) $mpesa->loadFromArray($platform);
    }
    return $mpesa;
}

/** Repair confirmed-but-unactivated payments, and query unresolved checkouts. */
function reconcileCustomerStk(PDO $pdo, array $tx, bool $allowQuery = true): string {
    require_once __DIR__ . '/schema_guard.php';
    ensurePaymentStatusEnums($pdo);
    $checkout = (string)$tx['checkout_request_id'];
    $tenant = (int)$tx['tenant_id'];
    $client = (int)$tx['client_id'];
    if (!$client || !$tenant || !$checkout) return 'pending';
    // Initiating a prompt is not confirmation, even on old rows with result_code=0.
    $confirmed = $tx['status'] === 'completed' && (string)($tx['result_code'] ?? '') === '0';
    $query = ['result_code' => 0, 'raw' => [], 'result_desc' => $tx['result_desc'] ?? 'Confirmed'];
    if (!$confirmed) {
        if ($tx['status'] === 'failed' && !str_contains($tx['result_desc'] ?? '', '15 minutes')) return 'failed';
        if (!$allowQuery || strtotime($tx['created_at']) > time() - 15) return 'pending';
        // Persist throttling across browser requests, login attempts and cron workers.
        $pdo->exec('CREATE TABLE IF NOT EXISTS stk_reconciliation (checkout_id VARCHAR(150) PRIMARY KEY, next_check_at DATETIME NOT NULL) ENGINE=InnoDB');
        $pdo->prepare('INSERT IGNORE INTO stk_reconciliation VALUES (?,NOW())')->execute([$checkout]);
        $claim = $pdo->prepare('UPDATE stk_reconciliation SET next_check_at=DATE_ADD(NOW(),INTERVAL 15 SECOND) WHERE checkout_id=? AND next_check_at<=NOW()');
        $claim->execute([$checkout]);
        if (!$claim->rowCount()) return 'pending';
        $pdo->prepare('UPDATE mpesa_transactions SET updated_at=NOW() WHERE checkout_request_id=? AND tenant_id=?')->execute([$checkout,$tenant]);
        $query = stkGateway($pdo, $tenant, $checkout)->stkQuery($checkout);
        $code = isset($query['result_code']) ? (int)$query['result_code'] : -1;
        if (!empty($query['error']) || $code === -1 || $code === 1025) return 'pending';
        if ($code !== 0) {
            // Only explicit payment outcomes are terminal. API failures are not evidence of non-payment.
            if (!in_array($code, [1, 1019, 1032, 1037, 2001], true)) return 'pending';
            $pdo->prepare("UPDATE mpesa_transactions SET status='failed',result_code=?,result_desc=?,updated_at=NOW() WHERE checkout_request_id=? AND tenant_id=? AND status<>'completed'")->execute([$code, $query['result_desc'] ?? 'Payment not completed', $checkout, $tenant]);
            return 'failed';
        }
    }
    $terms = stkConfirmedTerms($tx, $query);
    // Record Safaricom's confirmation before fallible provisioning. Completed rows
    // remain eligible for repair until their activation exists.
    $pdo->prepare("UPDATE mpesa_transactions SET status='completed',result_code=0,result_desc=?,updated_at=NOW() WHERE checkout_request_id=? AND tenant_id=?")->execute([$query['result_desc'] ?? 'Confirmed by STK query', $checkout, $tenant]);
    $applied = false;
    try {
        $check = $pdo->prepare('SELECT 1 FROM payment_activations WHERE tenant_id=? AND client_id=? AND activation_key=?');
        $check->execute([$tenant, $client, $checkout]);
        $applied = (bool)$check->fetchColumn();
    } catch (PDOException $e) { if (($e->errorInfo[1] ?? null) !== 1146) throw $e; }
    // Old completed payments may predate activation keys. A completed payment
    // with an existing expiry is not a new purchase to replay at today's time.
    if (!$applied && $confirmed) {
        $legacy = $pdo->prepare("SELECT c.expiry_date FROM clients c JOIN payments p ON p.client_id=c.id AND p.tenant_id=c.tenant_id
            WHERE c.id=? AND c.tenant_id=? AND p.status='completed' AND p.transaction_id IN (?,?) AND c.expiry_date IS NOT NULL LIMIT 1");
        $legacy->execute([$client, $tenant, $checkout, $terms['receipt']]);
        if ($legacy->fetchColumn()) return 'completed';
    }
    $paymentCheck = $pdo->prepare("SELECT 1 FROM payments WHERE tenant_id=? AND client_id=? AND status='completed' AND transaction_id IN (?,?)");
    $paymentCheck->execute([$tenant,$client,$checkout,$terms['receipt']]);
    if (!$applied || !$paymentCheck->fetchColumn()) process_payment_success($pdo, $client, $tenant, $terms['amount'], $terms['receipt'], 'mpesa_stk', null, null, $checkout);
    else {
        $pdo->prepare("UPDATE payments SET payment_method='mpesa_stk' WHERE tenant_id=? AND client_id=? AND transaction_id IN (?,?)")->execute([$tenant, $client, $checkout, $terms['receipt']]);
    }
    return 'completed';
}

function recoverCustomerPayments(PDO $pdo, int $client, int $tenant): bool {
    $stmt = $pdo->prepare("SELECT * FROM mpesa_transactions WHERE client_id=? AND tenant_id=? AND checkout_request_id IS NOT NULL AND (status IN ('pending','completed') OR result_desc LIKE '%15 minutes%') ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$client, $tenant]);
    $pending = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        try { $pending = reconcileCustomerStk($pdo, $tx) === 'pending' || $pending; }
        catch (Throwable $e) { $pending = true; error_log('STK recovery: ' . $e->getMessage()); }
    }
    return $pending;
}
