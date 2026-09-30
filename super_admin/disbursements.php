<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
require_once __DIR__ . '/../includes/disbursements.php';
ensureDisbursementSchema($pdo);
$_SESSION['disbursement_csrf'] ??= bin2hex(random_bytes(32));
$tenantId = (int)($_GET['tenant_id'] ?? 0);
$st = $pdo->prepare('SELECT company_name FROM tenants WHERE id = ?');
$st->execute([$tenantId]);
$name = $st->fetchColumn();
if (!$name) { http_response_code(404); exit('Tenant not found.'); }
$error = '';
$cutoff = str_replace('T', ' ', $_POST['cutoff'] ?? date('Y-m-d H:i:s'));
if (strlen($cutoff) === 16) $cutoff .= ':00';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['disbursement_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Session expired. Reload this page.');
        }
        if (($_POST['action'] ?? '') === 'record') {
            $input = $_POST;
            $input['cutoff'] = $cutoff;
            $input['disbursed_at'] = str_replace('T', ' ', $input['disbursed_at'] ?? '');
            if (strlen($input['disbursed_at']) === 16) $input['disbursed_at'] .= ':00';
            recordDisbursement($pdo, $tenantId, (int)$_SESSION['user_id'], $input);
            header('Location: disbursements.php?tenant_id=' . $tenantId . '&saved=1');
            exit;
        }
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
      catch (Throwable $e) { error_log('Manual disbursement: ' . $e->getMessage()); $error = 'Could not record the payout. No changes were saved. Please check the server log.'; }
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cutoff);
if (!$date || $date->format('Y-m-d H:i:s') !== $cutoff || $cutoff > date('Y-m-d H:i:s')) {
    $error = 'Select a valid cutoff that is not in the future.';
    $cutoff = date('Y-m-d H:i:s');
}
$rows = disbursementPayments($pdo, $tenantId, $cutoff);
$gross = array_sum(array_map(fn($r) => disbursementMoney((string)$r['amount']), $rows)) / 100;
$st = $pdo->prepare('SELECT * FROM tenant_disbursements WHERE tenant_id = ? ORDER BY id DESC LIMIT 100');
$st->execute([$tenantId]);
$history = $st->fetchAll(PDO::FETCH_ASSOC);
function dh($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disbursements - <?= dh($name) ?></title>
<style>body{font:16px system-ui;background:#141414;color:#e2e2e0;margin:0;padding:24px}main{max-width:1000px;margin:auto}a{color:#93c5fd}.card{background:#222;padding:24px;border-radius:12px;margin:24px 0}label{display:block;margin:16px 0}input,textarea,button{font:inherit;padding:10px;border-radius:6px;border:1px solid #666;background:#181818;color:white;max-width:100%;box-sizing:border-box}label input,label textarea{display:block;margin-top:6px}button{background:#245da0;cursor:pointer}td,th{padding:12px;text-align:left;border-bottom:1px solid #444}table{width:100%;border-collapse:collapse}.error{color:#fca5a5}.success{color:#86efac}p{line-height:1.6}</style>
<main><a href="collections.php?tab=held">Back to collections</a><h1><?= dh($name) ?>: disbursements</h1>
<?php if ($error): ?><p role="alert" class="error"><?= dh($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p role="status" class="success">Disbursement recorded. The tenant's outstanding payout balance has been updated.</p><?php endif; ?>
<div class="card"><h2>1. Preview collections</h2><p>Select the latest collection time covered by the transfer. The preview includes outstanding platform collections from earlier months.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= dh($_SESSION['disbursement_csrf']) ?>">
<label>Collections through (Nairobi time)<input type="datetime-local" step="1" name="cutoff" value="<?= dh(str_replace(' ', 'T', $cutoff)) ?>" required></label>
<button name="action" value="preview">Refresh preview</button></form>
<p><strong><?= count($rows) ?> payments: KES <?= number_format($gross, 2) ?></strong></p></div>
<?php if ($rows): ?><div class="card"><h2>2. Confirm money already sent</h2>
<p>This records a completed transfer. Cash sent plus fees withheld must equal the preview above. Platform invoices retain their payment status; do not withhold fees the tenant has already paid.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= dh($_SESSION['disbursement_csrf']) ?>">
<input type="hidden" name="cutoff" value="<?= dh($cutoff) ?>">
<input type="hidden" name="expected_gross" value="<?= number_format($gross, 2, '.', '') ?>">
<input type="hidden" name="expected_count" value="<?= count($rows) ?>">
<label>Cash sent (KES)<input type="number" step="0.01" min="0.01" name="cash_amount" value="<?= number_format($gross, 2, '.', '') ?>" required></label>
<label>Fees withheld (KES)<input type="number" step="0.01" min="0" name="fees_amount" value="0" required></label>
<label>Transfer charge paid by platform (KES; does not reduce tenant balance)<input type="number" step="0.01" min="0" name="platform_cost" value="0" required></label>
<label>Transfer date (Nairobi time)<input type="datetime-local" step="1" name="disbursed_at" value="<?= date('Y-m-d\TH:i:s') ?>" required></label>
<label>Transfer reference<input name="reference" maxlength="100" required></label>
<label>Notes / explanation of fees<textarea name="notes" maxlength="255"></textarea></label>
<label><input type="checkbox" required> I confirm the money has been sent to this tenant.</label>
<button name="action" value="record">Mark as disbursed</button></form></div><?php endif; ?>
<div class="card"><h2>Disbursement history</h2><div style="overflow:auto"><table><thead><tr><th>Date</th><th>Reference</th><th>Collections settled</th><th>Cash sent</th><th>Fees withheld</th><th>Platform transfer cost</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><?= dh($h['disbursed_at']) ?></td><td><?= dh($h['reference']) ?></td><td><?= number_format($h['gross_amount'], 2) ?></td><td><?= number_format($h['cash_amount'], 2) ?></td><td><?= number_format($h['fees_amount'], 2) ?></td><td><?= number_format($h['platform_cost'], 2) ?></td><td><?= dh($h['notes']) ?></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="7">No manual disbursements recorded yet.</td></tr><?php endif; ?>
</tbody></table></div></div></main></html>
