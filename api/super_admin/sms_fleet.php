<?php
/**
 * Per-tenant SMS verification for the whole installation.
 *
 * Uses includes/sms_verify.php, which resolves credentials through the same
 * functions SMSHelper sends with -- so this page, tools/sms_fleet.php and the
 * sender can never disagree about which key a tenant is on.
 *
 * The probe is a GET against the provider's balance route: it proves the
 * credential without sending a message or spending a credit.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';
require_once __DIR__ . '/../../includes/sms_verify.php';

// isSuperAdmin(), not superAdminGuard(): the guard redirects to login.php, and
// a fetch() following a redirect would parse an HTML login page as JSON and
// report a syntax error instead of "not authorised".
if (!isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin only']);
    exit;
}

// A probe per distinct credential can take a few seconds on a slow provider.
@set_time_limit(120);

// ?probe=0 answers from configuration alone, for a quick read when the
// provider is slow or unreachable.
$probe = ($_REQUEST['probe'] ?? '1') !== '0';

try {
    $rows = smsVerifyAllTenants($pdo, $probe);

    $counts = [];
    foreach ($rows as $r) {
        $counts[$r['verdict']] = ($counts[$r['verdict']] ?? 0) + 1;
    }

    // Labels resolved server-side so the page cannot invent its own wording.
    foreach ($rows as &$r) { $r['verdict_label'] = smsVerdictLabel($r['verdict']); }
    unset($r);

    echo json_encode([
        'success'  => true,
        'probed'   => $probe,
        'tenants'  => $rows,
        'counts'   => $counts,
        'order'    => SMS_VERDICT_ORDER,
        'checked_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
