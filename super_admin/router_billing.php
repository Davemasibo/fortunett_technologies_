<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
require_once __DIR__ . '/../includes/platform_billing.php';
ensurePlatformBillingSchema($pdo);

$tenantId = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($tenantId < 1) { http_response_code(400); exit('Invalid tenant.'); }

$tenantQuery = $pdo->prepare("SELECT t.id,t.company_name,t.status,COALESCE(p.base_monthly_fee,0) AS router_fee
    FROM tenants t LEFT JOIN platform_subscription_plans p ON p.id=t.subscription_plan_id WHERE t.id=?");
$tenantQuery->execute([$tenantId]);
$tenant = $tenantQuery->fetch(PDO::FETCH_ASSOC);
if (!$tenant) { http_response_code(404); exit('Tenant not found.'); }

$_SESSION['router_billing_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['router_billing_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Session expired. Reload and try again.');
        }
        $routerId = filter_var($_POST['router_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $billable = filter_var($_POST['platform_billable'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
        if ($routerId === false || $billable === false) throw new InvalidArgumentException('Choose a valid router billing setting.');

        $save = $pdo->prepare('UPDATE mikrotik_routers SET platform_billable=? WHERE id=? AND tenant_id=?');
        $save->execute([$billable, $routerId, $tenantId]);
        if ($save->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND tenant_id=?');
            $exists->execute([$routerId, $tenantId]);
            if (!$exists->fetchColumn()) throw new InvalidArgumentException('Router not found for this tenant.');
        }

        // Reprice this month's editable invoice immediately. Paid, part-paid,
        // and manually corrected invoices retain their recorded values.
        ensureCurrentPlatformInvoice($pdo, $tenantId);
        header('Location: router_billing.php?tenant_id=' . $tenantId . '&saved=1');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Router billing setting: ' . $e->getMessage());
        $error = 'Could not save this router billing setting.';
    }
}

$routerQuery = $pdo->prepare('SELECT id,name,ip_address,status,last_seen,platform_billable FROM mikrotik_routers WHERE tenant_id=? ORDER BY id');
$routerQuery->execute([$tenantId]);
$routers = $routerQuery->fetchAll(PDO::FETCH_ASSOC);
function routerBillingEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Router billing · <?= routerBillingEscape($tenant['company_name']) ?></title>
<link rel="stylesheet" href="css/dark.css?v=2">
<link rel="stylesheet" href="css/shell.css?v=2">
<link rel="stylesheet" href="css/admin.css?v=1">
<script src="js/shell.js?v=2" defer></script>
<style>
.rb-wrap{max-width:1050px;margin:0 auto;padding:24px}.rb-card{background:#1c1c1b;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px;margin-top:18px}.rb-muted{color:#a1a1aa}.rb-table{width:100%;border-collapse:collapse}.rb-table th,.rb-table td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left}.rb-table select{padding:8px;background:#111827;color:#fff;border:1px solid #374151;border-radius:6px}.rb-save{padding:8px 12px;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer}.rb-alert{padding:12px 14px;border-radius:8px;margin-top:14px}.rb-ok{background:rgba(16,185,129,.12);color:#6ee7b7}.rb-error{background:rgba(239,68,68,.12);color:#fca5a5}.rb-rowform{display:flex;gap:8px;align-items:center}.rb-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.rb-back{color:#93c5fd;text-decoration:none}
</style>
</head>
<body class="sa-shell">
<?php include __DIR__ . '/includes/navigation.php'; ?>
<div class="main">
<header class="topbar"><h1>Router billing</h1></header>
<main class="content rb-wrap">
<a class="rb-back" href="tenants.php?id=<?= $tenantId ?>">← <?= routerBillingEscape($tenant['company_name']) ?></a>
<section class="rb-card">
    <div class="rb-top">
        <div><h2 style="margin:0 0 6px">Billable MikroTik devices</h2>
        <p class="rb-muted" style="margin:0">Monthly rate: KES <?= number_format((float)$tenant['router_fee'], 2) ?> per billable router. Exempting a router preserves its saved configuration.</p></div>
        <a class="rb-back" href="billing.php?month=<?= date('Y-m') ?>">Platform billing</a>
    </div>
    <?php if ($error): ?><div class="rb-alert rb-error" role="alert"><?= routerBillingEscape($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="rb-alert rb-ok" role="status">Router billing setting saved. This month's editable invoice was refreshed; paid, part-paid, or manually corrected invoices remain unchanged.</div><?php endif; ?>
    <p class="rb-muted">This setting is controlled by the platform administrator. It affects future invoices and editable unpaid invoices; it does not delete or disable the router.</p>
    <div style="overflow-x:auto">
    <table class="rb-table">
        <thead><tr><th>Router</th><th>Connection status</th><th>Last seen</th><th>Monthly fee</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($routers as $router): ?>
        <tr>
            <td><strong><?= routerBillingEscape($router['name']) ?></strong><br><span class="rb-muted"><?= routerBillingEscape($router['ip_address']) ?></span></td>
            <td><?= routerBillingEscape(ucfirst((string)$router['status'])) ?></td>
            <td><?= $router['last_seen'] ? routerBillingEscape($router['last_seen']) : 'Never' ?></td>
            <td><?= (int)$router['platform_billable'] === 1 ? 'Billable' : 'Exempt' ?></td>
            <td><form method="post" class="rb-rowform">
                <input type="hidden" name="csrf" value="<?= routerBillingEscape($_SESSION['router_billing_csrf']) ?>">
                <input type="hidden" name="router_id" value="<?= (int)$router['id'] ?>">
                <select name="platform_billable" aria-label="Router monthly fee setting">
                    <option value="1" <?= (int)$router['platform_billable'] === 1 ? 'selected' : '' ?>>Charge fee</option>
                    <option value="0" <?= (int)$router['platform_billable'] === 0 ? 'selected' : '' ?>>Exempt fee</option>
                </select>
                <button class="rb-save" type="submit">Save</button>
            </form></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$routers): ?><tr><td colspan="5" class="rb-muted">No MikroTik routers are registered for this tenant.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
</main>
</div>
</body>
</html>
