<?php
/**
 * Unmatched paybill payments — list, suggest a match, credit, or dismiss.
 *
 * POST action=list                    → open payments for this tenant, newest first,
 *                                       each with suggested customers
 *      action=resolve  id, client_id  → credit it and run the full payment pipeline
 *      action=ignore   id             → dismiss (e.g. a genuine misdirected payment)
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/unmatched_payments.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$userId = (int)$_SESSION['user_id'];
$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$userId]);
$tenantId = (int)$t->fetchColumn();
if (!$tenantId) { ob_clean(); echo json_encode(['success' => false, 'error' => 'No tenant']); exit; }

$action = $_POST['action'] ?? 'list';

/**
 * Best-guess customers for an orphaned payment.
 *
 * Deliberately looser than resolveAccountRef(): that one must never credit the
 * wrong customer, so it refuses anything ambiguous. Here a human confirms, so
 * showing three plausible candidates beats showing none.
 */
function um_suggest(PDO $pdo, int $tenantId, string $ref, string $phone): array
{
    $tail = substr(preg_replace('/\D/', '', $phone), -9);
    $refDigits = preg_replace('/\D/', '', $ref);
    $refTail = $refDigits !== '' ? substr($refDigits, -9) : '';

    $sql = "
        SELECT id, full_name, username, phone, account_number, status, expiry_date,
               COALESCE(NULLIF(connection_type,''),'hotspot') AS connection_type
        FROM clients
        WHERE tenant_id = ?
          AND ( account_number = ?
             OR (? <> '' AND RIGHT(REPLACE(REPLACE(phone,' ',''),'+',''), 9) = ?)
             OR (? <> '' AND RIGHT(REPLACE(REPLACE(phone,' ',''),'+',''), 9) = ?)
             OR full_name LIKE ?
             OR username  = ? )
        ORDER BY (status = 'active') DESC, id DESC
        LIMIT 5
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$tenantId, $ref, $tail, $tail, $refTail, $refTail, '%' . $ref . '%', $ref]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

try {
    if ($action === 'list') {
        _unmatched_ensure_table($pdo);
        // tenant_id IS NULL rows are unrouted payments — the subdomain did not
        // resolve. Surface them too; whoever is looking is the likeliest owner.
        $st = $pdo->prepare("
            SELECT * FROM unmatched_payments
            WHERE status = 'open' AND (tenant_id = ? OR tenant_id IS NULL)
            ORDER BY created_at DESC LIMIT 100
        ");
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            unset($r['raw_payload']);   // large and not needed in the list
            $r['suggestions'] = um_suggest($pdo, $tenantId, (string)$r['account_ref'], (string)$r['phone']);
        }
        unset($r);

        ob_clean();
        echo json_encode(['success' => true, 'payments' => $rows, 'count' => count($rows)]);
        exit;
    }

    if ($action === 'resolve') {
        $rowId    = (int)($_POST['id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!$rowId || !$clientId) {
            ob_clean(); echo json_encode(['success' => false, 'error' => 'id and client_id required']); exit;
        }
        // Claim an unrouted (tenant_id IS NULL) row for this tenant before resolving
        $pdo->prepare("UPDATE unmatched_payments SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL")
            ->execute([$tenantId, $rowId]);

        $res = resolve_unmatched_payment($pdo, $rowId, $clientId, $tenantId, $userId);
        ob_clean();
        echo json_encode(['success' => $res['success'], 'message' => $res['message']]);
        exit;
    }

    if ($action === 'ignore') {
        $rowId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("
            UPDATE unmatched_payments SET status = 'ignored', resolved_by = ?, resolved_at = NOW()
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) AND status = 'open'
        ")->execute([$userId, $rowId, $tenantId]);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Dismissed.']);
        exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unknown action']);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
