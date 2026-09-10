document.addEventListener('click', async function (event) {
    const button = event.target.closest('.sms-retry');
    if (!button || button.disabled) return;
    const feedback = button.parentElement.querySelector('.sms-retry-feedback');
    button.disabled = true;
    button.textContent = 'Retrying…';
    feedback.textContent = 'Sending the original message…';
    try {
        const response = await fetch('api/sms/retry.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': smsRetryCsrf},
            body: JSON.stringify({id: Number(button.dataset.id)})
        });
        const result = await response.json();
        feedback.textContent = result.message || 'Refresh the history to check the retry status.';
        if (result.success) {
            button.textContent = 'Resent';
        } else {
            button.textContent = result.retryable ? 'Retry SMS' : 'Check history';
            button.disabled = result.retryable !== true;
        }
    } catch (error) {
        button.textContent = 'Check history';
        feedback.textContent = 'The result could not be confirmed. Refresh the history before trying again.';
    }
});
