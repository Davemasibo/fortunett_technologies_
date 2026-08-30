<?php
/**
 * Payment Routing — whose bank the money actually landed in.
 *
 * `payments.collection_type` is not a payment method and never a UI nicety:
 * 'platform' means FortuNett's own till took the money and owes the ISP a
 * payout, 'direct' means the ISP's own paybill/till took it and nothing is
 * owed. Every float, settlement figure and payout decision reads it.
 *
 * Before this file the answer was re-derived independently in four places and
 * three of them were wrong:
 *
 *   - api/payment/hotspot_payment_status.php hard-coded `false`, so every
 *     hotspot STK payment confirmed by the inline stkQuery — the common path
 *     whenever the callback is late or blocked — was written 'direct' even
 *     though FortuNett's till held the cash. Worse, that value OVERWROTE the
 *     correct 'platform' tag hotspot_stk_push.php had already put on the
 *     pending row, and suppressed the ISP payout in step 7 of the pipeline.
 *   - api/payment/callback.php and cron/stk_poll.php looked up
 *     `gateway_type = 'mpesa'`, which is not a member of the payment_gateways
 *     ENUM ('paybill_no_api','mpesa_api','bank_account','kopo_kopo','paypal').
 *     The query therefore never matched anything and every payment they
 *     confirmed was flagged platform-collected regardless of the truth.
 *
 * The routing decision belongs to whoever held the credentials that sent the
 * request, so the order of trust here is: a recorded 'platform' → what the caller explicitly says → a fresh derivation from
 * the tenant's gateway configuration.
 */

require_once __DIR__ . '/credential_helper.php';

/**
 * Every way this tenant can take money, not just the API one.
 *
 * The distinction that matters, and that a single boolean got wrong:
 *
 *   stk_own     — complete Daraja credentials, so an STK push is sent FROM the
 *                 tenant's own shortcode and the money lands in their account.
 *   paybill_own — the tenant has a shortcode of their own that a customer can
 *                 pay directly (a `paybill_no_api` paybill, a bank gateway's
 *                 paybill, or an `mpesa_api` shortcode whose secrets are
 *                 incomplete). No API involved: the customer just pays them.
 *
 * A tenant can easily have the second without the first — that is exactly what
 * `paybill_no_api` is for — and treating "no API credentials" as "collects
 * nothing of their own" books their paybill takings as platform money.
 *
 * `undecryptable` is the third state and the one that must never be guessed at.
 * A gateway row whose credentials will not decrypt (the encryption key was
 * rotated or regenerated) is indistinguishable from an empty one. That is fine
 * for live traffic — stk_push.php reads the same failure and genuinely routes
 * through the platform, so booking it as platform is correct — but it is not
 * safe for re-tagging HISTORIC payments, which were sent when the key still
 * worked. Callers doing history must check this flag and skip.
 *
 * @return array{stk_own:bool,paybill_own:bool,undecryptable:bool,shortcodes:array,detail:string}
 */
function tenantCollectionProfile(PDO $pdo, int $tenantId): array
{
    static $cache = [];
    if (array_key_exists($tenantId, $cache)) return $cache[$tenantId];

    $out = [
        'stk_own'       => false,
        'paybill_own'   => false,
        'undecryptable' => false,
        'shortcodes'    => [],
        'detail'        => 'no active gateway',
    ];

    if ($tenantId <= 0) return $cache[$tenantId] = $out;

    try {
        $st = $pdo->prepare(
            "SELECT id, gateway_type, credentials FROM payment_gateways
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY is_default DESC, id ASC"
        );
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('tenantCollectionProfile(' . $tenantId . '): ' . $e->getMessage());
        return $cache[$tenantId] = $out;
    }

    $notes = [];
    foreach ($rows as $row) {
        $type = (string)$row['gateway_type'];
        $raw  = (string)($row['credentials'] ?? '');

        // decrypt_gateway_credentials() passes legacy plain JSON through, so an
        // empty result from a non-empty blob means the key no longer matches.
        $c = $raw === '' ? [] : decrypt_gateway_credentials($raw);
        if ($raw !== '' && !$c) {
            $out['undecryptable'] = true;
            $notes[] = "$type#{$row['id']}: credentials will not decrypt";
            continue;
        }

        if ($type === 'mpesa_api') {
            $sc = trim((string)($c['shortcode'] ?? ''));
            if ($sc !== '') {
                $out['paybill_own'] = true;
                $out['shortcodes'][] = $sc;
            }
            $complete = !empty($c['consumer_key']) && !empty($c['consumer_secret'])
                     && !empty($c['passkey'])      && !empty($c['shortcode']);
            if ($complete) {
                $out['stk_own'] = true;
                $notes[] = "mpesa_api $sc (complete — STK routes to them)";
            } elseif ($sc !== '') {
                $notes[] = "mpesa_api $sc (incomplete secrets — direct payments only)";
            }
        } elseif ($type === 'paybill_no_api' || $type === 'bank_account') {
            $sc = trim((string)($c['paybill_number'] ?? ''));
            if ($sc !== '') {
                $out['paybill_own']  = true;
                $out['shortcodes'][] = $sc;
                $notes[] = "$type $sc (customers pay them directly)";
            }
        }
    }

    $out['shortcodes'] = array_values(array_unique($out['shortcodes']));
    if ($notes) $out['detail'] = implode('; ', $notes);

    return $cache[$tenantId] = $out;
}

/**
 * Does this tenant have their own working Daraja credentials?
 *
 * Mirrors the routing test in api/payment/stk_push.php and
 * api/payment/hotspot_stk_push.php EXACTLY — same gateway_type, same
 * is_active/is_default ordering, same four required fields. If the two ever
 * disagree, money routes one way and is booked the other.
 *
 * This answers only "would an STK push leave from their shortcode". For
 * "can they receive money at all without us", use tenantCollectionProfile().
 */
function tenantHasOwnMpesaCredentials(PDO $pdo, int $tenantId): bool
{
    return tenantCollectionProfile($pdo, $tenantId)['stk_own'];
}

/**
 * Payment methods that can only have come from an STK push.
 *
 * The routing question is different for each family: an STK push goes to
 * whichever shortcode held the credentials, while a paybill payment goes
 * wherever the customer typed. Only the first can be decided from the tenant's
 * API configuration.
 */
function isStkPaymentMethod(string $method): bool
{
    return in_array(strtolower(trim($method)), ['mpesa', 'mpesa_stk', 'stk'], true);
}

/**
 * Resolve the collection type for a payment about to be booked.
 *
 * Precedence, and why it is in this order:
 *
 *  1. A recorded 'platform' always wins. Only the code holding the credentials
 *     writes that, and downgrading it to 'direct' is the failure that hides a
 *     liability: it tells the ISP they already have money FortuNett is holding.
 *     Never let anything overwrite it.
 *  2. An explicit assertion from the caller. A C2B confirmation handler and a
 *     statement import genuinely know whose till the money came from.
 *  3. A recorded 'direct', but only when the tenant actually has a paybill of
 *     their own that could have received it. This is the important asymmetry:
 *     'direct' is the column DEFAULT, so a row written by any of the several
 *     endpoints that never set the column is indistinguishable from a
 *     deliberate 'direct'. Trusting it blindly would cement exactly the bug
 *     this file exists to fix.
 *  4. Otherwise derive it: a tenant with no M-Pesa credentials of their own has
 *     no account the money could have gone to except the platform's.
 *
 * @param string|null $recorded  collection_type already on the row, if any.
 * @param bool|null $platformCollected  Caller's assertion, or null for "derive it".
 * @return string 'platform' | 'direct'
 */
function resolveCollectionType(
    PDO      $pdo,
    int      $tenantId,
    ?string  $recorded = null,
    ?bool    $platformCollected = null
): string {
    $recorded = is_string($recorded) ? strtolower(trim($recorded)) : '';

    if ($recorded === 'platform') return 'platform';

    if ($platformCollected !== null) {
        return $platformCollected ? 'platform' : 'direct';
    }

    $ownPaybill = tenantHasOwnMpesaCredentials($pdo, $tenantId);

    if ($recorded === 'direct' && $ownPaybill) return 'direct';

    return $ownPaybill ? 'direct' : 'platform';
}
