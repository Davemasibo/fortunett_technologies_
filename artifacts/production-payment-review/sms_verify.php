<?php
/**
 * End-to-end verification of a tenant's SMS setup.
 *
 * "Is SMS working for this tenant?" had three different answers depending on
 * where you asked: the tenant's own settings form (which shows whatever is
 * stored), the outbox (which shows what happened days ago), and
 * tools/sms_diagnose.php (which had to be run per tenant over SSH). None of
 * them could answer it for the whole fleet, which is how an entire platform
 * spent days failing every send while every settings page looked correct.
 *
 * Everything here resolves through smsResolveConfig() and smsNormalizeApiUrl()
 * -- the same functions SMSHelper sends with. A verifier that derives the
 * endpoint or the key separately can probe one thing while the sender uses
 * another, which is the one thing a diagnostic must never do.
 *
 * The probe is a GET against the provider's balance route with the effective
 * bearer token. It proves the credential end to end without sending a message
 * or spending a credit: a valid token returns data, a dead one returns
 * "Unauthenticated." with an HTTP 200 that no status-code check would catch.
 */

require_once __DIR__ . '/sms_config.php';

/** Verdicts, worst first — this order drives the fleet summary. */
const SMS_VERDICT_ORDER = ['rejected', 'unreachable', 'no_credentials', 'simulation', 'stale_url', 'untested', 'ok'];

/**
 * Verify one tenant, end to end.
 *
 * @param bool $probe  Set false to skip the network call and report configuration only.
 */
function smsVerifyTenant(PDO $pdo, int $tenantId, bool $probe = true): array
{
    [$config, $usingPlatform] = smsResolveConfig($pdo, $tenantId);

    $out = [
        'tenant_id'      => $tenantId,
        'source'         => $config ? ($usingPlatform ? 'platform' : 'own') : 'none',
        'sender_id'      => $config ? trim((string)($config['sender_id'] ?? '')) : '',
        'api_url'        => $config ? smsNormalizeApiUrl($config['api_url'] ?? null) : '',
        'stored_api_url' => $config ? trim((string)($config['api_url'] ?? '')) : '',
        'key_length'     => 0,
        'key_has_pipe'   => false,
        'verdict'        => 'no_credentials',
        'detail'         => '',
        'action'         => '',
    ];

    if (!$config) {
        $out['detail'] = 'No usable credentials of their own and no platform key to fall back on.';
        $out['action'] = 'Set a platform-wide TalkSasa token under System Settings → SMS, or give this tenant their own.';
        $out += smsRecentOutcomes($pdo, $tenantId);
        return $out;
    }

    $key = trim((string)($config['api_key'] ?? ''));
    $out['key_length']   = strlen($key);
    $out['key_has_pipe'] = strpos($key, '|') !== false;

    // A stale stored URL still SENDS correctly -- smsNormalizeApiUrl() repairs
    // it on read -- so this is a warning about what the settings form is
    // showing the operator, not about deliverability.
    if (smsApiUrlIsStale($out['stored_api_url'])) {
        $out['verdict'] = 'stale_url';
        $out['detail']  = 'Stored endpoint is ' . $out['stored_api_url'] . ', repaired on read to ' . $out['api_url'] . '.';
        $out['action']  = 'Open the SMS settings page once to write the corrected URL back.';
    }

    if (!$probe) {
        if ($out['verdict'] === 'no_credentials') $out['verdict'] = 'untested';
        $out += smsRecentOutcomes($pdo, $tenantId);
        return $out;
    }

    $result = smsProbeKey($out['api_url'], $key);
    $out['probe'] = $result;

    if (!empty($result['simulation'])) {
        $out['verdict'] = 'simulation';
        $out['detail']  = 'The stored key is the literal TEST_KEY sentinel: every message is reported as sent '
                        . 'and none ever leaves the server.';
        $out['action']  = 'Replace it with a real TalkSasa API token before relying on any delivery figure.';
    } elseif ($result['ok']) {
        // A stale URL is still worth reporting even when sending works.
        if ($out['verdict'] !== 'stale_url') {
            $out['verdict'] = 'ok';
            $out['detail']  = 'The provider accepted this token.';
            $out['action']  = '';
        }
    } elseif ($result['reachable'] === false) {
        $out['verdict'] = 'unreachable';
        $out['detail']  = 'Could not reach the provider: ' . $result['error'];
        $out['action']  = 'Check outbound network access from the server, then re-run.';
    } else {
        $out['verdict'] = 'rejected';
        $out['detail']  = 'The provider rejected the '
                        . ($usingPlatform ? 'PLATFORM' : "tenant's own")
                        . ' token: ' . $result['message'];
        $out['action']  = $out['key_has_pipe']
            ? 'Generate a new API token in the TalkSasa dashboard under Developers/API and paste it in'
              . ($usingPlatform ? ' under System Settings → SMS.' : " on that tenant's SMS settings.")
            : 'The stored value contains no "|", so it is an older v1 key and can never authenticate against '
              . '/api/v3. Issue a v3 API token instead.';
    }

    $out += smsRecentOutcomes($pdo, $tenantId);
    return $out;
}

/**
 * GET the provider's balance route with a bearer token.
 *
 * Results are memoised on (url, key) for the life of the request: every tenant
 * falling back to the platform key shares one credential, and probing it once
 * per tenant would turn a fleet sweep into dozens of identical round trips.
 */
function smsProbeKey(string $url, string $key): array
{
    static $cache = [];
    $ck = md5($url . '|' . $key);
    if (isset($cache[$ck])) return $cache[$ck];

    $out = ['ok' => false, 'reachable' => null, 'http' => 0, 'message' => '', 'error' => ''];

    if ($key === '') {
        $out['message'] = 'no API key stored';
        return $cache[$ck] = $out;
    }
    if ($key === 'TEST_KEY') {
        // The simulation sentinel. Reporting it as working would be a lie -- it
        // sends nothing anywhere -- but it is not a rejection either, so it gets
        // its own answer rather than being blamed on the provider.
        $out['simulation'] = true;
        $out['message']    = 'simulation mode';
        return $cache[$ck] = $out;
    }

    $base  = preg_replace('~/sms/send/?$~', '', $url);
    $ch = curl_init($base . '/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $body  = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr  = curl_error($ch);
    curl_close($ch);

    $out['http'] = $http;

    if ($cErr !== '') {
        $out['reachable'] = false;
        $out['error']     = $cErr;
        return $cache[$ck] = $out;
    }
    $out['reachable'] = true;

    // An HTML body means a web page, not an API — almost always a wrong URL.
    if (stripos(ltrim((string)$body), '<') === 0) {
        $out['message'] = 'the endpoint returned a web page, not an API response';
        return $cache[$ck] = $out;
    }

    $json = json_decode((string)$body, true);
    $status = strtolower((string)($json['status'] ?? ''));

    // This provider answers "Unauthenticated." with an HTTP 200, so the status
    // code alone proves nothing either way.
    if (is_array($json) && $status !== '' && $status !== 'error' && $status !== 'failed') {
        $out['ok']      = true;
        $out['message'] = 'accepted';
        return $cache[$ck] = $out;
    }

    $out['message'] = (string)($json['message'] ?? $json['error'] ?? ('HTTP ' . $http . ' ' . substr((string)$body, 0, 80)));
    return $cache[$ck] = $out;
}

/** What actually happened to this tenant's recent messages. */
function smsRecentOutcomes(PDO $pdo, int $tenantId, int $days = 30): array
{
    $out = ['sent_30d' => 0, 'failed_30d' => 0, 'last_error' => '', 'last_sent_at' => null];

    try {
        $st = $pdo->prepare("
            SELECT status, COUNT(*) AS n, MAX(sent_at) AS last_at
            FROM sms_outbox
            WHERE tenant_id = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY status
        ");
        $st->execute([$tenantId, $days]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strtolower($r['status']) === 'sent') {
                $out['sent_30d']     = (int)$r['n'];
                $out['last_sent_at'] = $r['last_at'];
            } else {
                $out['failed_30d'] += (int)$r['n'];
            }
        }
    } catch (Throwable $e) { /* table may not exist on an unmigrated deployment */ }

    try {
        $st = $pdo->prepare("
            SELECT provider_response FROM sms_outbox
            WHERE tenant_id = ? AND status <> 'sent'
            ORDER BY sent_at DESC LIMIT 1
        ");
        $st->execute([$tenantId]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $j = json_decode((string)$raw, true);
            $out['last_error'] = is_array($j) ? (string)($j['message'] ?? '') : substr((string)$raw, 0, 160);
        }
    } catch (Throwable $e) { /* as above */ }

    return $out;
}

/** Verify every tenant. Sorted worst-first so the page leads with what is broken. */
function smsVerifyAllTenants(PDO $pdo, bool $probe = true): array
{
    try {
        $tenants = $pdo->query("SELECT id, company_name, subdomain, status FROM tenants ORDER BY company_name")
                       ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $rows = [];
    foreach ($tenants as $t) {
        $r = smsVerifyTenant($pdo, (int)$t['id'], $probe);
        $r['company_name']   = $t['company_name'];
        $r['subdomain']      = $t['subdomain'];
        $r['tenant_status']  = $t['status'];
        $rows[] = $r;
    }

    usort($rows, function ($a, $b) {
        $ia = array_search($a['verdict'], SMS_VERDICT_ORDER, true);
        $ib = array_search($b['verdict'], SMS_VERDICT_ORDER, true);
        if ($ia === $ib) return strcasecmp((string)$a['company_name'], (string)$b['company_name']);
        return $ia <=> $ib;
    });

    return $rows;
}

/** Human labels for a verdict. */
function smsVerdictLabel(string $verdict): string
{
    return [
        'ok'             => 'Working',
        'stale_url'      => 'Working — stored URL is stale',
        'untested'       => 'Not probed',
        'no_credentials' => 'No credentials',
        'simulation'     => 'Simulation only (TEST_KEY)',
        'unreachable'    => 'Provider unreachable',
        'rejected'       => 'Token rejected',
    ][$verdict] ?? $verdict;
}
