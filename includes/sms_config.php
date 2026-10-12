<?php
/**
 * One definition of where SMS credentials come from and what a live TalkSasa
 * endpoint looks like.
 *
 * Two faults made platform-level SMS unusable, and both were invisible:
 *
 *  1. Every writer and every table DEFAULT seeded the api_url as
 *     https://api.talksasa.com/v1/sms/send — a version of the API that no
 *     longer exists. `platform_sms_config` is created with that DEFAULT and
 *     seeded with `INSERT IGNORE (id) VALUES (1)`, so the platform row was
 *     BORN pointing at the dead endpoint. SMSHelper knew the correct v3 URL
 *     but only as a `?? fallback`, which fires when the column is NULL — and
 *     it never was. The provider answered 301 to an HTML page and the
 *     operator was shown "the SMS API URL is wrong" for a URL they had never
 *     typed. `smsNormalizeApiUrl()` upgrades a known-dead endpoint on read
 *     and on write, so no migration has to run for sending to start working.
 *
 *  2. The fallback to the platform key only fired when the tenant had NO row
 *     at all. A tenant who opened the SMS tab and saved it — with a blank key,
 *     which is what happens the first time anyone looks at the form — got an
 *     active row with an empty api_key. That row shadowed the platform
 *     credentials permanently, and the tenant could not tell: the UI said SMS
 *     was configured. `smsResolveConfig()` treats a row with no usable key as
 *     absent, which is the whole point of a platform fallback.
 */

/** The endpoint TalkSasa actually serves. */
const SMS_API_URL_DEFAULT = 'https://bulksms.talksasa.com/api/v3/sms/send';

const SMS_UWAZII_URL = 'https://restapi.uwaziimobile.com/v1/send';

function smsIsUwazii(array $config): bool
{
    return strtolower(trim((string)($config['provider'] ?? ''))) === 'uwazii'
        || strtolower((string)parse_url($config['api_url'] ?? '', PHP_URL_HOST)) === 'restapi.uwaziimobile.com';
}

function smsUwaziiRequest(string $phone, string $message, string $sender, string $key): array
{
    return [
        'headers' => ['Content-Type: application/json; charset=UTF-8', 'Accept: application/json', 'X-Access-Token: ' . trim($key)],
        'body' => [['number' => [$phone], 'senderID' => $sender, 'text' => $message,
            'type' => 'sms', 'lifetime' => 86400, 'delivery' => false]],
    ];
}

function smsUwaziiResponse(int $http, string $body, string $phone): array
{
    $json = json_decode($body, true);
    $detail = $json['errors'] ?? $json['message'] ?? $json['error'] ?? 'No acceptance confirmation returned';
    $detail = is_string($detail) ? $detail : json_encode($detail);
    if (smsSenderRejected($detail)) {
        return ['success' => false, 'sender_failure' => true, 'message' => 'Uwazii rejected the SMS sender: ' . $detail];
    }
    if ($http === 401 || $http === 403 || preg_match('/(?:invalid|expired|missing|incorrect|revoked).*token|token.*(?:invalid|expired|missing|incorrect|revoked)|unauthenticated|unauthorized/i', $detail)) {
        return ['success' => false, 'auth_failure' => true, 'message' => 'Uwazii authentication failed. Check your REST API token and IP whitelist.'];
    }
    if ($http >= 500 || !is_array($json)) {
        return ['success' => false, 'uncertain' => true, 'message' => 'Could not confirm Uwazii acceptance (HTTP ' . $http . ($http >= 500 ? ', provider server error' : ', invalid JSON response') . '). Check provider history before retrying.'];
    }
    if ($http >= 200 && $http < 300 && ($json['status'] ?? null) === true) {
        $parts = $json['data'][$phone] ?? [];
        if (is_array($parts) && count($parts) > 0) {
            foreach ($parts as $part) {
                if (!is_array($part) || empty($part['id_state'])) {
                    return ['success' => false, 'uncertain' => true, 'message' => 'Uwazii returned an incomplete acceptance confirmation. Check provider history.'];
                }
            }
            return ['success' => true, 'response' => $body, 'message' => 'Accepted by Uwazii'];
        }
        return ['success' => false, 'uncertain' => true, 'message' => 'Uwazii returned no message IDs for this number. Check provider history.'];
    }
    return ['success' => false, 'sender_failure' => smsSenderRejected($detail), 'message' => 'Uwazii rejected the SMS: ' . $detail];
}

/**
 * What a settings form shows instead of a stored key.
 *
 * A form must never render the live token into its HTML: it leaks the secret
 * into the page source and lets browser autofill overwrite it. Both settings
 * pages post this back (or an empty field) to mean "keep what is stored".
 */
const SMS_KEY_MASK = '••••••••';

/**
 * Endpoints that are dead and must never be sent to.
 *
 * Matched on host + path prefix rather than exact string because the stored
 * values differ by trailing slash and by http/https.
 */
const SMS_API_URL_DEAD = [
    'api.talksasa.com/v1',
    'api.talksasa.com/api/v1',
];

/** Hosts we know the correct send route for, and may therefore repair. */
const SMS_TALKSASA_HOSTS = ['bulksms.talksasa.com', 'api.talksasa.com'];

/**
 * Trim a stored api_url and repair it to an endpoint that actually answers.
 *
 * Three separate things go wrong with this column, and only one of them used
 * to be caught:
 *
 *  1. The retired v1 endpoint — the table DEFAULT, see the header comment.
 *  2. **The API root instead of the send route.** TalkSasa's docs lead with
 *     `https://bulksms.talksasa.com/api/v3/`, so that is what gets pasted. It
 *     is a live host on a live API version and looks completely correct in the
 *     settings form; the provider answers every send with
 *     `{"status":"error","message":"The route api/v3 could not be found."}`
 *     and HTTP 200. This is what had every SMS on a production deployment —
 *     payment confirmations and expiry reminders alike — failing for days,
 *     with a token that was never the problem.
 *  3. A doubled slash (`.com//api/v3/`) from concatenating a base and a path.
 *
 * So for a host whose send route we know, the path is *replaced* rather than
 * trusted. A URL for any other provider is left alone beyond collapsing the
 * slashes — we have no idea what its routes look like.
 *
 * A blank value resolves to the default too — a NULL column and an empty
 * string mean the same thing to an operator and used to behave differently.
 */
function smsNormalizeApiUrl(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') return SMS_API_URL_DEFAULT;

    // Collapse doubled slashes in the path without touching the scheme's "//".
    $url = preg_replace('#(?<!:)//+#', '/', $url);

    $probe = rtrim(preg_replace('#^https?://#i', '', $url), '/');

    foreach (SMS_API_URL_DEAD as $dead) {
        if (stripos($probe, $dead) === 0) return SMS_API_URL_DEFAULT;
    }

    $host = strtolower(explode('/', $probe)[0]);
    if (in_array($host, SMS_TALKSASA_HOSTS, true)) {
        // Anything that is not the send route — the API root, a trailing
        // slash, /sms, /send — is not something to POST a message to.
        if (!preg_match('#/sms/send$#i', $probe)) return SMS_API_URL_DEFAULT;
    }

    return $url;
}

/** True when a stored api_url points at an endpoint that no longer answers. */
function smsApiUrlIsStale(?string $url): bool
{
    $url = trim((string)$url);
    return $url !== '' && smsNormalizeApiUrl($url) !== $url;
}

/**
 * A credential row is only usable if it carries a non-blank API key.
 *
 * `is_active` is checked by the caller's query; this is the separate question
 * of whether the row has anything in it worth sending with.
 */
function smsSenderRejected(string $message): bool
{
    return (bool)preg_match('/(?:sender[ _-]?id|sender|originator).*(?:not authori[sz]ed|unauthori[sz]ed|not approved|unapproved|invalid|not registered|not allowed)|(?:invalid|unapproved|unauthori[sz]ed).*?(?:sender|originator)/i', $message);
}

function smsFallbackFailure(array $fallback, bool $senderFailure = false): array
{
    $fallback['success'] = false;
    $fallback[$senderFailure ? 'tenant_sender_failure' : 'tenant_auth_failure'] = true;
    $fallback['message'] = ($senderFailure ? 'The tenant SMS sender ID was rejected. Platform fallback: ' : 'The tenant SMS token was rejected. Platform fallback: ')
        . ($fallback['message'] ?? 'the provider did not confirm the message.');
    return $fallback;
}

function smsConfigIsUsable(?array $row): bool
{
    return is_array($row) && trim((string)($row['api_key'] ?? '')) !== '';
}

/**
 * The platform's shared SMS credentials, or null if they cannot send.
 *
 * Returned with the api_url already normalised, so a caller can never reach
 * the dead v1 endpoint through this function.
 */
function smsPlatformConfig(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->query("SELECT * FROM platform_sms_config WHERE id = 1 AND is_active = 1 LIMIT 1");
        $row  = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    } catch (Throwable $e) {
        return null; // table may predate the platform-comms migration
    }
    if (!smsConfigIsUsable($row)) return null;

    $row['api_url'] = smsIsUwazii($row) ? SMS_UWAZII_URL : smsNormalizeApiUrl($row['api_url'] ?? null);
    return $row;
}

/**
 * Decide which credentials a tenant sends with.
 *
 * Returns [config|null, usingPlatform]. The tenant's own row wins whenever it
 * is active AND has a key; anything less falls through to the platform, which
 * is what makes "set it once at platform level" true.
 */
function smsResolveConfig(PDO $pdo, $tenantId): array
{
    $own = null;
    if ($tenantId) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$tenantId]);
            $own = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $own = null;
        }
    }

    if (smsConfigIsUsable($own)) {
        $own['api_url'] = smsIsUwazii($own) ? SMS_UWAZII_URL : smsNormalizeApiUrl($own['api_url'] ?? null);
        return [$own, false];
    }

    $platform = smsPlatformConfig($pdo);
    return $platform ? [$platform, true] : [null, false];
}

/**
 * Rewrite stored dead endpoints to the live one.
 *
 * Read-time normalisation already makes sending work, so this is only about
 * making the settings forms stop showing operators a URL that is a lie.
 * Called from the two settings pages, not on every request.
 */
function smsHealStoredApiUrls(PDO $pdo): int
{
    $healed = 0;
    foreach ([['platform_sms_config', 'id'], ['sms_configurations', 'id']] as [$table, $pk]) {
        try {
            $rows = $pdo->query("SELECT {$pk} AS pk, api_url FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $r) {
            if (!smsApiUrlIsStale($r['api_url'] ?? null)) continue;
            try {
                $u = $pdo->prepare("UPDATE {$table} SET api_url = ? WHERE {$pk} = ?");
                $u->execute([smsNormalizeApiUrl($r['api_url']), $r['pk']]);
                $healed++;
            } catch (Throwable $e) { /* leave it; read-time normalisation covers sending */ }
        }
    }
    return $healed;
}
