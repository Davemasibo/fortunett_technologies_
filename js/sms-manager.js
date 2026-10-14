(() => {
    const messages = [...document.querySelectorAll('.sms-message-details')];
    function updateMessageControls() {
        messages.forEach(details => {
            if (details.open) return;
            const preview = details.nextElementSibling;
            if (!preview?.classList.contains('sms-message-preview')) return;
            details.hidden = preview.scrollHeight <= preview.clientHeight + 1;
        });
    }
    messages.forEach(details => details.addEventListener('toggle',updateMessageControls));
    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(updateMessageControls);
        messages.forEach(details => observer.observe(details.parentElement));
    }
    window.addEventListener('resize',updateMessageControls);
    document.fonts?.ready.then(updateMessageControls);
    updateMessageControls();
    const dialogs = ['configModal','sendModal','templateModal','bulkSmsModal'].map(id => document.getElementById(id)).filter(Boolean);
    let opener = null;
    let previousOverflow = '';
    const visible = dialog => dialog.style.display !== 'none';
    dialogs.forEach(dialog => {
        const heading = dialog.querySelector('h3');
        if (heading) { heading.id = dialog.id + 'Title'; dialog.setAttribute('aria-labelledby',heading.id); }
        dialog.setAttribute('role','dialog');
        dialog.setAttribute('aria-modal','true');
        dialog.setAttribute('aria-hidden','true');
        let wasOpen = false;
        new MutationObserver(() => {
            const open = visible(dialog);
            if (open === wasOpen) return;
            wasOpen = open;
            dialog.setAttribute('aria-hidden',String(!open));
            if (open) {
                opener = document.activeElement;
                previousOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                const first = dialog.querySelector('input:not([type="hidden"]),select,textarea,button');
                first?.focus();
            } else if (!dialogs.some(visible)) {
                document.body.style.overflow = previousOverflow;
                opener?.focus();
            }
        }).observe(dialog,{attributes:true,attributeFilter:['style']});
        dialog.addEventListener('click',event => { if (event.target === dialog) dialog.style.display='none'; });
    });
    document.addEventListener('keydown',event => {
        const dialog = dialogs.find(visible);
        if (!dialog) return;
        if (event.key === 'Escape') { dialog.style.display='none'; return; }
        if (event.key !== 'Tab') return;
        const items = [...dialog.querySelectorAll('input:not([type="hidden"]):not(:disabled),select:not(:disabled),textarea:not(:disabled),button:not(:disabled),a[href]')];
        const first = items[0], last = items[items.length-1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
})();
