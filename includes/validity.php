<?php
/**
 * Package validity — the single definition of what units a package may be sold
 * in, and the single way to turn (value, unit) into an expiry date.
 *
 * This exists because the answer used to be re-derived in a dozen endpoints,
 * each with its own whitelist. `payment_pipeline.php` allowed
 * hours/days/weeks/months, `customer/api/activate.php` allowed only
 * days/weeks/months, `hotspot_stk_push.php` carried its own singular map — and
 * every one of them fell back to *days* for anything it did not recognise. So
 * adding a unit to the package form without touching all of them would have
 * sold a 30-minute hotspot voucher and granted 30 days of internet.
 *
 * Rules:
 *  - The stored column is VARCHAR(20), not an ENUM, so historic rows hold
 *    singulars, plurals and typos. Normalisation is on *read*, never a
 *    migration.
 *  - Display retains a legacy days fallback; granting access rejects unknown
 *    units and non-positive durations instead of accidentally selling days.
 */

/** Units a package may be sold in, longest first — order drives the form. */
const PACKAGE_VALIDITY_UNITS = ['minutes', 'hours', 'days', 'weeks', 'months'];

/**
 * Normalise any stored/posted unit to one of PACKAGE_VALIDITY_UNITS.
 * Accepts singulars ("day"), abbreviations ("min", "hrs") and stray casing.
 */
function packageValidityUnit($unit, bool $strict = false): string
{
    $u = strtolower(trim((string)$unit));
    if ($u === '' && !$strict) return 'days';

    static $alias = [
        'min' => 'minutes', 'mins' => 'minutes', 'minute' => 'minutes', 'minutes' => 'minutes',
        'hr'  => 'hours',   'hrs'  => 'hours',   'hour'   => 'hours',   'hours'   => 'hours',
        'day' => 'days',    'days' => 'days',
        'wk'  => 'weeks',   'wks'  => 'weeks',   'week'   => 'weeks',   'weeks'   => 'weeks',
        'mo'  => 'months',  'mon'  => 'months',  'month'  => 'months',  'months'  => 'months',
    ];

    if ($strict && !isset($alias[$u])) throw new InvalidArgumentException('Package duration unit is invalid');
    return $alias[$u] ?? 'days';
}

/** A sane positive duration — a package with 0 validity would expire instantly. */
function packageValidityValue($value): int
{
    return max(1, (int)$value);
}

/**
 * "30 Minutes", "1 Hour" — grammatical, for display only.
 * Never feed this back into strtotime(); use packageExpiryFrom().
 */
function packageValidityLabel($value, $unit): string
{
    $val  = packageValidityValue($value);
    $unit = packageValidityUnit($unit);
    $word = ucfirst($val === 1 ? rtrim($unit, 's') : $unit);
    return $val . ' ' . $word;
}

/**
 * Absolute expiry for a fresh purchase.
 * $base is anything strtotime() understands; defaults to now.
 */
function packageExpiryFrom($value, $unit, $base = 'now'): string
{
    $baseTs = is_int($base) ? $base : (strtotime((string)$base) ?: time());
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) {
        throw new InvalidArgumentException('Package duration must be a positive whole number');
    }
    $val    = (int)$value;
    $u      = packageValidityUnit($unit, true);

    return date('Y-m-d H:i:s', strtotime('+' . $val . ' ' . $u, $baseTs));
}

/**
 * Renewal: stack onto unused time when the account is still live, otherwise
 * start from now. An expired account must not be credited for the gap.
 */
function packageExtendExpiry($currentExpiry, $value, $unit): string
{
    $cur = $currentExpiry ? strtotime((string)$currentExpiry) : 0;
    $base = ($cur && $cur > time()) ? $cur : time();

    return packageExpiryFrom($value, $unit, $base);
}

/**
 * The inverse of packageExpiryFrom(), for undoing a payment that should never
 * have been recorded.
 *
 * Exact only while nothing else has touched the expiry since — the caller is
 * responsible for checking that, because subtracting a period from an expiry a
 * *later* payment extended would silently take time the customer did pay for.
 */
function packageShortenExpiry($currentExpiry, $value, $unit): ?string
{
    $cur = $currentExpiry ? strtotime((string)$currentExpiry) : 0;
    if (!$cur) return null;

    return date('Y-m-d H:i:s', strtotime('-' . packageValidityValue($value) . ' ' . packageValidityUnit($unit), $cur));
}
