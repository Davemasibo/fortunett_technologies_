let customerSmsBusy = false;
let customerSmsRequest = '';
let customerSmsOpener = null;
function customerSmsId() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
}
function customerSmsFeedback(message, state) {
    const feedback = document.getElementById('smsFeedback');
    feedback.hidden = false;
    feedback.dataset.state = state;
    feedback.textContent = message;
}
function openSMSModal(customer) {
    if (!customer || customerSmsBusy) return;
    customerSmsOpener = document.activeElement;
    customerSmsRequest = customerSmsId();
    document.getElementById('smsForm').reset();
    document.getElementById('smsClientId').value = customer.id;
    document.getElementById('smsClientPhone').value = customer.phone || '';
    document.getElementById('smsCustomerName').textContent = customer.full_name || customer.name || 'Customer';
    document.getElementById('smsRecipientPhone').textContent = customer.phone || 'No phone number saved';
    document.getElementById('smsCharCount').textContent = '0 characters';
    document.getElementById('smsFeedback').hidden = true;
    document.getElementById('smsSendBtn').disabled = !customer.phone;
    document.getElementById('smsSendBtn').textContent = 'Send SMS';
    if (!customer.phone) customerSmsFeedback('Add a phone number to this customer before sending an SMS.', 'error');
    document.getElementById('smsModal').style.display = 'flex';
    document.getElementById('smsMessage').focus();
}
function closeSMSModal() {
    if (customerSmsBusy) return;
    document.getElementById('smsModal').style.display = 'none';
    customerSmsOpener?.focus();
}
function applyTemplate() {
    const template = document.getElementById('smsTemplate').value;
    if (template) document.getElementById('smsMessage').value = template;
    document.getElementById('smsCharCount').textContent = document.getElementById('smsMessage').value.length + ' characters';
}
async function handleSendSMS(event) {
    event.preventDefault();
    if (customerSmsBusy) return;
    const message = document.getElementById('smsMessage').value.trim();
    if (!message) { customerSmsFeedback('Enter a message before sending.', 'error'); return; }
    const clientId = Number(document.getElementById('smsClientId').value);
    const button = document.getElementById('smsSendBtn');
    customerSmsBusy = true;
    button.disabled = true;
    button.textContent = 'Sending…';
    document.getElementById('smsMessage').readOnly = true;
    document.getElementById('smsTemplate').disabled = true;
    customerSmsFeedback('Sending SMS. Please wait for confirmation…', 'progress');
    try {
        const response = await fetch('api/clients/send_sms.php', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':smsRetryCsrf},
            body:JSON.stringify({client_id:clientId, message, request_id:customerSmsRequest})
        });
        const result = await response.json();
        customerSmsFeedback(result.message || 'Check SMS history for the result.', result.success ? 'success' : 'error');
        button.textContent = result.success ? 'Sent' : (result.retryable ? 'Try again' : 'Check history');
        if (!result.success && result.retryable) {
            customerSmsRequest = customerSmsId();
            button.disabled = false;
        }
        loadSMSHistory(clientId);
    } catch (error) {
        customerSmsFeedback('The result could not be confirmed. Check SMS history before sending again.', 'error');
        button.textContent = 'Check history';
    } finally {
        customerSmsBusy = false;
        document.getElementById('smsMessage').readOnly = false;
        document.getElementById('smsTemplate').disabled = false;
    }
}
document.addEventListener('keydown', function (event) {
    const modal = document.getElementById('smsModal');
    if (!modal || modal.style.display === 'none') return;
    if (event.key === 'Escape') { event.stopPropagation(); closeSMSModal(); }
    if (event.key === 'Tab') {
        const focusable = [...modal.querySelectorAll('button:not(:disabled),a,input:not([type="hidden"]),select:not(:disabled),textarea')];
        const first = focusable[0], last = focusable[focusable.length-1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
