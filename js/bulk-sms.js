(() => {
    const form = document.getElementById('bulkSmsForm');
    if (!form) return;
    const audience = document.getElementById('bulkSmsAudience');
    const recipients = document.getElementById('bulkSmsRecipients');
    const message = document.getElementById('bulkSmsMessage');
    const preset = document.getElementById('bulkSmsPreset');
    const progress = document.getElementById('bulkSmsProgress');
    const results = document.getElementById('bulkSmsResults');
    let sending = false;
    // One SMS per saved phone, even when duplicate customer records are selected.
    function targets() {
        const seen = new Set();
        return [...recipients.options].filter(option => {
            if (audience.value === 'selected' && !option.selected) return false;
            let phone = option.dataset.phone.replace(/\D/g, '');
            if (phone.startsWith('0')) phone = '254' + phone.slice(1);
            else if (phone.length === 9) phone = '254' + phone;
            if (!phone || seen.has(phone)) return false;
            seen.add(phone);
            return true;
        });
    }
    function update() {
        recipients.hidden = audience.value !== 'selected';
        document.getElementById('bulkSmsCount').textContent = targets().length + ' unique phone numbers selected.';
    }
    audience.addEventListener('change', update);
    recipients.addEventListener('change', update);
    preset.addEventListener('change', () => { if (preset.value) message.value = preset.value; });
    window.addEventListener('beforeunload', event => {
        if (sending) { event.preventDefault(); event.returnValue = ''; }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending) return;
        const chosen = targets();
        const text = message.value.trim();
        if (!chosen.length || !text) { progress.textContent = 'Select recipients and enter a message.'; return; }
        if (/\[(area|time|offer details|date)\]/i.test(text)) { progress.textContent = 'Replace the starter details in square brackets before sending.'; return; }
        if (!window.confirm('Send this message to ' + chosen.length + ' unique phone numbers? SMS charges apply.\n\n' + text)) return;
        sending = true;
        [...form.elements].forEach(control => { control.disabled = true; });
        results.replaceChildren();
        let accepted = 0, failed = 0, unknown = 0, completed = 0;
        try {
            for (const option of chosen) {
                progress.textContent = 'Sending ' + (completed + 1) + ' of ' + chosen.length + '…';
                const bytes = crypto.getRandomValues(new Uint8Array(16));
                const requestId = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
                let detail;
                let stop = false;
                try {
                    const response = await fetch('api/clients/send_sms.php', {
                        method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': smsRetryCsrf},
                        body: JSON.stringify({client_id: Number(option.value), message: text, request_id: requestId})
                    });
                    const result = await response.json();
                    detail = result.message || 'Check SMS history for the result.';
                    if (result.success) accepted++;
                    else if (result.retryable || [400,401,403].includes(response.status)) failed++;
                    else unknown++;
                    stop = [401,403,503].includes(response.status);
                } catch (error) {
                    unknown++;
                    detail = 'Delivery could not be confirmed. Check SMS history before resending.';
                    stop = true;
                }
                const row = document.createElement('li');
                row.textContent = option.textContent + ': ' + detail;
                results.append(row);
                completed++;
                if (stop) break;
            }
        } finally {
            sending = false;
            [...form.elements].forEach(control => { control.disabled = false; });
            progress.textContent = accepted + ' accepted by the provider, ' + failed + ' failed, ' + unknown + ' unconfirmed, ' + (chosen.length - completed) + ' not attempted. Review SMS history before resending.';
        }
    });
    update();
})();
