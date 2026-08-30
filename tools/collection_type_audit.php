<?php
/**
 * Read-only. Shows, per tenant, how they actually collect money and how their
 * payments are currently tagged — so a re-tagging decision is made on evidence
 * rather than on one boolean.
 *
 *   php tools/collection_type_audit.php
 *   php tools/collection_type_audit.php --tenant=7
 *
 * Writes nothing. Run this before and after tools/repair_collection_type.php.
 *
 * What to look for
 * ----------------
 * A tenant with `paybill_own` and no `stk_own` — Ecoland's shape — collects
 * their customers' money into their own paybill with no API involved. Their
 * paybill payments are `direct` and must stay that way; only an STK push they
 * cannot send themselves is platform-collected.
 *
 * A tenant showing `CREDENTIALS WILL NOT DECRYPT` is unclassifiable, not empty.
 * The encryption key has been rotated since those credentials were saved, so
 * nothing here can tell what their historic payments did. Re-key the gateway in
 * Settings → Payments before re-tagging anything for them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/payment_routing.php';

$onlyTenant = 0;
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0) $onlyTenant = (int)substr($a, 9);
}

$sql = "SELECT id, company_name, subdomain FROM tenants";
$params = [];
if ($onlyTenant) { $sql .= " WHERE id = ?"; $params[] = $onlyTenant; }
$sql .= " ORDER BY id";
$st = $pdo->prepare($sql);
$st->execute($params);
$tenants = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$tenants) exit("No tenants found.\n");

foreach ($tenants as $t) {
    $tid  = (int)$t['id'];
    $prof = tenantCollectionProfile($pdo, $tid);

    echo str_repeat('=', 78) . "\n";
    printf("Tenant %-4s %s (%s)\n", $tid, $t['company_name'], $t['subdomain']);
    echo str_repeat('-', 78) . "\n";

    printf("  own STK credentials : %s\n", $prof['stk_own']     ? 'YES - STK leaves from their shortcode' : 'no  - STK goes out on the platform paybill');
    printf("  own paybill/till    : %s\n", $prof['paybill_own'] ? 'YES - ' . implode(', ', $prof['shortcodes']) : 'no');
    printf("  gateways            : %s\n", $prof['detail']);

    if ($prof['undecryptable']) {
        echo "  *** CREDENTIALS WILL NOT DECRYPT - this tenant cannot be classified ***\n";
        echo "      Do not re-tag their history. Re-save the gateway in Settings -> Payments first.\n";
    }

    // What the money actually looks like today
    try {
        $pSt = $pdo->prepare("
            SELECT payment_method,
                   collection_type,
                   COUNT(*)              AS n,
                   COALESCE(SUM(amount),0) AS total,
                   MIN(payment_date)     AS first_seen,
                   MAX(payment_date)     AS last_seen
            FROM payments
            WHERE tenant_id = ? AND status = 'completed'
            GROUP BY payment_method, collection_type
            ORDER BY total DESC
        ");
        $pSt->execute([$tid]);
        $rows = $pSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo "  (could not read payments: " . $e->getMessage() . ")\n\n";
        continue;
    }

    if (!$rows) { echo "  no completed payments\n\n"; continue; }

    echo "\n";
    printf("  %-22s %-10s %6s %14s  %s\n", 'METHOD', 'TAGGED', 'COUNT', 'TOTAL KES', 'VERDICT');
    foreach ($rows as $r) {
        $method = (string)$r['payment_method'];
        $tag    = (string)$r['collection_type'];

        // The verdict column is advisory. It states what the evidence supports,
        // and says so plainly when the evidence does not decide.
        if ($prof['undecryptable']) {
            $verdict = 'UNKNOWN - credentials will not decrypt';
        } elseif (in_array($method, ['cash', 'balance', 'manual', 'bank'], true)) {
            $verdict = 'direct (never touches M-Pesa)';
        } elseif ($method === 'mpesa_c2b_tenant') {
            $verdict = 'direct (their own paybill C2B)';
        } elseif (isStkPaymentMethod($method)) {
            $verdict = $prof['stk_own']
                ? 'direct (STK from their own shortcode)'
                : 'platform (they cannot send STK themselves)';
        } elseif ($method === 'mpesa_paybill') {
            $verdict = $prof['paybill_own']
                ? 'AMBIGUOUS - they own a paybill, so this may be theirs'
                : 'platform (no paybill of their own)';
        } else {
            $verdict = 'unclassified method';
        }

        $flag = (strpos($verdict, strtolower($tag)) === false && strpos($verdict, 'AMBIG') === false
                 && strpos($verdict, 'UNKNOWN') === false && strpos($verdict, 'unclassified') === false)
              ? ' <== MISMATCH' : '';

        printf("  %-22s %-10s %6d %14s  %s%s\n",
            substr($method, 0, 22), $tag, $r['n'], number_format($r['total'], 2), $verdict, $flag);
    }

    // Money the platform is shown as holding for them
    try {
        $qSt = $pdo->prepare("
            SELECT COUNT(*) n, COALESCE(SUM(net_amount),0) net
            FROM isp_payout_queue WHERE tenant_id = ? AND status = 'pending'
        ");
        $qSt->execute([$tid]);
        $q = $qSt->fetch(PDO::FETCH_ASSOC);
        if ($q && (int)$q['n'] > 0) {
            printf("\n  payout queue        : %d pending, KES %s owed to them\n", $q['n'], number_format($q['net'], 2));
        }
    } catch (Throwable $e) { /* table may not exist */ }

    echo "\n";
}

echo str_repeat('=', 78) . "\n";
echo "Nothing was written. Rows marked MISMATCH are what repair_collection_type.php\n";
echo "would act on; AMBIGUOUS and UNKNOWN rows it now leaves alone.\n";
