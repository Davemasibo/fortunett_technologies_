<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101318"><link rel="icon" href="/favicon.svg" type="image/svg+xml">
<title>Disbursements · <?= dh($name) ?> · FortuNett</title>
<link rel="stylesheet" href="css/dark.css?v=2"><link rel="stylesheet" href="css/shell.css?v=2"><link rel="stylesheet" href="css/admin.css?v=1">
<script src="js/shell.js?v=2" defer></script><script src="js/disbursements.js?v=4" defer></script>
<link rel="stylesheet" href="/css/responsive-app.css?v=1">
<script src="/js/responsive-app.js?v=1" defer></script>
</head><body class="sa-shell">
<?php include __DIR__ . '/navigation.php'; ?>
<div class="main"><header class="topbar"><h1>Collections &amp; payouts</h1><span class="sa-subtle">KES · Nairobi time</span></header>
<main class="content" id="sa-main-content">
<div class="sa-page-heading"><div><span class="sa-eyebrow">TENANT PAYOUT</span><h2><?= dh($name) ?></h2><p>Record money already sent and keep the remaining balance up to date.</p></div><a class="sa-back" href="collections.php?tab=held">← All collections</a></div>
<?php if ($error): ?><div class="sa-alert sa-alert-error" role="alert" id="disbursement-error" tabindex="-1"><strong>Disbursement not saved.</strong> <?= dh($error) ?><br>Your entries have been kept below.</div><?php endif; ?>
<?php if ($success): ?><div class="sa-alert sa-alert-success" role="status"><?= dh($success) ?></div><?php endif; ?>
<section class="card"><div class="card-body"><h2>Settle invoices before payout</h2>
<?php if ($invoiceOutstanding>0): ?>
<p>Outstanding invoices: <strong>KES <?= number_format($invoiceOutstanding/100,2) ?></strong>. Payouts are blocked until these invoices are settled.</p>
<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Due date</th><th>Unpaid balance (KES)</th></tr></thead><tbody>
<?php foreach($collectionInvoices as $ci): ?><tr><td><a href="billing.php?invoice=<?= (int)$ci['id'] ?>"><?= dh($ci['invoice_number']) ?></a></td><td><?= dh($ci['due_date']) ?></td><td><?= number_format(((float)$ci['total_due']-(float)$ci['amount_paid']),2) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p>Collections available: KES <?= number_format($gross,2) ?>. Remaining after invoice deductions: KES <?= number_format(max(0,$gross-$invoiceOutstanding/100),2) ?>. Transaction costs are charged separately when you record the payout.</p>
<?php if ((int)round($gross*100)<$invoiceOutstanding): ?><p role="alert">Collections are insufficient to settle all invoices. No payout can be recorded yet.</p><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="settle_invoices"><input type="hidden" name="csrf" value="<?= dh($_SESSION['disbursement_csrf']) ?>">
<input type="hidden" name="preview_cutoff" value="<?= dh($cutoff) ?>"><input type="hidden" name="expected_gross" value="<?= number_format($gross,2,'.','') ?>"><input type="hidden" name="expected_count" value="<?= count($rows) ?>"><input type="hidden" name="invoice_revision" value="<?= dh(collectionInvoiceRevision($collectionInvoices)) ?>">
<label class="sa-confirm"><input type="checkbox" name="confirm_invoices" value="1" required>Deduct KES <?= number_format($invoiceOutstanding/100,2) ?> from this tenant's collections to settle the invoices listed above.</label>
<button class="sa-btn sa-btn-primary" type="submit" <?= !$schemaReady || (int)round($gross*100)<$invoiceOutstanding?'disabled':'' ?>>Deduct invoices from collections</button></form>
<?php elseif($schemaReady): ?><p>No outstanding invoices. The remaining collections can be paid out.</p><?php endif; ?>
</div></section>
<div class="sa-dsb-layout">
    <section class="card" aria-labelledby="record-heading">
        <div class="card-head"><div><h2 id="record-heading">Record payout</h2><p>Record a transfer you already completed.</p></div></div>
        <form method="post" id="disbursement-form" data-available="<?= number_format($gross, 2, '.', '') ?>" data-preview-date="<?= dh(str_replace(' ', 'T', substr($cutoff, 0, 16))) ?>">
            <input type="hidden" name="csrf" value="<?= dh($_SESSION['disbursement_csrf']) ?>">
            <input type="hidden" name="preview_cutoff" value="<?= dh($cutoff) ?>">
            <input type="hidden" name="expected_gross" value="<?= number_format($gross, 2, '.', '') ?>">
            <input type="hidden" name="expected_count" value="<?= count($rows) ?>">
            <div class="sa-section">
                <div class="sa-section-title"><span class="sa-step" aria-hidden="true">1</span><h3>Transfer details</h3></div>
                <div class="sa-form-grid">
                    <div class="sa-field"><label for="payout_amount">Full cash received by tenant (KES)</label><input id="payout_amount" name="payout_amount" inputmode="decimal" placeholder="e.g. 10,000.00" value="<?= dh($form['payout_amount']) ?>" required aria-describedby="payout-help"><small id="payout-help">This amount plus the transaction cost is deducted from what you owe the tenant.</small></div>
                    <div class="sa-field"><label for="fees_amount">Transaction cost charged to tenant (KES)</label><input id="fees_amount" name="fees_amount" inputmode="decimal" value="<?= dh($form['fees_amount']) ?>" required aria-describedby="fees-help"><small id="fees-help">Charged after the full cash amount, from the tenant?s share. Enter 0 if there is no cost.</small></div>
                    <div class="sa-field"><label for="disbursed_at">Transfer date and time</label><input type="datetime-local" id="disbursed_at" name="disbursed_at" max="<?= $nowLocal ?>" value="<?= dh($form['disbursed_at']) ?>" required><small>Exact time you sent the money, in Nairobi time (UTC+3).</small></div>
                    <div class="sa-field sa-field-wide"><label for="reference">Transfer reference</label><input id="reference" name="reference" maxlength="100" placeholder="M-Pesa code or bank transfer reference" value="<?= dh($form['reference']) ?>" required autocomplete="off"><small>A reference can only be recorded once for this tenant.</small></div>
                </div>
            </div>
            <div class="sa-section">
                <div class="sa-section-title"><span class="sa-step" aria-hidden="true">2</span><h3>Collections covered</h3></div>
                <div class="sa-form-grid">
                    <div class="sa-field"><label for="cutoff_date">Collections through</label><input type="datetime-local" id="cutoff_date" name="cutoff_date" max="<?= $nowLocal ?>" value="<?= dh($form['cutoff_date']) ?>" required><small>Includes unpaid collections up to this exact time. Later collections await the next payout.</small></div>
                    <div class="sa-field" style="align-self:center;"><button class="sa-btn sa-btn-secondary" type="submit" name="action" value="preview" formnovalidate>Update preview</button></div>
                </div>
                <div class="sa-preview"><strong>KES <?= number_format($gross, 2) ?> available</strong><p><?= count($rows) ?> outstanding payment<?= count($rows) === 1 ? '' : 's' ?> through <?= dh(date('d M Y, H:i:s', strtotime($cutoff))) ?>. You can record a smaller payout and settle the rest later.</p></div>
                <p class="sa-subtle" id="preview-stale" hidden>The collection date or time changed. Update the preview before saving.</p>
            </div>
            <div class="sa-section">
                <div class="sa-section-title"><span class="sa-step" aria-hidden="true">3</span><h3>Optional details</h3></div>
                <div class="sa-form-grid">
                    <div class="sa-field"><label for="platform_cost">Transfer charge paid by you (KES)</label><input id="platform_cost" name="platform_cost" inputmode="decimal" value="<?= dh($form['platform_cost']) ?>" aria-describedby="platform-help"><small id="platform-help">Your expense. This does not reduce the tenant's balance.</small></div>
                    <div class="sa-field sa-field-wide"><label for="notes">Notes <span class="sa-subtle" id="notes-hint">(optional)</span></label><textarea id="notes" name="notes" rows="2" maxlength="255" placeholder="Explain any tenant fee, or add a transfer note."><?= dh($form['notes']) ?></textarea></div>
                </div>
                <p class="sa-fee-help">Example: KES 10,000 received + KES 50 transaction cost = KES 10,050 deducted from the tenant?s balance.</p>
            </div>
            <div class="sa-section">
                <p id="amount-feedback" class="sa-alert sa-alert-error" role="alert" hidden></p>
                <label class="sa-confirm"><input type="checkbox" name="confirmed" value="1" required <?= !empty($form['confirmed']) ? 'checked' : '' ?>>I confirm that this tenant has already received the amount entered above.</label>
                <div class="sa-form-actions"><span class="sa-subtle">The tenant will see the cash received and transaction cost.</span><button id="record-disbursement" class="sa-btn sa-btn-primary" type="submit" name="action" value="record" <?= !$schemaReady || !$rows || $invoiceOutstanding>0 ? 'disabled data-unavailable="true"' : '' ?>>Record payout <span aria-hidden="true">→</span></button></div>
            </div>
        </form>
    </section>
    <aside class="card sa-dsb-summary" aria-labelledby="summary-heading"><div class="card-body">
        <div class="sa-summary-label" id="summary-heading">Outstanding collections</div><div class="sa-summary-amount">KES <?= number_format($gross, 2) ?></div><p class="sa-subtle">Through the selected date and time</p>
        <div class="sa-summary-total">
            <div class="sa-summary-row"><span>Tenant receives</span><strong class="sa-numbers" id="summary-cash">KES 0.00</strong></div>
            <div class="sa-summary-row"><span>Transaction cost</span><strong class="sa-numbers" id="summary-fees">KES 0.00</strong></div>
            <div class="sa-summary-row"><span>Balance reduction</span><strong class="sa-numbers" id="summary-reduction">KES 0.00</strong></div>
        </div>
        <div class="sa-summary-total"><div class="sa-summary-row"><span>Still owed to tenant</span><strong class="sa-numbers" id="summary-remaining">KES <?= number_format($gross, 2) ?></strong></div></div>
        <div class="sa-summary-total"><div class="sa-summary-row"><span>Your transfer charge</span><strong class="sa-numbers" id="summary-platform">KES 0.00</strong></div><p class="sa-subtle">Your transfer charge is recorded separately from tenant funds. Platform invoices keep their existing payment status.</p></div>
    </div></aside>
</div>
<section class="card" aria-labelledby="history-heading"><div class="card-head"><div><h2 id="history-heading">Payout history</h2><p>Recorded transfers for <?= dh($name) ?> · all amounts in KES</p></div><span class="sa-subtle"><?= count($history) ?> record<?= count($history) === 1 ? '' : 's' ?></span></div>
<div class="table-wrap" tabindex="0" role="region" aria-label="Disbursement history"><table><thead><tr><th>Date / reference</th><th>Tenant received</th><th>Fees / invoice deduction</th><th>Balance reduction</th><th>Your charge</th><th>Status</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><div><?= dh(date('d M Y, H:i', strtotime($h['disbursed_at']))) ?></div><div class="sa-history-reference"><?= dh($h['reference']) ?></div></td><td class="sa-numbers"><?= number_format($h['cash_amount'], 2) ?></td><td class="sa-numbers"><?= number_format($h['fees_amount'], 2) ?></td><td class="sa-numbers"><?= number_format($h['gross_amount'], 2) ?></td><td class="sa-numbers"><?= number_format($h['platform_cost'], 2) ?></td><td><span class="sa-status-pill"><?= !empty($h['is_invoice_offset']) ? 'Invoice settled' : 'Disbursed' ?></span></td><td><?= dh($h['notes']) ?><br><a href="correct_financial.php?type=payout&amp;id=<?= (int)$h['id'] ?>">Correct entry</a></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="7" class="sa-empty">No transfers recorded yet. Confirm your first disbursement above.</td></tr><?php endif; ?>
</tbody></table></div></section>
</main></div></body></html>
