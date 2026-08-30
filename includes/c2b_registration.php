<?php
/**
 * Tenant C2B (paybill / till) URL registration — one implementation, three callers.
 *
 * Registering C2B is what makes a payment made *directly to the ISP's own
 * paybill* auto-activate the customer. Without it Safaricom has nowhere to send
 * the confirmation, api/payment/tenant_c2b_confirmation.php is never called, and
 * every direct payment has to be keyed in and activated by hand.
 *
 * It used to be a button an admin had to know to press, on a row that was hidden
 * entirely for Buy Goods tills. So for most tenants auto-activation was simply
 * never switched on and nothing in the UI said so. This file exists so that:
 *
 *   - api/payment_gateways/save.php can register automatically the moment
 *     working credentials are saved (registerTenantC2B),
 *   - api/payment/register_c2b.php stays a thin manual re-run of the same code,
 *   - payments.php / settings can render an honest ON/OFF banner
 *     (tenantC2BStatus) instead of leaving the ISP to guess.
 *
 * Registration is per-shortcode and permanent at Safaricom's end, so the flags
 * cached in payment_gateways.credentials are only a UI hint — re-registering the
 * same URLs is harmless and idempotent.
 */

require_once __DIR__ . '/credential_helper.php';
require_once __DIR__ . '/../classes/MpesaAPI.php';

/** Safaricom cannot reach a private address, so registration from one is refused. */
function c2bHostIsPublic(string $host): bool
{
    $host = explode(':', $host)[0];
    if ($host === '') return false;
    return !preg_match('/^(localhost$|127\.|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host);
}

/** The two URLs Safaricom will call, derived from the tenant's own subdomain. */
function c2bRegistrationUrls(string $host, bool $https = true): array
{
    $host   = explode(':', $host)[0];
    $base   = ($https ? 'https' : 'http') . '://' . $host;
    return [
        'validation'   => $base . '/api/payment/tenant_c2b_validation.php',
        'confirmation' => $base . '/api/payment/tenant_c2b_confirmation.php',
    ];
}

/**
 * Register (or re-register) a tenant's C2B URLs and cache the outcome.
 *
 * @param bool $quiet When true, a failure is logged and returned but never
 *                    surfaces as a hard error — used by the auto-register path
 *                    on save, where the credential save itself must still count
 *                    as a success.
 * @return array{success:bool, error?:string, message?:string, validation_url?:string, confirmation_url?:string}
 */
function registerTenantC2B(PDO $pdo, int $tenantId, int $gatewayId, string $host, bool $https = true, bool $quiet = false): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM payment_gateways
        WHERE id = ? AND tenant_id = ? AND gateway_type = 'mpesa_api'
        LIMIT 1
    ");
    $stmt->execute([$gatewayId, $tenantId]);
    $gateway = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$gateway) {
        return ['success' => false, 'error' => 'M-Pesa API gateway not found'];
    }

    if (!c2bHostIsPublic($host)) {
        return [
            'success' => false,
            'error'   => 'Cannot register C2B from a local/private URL. Deploy to your public domain first.',
        ];
    }

    $creds = decrypt_gateway_credentials($gateway['credentials']);
    $isTill = (($creds['shortcode_type'] ?? 'paybill') === 'till');

    // Buy Goods registers against the store / head-office number, not the till
    // the customer pays to. Safaricom rejects the till number with a shortcode
    // error that reads like bad credentials, so name the real problem here.
    if ($isTill && empty($creds['store_number'])) {
        return [
            'success' => false,
            'error'   => 'This is a Till (Buy Goods) number, so C2B must be registered against your '
                       . 'Store / Head-Office number. Add it under Edit → Store Number '
                       . '(M-Pesa Org Portal → your organisation shortcode), then register again.',
        ];
    }

    $mpesa = new MpesaAPI($pdo, $tenantId);
    if (!$mpesa->hasValidCredentials()) {
        return [
            'success' => false,
            'error'   => 'Incomplete M-Pesa credentials. Ensure Consumer Key, Consumer Secret, '
                       . 'Passkey and Shortcode are all saved.',
        ];
    }

    $urls   = c2bRegistrationUrls($host, $https);
    $result = $mpesa->registerC2B($urls['validation'], $urls['confirmation'], 'Completed');

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    if (empty($result['success'])) {
        @file_put_contents($logDir . '/tenant_c2b.log',
            date('Y-m-d H:i:s') . " C2B REGISTER FAILED" . ($quiet ? ' (auto)' : '')
            . ": tenant=$tenantId gateway=$gatewayId err=" . ($result['error'] ?? 'unknown') . "\n",
            FILE_APPEND | LOCK_EX);
        return ['success' => false, 'error' => $result['error'] ?? 'Registration failed'];
    }

    // Cache the outcome against the shortcode it was registered for, so a later
    // shortcode change can invalidate it (see c2bForgetRegistration()).
    $creds['c2b_registered']       = true;
    $creds['c2b_registered_at']    = date('Y-m-d H:i:s');
    $creds['c2b_registered_for']   = $isTill && !empty($creds['store_number'])
                                     ? $creds['store_number'] : ($creds['shortcode'] ?? '');
    $creds['c2b_validation_url']   = $urls['validation'];
    $creds['c2b_confirmation_url'] = $urls['confirmation'];

    $pdo->prepare("UPDATE payment_gateways SET credentials = ? WHERE id = ? AND tenant_id = ?")
        ->execute([encrypt_gateway_credentials($creds), $gatewayId, $tenantId]);

    @file_put_contents($logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " C2B REGISTERED" . ($quiet ? ' (auto)' : '')
        . ": tenant=$tenantId gateway=$gatewayId validation={$urls['validation']} confirmation={$urls['confirmation']}\n",
        FILE_APPEND | LOCK_EX);

    return [
        'success'          => true,
        'message'          => 'C2B registered. Customers paying your ' . ($isTill ? 'till' : 'paybill')
                            . ' will now be auto-connected on payment.',
        'validation_url'   => $urls['validation'],
        'confirmation_url' => $urls['confirmation'],
    ];
}

/**
 * Strip the cached registration flags from a credentials array.
 *
 * Registration belongs to a shortcode. When the shortcode (or a till's store
 * number) changes, the old flag would keep claiming auto-activation is on for a
 * number Safaricom has never been told about.
 */
function c2bForgetRegistration(array $creds): array
{
    unset(
        $creds['c2b_registered'],
        $creds['c2b_registered_at'],
        $creds['c2b_registered_for'],
        $creds['c2b_validation_url'],
        $creds['c2b_confirmation_url']
    );
    return $creds;
}

/** The shortcode C2B is (or would be) registered against — store number for a till. */
function c2bEffectiveShortcode(array $creds): string
{
    $isTill = (($creds['shortcode_type'] ?? 'paybill') === 'till');
    return (string)(($isTill && !empty($creds['store_number']))
        ? $creds['store_number']
        : ($creds['shortcode'] ?? ''));
}

/**
 * Answer, for one tenant: are customer payments made straight to this ISP's own
 * paybill/till activating the customer automatically — and if not, why not?
 *
 * @return array{
 *   mode:string,          'direct'|'platform'
 *   active:bool,          auto-activation confirmed on
 *   gateway_id:?int,
 *   shortcode:string,
 *   shortcode_type:string,
 *   environment:string,
 *   registered_at:?string,
 *   reason:string         short human sentence for the banner
 * }
 */
function tenantC2BStatus(PDO $pdo, int $tenantId): array
{
    $out = [
        'mode'           => 'platform',
        'active'         => false,
        'gateway_id'     => null,
        'shortcode'      => '',
        'shortcode_type' => 'paybill',
        'environment'    => 'sandbox',
        'registered_at'  => null,
        'reason'         => 'You are collecting through the FortuNett shared paybill. '
                          . 'Payments are activated automatically and settled to you on release.',
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT id, credentials FROM payment_gateways
            WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
            ORDER BY is_default DESC, id ASC LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $gw = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }

    // No usable mpesa_api gateway. Before falling back to "you collect through
    // the platform", check for a manual paybill - because if one exists the
    // customer portal is telling customers to pay it, and NOTHING in this system
    // captures money sent there. Saying "collecting through FortuNett" in that
    // situation is not a harmless default, it is wrong in the direction that
    // loses payments.
    $apiCreds = $gw ? decrypt_gateway_credentials((string)($gw['credentials'] ?? '')) : [];
    $apiUsable = !empty($apiCreds['shortcode']) && !empty($apiCreds['consumer_key']);

    if (!$apiUsable) {
        try {
            $mSt = $pdo->prepare("
                SELECT id, credentials FROM payment_gateways
                WHERE tenant_id = ? AND gateway_type = 'paybill_no_api' AND is_active = 1
                ORDER BY is_default DESC, id ASC LIMIT 1
            ");
            $mSt->execute([$tenantId]);
            $manual = $mSt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $manual = null;
        }

        if ($manual) {
            $mc = decrypt_gateway_credentials((string)($manual['credentials'] ?? ''));
            $sc = trim((string)($mc['paybill_number'] ?? ''));
            if ($sc !== '') {
                $out['mode']       = 'manual_paybill';
                $out['active']     = false;
                $out['gateway_id'] = (int)$manual['id'];
                $out['shortcode']  = $sc;
                $out['reason']     = 'Your paybill ' . $sc . ' is shown to customers, but payments sent '
                                   . 'to it are NOT captured and customers are NOT reconnected — a manual '
                                   . 'paybill has no API behind it. To automate this, add M-Pesa API '
                                   . 'credentials (Consumer Key, Consumer Secret, Passkey and Shortcode) '
                                   . 'for ' . $sc . ' under Settings → Payments; C2B then registers itself '
                                   . 'and payments reconnect customers within seconds. Until then, reconcile '
                                   . 'with Import Statement on this page.';
            }
        }

        if (!$gw) return $out;
    }

    $creds = decrypt_gateway_credentials($gw['credentials']);
    if (empty($creds['shortcode']) || empty($creds['consumer_key'])) return $out;

    $out['mode']           = 'direct';
    $out['gateway_id']     = (int)$gw['id'];
    $out['shortcode']      = (string)$creds['shortcode'];
    $out['shortcode_type'] = $creds['shortcode_type'] ?? 'paybill';
    $out['environment']    = $creds['environment'] ?? 'sandbox';
    $out['registered_at']  = $creds['c2b_registered_at'] ?? null;

    $isTill = ($out['shortcode_type'] === 'till');

    if ($isTill && empty($creds['store_number'])) {
        $out['reason'] = 'Auto-activation is OFF. A Till (Buy Goods) number registers against your '
                       . 'Store / Head-Office number — add it in Settings → Payments → Edit, then register.';
        return $out;
    }

    if (empty($creds['c2b_registered'])) {
        $out['reason'] = 'Auto-activation is OFF. Customers paying your '
                       . ($isTill ? 'till' : 'paybill') . ' ' . $out['shortcode']
                       . ' will not be connected until you register C2B in Settings → Payments.';
        return $out;
    }

    // A cached flag that names a different shortcode is stale, not proof.
    $registeredFor = (string)($creds['c2b_registered_for'] ?? '');
    $effective     = c2bEffectiveShortcode($creds);
    if ($registeredFor !== '' && $registeredFor !== $effective) {
        $out['reason'] = 'Auto-activation was registered for shortcode ' . $registeredFor
                       . ' but you now collect on ' . $effective
                       . '. Re-register C2B in Settings → Payments.';
        return $out;
    }

    $out['active'] = true;
    $out['reason'] = 'Auto-activation is ON. Payments made straight to your '
                   . ($isTill ? 'till' : 'paybill') . ' ' . $out['shortcode']
                   . ' are recorded and the customer is reconnected within seconds.';
    return $out;
}
