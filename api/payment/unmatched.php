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
        // tenant_id IS NULL rows are unrouted payments — the confirmation could
        // not be attributed to any ISP. Buy Goods (Till) payments land here as a
        // matter of course, because a till gives the customer nowhere to type an
        // account number, so these are no longer the rare case they once were.
        //
        // They are still surfaced, but NOT to everybody: an unrouted row is only
        // offered to a tenant who has a customer it could plausibly belong to
        // (um_suggest() finds a match on account ref, payer phone, name or
        // username). Without that filter every ISP on the platform would see —
        // and could claim — every other ISP's orphaned till money.
        $st = $pdo->prepare("
            SELECT * FROM unmatched_payments
            WHERE status = 'open' AND (tenant_id = ? OR tenant_id IS NULL)
            ORDER BY created_at DESC LIMIT 100
        ");
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $visible = [];
        foreach ($rows as $r) {
            unset($r['raw_payload']);   // large and not needed in the list
            $r['suggestions'] = um_suggest($pdo, $tenantId, (string)$r['account_ref'], (string)$r['phone']);
            if ($r['tenant_id'] === null && !$r['suggestions']) {
                continue;   // somebody else's orphan, or nobody's yet
            }
            $r['unrouted'] = ($r['tenant_id'] === null);
            $visible[] = $r;
        }
        $rows = $visible;

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
        // Claim an unrouted (tenant_id IS NULL) row for this tenant before
        // resolving — but only if this tenant actually has a customer it could
        // belong to. The list filter already hides other ISPs' orphans; this
        // repeats the check server-side so a hand-crafted POST cannot skip it.
        $orphan = $pdo->prepare("SELECT account_ref, phone FROM unmatched_payments WHERE id = ? AND tenant_id IS NULL");
        $orphan->execute([$rowId]);
        if ($o = $orphan->fetch(PDO::FETCH_ASSOC)) {
            if (!um_suggest($pdo, $tenantId, (string)$o['account_ref'], (string)$o['phone'])) {
                ob_clean();
                echo json_encode(['success' => false,
                    'error' => 'This payment does not match any of your customers. It may belong to another ISP.']);
                exit;
            }
            $pdo->prepare("UPDATE unmatched_payments SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL")
                ->execute([$tenantId, $rowId]);
        }

        $res = resolve_unmatched_payment($pdo, $rowId, $clientId, $tenantId, $userId);
        ob_clean();
        echo json_encode(['success' => $res['success'], 'message' => $res['message']]);
        exit;
    }

    if ($action === 'ignore') {
        $rowId = (int)($_POST['id'] ?? 0);
        // Only rows already owned by this tenant can be dismissed. Dismissing an
        // unrouted row would hide another ISP's money from them permanently.
        $pdo->prepare("
            UPDATE unmatched_payments SET status = 'ignored', resolved_by = ?, resolved_at = NOW()
            WHERE id = ? AND tenant_id = ? AND status = 'open'
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
