<?php
/**
 * M-Pesa C2B Validation Handler — Platform Shared Paybill
 *
 * Called by Safaricom BEFORE crediting the customer's account.
 * We validate that the account number maps to a known tenant + client.
 * Return ResultCode 0 to accept, any other code to reject.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$content = file_get_contents('php://input');
file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " VALIDATE -- " . $content . "\n", FILE_APPEND | LOCK_EX);

$data       = json_decode($content, true);
$accountRef = strtoupper(trim($data['BillRefNumber'] ?? ''));

if (empty($accountRef)) {
    echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Invalid account number']);
    exit;
}

try {
    // Try to resolve the account number using prefix matching (same logic as confirmation)
    $resolved = false;
    for ($prefixLen = 3; $prefixLen >= 1; $prefixLen--) {
        if (strlen($accountRef) <= $prefixLen) continue;
        $candidatePrefix = substr($accountRef, 0, $prefixLen);
        $candidateId     = ltrim(substr($accountRef, $prefixLen), '0') ?: '0';
        if (!ctype_digit($candidateId)) continue;

        $stmt = $pdo->prepare("
            SELECT u.tenant_id FROM users u
            WHERE u.account_prefix = ? AND u.role = 'admin' AND u.tenant_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$candidatePrefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $tenantId = (int)$row['tenant_id'];
            $clientId = (int)$candidateId;
            $check = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
            $check->execute([$clientId, $tenantId]);
            if ($check->fetchColumn()) {
                $resolved = true;
            }
            break;
        }
    }

    if ($resolved) {
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    } else {
        file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " REJECTED: unresolved account=$accountRef\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Invalid account number']);
    }
} catch (Throwable $e) {
    file_put_contents($logDir . '/mpesa_errors.log', date('Y-m-d H:i:s') . " C2B validate error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
