window.ExpiryModal = (() => {
    const el = id => document.getElementById(id);
    let customer, previousFocus, busy = false;
    const localValue = date => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0,16);
    function setDate(value) { el('expiryCalendar').value = value.slice(0,10); el('expiryClock').value = value.slice(11,16); preview(); }
    function preview() {
        const value = el('expiryCalendar').value + 'T' + el('expiryClock').value;
        el('ownerAccessExpiry').value = el('expiryDateInput').value = value;
        const date = new Date(value);
        el('expiryPreview').textContent = isNaN(date) ? 'Choose a date and time.' : 'Access ends ' + date.toLocaleString(undefined, {dateStyle:'full',timeStyle:'short'});
    }
    function preset(years, days = 0) {
        const date = new Date(); date.setFullYear(date.getFullYear() + years); date.setDate(date.getDate() + days);
        setDate(localValue(date));
    }
    function addTime(minutes) {
        const selected = el('expiryCalendar').value + 'T' + el('expiryClock').value;
        const date = new Date(Math.max(new Date(selected).getTime() || 0, Date.now()));
        date.setMinutes(date.getMinutes() + minutes);
        setDate(localValue(date));
    }
    function addMonths(months) {
        const selected = el('expiryCalendar').value + 'T' + el('expiryClock').value;
        const date = new Date(Math.max(new Date(selected).getTime() || 0, Date.now()));
        const day = date.getDate();
        date.setDate(1);
        date.setMonth(date.getMonth() + months);
        // Keep month-end extensions inside the intended month (Jan 31 -> Feb 28).
        date.setDate(Math.min(day, new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()));
        setDate(localValue(date));
    }
    function selectAction() {
        const action = el('expiryAction').value;
        el('expiryDateSection').hidden = action === 'package';
        el('expiryPackageSection').hidden = action !== 'package';
        el('expiryPresets').hidden = action !== 'owner';
        el('expiryExtensions').hidden = action !== 'date';
        el('expiryFeedback').textContent = '';
        el('expiryActionHelp').textContent = action === 'owner' ? 'Activate this hotspot account without payment for up to 10 years. Package speed and device limits still apply.' : 'Set an earlier or later expiry, or add time below. Added time starts from the selected expiry or now, whichever is later.';
        el('expirySave').textContent = action === 'owner' ? 'Grant owner access' : action === 'date' ? 'Save expiry' : 'Change package';
        if (action === 'owner') preset(1);
        else setDate(customer.expiry_date ? customer.expiry_date.replace(' ','T') : localValue(new Date()));
    }
    function open(value) {
        customer = value; previousFocus = document.activeElement;
        el('expiryCustomerName').textContent = value.full_name || value.name || value.username || 'Customer';
        const owner = el('expiryOwnerOption');
        if (owner) owner.disabled = owner.hidden = value.connection_type !== 'hotspot';
        el('expiryAction').value = 'date';
        el('expiryPackageSelect').value = '';
        selectAction(); el('expiryAction').focus();
    }
    function feedback(message) { el('expiryFeedback').textContent = message; }
    function save() {
        if (busy) return;
        const action = el('expiryAction').value;
        if (action === 'package') { applyChangePackage(); return; }
        if (!el('expiryCalendar').reportValidity() || !el('expiryClock').reportValidity()) return;
        preview();
        const date = new Date(el('expiryDateInput').value), now = new Date(), max = new Date(); max.setFullYear(max.getFullYear()+10);
        if (action === 'owner' && (date <= now || date > max)) { feedback('Choose a future date within 10 years.'); return; }
        if (isNaN(date.getTime())) { feedback('Choose a valid date and time.'); return; }
        action === 'owner' ? applyOwnerAccess() : applySetDate();
    }
    function pending(value) { busy = value; el('expiryModal').querySelectorAll('button,input,select').forEach(node => node.disabled = value); if (!value && el('expiryOwnerOption')) el('expiryOwnerOption').disabled = customer.connection_type !== 'hotspot'; }
    function close() { if (busy) return false; previousFocus?.focus(); return true; }
    ['expiryCalendar','expiryClock'].forEach(id => { el(id).addEventListener('input', preview); el(id).addEventListener('click', () => { try { el(id).showPicker(); } catch (_) {} }); });
    el('expiryModal').addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeExpiryModal(); }
        if (event.key === 'Tab') {
            const items = [...el('expiryModal').querySelectorAll('button,input,select')].filter(node => !node.disabled && node.offsetParent !== null);
            const first = items[0], last = items[items.length-1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    return {open, close, save, preset, addTime, addMonths, selectAction, pending, feedback};
})();
