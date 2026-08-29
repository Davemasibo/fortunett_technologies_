<?php
/**
 * Import / reconcile payments from CSV — including a raw M-Pesa statement.
 *
 * Why this is more than a CSV loader
 * ----------------------------------
 * C2B is what makes a payment sent straight to the ISP's own paybill activate
 * the customer by itself. But there is no Daraja API that lists past C2B
 * transactions, so when C2B was never registered, was registered late, or
 * Safaricom simply never called the confirmation URL, that money is unrecoverable
 * by any poller. The ISP's M-Pesa statement is the only remaining record.
 *
 * This endpoint used to INSERT a payments row and stop. The money appeared in
 * the ledger and the customer stayed disconnected — which is exactly the manual
 * work this is supposed to remove. It now runs the same pipeline a live C2B
 * confirmation runs: resolve the account, credit it, extend expiry, provision
 * the router, notify. Anything it cannot attribute goes to the unmatched queue
 * for one-click assignment rather than being silently dropped or, worse,
 * recorded against nobody.
 *
 * Accepted headers — a Safaricom statement export works unedited:
 *   Receipt No. / TransID          → transaction_id   (dedupe key)
 *   Completion Time / TransTime    → payment_date
 *   Paid In / TransAmount / Amount → amount           (Withdrawn / negative rows are skipped)
 *   Other Party Info / Details     → payer name + phone
 *   Account No. / BillRefNumber    → account reference
 * plus the original simple form: client_phone, client_name, account_number,
 * payment_method, status, notes.
 *
 * Reconciliation is opt-out (activate=0) for the rare case of loading historical
 * rows for reporting only, where re-provisioning long-expired customers would be
 * wrong.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/account_resolver.php';
require_once __DIR__ . '/../../includes/payment_pipeline.php';
require_once __DIR__ . '/../../includes/unmatched_payments.php';
require_once __DIR__ . '/../../includes/schema_guard.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']); exit;
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) {
    $s = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $s->execute([(int)$_SESSION['user_id']]);
    $tenantId = (int)$s->fetchColumn();
    if ($tenantId) $_SESSION['tenant_id'] = $tenantId;
}
if (!$tenantId) { echo json_encode(['success'=>false,'message'=>'Tenant not found']); exit; }

ensurePaymentStatusEnums($pdo);

// Reconcile (credit + provision) unless explicitly told to load rows only.
$activate = !isset($_POST['activate']) || $_POST['activate'] !== '0';

if (empty($_FILES['csv_file']['tmp_name'])) {
    echo json_encode(['success'=>false,'message'=>'No file uploaded']); exit;
}

$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
if (!$fh) { echo json_encode(['success'=>false,'message'=>'Cannot read file']); exit; }

$header = fgetcsv($fh);
if (!$header) { echo json_encode(['success'=>false,'message'=>'Empty file']); exit; }

// Normalise headers so "Receipt No.", "receipt_no" and "RECEIPT NO" are one key.
$norm = static fn($h) => preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$h)));
$header = array_map($norm, $header);
// A Safaricom export carries a UTF-8 BOM that would otherwise glue itself to the
// first column name and make it unmatchable.
if (isset($header[0])) $header[0] = preg_replace('/^_+/', '', str_replace("\xEF\xBB\xBF", '', $header[0]));

/** First non-empty value among several possible column names. */
$pick = static function (array $row, array $keys): string {
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]);
    }
    return '';
};

$imported = 0; $skipped = 0; $activated = 0; $queued = 0; $errors = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 1 || (count($row) === 1 && trim((string)$row[0]) === '')) continue;
    $data = array_combine($header, array_pad($row, count($header), ''));

    // Amount. A statement has separate Paid In / Withdrawn columns; only money
    // coming IN is a customer payment. Amounts carry thousands separators.
    $rawAmount = $pick($data, ['paid_in', 'transamount', 'amount', 'credit']);
    $amount    = (float)str_replace([',', ' '], '', $rawAmount);
    if ($amount <= 0) { $skipped++; continue; }

    $txId = $pick($data, ['receipt_no', 'receipt_number', 'transid', 'transaction_id', 'trans_id']);
    if ($txId === '') $txId = 'IMP-' . strtoupper(bin2hex(random_bytes(6)));

    // "254712345678 - JOHN DOE" — the statement packs phone and name together.
    $otherParty = $pick($data, ['other_party_info', 'details', 'payer', 'payer_name']);
    $phone      = $pick($data, ['msisdn', 'client_phone', 'phone', 'phone_number']);
    $payerName  = $pick($data, ['payer_name', 'client_name', 'name']);
    if ($otherParty !== '') {
        if (preg_match('/(\+?\d[\d\s]{8,})/', $otherParty, $m) && $phone === '') {
            $phone = preg_replace('/\D/', '', $m[1]);
        }
        if ($payerName === '') {
            $payerName = trim(preg_replace('/[\d\+\-]/', '', $otherParty), " \t-–—");
        }
    }

    $accountRef = $pick($data, ['account_no', 'account_number', 'billrefnumber', 'bill_ref_number', 'account']);

    $method = strtolower($pick($data, ['payment_method', 'method'])) ?: 'mpesa_paybill';
    if (!in_array($method, ['mpesa', 'mpesa_paybill', 'cash', 'bank', 'card'], true)) $method = 'mpesa_paybill';

    $status = strtolower($pick($data, ['status'])) ?: 'completed';
    if (!in_array($status, ['completed', 'pending', 'failed'], true)) $status = 'completed';

    $notes = $pick($data, ['notes', 'description']);

    // Date
    $rawDate = $pick($data, ['completion_time', 'payment_date', 'date', 'transtime', 'initiation_time']);
    $payDate = null;
    if ($rawDate !== '') {
        // TransTime arrives as YYYYMMDDHHMMSS, which strtotime() misreads.
        if (preg_match('/^\d{14}$/', $rawDate)) {
            $payDate = substr($rawDate,0,4).'-'.substr($rawDate,4,2).'-'.substr($rawDate,6,2).' '
                     . substr($rawDate,8,2).':'.substr($rawDate,10,2).':'.substr($rawDate,12,2);
        } else {
            $ts = strtotime($rawDate);
            if ($ts !== false) $payDate = date('Y-m-d H:i:s', $ts);
        }
    }
    if (!$payDate) $payDate = date('Y-m-d H:i:s');

    try {
        // Dedupe on the receipt. This is what makes re-importing an overlapping
        // statement safe — and an ISP will re-import overlapping statements.
        if (strpos($txId, 'IMP-') !== 0) {
            $dup = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
            $dup->execute([$txId]);
            if ($dup->fetchColumn()) { $skipped++; continue; }
        }

        // Resolve exactly the way a live confirmation would: account number,
        // then phone (the portal tells customers to type theirs), then
        // PREFIX+id, then bare id — all scoped to this tenant.
        $clientId = null;
        if ($accountRef !== '' || $phone !== '') {
            $match    = resolveAccountRef($pdo, strtoupper($accountRef), $phone, $tenantId);
            $clientId = $match['client_id'] ?? null;
        }
        // Fall back to the explicit columns of the original simple template.
        if (!$clientId) {
            foreach ([['account_number', $accountRef],
                      ['phone',          $phone],
                      ['full_name',      $payerName]] as [$col, $val]) {
                if ($val === '') continue;
                $chk = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND $col = ? LIMIT 1");
                $chk->execute([$tenantId, $val]);
                $clientId = $chk->fetchColumn() ?: null;
                if ($clientId) break;
            }
        }

        if (!$clientId) {
            // Never record a payment against nobody and call it imported — that
            // is how money goes missing. Queue it where a human can assign it in
            // one click, running the full pipeline at that point.
            record_unmatched_payment(
                $pdo, $tenantId, $txId, $amount, $phone, $accountRef,
                'unmatched', 'statement_import', null, $payerName
            );
            $queued++;
            continue;
        }

        $pdo->prepare("
            INSERT INTO payments
                (tenant_id, client_id, amount, payment_method,
                 transaction_id, status, payment_date, collection_type, notes, created_at)
            VALUES (?,?,?,?,?,?,?,'direct',?,NOW())
        ")->execute([
            $tenantId, $clientId, $amount, $method, $txId, $status, $payDate,
            $notes !== '' ? $notes : 'Reconciled from statement import' . ($accountRef !== '' ? ' — ref: ' . $accountRef : ''),
        ]);
        $imported++;

        // Credit, extend, provision, notify — the same path a live C2B
        // confirmation takes. platformCollected=false: an ISP importing their own
        // statement is by definition importing money already in their own account.
        if ($activate && $status === 'completed') {
            $cSt = $pdo->prepare("SELECT package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
            $cSt->execute([$clientId, $tenantId]);
            $packageId = $cSt->fetchColumn();

            // The pipeline reports per-step, not a single success flag; a router
            // that is unreachable queues the provision rather than failing the
            // payment, so the payment step is the honest thing to count.
            $res = process_payment_success(
                $pdo, (int)$clientId, $tenantId, $amount, $txId, $method,
                $packageId ? (int)$packageId : null, false
            );
            if (!empty($res['steps']['payment'])) $activated++;
        }
    } catch (Throwable $e) {
        $errors[] = "Row (tx: $txId): " . $e->getMessage();
    }
}
fclose($fh);

$parts = ["$imported recorded"];
if ($activated) $parts[] = "$activated customer(s) credited and reconnected";
if ($queued)    $parts[] = "$queued could not be matched — review them under Unclaimed Payments";
if ($skipped)   $parts[] = "$skipped skipped (duplicate or not a payment)";
if ($errors)    $parts[] = count($errors) . ' error(s)';

echo json_encode([
    'success'   => true,
    'imported'  => $imported,
    'activated' => $activated,
    'queued'    => $queued,
    'skipped'   => $skipped,
    'errors'    => $errors,
    'message'   => implode(', ', $parts),
]);
