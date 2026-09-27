<?php
require_once __DIR__ . '/router_expiry.php';
require_once __DIR__ . '/hotspot_device.php';

class HotspotConnectionException extends RuntimeException {
    public string $reason;
    public function __construct(string $reason, string $message) {
        parent::__construct($message);
        $this->reason = $reason;
    }
}

function hotspotConnectionFailure(Throwable $error): array {
    $message = strtolower($error->getMessage());
    if ($error instanceof HotspotConnectionException) {
        return ['success'=>false, 'code'=>$error->reason, 'message'=>$error->getMessage()];
    }
    if (str_contains($message, 'queue') && str_contains($message, 'already have')) {
        return ['success'=>false, 'code'=>'queue_conflict', 'message'=>'Your payment is valid, but the router could not start your connection. Please contact support with your payment code. Do not pay again.'];
    }
    if (str_contains($message, 'simultaneous session') || str_contains($message, 'no more sessions')) {
        return ['success'=>false, 'code'=>'session_limit', 'message'=>'All device sessions for your package are in use. Disconnect another device, then try again.'];
    }
    return ['success'=>false, 'code'=>'connection_pending', 'message'=>'Connection setup is still pending. Please retry; if you already paid, do not pay again.'];
}

/** Check before provisioning: reconnect must never evict another paid device. */
function assertHotspotSessionCapacity($api, string $username, string $mac, int $limit): void {
    $mac = hotspotDeviceMac($mac);
    $sessions = array_filter(routerCheckedCommand($api, '/ip/hotspot/active/print', ['?user=' . $username]),
        fn($row) => ($row['user'] ?? '') === $username);
    foreach ($sessions as $session) {
        if ($mac !== '' && hotspotDeviceMac($session['mac-address'] ?? '') === $mac) return;
    }
    if (count($sessions) >= max(1, $limit)) {
        throw new HotspotConnectionException('session_limit', 'All ' . max(1, $limit) . ' device sessions for your package are in use. Disconnect another device, then try again.');
    }
}

function hotspotPhoneUsername(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^0([17]\d{8})$/', $digits, $m)) $digits = '254' . $m[1];
    elseif (preg_match('/^[17]\d{8}$/', $digits)) $digits = '254' . $digits;
    if (!preg_match('/^254[17]\d{8}$/', $digits)) throw new InvalidArgumentException('Enter a valid phone number for the hotspot username.');
    return $digits;
}

function hotspotGeneratePin(): string {
    return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}
