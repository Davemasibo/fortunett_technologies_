<?php
require_once __DIR__ . '/../includes/sms_config.php';
require_once __DIR__ . '/../includes/schema_guard.php';

class SMSHelper {
    private $pdo;
    private $tenant_id;
    private $config;
    private $using_platform = false;

    public function __construct($pdo, $tenant_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->loadConfig();
    }

    /**
     * A sender bound to the platform credentials and to no tenant.
     *
     * Used by the super-admin test send: it must prove the PLATFORM key works
     * without a tenant's own row shadowing it, and must not write a test
     * message into any tenant's outbox.
     */
    public static function platform($pdo): self
    {
        return new self($pdo, 0);
    }

    private function loadConfig() {
        // Which credentials a tenant sends with is decided in one place —
        // see includes/sms_config.php. In particular a tenant row that exists
        // but carries no API key is treated as absent, so it cannot shadow the
        // platform key the way it used to.
        [$this->config, $this->using_platform] = smsResolveConfig($this->pdo, $this->tenant_id);
    }

    public function isUsingPlatform(): bool {
        return $this->using_platform;
    }

    public function hasConfig(): bool {
        return !empty($this->config);
    }

    public function send($phone, $message, $clientId = null) {
        if (!$this->config) {
            return ['success' => false, 'message' => 'SMS not configured. Set up SMS credentials in Settings or contact your platform admin.'];
        }

        $phone    = $this->formatPhone($phone);
        $response = $this->sendViaTalkSasa($phone, $message);

        // A tenant whose own TalkSasa token is wrong, expired or revoked used
        // to lose EVERY customer notification, permanently and silently: their
        // row is "usable" (it has a key) so it shadows the platform key, and
        // nothing in the admin UI reports a send failure. The platform key is
        // the whole point of a platform fallback -- use it rather than let the
        // customer go untold that their payment landed.
        if (empty($response['success']) && !empty($response['auth_failure']) && !$this->using_platform) {
            $platform = smsPlatformConfig($this->pdo);
            if ($platform) {
                $ownCfg = $this->config;
                $this->config         = $platform;
                $this->using_platform = true;

                $retry = $this->sendViaTalkSasa($phone, $message);
                if (!empty($retry['success'])) {
                    error_log('[sms] tenant ' . $this->tenant_id . ' key rejected; sent on the platform key instead');
                    $retry['fell_back'] = true;
                    $response = $retry;
                } else {
                    // Both keys failed. Report the tenant's own failure -- that
                    // is the one they can act on -- but say the fallback was
                    // tried so nobody hunts for a platform outage that is not
                    // the cause.
                    $this->config         = $ownCfg;
                    $this->using_platform = false;
                    $response['message']  = ($response['message'] ?? 'SMS failed.')
                        . ' The platform key was tried as a fallback and was also rejected.';
                }
            }
        }

        $this->logMessage($clientId, $phone, $message, $response);

        return $response;
    }

    /**
     * Normalise anything a human or a captive portal typed into 2547XXXXXXXX.
     *
     * The old version only handled a leading 0. A customer who typed
     * `712345678` -- which the hotspot portal accepts, and which is how many
     * people write their own number -- was handed to the provider as nine
     * digits and rejected; `+254 712 345 678` survived only by accident of the
     * digit strip. Every SMS to those customers failed at the provider with a
     * message nobody read.
     */
    private function formatPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', (string)$phone);

        // +254 0712... -- both the country code and the trunk 0. Seen whenever
        // a stored 07 number is prefixed with the country code by a form.
        if (strpos($phone, '2540') === 0) {
            $phone = '254' . substr($phone, 4);
        } elseif (strpos($phone, '0') === 0) {
            $phone = '254' . substr($phone, 1);
        } elseif (strlen($phone) === 9 && (strpos($phone, '7') === 0 || strpos($phone, '1') === 0)) {
            // Bare subscriber number: 7XXXXXXXX (Safaricom/Airtel) or 1XXXXXXXX
            $phone = '254' . $phone;
        }

        return $phone;
    }

    private function sendViaTalkSasa($phone, $message) {
        $url      = smsNormalizeApiUrl($this->config['api_url'] ?? null);
        // Trimmed because a token pasted from a dashboard very often carries a
        // trailing newline or space, and a bearer header with one is rejected
        // as "Unauthenticated." — indistinguishable from a wrong key.
        $apiKey   = trim((string)($this->config['api_key'] ?? ''));
        $senderId = trim((string)($this->config['sender_id'] ?? ''));

        // Simulation is opt-in via an explicit sentinel key and nothing else.
        // This used to also trigger on an EMPTY key, so a deployment with no
        // credentials at all reported every message as sent and logged it as
        // 'sent' — the operator had no way to discover SMS was never leaving
        // the server. An empty key now falls through to send() returning the
        // honest "not configured" error.
        if ($apiKey === 'TEST_KEY') {
            $via = $this->using_platform ? ' (platform)' : '';
            return ['success' => true, 'message' => 'Simulated sent to ' . $phone . $via];
        }

        // Both field spellings are sent because TalkSasa renamed 'phone' to
        // 'recipient' between API versions and ignores whichever it does not
        // use. Sending both means one stored api_url change does not also need
        // a code change.
        $data = [
            'api_key'   => $apiKey,
            'sender_id' => $senderId,
            'phone'     => $phone,
            'recipient' => $phone,
            'type'      => 'plain',
            'message'   => $message,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            // v3 authenticates by bearer token; v1 reads api_key from the body.
            // An endpoint that wants neither ignores the header.
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        // The provider answers a bare 301 when the stored api_url is out of
        // date, and cURL does NOT follow redirects by default — so the send
        // failed with an Apache "Moved Permanently" page shown to the operator
        // as if it were an SMS error. POSTREDIR_ALL is what makes the retry a
        // POST as well; without it cURL silently downgrades to GET and the
        // message body is dropped.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        $result    = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'message' => 'Could not reach the SMS provider: ' . $curlError];
        }

        // An HTML body means we reached a web page, not an API — almost always a
        // wrong api_url. Say that instead of pasting an Apache error page into
        // the UI, which is what the operator actually saw.
        $looksHtml = stripos(ltrim((string)$result), '<') === 0;
        if ($looksHtml) {
            return [
                'success' => false,
                'message' => 'The SMS API URL is wrong — ' . $finalUrl . ' returned a web page, not an API response. '
                           . 'Fix it under Settings on this page (TalkSasa\'s current endpoint is '
                           . 'https://bulksms.talksasa.com/api/v3/sms/send).',
            ];
        }

        $json = json_decode((string)$result, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            // A 200 is not proof of delivery. These APIs return an error status
            // in the body with an HTTP 200, so treating the code alone as
            // success logged failed messages as 'sent'.
            $status = strtolower((string)($json['status'] ?? 'success'));
            if (is_array($json) && in_array($status, ['error', 'failed', 'failure'], true)) {
                $why = $json['message'] ?? $json['error'] ?? 'the provider rejected the message';
                $why = is_string($why) ? $why : json_encode($why);

                // "Unauthenticated." arrives with an HTTP 200 from this provider,
                // so it never reaches the 401 branch below. It is a credentials
                // problem regardless of the status code, and saying so beats
                // echoing one bare word to the operator.
                if (stripos($why, 'unauthenticated') !== false || stripos($why, 'unauthorized') !== false) {
                    return ['success' => false, 'auth_failure' => true, 'message' => $this->authFailureHint(200)];
                }

                return ['success' => false, 'message' => 'Provider rejected it: ' . $why];
            }

            $via = $this->using_platform ? ' (via platform SMS)' : '';
            return ['success' => true, 'response' => $result, 'message' => 'Sent' . $via];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'auth_failure' => true, 'message' => $this->authFailureHint($httpCode)];
        }

        $detail = is_array($json) ? ($json['message'] ?? json_encode($json)) : substr((string)$result, 0, 300);
        return ['success' => false, 'message' => 'Provider error (HTTP ' . $httpCode . ') from ' . $finalUrl . ': ' . $detail];
    }

    private function logMessage($clientId, $phone, $message, $response) {
        // SMSHelper::platform() has no tenant to file the message under, and
        // sms_outbox.tenant_id is not nullable — a super-admin test send must
        // not land in some tenant's message history either way.
        if (!$this->tenant_id) return;

        // This runs AFTER the message has already left the server, so an
        // unwritable outbox must not be allowed to turn a delivered SMS into a
        // thrown exception at the call site. On a deployment missing the
        // sms_outbox migration that is exactly what happened: every send in
        // the payment pipeline was recorded as a failure and every send from
        // sms.php showed the operator an error, for messages the customer
        // actually received.
        try {
            ensureSmsTables($this->pdo);

            $status           = !empty($response['success']) ? 'sent' : 'failed';
            $providerResponse = is_array($response) ? json_encode($response) : $response;
            $stmt = $this->pdo->prepare("INSERT INTO sms_outbox (tenant_id, client_id, recipient_phone, message, status, provider_response) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$this->tenant_id, $clientId, $phone, $message, $status, $providerResponse]);
        } catch (Throwable $e) {
            error_log('[sms] could not write sms_outbox for tenant ' . $this->tenant_id . ': ' . $e->getMessage());
        }
    }

    public function getTemplates() {
        $stmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE tenant_id = ?");
        $stmt->execute([$this->tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveTemplate($key, $name, $content) {
        $stmt = $this->pdo->prepare("INSERT INTO sms_templates (tenant_id, template_key, template_name, template_content) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE template_name = VALUES(template_name), template_content = VALUES(template_content)");
        return $stmt->execute([$this->tenant_id, $key, $name, $content]);
    }

    public function saveConfig($provider, $apiKey, $senderId, $apiUrl) {
        // Trimmed on the way in as well as on the way out. A token pasted from
        // a provider dashboard routinely carries a trailing newline, and a
        // bearer header built from it is rejected as "Unauthenticated." — a
        // message that gives the operator no clue the value merely has an
        // invisible character on the end.
        $provider = trim((string)$provider);
        $apiKey   = trim((string)$apiKey);
        $senderId = trim((string)$senderId);
        $apiUrl   = smsNormalizeApiUrl($apiUrl);

        $stmt = $this->pdo->prepare("INSERT INTO sms_configurations (tenant_id, provider, api_key, sender_id, api_url) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE provider = VALUES(provider), api_key = VALUES(api_key), sender_id = VALUES(sender_id), api_url = VALUES(api_url)");
        return $stmt->execute([$this->tenant_id, $provider, $apiKey, $senderId, $apiUrl]);
    }

    /**
     * Say WHICH credentials failed and where to fix them.
     *
     * Without this the operator sees the provider's one-word "Unauthenticated."
     * and cannot tell whether the system used their own API key or fell back to
     * the platform's — which are edited in two different places by two
     * different people.
     */
    private function authFailureHint(int $httpCode): string
    {
        $key    = trim((string)($this->config['api_key'] ?? ''));
        $masked = $key === ''
            ? '(empty)'
            : substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4)
              . ' — ' . strlen($key) . ' chars';

        if ($this->using_platform) {
            return 'The SMS provider rejected the PLATFORM API key (' . $masked . ', HTTP ' . $httpCode . '). '
                 . 'Your account has no SMS credentials of its own, so it is using FortuNett\'s. '
                 . 'Either add your own TalkSasa key under Settings on this page, or ask your FortuNett admin to renew the platform key.';
        }

        return 'The SMS provider rejected your API key (' . $masked . ', HTTP ' . $httpCode . '). '
             . 'TalkSasa v3 wants the API TOKEN from Dashboard → Developers/API, not your password or the v1 key. '
             . 'Update it under Settings on this page.';
    }

    public function sendTemplate($clientId, $templateKey) {
        $cStmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $cStmt->execute([$clientId, $this->tenant_id]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) return ['success' => false, 'message' => 'Client not found'];

        $tStmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE tenant_id = ? AND template_key = ?");
        $tStmt->execute([$this->tenant_id, $templateKey]);
        $template = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) return ['success' => false, 'message' => 'Template not found'];

        $message = $this->renderPlaceholders($template['template_content'], $client);

        return $this->send($client['phone'], $message, $clientId);
    }

    /**
     * Fill {placeholders} from a client row.
     *
     * Public and used by sms.php as well, so a message typed by hand gets the
     * same substitution a stored template does. Previously this lived inline in
     * sendTemplate(), so picking a template in the Send SMS box and editing one
     * word sent the customer a literal "{name}".
     */
    public function renderPlaceholders(string $message, array $client): string
    {
        $map = [
            '{name}'           => $client['full_name']         ?? ($client['name'] ?? ''),
            '{username}'       => $client['mikrotik_username'] ?? '',
            '{password}'       => $client['mikrotik_password'] ?? '',
            '{phone}'          => $client['phone']             ?? '',
            '{account_number}' => $client['account_number']    ?? '',
            '{expiry_date}'    => !empty($client['expiry_date'])
                                  ? date('d M Y', strtotime($client['expiry_date'])) : '',
            '{amount}'         => number_format((float)($client['package_price'] ?? 0)),
        ];

        return str_replace(array_keys($map), array_values($map), $message);
    }
}
?>
