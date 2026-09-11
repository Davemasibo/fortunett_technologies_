<?php
require_once __DIR__ . '/router_expiry.php';

/** Findings describe evidence; none authorize extending or shortening access. */
function connectivityEvidenceFindings(array $client): array {
    $findings = $client['findings'] ?? [];
    $user = $client['router_user'] ?? null;
    if (empty($client['last_seen']) && $user && routerUptimeSeconds($user['uptime'] ?? '0s') > 0) {
        $findings = array_values(array_diff($findings, ['NO_RECORDED_CONNECTION']));
        $findings[] = 'ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP';
    }
    if ($user && ($user['profile'] ?? '') !== ($client['mikrotik_profile'] ?? '')) {
        $findings[] = 'ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE';
    }
    if (!empty($client['entitled_now']) && empty($client['device_context_available']) && ($client['connection_type'] ?? '') === 'hotspot') {
        $findings[] = 'NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN';
    }
    if (!empty($client['entitled_now']) && isset($client['payments']['confirmed_count']) && (int)$client['payments']['confirmed_count'] === 0) {
        $findings = array_values(array_diff($findings, ['PAID_ACCESS_NO_ACTIVE_SESSION']));
        $findings[] = 'ACTIVE_ACCESS_WITHOUT_COMPLETED_PAYMENT_RECORD';
    }
    return array_values(array_unique($findings));
}
