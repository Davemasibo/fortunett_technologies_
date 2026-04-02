<?php
/**
 * GET /api/clients/reports.php?client_id=X
 * Customer analytics: lifetime value, payment reliability, churn risk, value rank, charts
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id = (int)($_GET['client_id'] ?? 0);
if (!$client_id) { echo json_encode(['success'=>false,'message'=>'client_id required']); exit; }

$chk = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
$client = $chk->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

// Also fetch package name
$pkgName = $client['subscription_plan'] ?? '—';
try {
    if (!empty($client['package_id'])) {
        $pkgSt = $pdo->prepare("SELECT name FROM packages WHERE id = ?");
        $pkgSt->execute([$client['package_id']]);
        $pkgRow = $pkgSt->fetchColumn();
        if ($pkgRow) $pkgName = $pkgRow;
    }
} catch (Exception $e) {}

try {
    $data = [
        'success'        => true,
        'account_number' => $client['account_number']   ?? null,
        'status'         => $client['status']           ?? 'unknown',
        'expiry_date'    => $client['expiry_date']       ?? null,
        'package_name'   => $pkgName,
        'mikrotik_username' => $client['mikrotik_username'] ?? null,
    ];

    // ── Lifetime value (total completed payments) ─────────────────
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM payments WHERE client_id=? AND tenant_id=? AND status='completed'");
    $st->execute([$client_id, $tenant_id]);
    [$ltv, $totalPayments] = $st->fetch(PDO::FETCH_NUM);
    $data['lifetime_value']   = (float)$ltv;
    $data['total_payments']   = (int)$totalPayments;

    // ── Last payment date ─────────────────────────────────────────
    $st = $pdo->prepare("SELECT MAX(payment_date) FROM payments WHERE client_id=? AND tenant_id=? AND status='completed'");
    $st->execute([$client_id, $tenant_id]);
    $lastPayDate = $st->fetchColumn();
    $data['last_payment_date'] = $lastPayDate;
    $daysSincePayment = $lastPayDate ? (int)floor((time() - strtotime($lastPayDate)) / 86400) : null;
    $data['days_since_payment'] = $daysSincePayment;

    // ── Average monthly spend (last 6 months) ────────────────────
    $monthlyData = []; $monthLabels = [];
    for ($i = 5; $i >= 0; $i--) {
        $y = date('Y', strtotime("-$i months"));
        $m = date('m', strtotime("-$i months"));
        $monthLabels[] = date('M y', strtotime("-$i months"));
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE client_id=? AND tenant_id=? AND status='completed' AND YEAR(payment_date)=? AND MONTH(payment_date)=?");
        $st->execute([$client_id, $tenant_id, $y, $m]);
        $monthlyData[] = (float)$st->fetchColumn();
    }
    $data['monthly_labels'] = $monthLabels;
    $data['monthly_data']   = $monthlyData;
    $nonZero = array_filter($monthlyData);
    $data['avg_monthly'] = count($nonZero) ? round(array_sum($nonZero) / count($nonZero), 2) : 0;

    // ── Payment reliability (months with payment out of last 6) ──
    $paidMonths = count(array_filter($monthlyData));
    $data['payment_reliability'] = round(($paidMonths / 6) * 100);

    // ── Days until expiry ─────────────────────────────────────────
    $expiryDate = $client['expiry_date'] ?? null;
    $daysToExpiry = $expiryDate ? (int)floor((strtotime($expiryDate) - time()) / 86400) : null;
    $data['days_to_expiry'] = $daysToExpiry;

    // ── Churn risk ────────────────────────────────────────────────
    $status = strtolower($client['status'] ?? 'inactive');
    if ($status === 'suspended' || $status === 'inactive') {
        $churnRisk = 'High';
    } elseif ($daysToExpiry !== null && $daysToExpiry < 0) {
        $churnRisk = 'High';
    } elseif ($daysSincePayment !== null && $daysSincePayment > 45) {
        $churnRisk = 'High';
    } elseif ($daysToExpiry !== null && $daysToExpiry <= 7) {
        $churnRisk = 'Medium';
    } elseif ($daysSincePayment !== null && $daysSincePayment > 25) {
        $churnRisk = 'Medium';
    } else {
        $churnRisk = 'Low';
    }
    $data['churn_risk'] = $churnRisk;

    // ── Value rank among all tenant clients ───────────────────────
    $st = $pdo->prepare("
        SELECT client_id, COALESCE(SUM(amount),0) AS total
        FROM payments
        WHERE tenant_id = ? AND status = 'completed'
        GROUP BY client_id
        ORDER BY total DESC
    ");
    $st->execute([$tenant_id]);
    $allRanks = $st->fetchAll(PDO::FETCH_ASSOC);
    $rank = null;
    $totalClients = count($allRanks);
    foreach ($allRanks as $idx => $row) {
        if ((int)$row['client_id'] === $client_id) {
            $rank = $idx + 1;
            break;
        }
    }
    $data['value_rank']     = $rank ?? ($totalClients + 1);
    $data['total_clients']  = $totalClients ?: 1;

    // ── Account age (days since created) ─────────────────────────
    $data['account_age_days'] = (int)floor((time() - strtotime($client['created_at'])) / 86400);

    echo json_encode($data);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
