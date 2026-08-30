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
 * Does this tenant have their own working Daraja credentials?
 *
 * Mirrors the routing test in api/payment/stk_push.php and
 * api/payment/hotspot_stk_push.php EXACTLY — same gateway_type, same
 * is_active/is_default ordering, same four required fields. If the two ever
 * disagree, money routes one way and is booked the other.
 */
function tenantHasOwnMpesaCredentials(PDO $pdo, int $tenantId): bool
{
    if ($tenantId <= 0) return false;

    static $cache = [];
    if (array_key_exists($tenantId, $cache)) return $cache[$tenantId];

    $has = false;
    try {
        $st = $pdo->prepare(
            "SELECT credentials FROM payment_gateways
             WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
             ORDER BY is_default DESC LIMIT 1"
        );
        $st->execute([$tenantId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            // Credentials are an AES blob; decrypt_gateway_credentials() also
            // passes plain-JSON rows through, so this is safe on both formats.
            // A raw json_decode() here reads an encrypted row as NULL and
            // concludes the tenant has no gateway at all.
            $c = decrypt_gateway_credentials((string)($row['credentials'] ?? ''));
            $has = !empty($c['consumer_key']) && !empty($c['consumer_secret'])
                && !empty($c['passkey'])      && !empty($c['shortcode']);
        }
    } catch (Throwable $e) {
        error_log('tenantHasOwnMpesaCredentials(' . $tenantId . '): ' . $e->getMessage());
    }

    return $cache[$tenantId] = $has;
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
