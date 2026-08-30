<?php
/**
 * Replay C2B confirmations that the handlers discarded.
 *
 *   php tools/recover_c2b_log.php              # dry run — shows what it would do
 *   php tools/recover_c2b_log.php --apply      # actually credit / queue them
 *   php tools/recover_c2b_log.php --apply --since=2026-08-01
 *
 * Why this exists
 * ---------------
 * Both C2B confirmation handlers used to begin with:
 *
 *     if (empty($accountRef) || $amount <= 0) { log('SKIPPED'); exit; }
 *
 * A Buy Goods (Till) confirmation has no BillRefNumber — a till gives the
 * customer nowhere to type an account number — so every till payment hit that
 * branch and was thrown away. The money was banked, the customer got nothing,
 * and Daraja has no API that lists past C2B transactions, so nothing could pull
 * them back.
 *
 * The one thing that saves this: the handlers write the raw payload to
 * logs/mpesa_c2b.log BEFORE the guard runs. Every discarded payment is still on
 * disk in full. This tool re-feeds those payloads through the fixed logic —
 * resolve the payer, credit and provision them if they can be identified,
 * otherwise queue them in unmatched_payments for a human.
 *
 * Idempotent: a TransID already in `payments` or `unmatched_payments` is
 * skipped, so re-running is safe and it can be pointed at a rotated log too.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/auto_provision.php';
require_once __DIR__ . '/../includes/payment_pipeline.php';
require_once __DIR__ . '/../includes/account_resolver.php';
require_once __DIR__ . '/../includes/platform_billing.php';
require_once __DIR__ . '/../includes/schema_guard.php';
require_once __DIR__ . '/../includes/unmatched_payments.php';

$opts    = getopt('', ['apply', 'since::', 'file::', 'limit::']);
$apply   = isset($opts['apply']);
$since   = !empty($opts['since']) ? strtotime($opts['since']) : 0;
$limit   = !empty($opts['limit']) ? (int)$opts['limit'] : 0;
$files   = !empty($opts['file'])
    ? [$opts['file']]
    : [__DIR__ . '/../logs/mpesa_c2b.log', __DIR__ . '/../logs/tenant_c2b.log'];

ensurePaymentStatusEnums($pdo);
_unmatched_ensure_table($pdo);

echo $apply ? "=== APPLYING ===\n" : "=== DRY RUN (add --apply to commit) ===\n";

/**
 * Every payload the handlers logged, newest last, keyed by TransID so a
 * duplicate log line (a Safaricom retry) is only considered once.
 */
$payloads = [];
foreach ($files as $file) {
    if (!is_readable($file)) {
        echo "  (no log at $file)\n";
        continue;
    }
    $lines = explode("\n", (string)file_get_contents($file));
    echo "  reading " . basename($file) . " (" . count($lines) . " lines)\n";

    foreach ($lines as $line) {
        // Format written by the handlers: "Y-m-d H:i:s -- {json}"
        $pos = strpos($line, ' -- {');
        if ($pos === false) {
            continue;
        }
        $stamp = strtotime(substr($line, 0, 19));
        if ($since && $stamp && $stamp < $since) {
            continue;
        }
        $data = json_decode(substr($line, $pos + 4), true);
        if (!is_array($data)) {
            continue;
        }
        $tx = (string)($data['TransID'] ?? ($data['TransactionID'] ?? ''));
        if ($tx === '') {
            continue;
        }
        $payloads[$tx] = ['data' => $data, 'raw' => substr($line, $pos + 4), 'stamp' => $stamp];
    }
}

if (!$payloads) {
    exit("\nNo C2B payloads found in the logs.\n");
}
echo "\n" . count($payloads) . " distinct transactions in the logs.\n\n";

$stats = ['already' => 0, 'credited' => 0, 'queued' => 0, 'skipped' => 0, 'failed' => 0];
$done  = 0;

foreach ($payloads as $tx => $p) {
    if ($limit && $done >= $limit) {
        echo "  (stopping at --limit=$limit)\n";
        break;
    }

    $data   = $p['data'];
    $amount = (float)($data['TransAmount'] ?? 0);
    $phone  = (string)($data['MSISDN'] ?? '');
    $ref    = strtoupper(trim((string)($data['BillRefNumber'] ?? '')));
    $name   = trim(preg_replace('/\s+/', ' ',
        ($data['FirstName'] ?? '') . ' ' . ($data['MiddleName'] ?? '') . ' ' . ($data['LastName'] ?? '')));
    $when   = $p['stamp'] ? date('d M Y H:i', $p['stamp']) : '?';

    if ($amount <= 0) {
        $stats['skipped']++;
        continue;
    }

    // Already handled — by the original run, by an earlier pass of this tool,
    // or sitting in the unmatched queue awaiting a human.
    $seen = $pdo->prepare("SELECT 1 FROM payments WHERE transaction_id = ? LIMIT 1");
    $seen->execute([$tx]);
    if ($seen->fetchColumn()) {
        $stats['already']++;
        continue;
    }
    $seen = $pdo->prepare("SELECT 1 FROM unmatched_payments WHERE transaction_id = ? LIMIT 1");
    $seen->execute([$tx]);
    if ($seen->fetchColumn()) {
        $stats['already']++;
        continue;
    }

    $label = sprintf('%-12s  %-17s  KSH %-9.2f %-14s %s',
        $tx, $when, $amount, $phone, $ref !== '' ? "ref=$ref" : '(no ref - till)');

    // A tenant paying FortuNett, not an end customer.
    $platformTenantId = $ref !== '' ? resolvePlatformBillingRef($pdo, $ref) : null;
    if ($platformTenantId) {
        if (!$apply) {
            echo "  PLATFORM  $label -> tenant $platformTenantId\n";
            $stats['credited']++;
            $done++;
            continue;
        }
        $res = applyPlatformPayment($pdo, $platformTenantId, $amount, $tx, $phone, $ref, 'c2b_recovered', $p['raw']);
        echo ($res['ok'] ? '  PLATFORM  ' : '  FAILED    ') . "$label -> tenant $platformTenantId: {$res['message']}\n";
        $stats[$res['ok'] ? 'credited' : 'failed']++;
        $done++;
        continue;
    }

    $match = resolveAccountRef($pdo, $ref, $phone, null);

    if (!$match) {
        if (!$apply) {
            echo "  QUEUE     $label -> no customer matched\n";
            $stats['queued']++;
            $done++;
            continue;
        }
        record_unmatched_payment($pdo, null, $tx, $amount, $phone, $ref,
            'unrouted', 'c2b_recovered', $p['raw'], $name);
        echo "  QUEUE     $label -> unmatched_payments\n";
        $stats['queued']++;
        $done++;
        continue;
    }

    $tenantId = (int)$match['tenant_id'];
    $clientId = (int)$match['client_id'];

    $cs = $pdo->prepare("SELECT id, package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $cs->execute([$clientId, $tenantId]);
    $client = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        if ($apply) {
            record_unmatched_payment($pdo, $tenantId, $tx, $amount, $phone, $ref,
                'unmatched', 'c2b_recovered', $p['raw'], $name);
        }
        echo "  QUEUE     $label -> client $clientId not in tenant $tenantId\n";
        $stats['queued']++;
        $done++;
        continue;
    }

    if (!$apply) {
        echo "  CREDIT    $label -> tenant $tenantId client $clientId (via {$match['matched_by']})\n";
        $stats['credited']++;
        $done++;
        continue;
    }

    try {
        // payment_date is the DATE the money actually arrived, not today, so the
        // recovered rows land in the right billing period.
        $pdo->prepare("
            INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date, collection_type, notes)
            VALUES (?, ?, ?, 'mpesa_paybill', ?, 'completed', ?, 'platform', ?)
        ")->execute([
            $clientId, $tenantId, $amount, $tx,
            date('Y-m-d', $p['stamp'] ?: time()),
            'Recovered from C2B log — ' . ($ref !== '' ? "acct: $ref" : 'till payment, no account ref'),
        ]);

        $pdo->prepare("
            INSERT INTO mpesa_transactions
                (client_id, tenant_id, phone_number, amount, merchant_request_id,
                 checkout_request_id, result_code, result_desc, mpesa_receipt_number, raw_callback, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'C2B-RECOVERED', ?, 0, 'C2B Payment (recovered from log)', ?, ?, NOW(), NOW())
        ")->execute([
            $clientId, $tenantId, $phone, $amount,
            $tx . '-recovered', $tx, $p['raw'],
        ]);

        process_payment_success(
            $pdo, $clientId, $tenantId, $amount, $tx, 'mpesa_paybill',
            $client['package_id'] ? (int)$client['package_id'] : null,
            true
        );

        echo "  CREDIT    $label -> tenant $tenantId client $clientId (via {$match['matched_by']})\n";
        $stats['credited']++;
    } catch (Throwable $e) {
        echo "  FAILED    $label -> " . $e->getMessage() . "\n";
        $stats['failed']++;
    }
    $done++;
}

echo "\n";
echo "  already handled : {$stats['already']}\n";
echo "  credited        : {$stats['credited']}\n";
echo "  queued for review: {$stats['queued']}\n";
echo "  zero amount     : {$stats['skipped']}\n";
echo "  failed          : {$stats['failed']}\n";

if (!$apply) {
    echo "\nNothing was written. Re-run with --apply to commit.\n";
} else {
    echo "\nQueued payments are on super_admin/collections.php?tab=unmatched and on\n";
    echo "each tenant's own payments page, where they can be assigned to a customer.\n";
}
