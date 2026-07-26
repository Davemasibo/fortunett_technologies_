<?php
/**
 * Resolve an M-Pesa BillRefNumber to a (tenant, client) pair.
 *
 * This is the single place that decides "who paid?" for paybill money, and it
 * must accept everything a real customer might actually type, because they are
 * standing at a till and we only get one shot at it:
 *
 *   1. Their account number exactly            — "E001", "F00012"
 *   2. Their phone number                      — "0712345678", "254712345678"
 *      The captive portal's manual-paybill instructions literally say
 *      "Account No: your phone number", so this is the COMMON case, not an edge
 *      case. It previously fell through to UNROUTABLE and the customer had to be
 *      activated by hand.
 *   3. PREFIX + client id                      — "J0023"
 *   4. A bare client id                        — "23"
 *
 * The prefix lookup deliberately mirrors AccountNumberGenerator::getPrefix() —
 * tenant_settings first, then users.account_prefix, then subdomain, then admin
 * username. The old resolver only checked users.account_prefix AND required
 * role='admin', so any tenant whose admin row has a different role (or whose
 * prefix lives in tenant_settings) could never be matched at all.
 */

/**
 * @param string   $ref       BillRefNumber as sent by Safaricom
 * @param string   $phone     MSISDN from the payment, used as a secondary match
 * @param int|null $tenantId  Constrain to one tenant (tenant-owned paybill); null = search all (platform paybill)
 * @return array|null ['tenant_id'=>int,'client_id'=>int,'matched_by'=>string]
 */
function resolveAccountRef(PDO $pdo, string $ref, string $phone = '', ?int $tenantId = null): ?array
{
    $ref = strtoupper(trim($ref));
    $scope = $tenantId ? ' AND tenant_id = ' . (int)$tenantId : '';

    // ── 1. Exact account number ───────────────────────────────────────────────
    if ($ref !== '') {
        $st = $pdo->prepare("SELECT id, tenant_id FROM clients WHERE UPPER(TRIM(account_number)) = ?$scope LIMIT 2");
        $st->execute([$ref]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return ['tenant_id' => (int)$rows[0]['tenant_id'], 'client_id' => (int)$rows[0]['id'], 'matched_by' => 'account_number'];
        }
        if (count($rows) > 1) {
            // Ambiguous across tenants — refuse rather than credit the wrong ISP
            error_log("resolveAccountRef: account_number '$ref' is not unique across tenants");
            return null;
        }
    }

    // ── 2. Phone number, from the ref or from the paying MSISDN ───────────────
    foreach ([_arNormalisePhone($ref), _arNormalisePhone($phone)] as $candidate) {
        if ($candidate === null) {
            continue;
        }
        // Match the last 9 digits so 0712…, 254712… and +254712… all agree
        $tail = substr($candidate, -9);
        $st = $pdo->prepare("
            SELECT id, tenant_id FROM clients
            WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ?$scope
            ORDER BY (status = 'active') DESC, id DESC
        ");
        $st->execute([$tail]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }

        // Duplicates inside ONE tenant are fine — we still know which ISP gets
        // the money, so take the active/newest row. Only a phone that spans
        // different tenants is truly ambiguous, and there we must refuse rather
        // than credit the wrong ISP.
        $tenants = array_unique(array_map(static fn($r) => (int)$r['tenant_id'], $rows));
        if (count($tenants) > 1) {
            error_log("resolveAccountRef: phone '$tail' matches clients in tenants " . implode(',', $tenants));
            continue;
        }

        return [
            'tenant_id'  => (int)$rows[0]['tenant_id'],
            'client_id'  => (int)$rows[0]['id'],
            'matched_by' => count($rows) > 1 ? 'phone(newest of ' . count($rows) . ')' : 'phone',
        ];
    }

    // ── 3. PREFIX + client id ─────────────────────────────────────────────────
    if ($ref !== '') {
        for ($len = 3; $len >= 1; $len--) {
            if (strlen($ref) <= $len) continue;

            $prefix    = substr($ref, 0, $len);
            $digits    = substr($ref, $len);
            if (!ctype_digit($digits)) continue;
            $candidateId = (int)ltrim($digits, '0');
            if ($candidateId <= 0) continue;

            foreach (_arTenantsForPrefix($pdo, $prefix, $tenantId) as $tid) {
                $st = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
                $st->execute([$candidateId, $tid]);
                if ($st->fetchColumn()) {
                    return ['tenant_id' => $tid, 'client_id' => $candidateId, 'matched_by' => "prefix:$prefix"];
                }
            }
        }
    }

    // ── 4. Bare client id — only safe when the tenant is already known ────────
    if ($tenantId && $ref !== '' && ctype_digit(ltrim($ref, '0') ?: '0')) {
        $candidateId = (int)ltrim($ref, '0');
        if ($candidateId > 0) {
            $st = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
            $st->execute([$candidateId, $tenantId]);
            if ($st->fetchColumn()) {
                return ['tenant_id' => $tenantId, 'client_id' => $candidateId, 'matched_by' => 'client_id'];
            }
        }
    }

    return null;
}

/**
 * Tenants whose account prefix matches $prefix.
 * Mirrors AccountNumberGenerator::getPrefix() precedence so a number that was
 * issued by the generator can always be resolved back again.
 */
function _arTenantsForPrefix(PDO $pdo, string $prefix, ?int $only = null): array
{
    $prefix = strtoupper($prefix);
    $out    = [];

    $add = function ($tid) use (&$out, $only) {
        $tid = (int)$tid;
        if ($tid && !in_array($tid, $out, true) && (!$only || $tid === $only)) {
            $out[] = $tid;
        }
    };

    // Priority 1 — tenant_settings.account_prefix
    try {
        $st = $pdo->prepare("SELECT tenant_id FROM tenant_settings WHERE setting_key='account_prefix' AND UPPER(setting_value) = ?");
        $st->execute([$prefix]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) $add($tid);
    } catch (Throwable $_e) {}

    // Priority 2 — users.account_prefix (any role: the tenant admin is not
    // always stored with role='admin', which is what broke the old lookup)
    try {
        $st = $pdo->prepare("SELECT tenant_id FROM users WHERE UPPER(account_prefix) = ? AND tenant_id IS NOT NULL");
        $st->execute([$prefix]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) $add($tid);
    } catch (Throwable $_e) {}

    // Priority 3 — tenant subdomain initials
    try {
        $st = $pdo->prepare("SELECT id FROM tenants WHERE UPPER(LEFT(subdomain, ?)) = ?");
        $st->execute([strlen($prefix), $prefix]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) $add($tid);
    } catch (Throwable $_e) {}

    // Priority 4 — admin username initials
    try {
        $st = $pdo->prepare("
            SELECT t.id FROM tenants t
            JOIN users u ON u.id = t.admin_user_id
            WHERE UPPER(LEFT(u.username, ?)) = ?
        ");
        $st->execute([strlen($prefix), $prefix]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) $add($tid);
    } catch (Throwable $_e) {}

    return $out;
}

/** "0712 345 678" / "+254712345678" → "254712345678"; null when not a phone. */
function _arNormalisePhone(string $raw): ?string
{
    $d = preg_replace('/\D/', '', $raw);
    if ($d === '' || strlen($d) < 9) {
        return null;
    }
    if (str_starts_with($d, '0'))   $d = '254' . substr($d, 1);
    if (str_starts_with($d, '7') || str_starts_with($d, '1')) {
        if (strlen($d) === 9) $d = '254' . $d;
    }
    return strlen($d) >= 11 ? $d : null;
}
