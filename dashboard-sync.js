/* Shared save feedback; the server owns retries and the purchased deadline. */
window.DashboardSync = (() => {
    const csrf = document.currentScript.dataset.csrf;
    let running = false, timer, hadPending = false, refreshVersion = 0, revision = 0;
    const panel = document.createElement('div');
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');
    panel.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:10000;max-width:420px;padding:14px 18px;border-radius:10px;background:#18344c;color:#fff;box-shadow:0 4px 20px #0004;display:none';
    document.body.appendChild(panel);
    function message(text) { panel.textContent = text; panel.style.display = 'block'; }
    async function refresh() {
        const version = ++refreshVersion;
        const response = await fetch(location.href, { cache: 'no-store' });
        if (!response.ok) throw new Error('Refresh failed');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        if (!page.querySelector('.stats-row')) throw new Error('Dashboard unavailable');
        if (version !== refreshVersion) return;
        const selected = new Set(Array.from(document.querySelectorAll('.row-check:checked'), input => input.value));
        const selectors = ['.stats-row', '.packages-section', '.table-container'];
        for (const selector of selectors) {
            const current = document.querySelector(selector), next = page.querySelector(selector);
            if (current && next) {
                const scroll = current.scrollLeft;
                current.replaceWith(next);
                next.scrollLeft = scroll;
            }
        }
        document.querySelectorAll('.row-check').forEach(input => { input.checked = selected.has(input.value); });
        if (typeof window.updateBulkBar === 'function') window.updateBulkBar();
    }
    async function poll() {
        if (running) return;
        clearTimeout(timer);
        running = true;
        const version = revision;
        let delay = 10000;
        try {
            const response = await fetch('api/dashboard_sync.php', { method: hadPending ? 'POST' : 'GET', cache: 'no-store', headers: { 'X-CSRF-Token': csrf } });
            const result = await response.json();
            if (version !== revision) { delay = 300; return; }
            if (!response.ok || !result.success) throw new Error('Status unavailable');
            if (result.pending) {
                hadPending = true;
                message(result.waiting
                    ? `Saved. ${result.pending} update(s) pending; some could not be applied. Retrying automatically.`
                    : `Saved. Applying ${result.pending} update(s) to routers…`);
                delay = result.waiting === result.pending ? 10000 : 300;
            } else if (hadPending) {
                hadPending = false;
                message('Saved and applied to routers.');
                await refresh();
            }
        } catch (_) {
            if (hadPending) message('Saved. Connection interrupted while checking progress. Updates will retry automatically.');
        } finally {
            running = false;
            timer = setTimeout(poll, delay);
        }
    }
    async function saved(data) {
        revision++;
        message(data.message || 'Saved. Applying changes…');
        hadPending = Boolean(data.sync_pending) || hadPending;
        try { await refresh(); }
        catch (_) { message('Saved. Could not refresh the list; refresh the page to see your changes.'); }
        poll();
    }
    timer = setTimeout(poll, 500);
    window.addEventListener('online', poll);
    return { saved, refresh };
})();
