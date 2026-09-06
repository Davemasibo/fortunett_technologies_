<?php
/**
 * Tenant-level business report for a period.
 *
 * reports.php could only ever produce a report about ONE customer, so an
 * operator asking the ordinary question -- "what did we collect last month, and
 * the month before?" -- had nowhere to go but the dashboard charts, which had
 * no working period control either.
 *
 * Periods come from includes/analytics_range.php, the same vocabulary the
 * dashboard uses, so a figure here and a bar there can never disagree about
 * what "last 6 months" covers.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/analytics_range.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenantId = (int)$st->fetchColumn();
if (!$tenantId) {
    echo json_encode(['success' => false, 'message' => 'No tenant']);
    exit;
}

$range = analyticsRange($_GET['range'] ?? '6m');
$from  = $range['start'];
$to    = $range['end_exclusive'];

/** Every query is scoped by tenant_id and by the period, in that order. */
$q = function (string $sql, array $params = []) use ($pdo, $tenantId, $from, $to) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$tenantId, $from, $to], $params));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[reports] ' . $e->getMessage());
        return [];
    }
};

$out = [
    'success' => true,
    'range'   => ['key' => $range['key'], 'label' => $range['label'], 'bucket' => $range['bucket'],
                  'start' => $range['start'], 'end' => $range['end']],
];

// ── Headline ─────────────────────────────────────────────────────────────────
$totals = $q("
    SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS n,
           COALESCE(AVG(amount),0) AS avg_amount
    FROM payments
    WHERE tenant_id = ? AND payment_date >= ? AND payment_date < ? AND status = 'completed'
");
$out['totals'] = [
    'collected' => (float)($totals[0]['total'] ?? 0),
    'payments'  => (int)($totals[0]['n'] ?? 0),
    'average'   => (float)($totals[0]['avg_amount'] ?? 0),
];

// Money that did NOT land, stated separately rather than folded into the total
// -- a report that silently drops failures reads as lost revenue nobody can
// account for.
$unpaid = $q("
    SELECT status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE tenant_id = ? AND payment_date >= ? AND payment_date < ? AND status <> 'completed'
    GROUP BY status
");
$out['not_collected'] = $unpaid;

// ── Collections per bucket — the month-by-month table ────────────────────────
$expr = analyticsBucketExpr($range, 'payment_date');
$rows = $q("
    SELECT $expr AS k, COALESCE(SUM(amount),0) AS v, COUNT(*) AS n
    FROM payments
    WHERE tenant_id = ? AND payment_date >= ? AND payment_date < ? AND status = 'completed'
    GROUP BY k
");
$series = analyticsSeries($range, $rows, 'k', 'v');
$counts = analyticsSeries($range, $rows, 'k', 'n', true);
$out['buckets'] = [];
foreach ($range['buckets'] as $i => $b) {
    $out['buckets'][] = [
        'key'      => $b['key'],
        'label'    => $b['label'],
        'amount'   => $series['data'][$i],
        'payments' => $counts['data'][$i],
    ];
}

// ── Breakdowns ───────────────────────────────────────────────────────────────
$out['by_method'] = $q("
    SELECT payment_method AS name, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE tenant_id = ? AND payment_date >= ? AND payment_date < ? AND status = 'completed'
    GROUP BY payment_method ORDER BY total DESC
");

// Whose bank the money is in. Reported because "we collected X" means something
// different when part of X is still held by FortuNett awaiting disbursement.
$out['by_route'] = $q("
    SELECT
        CASE
            WHEN collection_type = 'platform' AND released_at IS NOT NULL THEN 'disbursed'
            WHEN collection_type = 'platform'                             THEN 'awaiting_disbursement'
            ELSE 'paid_to_you'
        END AS name,
        COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
    FROM payments
    WHERE tenant_id = ? AND payment_date >= ? AND payment_date < ? AND status = 'completed'
    GROUP BY name ORDER BY total DESC
");

$out['by_package'] = $q("
    SELECT COALESCE(NULLIF(pk.name,''), 'No package') AS name,
           COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS total
    FROM payments p
    LEFT JOIN clients  c  ON c.id  = p.client_id
    LEFT JOIN packages pk ON pk.id = c.package_id
    WHERE p.tenant_id = ? AND p.payment_date >= ? AND p.payment_date < ? AND p.status = 'completed'
    GROUP BY name ORDER BY total DESC LIMIT 12
");

$out['top_customers'] = $q("
    SELECT COALESCE(NULLIF(c.full_name,''), c.name, 'Unknown') AS name,
           c.phone, c.account_number,
           COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS total
    FROM payments p
    JOIN clients c ON c.id = p.client_id AND c.tenant_id = p.tenant_id
    WHERE p.tenant_id = ? AND p.payment_date >= ? AND p.payment_date < ? AND p.status = 'completed'
    GROUP BY c.id ORDER BY total DESC LIMIT 15
");

// ── Customers gained in the period ───────────────────────────────────────────
$newC = $q("
    SELECT COUNT(*) AS n FROM clients
    WHERE tenant_id = ? AND created_at >= ? AND created_at < ?
");
$out['new_customers'] = (int)($newC[0]['n'] ?? 0);

// Point-in-time figures: not period-scoped, so they take their own query rather
// than being squeezed into $q().
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND status = 'active'");
    $st->execute([$tenantId]);
    $out['active_customers'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND expiry_date < NOW()");
    $st->execute([$tenantId]);
    $out['expired_customers'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $out['active_customers'] = null;
    $out['expired_customers'] = null;
}

echo json_encode($out);
