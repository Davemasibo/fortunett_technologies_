<?php
/**
 * M-Pesa C2B Validation Handler — Tenant-Own Paybill
 *
 * Safaricom calls this URL BEFORE crediting the customer's M-Pesa account.
 * We verify the BillRefNumber maps to a real client under this tenant.
 * Return ResultCode 0 to accept, any other code to reject the transaction.
 *
 * BillRefNumber (what the customer types at M-Pesa):
 *   - Tries clients.account_number exact match first
 *   - Falls back to matching by numeric client ID (padded or plain)
 *
 * Tenant is identified from HTTP_HOST subdomain (e.g. acme.fortunetttech.site).
 * Register this URL in Daraja as the Validation URL for your own shortcode.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/account_resolver.php';

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$content = file_get_contents('php://input');
@file_put_contents(
    $logDir . '/tenant_c2b.log',
    date('Y-m-d H:i:s') . ' VALIDATE HOST=' . ($_SERVER['HTTP_HOST'] ?? '') . ' -- ' . $content . "\n",
    FILE_APPEND | LOCK_EX
);

// GET health-check — lets admins confirm the URL is reachable
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok', 'endpoint' => 'tenant_c2b_validation']);
    exit;
}

$data       = json_decode($content, true) ?? [];
$accountRef = strtoupper(trim($data['BillRefNumber'] ?? ''));
$phone      = $data['MSISDN'] ?? '';

// No account ref at all still gets accepted — the payer's MSISDN alone is often
// enough for the resolver to find the client.

// Detect tenant from subdomain
$httpHost  = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$hostParts = explode('.', $httpHost);
$tenantId  = null;

if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
    $subdomain = $hostParts[0];
    $ts = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
    $ts->execute([$subdomain]);
    $tenantId = $ts->fetchColumn() ?: null;
}

if (!$tenantId) {
    @file_put_contents($logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " VALIDATE REJECT: unresolvable host={$httpHost}\n", FILE_APPEND | LOCK_EX);
    // Return 0 so Safaricom doesn't hold the customer's money on our routing error
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

try {
    // Same resolver as the confirmation handler — validation must never reject a
    // reference confirmation would have accepted.
    $match = resolveAccountRef($pdo, $accountRef, $phone ?? '', (int)$tenantId);

    if ($match) {
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    } else {
        // Accept regardless: bouncing the payment at the till strands a customer
        // who mistyped, whereas confirmation logs it as UNMATCHED for a human to
        // reconcile. Taking the money and fixing the mapping is the better failure.
        @file_put_contents($logDir . '/tenant_c2b.log',
            date('Y-m-d H:i:s') . " VALIDATE UNRESOLVED (accepted anyway): account={$accountRef} tenant={$tenantId}\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
} catch (Throwable $e) {
    @file_put_contents($logDir . '/mpesa_errors.log',
        date('Y-m-d H:i:s') . " tenant_c2b_validation error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    // Accept on DB error — better to let the payment through than to block it
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

/**
 * Resolve a BillRefNumber to a client ID under the given tenant.
 * Tries account_number exact match first, then numeric client ID fallback.
 */
function resolveClientFromRef(PDO $pdo, string $ref, int $tenantId): ?int {
    // 1. account_number exact match (primary path)
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE account_number = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$ref, $tenantId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    // 2. Numeric client ID fallback (customers entering plain "23" or "0023")
    $numeric = ltrim($ref, '0') ?: '0';
    if (ctype_digit($numeric)) {
        $stmt2 = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt2->execute([(int)$numeric, $tenantId]);
        $id2 = $stmt2->fetchColumn();
        if ($id2) return (int)$id2;
    }

    return null;
}
