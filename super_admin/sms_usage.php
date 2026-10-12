<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
require_once __DIR__ . '/../includes/sms_billing.php';
ensureSmsUsageSchema($pdo);
$month = $_GET['month'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) { http_response_code(400); exit('Invalid month'); }
$start=$month.'-01'; $end=date('Y-m-01',strtotime($start.' +1 month'));
$tenant=max(0,(int)($_GET['tenant'] ?? 0));
$filter=$tenant ? ' AND u.tenant_id=?' : '';
$params=[$start,$end]; if ($tenant) $params[]=$tenant;
$st=$pdo->prepare("SELECT u.tenant_id,t.company_name,
    SUM(u.status='accepted') AS messages,
    SUM(CASE WHEN u.status='accepted' THEN u.sms_units ELSE 0 END) AS units,
    SUM(CASE WHEN u.status='accepted' THEN u.amount ELSE 0 END) AS amount,
    SUM(u.status IN ('unknown','sending')) AS unresolved
    FROM platform_sms_usage u JOIN tenants t ON t.id=u.tenant_id
    WHERE u.created_at>=? AND u.created_at<? $filter GROUP BY u.tenant_id,t.company_name ORDER BY t.company_name");
$st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
$st=$pdo->prepare("SELECT u.*,t.company_name FROM platform_sms_usage u JOIN tenants t ON t.id=u.tenant_id
    WHERE u.created_at>=? AND u.created_at<? $filter ORDER BY u.id DESC LIMIT 200");
$st->execute($params);$recent=$st->fetchAll(PDO::FETCH_ASSOC);
function smsUsageEscape($value):string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tenant SMS usage</title>
<style>body{font:15px system-ui;background:#f1f5f9;color:#172033;margin:0;padding:24px}main{max-width:1200px;margin:auto}table{width:100%;border-collapse:collapse;background:white;margin:20px 0}th,td{text-align:left;padding:12px;border-bottom:1px solid #e2e8f0}section{overflow:auto}a{color:#2563eb}input,button{font:inherit;padding:8px}form{display:flex;gap:12px;align-items:center;flex-wrap:wrap}</style><link rel="stylesheet" href="/css/responsive-app.css?v=1">
<script src="/js/responsive-app.js?v=1" defer></script>
</head>
<body><main><nav><a href="billing.php">Billing</a> · <a href="plans.php">Package SMS rates</a></nav>
<h1>Tenant SMS usage</h1><p>Only accepted customer SMS funded by the platform are charged. Multipart messages count as multiple SMS parts. Rates are saved at send time; unresolved sends await provider verification.</p>
<form><label>Month <input type="month" name="month" value="<?= smsUsageEscape($month) ?>" required></label><label>Tenant ID <input type="number" name="tenant" min="0" value="<?= $tenant ?>"></label><button>View usage</button></form>
<section><table><thead><tr><th>Tenant</th><th>Accepted messages</th><th>SMS parts</th><th>Charge (KSH)</th><th>Unresolved sends</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><a href="?month=<?= smsUsageEscape($month) ?>&amp;tenant=<?= (int)$row['tenant_id'] ?>"><?= smsUsageEscape($row['company_name']) ?></a></td><td><?= (int)$row['messages'] ?></td><td><?= (int)$row['units'] ?></td><td><?= number_format($row['amount'],2) ?></td><td><?= (int)$row['unresolved'] ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">No platform SMS usage recorded this month.</td></tr><?php endif; ?></tbody></table></section>
<h2>Latest 200 sends</h2><section><table><thead><tr><th>Time</th><th>Tenant</th><th>Recipient</th><th>Status</th><th>Parts</th><th>Rate (KSH)</th><th>Charge (KSH)</th><th>Invoice</th></tr></thead><tbody>
<?php foreach($recent as $row): ?><tr><td><?= smsUsageEscape($row['created_at']) ?></td><td><?= smsUsageEscape($row['company_name']) ?></td><td><?= smsUsageEscape($row['recipient']) ?></td><td><?= smsUsageEscape($row['status']) ?></td><td><?= (int)$row['sms_units'] ?></td><td><?= number_format($row['rate'],4) ?></td><td><?= number_format($row['amount'],2) ?></td><td><?= $row['invoice_id'] ? '<a href="billing.php?all=1&amp;invoice='.(int)$row['invoice_id'].'">View invoice</a>' : 'Unbilled' ?></td></tr><?php endforeach; ?>
</tbody></table></section></main></body></html>
