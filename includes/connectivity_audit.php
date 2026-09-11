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

/** Compare purchased entitlement, the database deadline and router limits.
 * These observations do not certify traffic cutoff or scheduler execution.
 */
function connectivityExpiryFindings(array $client): array {
    $findings=[];
    $purchase=$client['purchase_entitlement'] ?? [];
    if (empty($purchase['repairable'])) $findings[]='PURCHASE_HISTORY_REQUIRES_REVIEW';
    elseif (!empty($client['expiry_date']) && strtotime($client['expiry_date'])>strtotime($purchase['expiry'])) $findings[]='DATABASE_EXPIRY_EXCEEDS_PURCHASES';
    $user=$client['router_user'] ?? null;
    if (!$user || ($user['disabled'] ?? '')!=='false') return $findings;
    if (empty($client['entitled_now'])) $findings[]='NOT_ENTITLED_ROUTER_USER_ENABLED';
    elseif (empty($client['latest_allowed_router_deadline'])) $findings[]='ROUTER_CLOCK_COMPARISON_UNAVAILABLE';
    if (routerUptimeSeconds($user['limit-uptime'] ?? '0s')<=0) $findings[]='ENABLED_HOTSPOT_WITHOUT_UPTIME_LIMIT';
    if (empty($client['watchdog_installed'])) $findings[]='PAID_EXPIRY_WATCHDOG_MISSING';
    $schedule=$client['deadline_schedule'] ?? null;
    if (!$schedule || ($schedule['disabled'] ?? '')!=='false') {
        $findings[]='PAID_DEADLINE_MISSING_OR_DISABLED';
        return $findings;
    }
    $date=$schedule['start-date'] ?? '';
    $format=preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)?'Y-m-d':'M/d/Y';
    $deadline=DateTimeImmutable::createFromFormat('!'.$format.' H:i:s',$date.' '.($schedule['start-time'] ?? ''),new DateTimeZone('UTC'));
    $errors=DateTimeImmutable::getLastErrors();
    if (!$deadline || ($errors && ($errors['warning_count'] || $errors['error_count']))) $findings[]='ROUTER_DEADLINE_UNREADABLE';
    elseif (!empty($client['latest_allowed_router_deadline']) && strcmp($deadline->format('YmdHis'),$client['latest_allowed_router_deadline'])>0) $findings[]='ROUTER_DEADLINE_EXCEEDS_DATABASE_EXPIRY';
    return $findings;
}
