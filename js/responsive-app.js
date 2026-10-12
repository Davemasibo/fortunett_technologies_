/* Keep wide data tables scrollable without making the entire page overflow. */
(() => {
    function prepareTables(root) {
        root.querySelectorAll('table').forEach(table => {
            if (table.classList.contains('sms-table') || table.closest('.responsive-table-scroll,.table-responsive') || !table.parentElement) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'responsive-table-scroll';
            wrapper.tabIndex = 0;
            wrapper.setAttribute('role','region');
            wrapper.setAttribute('aria-label',table.caption?.textContent || 'Scrollable data table');
            table.before(wrapper);
            wrapper.append(table);
        });
    }
    function start() {
        prepareTables(document);
        let scheduled = false;
        new MutationObserver(() => {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(() => { scheduled = false; prepareTables(document); });
        }).observe(document.body,{childList:true,subtree:true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',start,{once:true}); else start();
})();
