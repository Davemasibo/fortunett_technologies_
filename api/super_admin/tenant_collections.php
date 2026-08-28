<?php
/**
 * Super Admin — per-tenant live collections feed.
 *
 * POST /api/super_admin/tenant_collections.php
 *   action=feed  tenant_id=N  [after_id=M]  [limit=40]
 *
 * super_admin/tenants.php?id=N previously showed only platform_invoices — what
 * the tenant owes FortuNett. It could not answer the question an operator
 * actually asks while money is moving: "is anything arriving right now, and for
 * whom?" This endpoint is that answer, polled every few seconds.
 *
 * Two things must not be confused, so they are returned separately:
 *   collections  — end-customer money (payments.tenant_id = N). This is the
 *                  ISP's revenue; part of it may be float FortuNett is holding
 *                  (collection_type='platform' AND released_at IS NULL).
 *   platform_paid — what the tenant has paid FortuNett (platform_payments).
 *
 * `unmatched` is money that arrived and could not be credited to anybody. It is
 * the most important number here: a non-zero count means customers have paid
 * and are still offline.
 *
 * payments.payment_date is a DATE column, so it cannot order or bucket anything
 * within a day. Every time window below uses created_at, which is the real
 * moment the row was written.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';
require_once __DIR__ . '/../../includes/unmatched_payments.php';

if (!isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$tenantId = (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
$afterId  = (int)($_POST['after_id']  ?? $_GET['after_id']  ?? 0);
$limit    = max(1, min(100, (int)($_POST['limit'] ?? $_GET['limit'] ?? 40)));

if ($tenantId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'tenant_id is required']);
    exit;
}

/** Sum + count of completed collections inside a window. */
function _tcWindow(PDO $pdo, int $tenantId, string $since): array
{
    $st = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(amount), 0) a
        FROM payments
        WHERE tenant_id = ? AND status = 'completed' AND created_at >= ?
    ");
    $st->execute([$tenantId, $since]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['count' => (int)($r['c'] ?? 0), 'amount' => (float)($r['a'] ?? 0)];
}

/** "12s ago", "4m ago", "3h ago", "2d ago" */
function _tcAgo(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - strtotime($ts);
    if ($d < 0)     return 'just now';
    if ($d < 60)    return $d . 's ago';
    if ($d < 3600)  return intdiv($d, 60) . 'm ago';
    if ($d < 86400) return intdiv($d, 3600) . 'h ago';
    return intdiv($d, 86400) . 'd ago';
}

try {
    $out = ['success' => true, 'server_time' => date('H:i:s'), 'tenant_id' => $tenantId];

    // ── Windows ──────────────────────────────────────────────────────────────
    $out['totals'] = [
        'today' => _tcWindow($pdo, $tenantId, date('Y-m-d 00:00:00')),
        'week'  => _tcWindow($pdo, $tenantId, date('Y-m-d 00:00:00', strtotime('-6 days'))),
        'month' => _tcWindow($pdo, $tenantId, date('Y-m-d 00:00:00', strtotime('-29 days'))),
        'all'   => _tcWindow($pdo, $tenantId, '1970-01-01 00:00:00'),
    ];

    // ── Float: collected through the platform paybill, not yet paid out ──────
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN released_at IS NULL     THEN amount END), 0) unreleased,
               COUNT(CASE WHEN released_at IS NULL THEN 1 END)                     unreleased_count,
               COALESCE(SUM(CASE WHEN released_at IS NOT NULL THEN amount END), 0) released,
               MIN(CASE WHEN released_at IS NULL THEN created_at END)              oldest_unreleased
        FROM payments
        WHERE tenant_id = ? AND status = 'completed' AND collection_type = 'platform'
    ");
    $st->execute([$tenantId]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['float'] = [
        'unreleased'        => (float)($f['unreleased'] ?? 0),
        'unreleased_count'  => (int)($f['unreleased_count'] ?? 0),
        'released'          => (float)($f['released'] ?? 0),
        'oldest_unreleased' => $f['oldest_unreleased'] ?? null,
        'oldest_ago'        => _tcAgo($f['oldest_unreleased'] ?? null),
    ];

    // ── Pending: initiated, no callback yet. A rising count means the callback
    //    path is broken, not that customers stopped paying. ──────────────────
    $st = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM payments
        WHERE tenant_id = ? AND status = 'pending' AND created_at >= ?
    ");
    $st->execute([$tenantId, date('Y-m-d H:i:s', strtotime('-24 hours'))]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['pending_24h'] = ['count' => (int)($p['c'] ?? 0), 'amount' => (float)($p['a'] ?? 0)];

    // ── Live feed ────────────────────────────────────────────────────────────
    // after_id lets the browser poll for only what is new; the panel highlights
    // those rows so an operator watching the screen sees money land.
    $sql = "
        SELECT p.id, p.client_id, p.amount, p.payment_method, p.transaction_id,
               p.status, p.collection_type, p.released_at, p.created_at,
               COALESCE(NULLIF(c.name,''), c.full_name) AS client_name,
               c.account_number, c.phone, c.status AS client_status
        FROM payments p
        LEFT JOIN clients c ON c.id = p.client_id AND c.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
    ";
    $args = [$tenantId];
    if ($afterId > 0) {
        $sql .= " AND p.id > ?";
        $args[] = $afterId;
    }
    $sql .= " ORDER BY p.id DESC LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'              => (int)$r['id'],
            'client_id'       => $r['client_id'] ? (int)$r['client_id'] : null,
            'client_name'     => $r['client_name'] ?: 'Unknown customer',
            'account_number'  => $r['account_number'] ?: '',
            'phone'           => $r['phone'] ?: '',
            'client_status'   => $r['client_status'] ?: '',
            'amount'          => (float)$r['amount'],
            'method'          => $r['payment_method'] ?: '',
            'transaction_id'  => $r['transaction_id'] ?: '',
            'status'          => $r['status'] ?: '',
            'collection_type' => $r['collection_type'] ?: 'direct',
            'released'        => !empty($r['released_at']),
            'created_at'      => $r['created_at'],
            'time'            => $r['created_at'] ? date('d M H:i', strtotime($r['created_at'])) : '',
            'ago'             => _tcAgo($r['created_at']),
        ];
    }
    $out['payments'] = $rows;

    // Cursor is the tenant's true max id, not the max of this page — a feed
    // limited to `limit` rows would otherwise re-serve the same rows forever.
    $st = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM payments WHERE tenant_id = ?");
    $st->execute([$tenantId]);
    $out['max_id'] = (int)$st->fetchColumn();

    // ── Money that arrived and matched nobody ────────────────────────────────
    $out['unmatched'] = ['count' => 0, 'amount' => 0.0, 'rows' => [], 'unrouted_global' => 0];
    try {
        _unmatched_ensure_table($pdo);
        $st = $pdo->prepare("
            SELECT COUNT(*) c, COALESCE(SUM(amount),0) a
            FROM unmatched_payments WHERE tenant_id = ? AND status = 'open'
        ");
        $st->execute([$tenantId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['unmatched']['count']  = (int)($u['c'] ?? 0);
        $out['unmatched']['amount'] = (float)($u['a'] ?? 0);

        $st = $pdo->prepare("
            SELECT id, transaction_id, amount, phone, account_ref, payer_name,
                   source, reason, created_at
            FROM unmatched_payments
            WHERE tenant_id = ? AND status = 'open'
            ORDER BY id DESC LIMIT 15
        ");
        $st->execute([$tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['ago']  = _tcAgo($r['created_at']);
            $r['time'] = $r['created_at'] ? date('d M H:i', strtotime($r['created_at'])) : '';
            $out['unmatched']['rows'][] = $r;
        }

        // Platform-level money that belongs to no tenant at all. Surfaced on
        // every tenant page because a till payment carries no account reference
        // and lands here, and somebody has to notice it.
        $out['unmatched']['unrouted_global'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM unmatched_payments WHERE tenant_id IS NULL AND status = 'open'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['unmatched']['error'] = $e->getMessage();
    }

    // ── What this tenant has paid FortuNett ──────────────────────────────────
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, MAX(paid_at) last_paid
            FROM platform_payments WHERE tenant_id = ?
        ");
        $st->execute([$tenantId]);
        $pp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['platform_paid'] = [
            'count'     => (int)($pp['c'] ?? 0),
            'amount'    => (float)($pp['a'] ?? 0),
            'last_paid' => $pp['last_paid'] ?? null,
            'last_ago'  => _tcAgo($pp['last_paid'] ?? null),
        ];
    } catch (Throwable $e) {
        $out['platform_paid'] = ['count' => 0, 'amount' => 0.0, 'last_paid' => null, 'last_ago' => '—'];
    }

    echo json_encode($out);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
