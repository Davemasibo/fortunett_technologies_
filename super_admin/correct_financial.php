<?php
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
superAdminGuard();
require_once __DIR__.'/../includes/financial_corrections.php';
require_once __DIR__.'/../includes/platform_billing.php';
ensurePlatformBillingSchema($pdo); ensureSmsInvoiceSchema($pdo); ensureDisbursementSchema($pdo); ensureFinancialCorrectionSchema($pdo);
$type=$_GET['type']??'payout'; $id=(int)($_GET['id']??0);
if(!in_array($type,['invoice','payout'],true)){http_response_code(400);exit('Invalid record type.');}
$table=$type==='invoice'?'platform_invoices':'tenant_disbursements';
$st=$pdo->prepare("SELECT * FROM $table WHERE id=?");$st->execute([$id]);$record=$st->fetch(PDO::FETCH_ASSOC);
if(!$record){http_response_code(404);exit('Record not found.');}
$_SESSION['financial_csrf']??=bin2hex(random_bytes(32));$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals($_SESSION['financial_csrf'],(string)($_POST['csrf']??''))) throw new InvalidArgumentException('Session expired. Reload and try again.');
        correctFinancialRecord($pdo,$type,$id,(int)$_SESSION['user_id'],$_POST);
        header('Location: correct_financial.php?type='.$type.'&id='.$id.'&saved=1');exit;
    } catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Financial correction: '.$e->getMessage());$error='Correction could not be saved. No changes were applied.';}
}
$values=array_merge($record,$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[]);
$st=$pdo->prepare('SELECT * FROM financial_corrections WHERE record_type=? AND record_id=? ORDER BY id DESC');$st->execute([$type,$id]);$history=$st->fetchAll(PDO::FETCH_ASSOC);
function fh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$back=$type==='invoice'?'billing.php?invoice='.$id:'disbursements.php?tenant_id='.(int)$record['tenant_id'];
$fields=$type==='invoice'?['pppoe_user_count'=>'PPPoE user count','pppoe_fee_per_user'=>'PPPoE fee per user','hotspot_collections'=>'Hotspot collections','hotspot_commission_rate'=>'Hotspot commission rate (0.03 = 3%)','base_fee'=>'Monthly fee','sms_amount'=>'SMS charge']:['cash_amount'=>'Full cash received','fees_amount'=>'Transaction cost charged to tenant','platform_cost'=>'Transfer charge paid by platform'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Correct <?= fh($type) ?> · FortuNett</title>
<link rel="stylesheet" href="css/dark.css?v=2"><link rel="stylesheet" href="css/shell.css?v=2"><link rel="stylesheet" href="css/admin.css?v=1"><script src="js/shell.js?v=2" defer></script></head><body class="sa-shell">
<?php include __DIR__.'/includes/navigation.php'; ?>
<div class="main"><header class="topbar"><h1>Correct <?= fh($type) ?></h1></header><main class="content">
<p><a href="<?= fh($back) ?>">← Back to <?= fh($type) ?></a></p>
<section class="card"><div class="card-body"><h2><?= fh($record[$type==='invoice'?'invoice_number':'reference']) ?></h2>
<p>Tenant #<?= (int)$record['tenant_id'] ?> · All amounts in KES. Each correction retains the previous values and your reason.</p>
<?php if($error): ?><p class="sa-alert sa-alert-error" role="alert"><?= fh($error) ?></p><?php endif; ?>
<?php if(isset($_GET['saved'])): ?><p class="sa-alert sa-alert-success" role="status">Correction saved. Linked balances have been updated.</p><?php endif; ?>
<?php if($type==='payout'): ?><p>The tenant balance reduces by the full cash received plus the transaction cost. Any extra deduction uses available collections as of now. This records a correction to money already sent.</p>
<?php else: ?><p>Invoice total uses user count ? fee, collections ? commission rate, monthly fee, and SMS charge. For an invoice settled from collections, its linked deduction updates with the total. Separately paid invoices retain their paid total.</p><?php endif; ?>
<?php if($type==='invoice' && (float)($record['carryover_amount']??0)>0): ?><p>Prior-period collections commission: KES <?= number_format($record['carryover_amount'],2) ?>. This carried amount stays linked to the source invoices.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= fh($_SESSION['financial_csrf']) ?>"><input type="hidden" name="revision" value="<?= fh($values['revision']??financialRevision($record)) ?>">
<div class="sa-form-grid">
<?php foreach($fields as $key=>$label): ?><div class="sa-field"><label for="<?= $key ?>"><?= fh($label) ?><?= in_array($key,['pppoe_user_count','hotspot_commission_rate'],true)?'':' (KES)' ?></label><input id="<?= $key ?>" name="<?= $key ?>" inputmode="decimal" value="<?= fh($values[$key]) ?>" required></div><?php endforeach; ?>
<?php if($type==='invoice'): ?><div class="sa-field"><label for="due_date">Due date</label><input type="date" id="due_date" name="due_date" value="<?= fh(substr($values['due_date'],0,10)) ?>" required></div><?php endif; ?>
<div class="sa-field sa-field-wide"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" <?= $type==='payout'?'maxlength="255"':'' ?>><?= fh($values['notes']??'') ?></textarea></div>
<div class="sa-field sa-field-wide"><label for="reason">Reason for correction</label><textarea id="reason" name="reason" maxlength="255" required><?= fh($values['reason']??'') ?></textarea></div>
</div><p id="correction-total"></p><button type="submit" class="sa-btn sa-btn-primary">Save correction</button></form></div></section>
<section class="card"><div class="card-body"><h2>Correction history</h2>
<?php if(!$history): ?><p>No corrections yet.</p><?php endif; ?>
<?php foreach($history as $h): ?><details><summary><?= fh($h['created_at']) ?> · Admin #<?= (int)$h['actor_id'] ?> · <?= fh($h['reason']) ?></summary><div class="table-wrap"><table><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
<?php $before=json_decode($h['before_values'],true);$after=json_decode($h['after_values'],true);foreach($after as $key=>$value): if(($before[$key]??null)===$value)continue; ?><tr><td><?= fh($key) ?></td><td><?= fh($before[$key]??'') ?></td><td><?= fh($value??'') ?></td></tr><?php endforeach; ?>
</tbody></table></div></details><?php endforeach; ?></div></section></main></div>
<script>
(()=>{const fields=<?= json_encode(array_keys($fields)) ?>,type=<?= json_encode($type) ?>,carryover=<?= json_encode((float)($record['carryover_amount']??0)) ?>;
function update(){const amounts=fields.map(k=>Number(document.getElementById(k).value.replaceAll(',','')));const total=type==='payout'?amounts[0]+amounts[1]:amounts[0]*amounts[1]+amounts[2]*amounts[3]+amounts[4]+amounts[5]+carryover;document.getElementById('correction-total').textContent=(type==='payout'?'Tenant balance reduction: KES ':'Invoice total: KES ')+(Number.isFinite(total)?total.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2}):'—');}
fields.forEach(k=>document.getElementById(k).addEventListener('input',update));update();})();
</script></body></html>
