<?php
require_once __DIR__.'/../includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
superAdminGuard();
require_once __DIR__.'/../includes/tenant_payouts.php';
ensurePayoutTables($pdo);
ensurePayoutReconciliationSchema($pdo);
$_SESSION['payout_review_csrf'] ??= bin2hex(random_bytes(32));
$unknown=$pdo->query("SELECT b.*,t.company_name FROM isp_payout_batches b JOIN tenants t ON t.id=b.tenant_id WHERE b.status='unknown' OR (b.status IN ('sending','accepted') AND b.created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)) ORDER BY b.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$bankRows=[];
$banks=$pdo->query("SELECT t.id,t.company_name,MAX(s.bank_verified_at) AS bank_verified_at FROM tenants t LEFT JOIN tenant_payout_settings s ON s.tenant_id=t.id JOIN payment_gateways g ON g.tenant_id=t.id AND g.gateway_type='bank_account' AND g.is_active=1 GROUP BY t.id,t.company_name ORDER BY t.company_name")->fetchAll(PDO::FETCH_ASSOC);
foreach($banks as $r){$d=tenantBankPayoutDestination($pdo,(int)$r['id']);$r['destination']=$d;if(!empty($d['configured']) && empty($r['bank_verified_at']))$bankRows[]=$r;}
function payoutAdminEscape($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$uncertainTotal=array_sum(array_map(static fn($b)=>(float)$b['sent_amount'],$unknown));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Automated payouts · FortuNett</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"><link href="css/dark.css?v=2" rel="stylesheet"><link href="css/shell.css?v=2" rel="stylesheet"><link href="css/admin.css?v=1" rel="stylesheet"><script src="js/shell.js?v=2" defer></script>
<style>
.payout-main{margin-left:var(--sidebar-w,252px);width:calc(100% - var(--sidebar-w,252px));padding:30px;max-width:1540px}.sa-page-heading{margin-bottom:24px}.payout-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:20px 0 24px}.payout-metric,.payout-panel{background:var(--neu-s2,#1a2028);border:1px solid var(--neu-border,#2b3441);border-radius:12px}.payout-metric{padding:18px 20px}.payout-metric span{display:block;color:var(--neu-muted,#a5b0bf);font-size:11px;text-transform:uppercase;letter-spacing:.07em}.payout-metric strong{display:block;margin-top:6px;font-size:25px;font-variant-numeric:tabular-nums}.payout-panel{margin:0 0 20px;overflow:hidden}.payout-panel-head{padding:19px 22px;border-bottom:1px solid var(--neu-border,#2b3441)}.payout-panel-head h2{font-size:16px;margin:0}.payout-panel-head p{font-size:12px;color:var(--neu-muted,#a5b0bf);margin:5px 0 0;line-height:1.6}.payout-panel-body{padding:0 22px}.payout-row{padding:19px 0;border-bottom:1px solid var(--neu-border,#2b3441)}.payout-row:last-child{border-bottom:0}.payout-row-title{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:8px}.payout-row-title strong{font-size:14px}.payout-row-meta{color:var(--neu-muted,#a5b0bf);font-size:12px;line-height:1.8;overflow-wrap:anywhere}.payout-review-form{display:grid;grid-template-columns:minmax(145px,.7fr) minmax(180px,.8fr) minmax(240px,1.5fr) auto;gap:10px;align-items:end;margin-top:15px}.payout-review-form label{display:block;font-size:11px;color:var(--neu-muted,#a5b0bf);margin:0 0 5px}.payout-review-form input,.payout-review-form select{width:100%;min-width:0}.payout-empty{padding:24px 0;color:var(--neu-muted,#a5b0bf);font-size:13px}.payout-note{padding:12px 14px;border-radius:8px;background:#152235;border:1px solid #2d4666;color:#b9cce5;font-size:12px;line-height:1.6;margin:0 0 16px}.payout-feedback{position:sticky;top:80px;z-index:90;margin-bottom:14px}.payout-feedback:empty{display:none}.payout-feedback:not(:empty){padding:12px 15px;border-radius:8px;background:#1c302a;border:1px solid #356453;color:#b0e4cc}.payout-feedback[data-error="1"]{background:#302025;border-color:#76464d;color:#ffc6ce}.payout-status{display:inline-flex;align-items:center;border:1px solid #596a83;color:#c5d3e6;border-radius:20px;padding:4px 9px;font-size:10px;white-space:nowrap}.payout-status-waiting{border-color:#8a6332;color:#f3d39a;background:#382b19}.payout-bank-facts{display:flex;flex-wrap:wrap;gap:5px 16px}.payout-bank-facts span{white-space:nowrap}@media(max-width:900px){.payout-main{margin-left:0;width:100%;padding:22px 16px}.payout-review-form{grid-template-columns:1fr 1fr}.payout-review-form .evidence-field{grid-column:1/-1}.payout-review-form button{width:100%;min-height:42px}}@media(max-width:560px){.payout-metrics{grid-template-columns:1fr;gap:9px}.payout-metric{padding:13px 16px}.payout-metric strong{font-size:21px}.payout-panel-head,.payout-panel-body{padding-left:16px;padding-right:16px}.payout-row-title{display:block}.payout-row-title .payout-status{margin-top:9px}.payout-review-form{grid-template-columns:1fr}.payout-review-form .evidence-field{grid-column:auto}}
</style></head><body>
<?php require __DIR__.'/includes/navigation.php'; ?>
<main id="sa-main-content" class="payout-main">
    <div class="sa-page-heading"><div><span class="sa-eyebrow">FINANCE · SETTLEMENT CONTROL</span><h2>Automated payouts</h2><p>Verify tenant bank destinations and reconcile transfers whose Safaricom outcome needs confirmation.</p></div></div>
    <div id="payoutReviewFeedback" class="payout-feedback" role="status" aria-live="polite"></div>
    <section class="payout-metrics" aria-label="Payout review summary">
        <div class="payout-metric"><span>Bank destinations to verify</span><strong><?= count($bankRows) ?></strong></div>
        <div class="payout-metric"><span>Transfers to reconcile</span><strong><?= count($unknown) ?></strong></div>
        <div class="payout-metric"><span>Unconfirmed amount</span><strong>KES <?= number_format($uncertainTotal,2) ?></strong></div>
    </section>
    <p class="payout-note"><strong>Amount guide:</strong> Collections pages show tenant funds held before fees. A tenant’s withdrawal amount is the net eligible balance after commission and payout rules. Transfers with an unknown outcome remain locked; reconcile the Safaricom result before another transfer can be sent.</p>

    <section class="payout-panel" aria-labelledby="bank-review-heading">
        <header class="payout-panel-head"><h2 id="bank-review-heading">Bank destinations awaiting verification</h2><p>Confirm ownership and the receiver shortcode/account reference against supporting documents before approving B2B payouts.</p></header>
        <div class="payout-panel-body">
        <?php if(!$bankRows): ?><p class="payout-empty">All configured bank destinations have been reviewed.</p><?php endif; ?>
        <?php foreach($bankRows as $r): $d=$r['destination']; ?><article class="payout-row">
            <div class="payout-row-title"><strong><?=payoutAdminEscape($r['company_name'])?></strong><span class="payout-status">Verification required</span></div>
            <div class="payout-row-meta payout-bank-facts"><span><?=payoutAdminEscape($d['bank_name']??'Incomplete bank details')?></span><span><?=payoutAdminEscape($d['account_name']??'Account name missing')?></span><span><?=!empty($d['account_number'])?'Account ending '.payoutAdminEscape(substr($d['account_number'],-4)):'No account number'?></span><span>Receiver shortcode <?=payoutAdminEscape($d['receiver_shortcode']??'missing')?></span></div>
            <?php if(!empty($d['valid'])): ?><button class="sa-btn sa-btn-primary" type="button" style="margin-top:13px" onclick="verifyBank(<?= (int)$r['id'] ?>,this)">Mark destination verified</button><?php else: ?><p class="payout-row-meta" style="color:#f0ca87;margin:12px 0 0">The tenant must complete the bank details and shortcode before this destination can be verified.</p><?php endif; ?>
        </article><?php endforeach; ?>
        </div>
    </section>

    <section class="payout-panel" aria-labelledby="transfer-review-heading">
        <header class="payout-panel-head"><h2 id="transfer-review-heading">Transfers awaiting reconciliation</h2><p>Transfers with an unknown result, including requests stuck in sending for over 15 minutes, appear here. Check the M-Pesa statement or Safaricom confirmation. A failed decision returns collections to the queue for a future attempt.</p></header>
        <div class="payout-panel-body">
        <?php if(!$unknown): ?><p class="payout-empty">No transfers need reconciliation right now.</p><?php endif; ?>
        <?php foreach($unknown as $b): ?><article class="payout-row">
            <div class="payout-row-title"><strong>Transfer #<?= (int)$b['id']?> · <?=payoutAdminEscape($b['company_name'])?> · KES <?=number_format((float)$b['sent_amount'],2)?></strong><span class="payout-status payout-status-waiting">Outcome unconfirmed</span></div>
            <div class="payout-row-meta">Method: <?=payoutAdminEscape(strtoupper($b['payout_method']??'mpesa'))?> · Destination/reference: <?=payoutAdminEscape($b['payout_reference'] ?: $b['payout_phone'])?><br>Started: <time datetime="<?=payoutAdminEscape(date(DATE_ATOM,strtotime($b['created_at'])))?>"><?=payoutAdminEscape(date('d M Y, H:i',strtotime($b['created_at'])))?></time> · Latest response: <?=payoutAdminEscape($b['result_desc'] ?: 'No result callback received')?></div>
            <form class="payout-review-form" onsubmit="return resolvePayout(event,<?= (int)$b['id']?>)">
                <div><label for="decision-<?= (int)$b['id'] ?>">Confirmed outcome</label><select id="decision-<?= (int)$b['id'] ?>" name="decision" class="form-select"><option value="paid">Paid successfully</option><option value="failed">Not paid</option></select></div>
                <div><label for="receipt-<?= (int)$b['id'] ?>">M-Pesa receipt</label><input id="receipt-<?= (int)$b['id'] ?>" name="receipt" class="form-control" placeholder="Required if paid" maxlength="64"></div>
                <div class="evidence-field"><label for="evidence-<?= (int)$b['id'] ?>">Reconciliation evidence</label><input id="evidence-<?= (int)$b['id'] ?>" name="evidence" class="form-control" placeholder="Statement reference or Safaricom confirmation" required minlength="8" maxlength="1000"></div>
                <button class="sa-btn sa-btn-primary" type="submit">Save outcome</button>
            </form>
        </article><?php endforeach; ?>
        </div>
    </section>
</main><script>
const reviewCsrf=<?=json_encode($_SESSION['payout_review_csrf'])?>;
async function reviewPost(values){const fd=new FormData();fd.append('csrf',reviewCsrf);Object.entries(values).forEach(([k,v])=>fd.append(k,v));const r=await fetch('../api/super_admin/payout_review.php',{method:'POST',body:fd,credentials:'same-origin'});let d;try{d=await r.json()}catch(_){throw new Error('The server returned an unreadable response. Refresh and check the payout status before trying again.')}if(!r.ok||!d.success)throw new Error(d.message||'Review could not be saved.');return d;}
function showReviewFeedback(message,error=false){const el=document.getElementById('payoutReviewFeedback');el.textContent=message;el.dataset.error=error?'1':'0';}
async function verifyBank(tenantId,button){if(!confirm('Confirm that the tenant bank ownership and receiver details have been checked?'))return;button.disabled=true;try{const d=await reviewPost({action:'verify_bank',tenant_id:tenantId});showReviewFeedback(d.message);setTimeout(()=>location.reload(),700);}catch(e){showReviewFeedback(e.message,true);button.disabled=false;}}
async function resolvePayout(event,batchId){event.preventDefault();const form=event.currentTarget;const button=form.querySelector('button[type="submit"]');const values=Object.fromEntries(new FormData(form));values.action='resolve_unknown';values.batch_id=batchId;button.disabled=true;showReviewFeedback('Saving reconciliation…');try{const d=await reviewPost(values);showReviewFeedback(d.message);setTimeout(()=>location.reload(),700);}catch(e){showReviewFeedback(e.message,true);button.disabled=false;}return false;}
</script></body></html>
