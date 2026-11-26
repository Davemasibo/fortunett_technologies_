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
<link rel="stylesheet" href="css/dark.css?v=2"><link rel="stylesheet" href="css/shell.css?v=2"><link rel="stylesheet" href="css/admin.css?v=1"><link rel="stylesheet" href="/css/responsive-app.css?v=1"><script src="js/shell.js?v=2" defer></script>
<script src="/js/responsive-app.js?v=1" defer></script>
</head>
<body class="sa-shell"><?php require __DIR__.'/includes/navigation.php'; ?><div class="main"><header class="topbar"><h1>Tenant SMS usage</h1><span class="sa-subtle">Usage and billing</span></header><main id="sa-main-content" class="content">
<div class="sa-page-heading"><div><span class="sa-eyebrow">COMMUNICATION · BILLING</span><h2>Tenant SMS usage</h2><p>Accepted platform-funded customer SMS are billable. Unresolved sends remain pending provider verification.</p></div></div>
<section class="card"><div class="card-body"><form class="filters-bar"><label for="sms-month">Month</label><input id="sms-month" type="month" name="month" value="<?= smsUsageEscape($month) ?>" required><label for="sms-tenant">Tenant ID</label><input id="sms-tenant" type="number" name="tenant" min="0" value="<?= $tenant ?>"><button class="sa-btn sa-btn-primary">View usage</button></form></div></section>
<section class="card" style="margin-top:18px"><div class="card-head"><div><h2>Usage by tenant</h2><p>One accepted customer message counts as one SMS unit.</p></div></div><div class="table-wrap"><table><thead><tr><th>Tenant</th><th>Accepted messages</th><th>SMS units</th><th>Charge (KES)</th><th>Unresolved sends</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><a href="?month=<?= smsUsageEscape($month) ?>&amp;tenant=<?= (int)$row['tenant_id'] ?>"><?= smsUsageEscape($row['company_name']) ?></a></td><td><?= (int)$row['messages'] ?></td><td><?= (int)$row['units'] ?></td><td><?= number_format($row['amount'],2) ?></td><td><?= (int)$row['unresolved'] ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="sa-empty">No platform SMS usage recorded for this month.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card" style="margin-top:18px"><div class="card-head"><div><h2>Latest 200 sends</h2><p>Provider state, rated units and invoice link for each recent send.</p></div></div><div class="table-wrap"><table><thead><tr><th>Time</th><th>Tenant</th><th>Recipient</th><th>Status</th><th>Units</th><th>Rate (KES)</th><th>Charge (KES)</th><th>Invoice</th></tr></thead><tbody>
<?php foreach($recent as $row): ?><tr><td><?= smsUsageEscape($row['created_at']) ?></td><td><?= smsUsageEscape($row['company_name']) ?></td><td><?= smsUsageEscape($row['recipient']) ?></td><td><?= smsUsageEscape($row['status']) ?></td><td><?= (int)$row['sms_units'] ?></td><td><?= number_format($row['rate'],4) ?></td><td><?= number_format($row['amount'],2) ?></td><td><?= $row['invoice_id'] ? '<a href="billing.php?all=1&amp;invoice='.(int)$row['invoice_id'].'">View invoice</a>' : 'Unbilled' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section></main></div></body></html>
