(() => {
    'use strict';
    const form = document.getElementById('disbursement-form');
    if (!form) return;
    const field = name => form.elements.namedItem(name);
    const button = document.getElementById('record-disbursement');
    const available = Math.round(Number(form.dataset.available) * 100);
    const feedback = document.getElementById('amount-feedback');
    const money = value => {
        let raw = value.trim();
        if (raw === '') return 0;
        if (/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/.test(raw)) raw = raw.replaceAll(',', '');
        return /^\d{1,9}(\.\d{1,2})?$/.test(raw) ? Math.round(Number(raw) * 100) : NaN;
    };
    const format = cents => 'KES ' + (cents / 100).toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function update() {
        const cash = money(field('cash_amount').value), fees = money(field('fees_amount').value), cost = money(field('platform_cost').value);
        const stale = field('cutoff_date').value !== form.dataset.previewDate;
        const invalid = ![cash, fees, cost].every(Number.isFinite);
        const over = !invalid && cash + fees > available;
        document.getElementById('preview-stale').hidden = !stale;
        const values = {cash, fees, platform: cost, reduction: cash + fees, remaining: available - cash - fees};
        Object.entries(values).forEach(([name, value]) => { document.getElementById('summary-' + name).textContent = Number.isFinite(value) ? format(value) : '—'; });
        feedback.textContent = invalid ? 'Use a valid amount with up to two decimal places.' : over
            ? 'Tenant cash plus deducted fees exceeds the available collections. A charge paid by you belongs in “Transfer charge paid by you”.' : '';
        feedback.hidden = !feedback.textContent;
        field('notes').required = fees > 0;
        document.getElementById('notes-hint').textContent = fees > 0 ? '(required for tenant fees)' : '(optional)';
        button.disabled = !!button.dataset.unavailable || invalid || over || cash <= 0 || stale;
    }
    ['cash_amount', 'fees_amount', 'platform_cost', 'cutoff_date'].forEach(name => field(name).addEventListener('input', update));
    field('disbursed_at').addEventListener('change', () => {
        if (field('disbursed_at').value && field('cutoff_date').value > field('disbursed_at').value) field('cutoff_date').value = field('disbursed_at').value;
        update();
    });
    form.addEventListener('submit', e => {
        if (e.submitter?.value !== 'record') return;
        update();
        if (button.disabled) { e.preventDefault(); return; }
        // Preserve the submit action while blocking accidental double-clicks.
        const action = document.createElement('input'); action.type = 'hidden'; action.name = 'action'; action.value = 'record'; form.appendChild(action);
        button.disabled = true; button.textContent = 'Recording…';
    });
    update();
    document.getElementById('disbursement-error')?.focus();
})();
