<?php
/**
 * Read-only. Shows, per tenant, how they actually collect money and how their
 * payments are currently tagged — so a re-tagging decision is made on evidence
 * rather than on one boolean.
 *
 *   php tools/collection_type_audit.php
 *   php tools/collection_type_audit.php --tenant=7
 *   php tools/collection_type_audit.php --subdomain=ecolandattic
 *
 * Writes nothing. Run this before and after tools/repair_collection_type.php.
 *
 * What to look for
 * ----------------
 * `own STK credentials: no` is the decisive line for STK payments. Without
 * Daraja credentials the push physically left on the PLATFORM's shortcode, so
 * the money landed in the platform till — whatever paybill the tenant may own
 * separately. A `paybill_no_api` paybill cannot send an STK push either, so
 * owning one changes nothing for those rows.
 *
 * `own paybill/till: YES` only decides `mpesa_paybill` rows, and even then only
 * as AMBIGUOUS: a paybill payment goes wherever the customer typed, which no
 * amount of configuration can tell you after the fact.
 *
 * An `INACTIVE gateway` line matters more than it looks. A switched-off gateway
 * is invisible everywhere else in the app, so this is the one place a paybill
 * the operator believes is live shows up as not routing anything.
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
$onlySubdomain = '';
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0)    $onlyTenant    = (int)substr($a, 9);
    if (strpos($a, '--subdomain=') === 0) $onlySubdomain = trim(substr($a, 12));
}

// Scoping by subdomain as well as by id: the operator knows a tenant by the
// host in their browser, not by a row id, and looking the id up first is an
// extra step in exactly the moment someone is chasing a money discrepancy.
$sql = "SELECT id, company_name, subdomain FROM tenants";
$params = [];
if ($onlyTenant) {
    $sql .= " WHERE id = ?";
    $params[] = $onlyTenant;
} elseif ($onlySubdomain !== '') {
    $sql .= " WHERE subdomain = ?";
    $params[] = $onlySubdomain;
}
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

    // An INACTIVE gateway reads exactly like no gateway in every other view,
    // which is how a tenant can insist they have a paybill while the system
    // shows none. Name them separately rather than letting the operator and the
    // database disagree in silence.
    try {
        $iSt = $pdo->prepare("SELECT gateway_type, credentials FROM payment_gateways
                              WHERE tenant_id = ? AND is_active = 0");
        $iSt->execute([$tid]);
        foreach ($iSt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $c  = decrypt_gateway_credentials((string)($g['credentials'] ?? ''));
            $sc = $c['shortcode'] ?? ($c['paybill_number'] ?? '');
            printf("  !! INACTIVE gateway  : %s %s - switched off, so nothing routes to it\n",
                $g['gateway_type'], $sc !== '' ? $sc : '(no shortcode)');
        }
    } catch (Throwable $e) { /* advisory only */ }

    // What the money actually looks like today
    try {
        $pSt = $pdo->prepare("
            SELECT p.payment_method,
                   p.collection_type,
                   CASE WHEN " . manuallyRecordedSql('p') . " THEN 1 ELSE 0 END AS manual_entry,
                   COUNT(*)                 AS n,
                   COALESCE(SUM(p.amount),0) AS total,
                   MIN(p.payment_date)      AS first_seen,
                   MAX(p.payment_date)      AS last_seen
            FROM payments p
            WHERE p.tenant_id = ? AND p.status = 'completed'
            GROUP BY p.payment_method, p.collection_type, manual_entry
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
        //
        // The manual check comes FIRST and overrides everything. A hand-entered
        // receipt carries the method the operator picked - 'mpesa' for an M-Pesa
        // payment - so by method alone it is indistinguishable from an STK push.
        // Only the entry marker tells them apart, and a hand-entered payment is
        // money the ISP already had by definition.
        if ((int)($r['manual_entry'] ?? 0) === 1) {
            $verdict = 'direct (recorded by hand - the ISP already had it)';
        } elseif ($prof['undecryptable']) {
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
            substr($method, 0, 22) . ((int)($r['manual_entry'] ?? 0) === 1 ? ' (manual)' : ''),
            $tag, $r['n'], number_format($r['total'], 2), $verdict, $flag);
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
